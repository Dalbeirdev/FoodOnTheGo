/// The customer's account on the backend (Module 25) — mirrors customer-web/src/account/api/apiAccount.ts.
///
/// Same interfaces as the Mock* repositories, so no screen changes hands. What the backend is authoritative for,
/// and this layer never works around:
///  * identity: the phone is read-only here; it changes only through the verified flow (code to the NEW number),
///    which signs every other device out;
///  * what may be edited: name, e-mail, language (from the market's options), birthday, gender, cuisine preferences,
///    vegetarian filter, search radius — status, market, verification and roles never leave this app;
///  * favorites: only restaurants a customer may see can be added; a favorite of a restaurant that was later hidden
///    comes back as `available: false` with its name only;
///  * saved locations: market, city and service-area resolution and the coverage answer; the app sends an address
///    and, when it has one, a pin;
///  * payment methods: safe metadata only, no endpoint ever receives payment details, references stay on the server;
///  * notification preferences: locked security channels cannot be switched off; marketing stays off until chosen.
///
/// The notification INBOX (list / mark read) stays development data until the delivery module; it is labelled as such.
library;

import '../auth/auth_repository.dart' show KeyValueStore;
import '../core/app_config.dart';
import '../data/api_client.dart';
import '../discovery/api_restaurants.dart' show ApiRestaurantRepository, knownRestaurants;
import '../discovery/discovery_repository.dart' show globalRestaurants;
import '../market/market.dart';
import 'account_repositories.dart';

/* ------------------------------------------------------------------ errors */

/// Backend error → the [RepositoryException] the screens already show. Field messages win for a refused request;
/// codes are kept for the flows (reauthentication_required, stale_update, phone_in_use).
RepositoryException toRepositoryException(String resource, Object e) {
  if (e is RepositoryException) return e;
  if (e is! ApiException) return RepositoryException(resource, 'Something went wrong. Please try again.');
  final fields = {for (final f in e.errors.entries) if (f.value.isNotEmpty) f.key: f.value.first};
  final message = switch (e.kind) {
    ApiErrorKind.validation => fields.values.firstOrNull ?? e.message,
    ApiErrorKind.server => 'Something went wrong on our side. Please try again.',
    _ => e.message,
  };
  return RepositoryException(resource, message, code: e.code, fields: fields);
}

Map<String, dynamic> _map(Object? data) => data is Map<String, dynamic> ? data : const {};
List<Map<String, dynamic>> _maps(Object? data) => data is List ? [for (final e in data) e as Map<String, dynamic>] : const [];

/* ------------------------------------------------------------------ profile */

const _localeNames = {'en-IN': 'English (India)', 'en-GB': 'English (UK)', 'en-US': 'English (US)', 'en-AE': 'English (UAE)', 'hi-IN': 'Hindi'};
String localeName(String code) => _localeNames[code] ?? code;

CustomerProfile profileFromApi(Map<String, dynamic> d, [List<Option> cuisineOptions = const []]) {
  final favorites = [for (final c in d['favorite_cuisines'] as List<dynamic>) Option((c as Map<String, dynamic>)['code'] as String, c['name'] as String)];
  final known = cuisineOptions.map((o) => o.code).toSet();
  final avatar = d['avatar'] as Map<String, dynamic>?;
  return CustomerProfile(
    id: d['id'] as String,
    name: (d['name'] as String?) ?? '',
    displayName: d['display_name'] as String?,
    phone: d['phone'] as String,
    phoneMasked: d['phone_masked'] as String?,
    email: (d['email'] as String?) ?? '',
    avatarPath: avatar?['url'] as String?,
    dob: (d['date_of_birth'] as String?) ?? '',
    gender: ((d['gender'] as String?) ?? '').toLowerCase(),
    language: d['preferred_locale'] as String,
    localeOptions: [for (final code in d['locale_options'] as List<dynamic>) Option(code as String, localeName(code))],
    cuisines: [for (final c in favorites) c.code],
    cuisineOptions: [...cuisineOptions, ...favorites.where((c) => !known.contains(c.code))],
    vegetarian: d['vegetarian_only'] == true,
    searchRadiusKm: (d['search_radius_km'] as int?) ?? 20,
    memberSince: (d['member_since'] as String?) ?? '',
    status: ((d['status'] as String?) ?? 'ACTIVE').toLowerCase(),
    deletionRequestedAt: d['deletion_requested_at'] as String?,
    version: d['version'] as int?,
  );
}

bool _sameList(List<String> a, List<String> b) => a.length == b.length && [for (var i = 0; i < a.length; i++) a[i] == b[i]].every((x) => x);

/// Only what changed, and only the fields the backend lets a customer change — never the phone, status or market.
Map<String, dynamic> profileDiff(CustomerProfile before, CustomerProfile after) {
  final b = <String, dynamic>{};
  if (before.version != null) b['version'] = before.version;
  if (after.name != before.name) b['name'] = after.name.trim();
  if (after.email != before.email) b['email'] = after.email.trim().isEmpty ? null : after.email.trim();
  if (after.dob != before.dob) b['date_of_birth'] = after.dob.isEmpty ? null : after.dob;
  if (after.gender != before.gender) b['gender'] = after.gender.isEmpty ? null : after.gender.toUpperCase();
  if (after.language != before.language) b['preferred_locale'] = after.language;
  if (!_sameList(after.cuisines, before.cuisines)) b['favorite_cuisines'] = after.cuisines;
  if (after.vegetarian != before.vegetarian) b['vegetarian_only'] = after.vegetarian;
  if (after.searchRadiusKm != before.searchRadiusKm) b['search_radius_km'] = after.searchRadiusKm;
  return b;
}

CodeChallenge challengeFromApi(Map<String, dynamic> c, {DateTime Function()? now, String? devOtp}) {
  // Server timestamps → this device's clock, so a wrong device clock cannot shorten or extend the display.
  final skew = (now ?? DateTime.now)().difference(DateTime.parse(c['server_time'] as String));
  final dev = devOtp ?? AppConfig.devOtp;
  return CodeChallenge(
    challengeId: c['challenge_id'] as String,
    phoneMasked: c['phone_masked'] as String,
    expiresAt: DateTime.parse(c['expires_at'] as String).add(skew),
    resendAfter: DateTime.parse(c['resend_available_at'] as String).add(skew),
    attemptsAllowed: c['attempts_allowed'] as int,
    devOtp: c['delivery'] == 'development' && dev.isNotEmpty ? dev : null,
    changeId: c['change_id'] as String?,
  );
}

class ApiProfileRepository extends ProfileRepository implements AccountSecurity {
  ApiProfileRepository({required ApiClient client, String? country}) : _api = client, _country = country; // ignore: prefer_initializing_formals
  final ApiClient _api;
  final String? _country;
  CustomerProfile? _current;
  static List<Option>? _cuisineCache;

  /// The backend profile is the sign-in identity: the header is re-read, not written twice.
  @override
  bool get updatesIdentity => true;
  @override
  AccountSecurity? get security => this;
  String get _marketCountry => _country ?? marketAvailability.activeMarket.countryCode;

  /// Cuisine code → name for the preference chips (the platform taxonomy, read once).
  Future<List<Option>> _cuisines() async {
    if (_cuisineCache != null) return _cuisineCache!;
    try {
      final r = await _api.get('/cuisines', auth: false);
      return _cuisineCache = [for (final c in r['data'] as List<dynamic>) Option((c as Map<String, dynamic>)['code'] as String, c['name'] as String)];
    } catch (_) {
      return const [];
    }
  }

  Future<CustomerProfile> _keep(Map<String, dynamic> d) async => _current = profileFromApi(d, await _cuisines());

  @override
  Future<CustomerProfile> get(String userId, {required String name, required String phone, String? email, required String memberSince}) async {
    try {
      return await _keep(await _api.get('/customer/profile'));
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<CustomerProfile> update(String userId, CustomerProfile Function(CustomerProfile) change) async {
    try {
      final before = _current ?? await _keep(await _api.get('/customer/profile'));
      final body = profileDiff(before, change(before));
      if (body.keys.every((k) => k == 'version')) return before;
      return await _keep(await _api.patch('/customer/profile', body: body));
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<CustomerProfile> setAvatar(String userId, AvatarUpload? upload) async {
    try {
      if (upload == null) return await _keep(_map(await _api.delete('/customer/profile/avatar')));
      return await _keep(_map(await _api.send('POST', '/customer/profile/avatar', file: UploadFile(field: 'image', bytes: upload.bytes, filename: upload.filename))));
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<CustomerProfile> requestDeletion(String userId, {String? reason}) async {
    try {
      final r = await _api.post('/customer/account/deletion-request', body: {'confirm': true, if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim()});
      return await _keep(r['data'] as Map<String, dynamic>);
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<CodeChallenge> requestReauth() async {
    try {
      return challengeFromApi(await _api.post('/customer/account/reauth', body: const <String, dynamic>{}));
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<void> verifyReauth(String challengeId, String code) async {
    try {
      await _api.post('/customer/account/reauth/verify', body: {'challenge_id': challengeId, 'code': code});
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<CodeChallenge> requestPhoneChange(String phone) async {
    try {
      return challengeFromApi(await _api.post('/customer/phone-change/request', body: {'phone': phone, 'country': _marketCountry}));
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }

  @override
  Future<CustomerProfile> verifyPhoneChange(String challengeId, String code) async {
    try {
      return await _keep(await _api.post('/customer/phone-change/verify', body: {'challenge_id': challengeId, 'code': code}));
    } catch (e) {
      throw toRepositoryException('profile', e);
    }
  }
}

/* ------------------------------------------------------------------ favorites */

Favorite favoriteFromApi(Map<String, dynamic> d) {
  final r = d['restaurant'] as Map<String, dynamic>?;
  final restaurant = r == null ? null : ApiRestaurantRepository.toRestaurant(r);
  final slug = d['slug'] as String;
  // The screens address a restaurant by the id the development data uses for the same slug (the backend takes slug or id).
  final legacy = globalRestaurants.where((x) => x.slug == slug).firstOrNull;
  return Favorite(restaurantId: restaurant?.id ?? legacy?.id ?? slug, slug: slug, name: d['name'] as String, addedAt: (d['added_at'] as String?) ?? '', available: d['available'] == true, restaurant: restaurant);
}

String _slugOf(String id) => knownRestaurants.where((r) => r.id == id || r.slug == id).firstOrNull?.slug ?? id;

class ApiFavoriteRepository implements FavoriteRepository {
  ApiFavoriteRepository({required ApiClient client}) : _api = client;
  final ApiClient _api;

  @override
  Future<List<Favorite>> list(String userId) async {
    try {
      final out = <Favorite>[];
      var last = 1;
      for (var page = 1; page <= last && page <= 10; page++) {
        final r = await _api.get('/customer/favorites', query: {'page[size]': '50', 'page[number]': '$page'});
        out.addAll(_maps(r['data']).map(favoriteFromApi));
        last = ((r['meta'] as Map<String, dynamic>?)?['last_page'] as int?) ?? 1;
      }
      return out;
    } catch (e) {
      throw toRepositoryException('favorites', e);
    }
  }

  @override
  Future<List<Favorite>> add(String userId, String restaurantId) async {
    try {
      await _api.post('/customer/favorites/${Uri.encodeComponent(_slugOf(restaurantId))}', body: const <String, dynamic>{});
      return await list(userId);
    } catch (e) {
      throw toRepositoryException('favorites', e);
    }
  }

  @override
  Future<List<Favorite>> remove(String userId, String restaurantId) async {
    try {
      await _api.delete('/customer/favorites/${Uri.encodeComponent(_slugOf(restaurantId))}');
      return await list(userId);
    } catch (e) {
      throw toRepositoryException('favorites', e);
    }
  }
}

/* ------------------------------------------------------------------ saved journey locations */

SavedAddress addressFromApi(Map<String, dynamic> d) {
  final a = d['address'] as Map<String, dynamic>;
  final loc = d['location'] as Map<String, dynamic>?;
  final c = d['coverage'] as Map<String, dynamic>;
  return SavedAddress(
    id: d['id'] as String,
    label: d['label'] as String,
    kind: AddressKind.values.byName((d['kind'] as String).toLowerCase()),
    line1: (a['line1'] as String?) ?? '',
    line2: (a['line2'] as String?) ?? '',
    locality: (a['locality'] as String?) ?? '',
    city: (a['city'] as String?) ?? '',
    state: (a['region'] as String?) ?? '',
    pincode: (a['postal_code'] as String?) ?? '',
    lat: (loc?['latitude'] as num?)?.toDouble(),
    lng: (loc?['longitude'] as num?)?.toDouble(),
    isDefault: d['is_default'] == true,
    coverage: Coverage(status: c['status'] as String, reason: c['reason'] as String?, market: c['market'] as String?, city: c['city'] as String?, serviceArea: c['service_area'] as String?),
    formattedAddress: a['formatted'] as String?,
    countryCode: a['country_code'] as String?,
    version: d['version'] as int?,
  );
}

/// The form fields onto the API: the formatted line is composed here; market, city and coverage are the backend's business.
Map<String, dynamic> savedLocationBody(SavedAddress a, String country) {
  String? t(String s) => s.trim().isEmpty ? null : s.trim();
  final tail = [a.state.trim(), a.pincode.trim()].where((s) => s.isNotEmpty).join(' ');
  final formatted = [a.line1, a.line2, a.locality, a.city, tail].map((s) => s.trim()).where((s) => s.isNotEmpty).join(', ');
  return {
    'kind': a.kind.name.toUpperCase(),
    'label': a.label.trim(),
    'line1': t(a.line1),
    'line2': t(a.line2),
    'locality': t(a.locality),
    'city': t(a.city),
    'region': t(a.state),
    'postal_code': t(a.pincode),
    'country_code': a.countryCode ?? country,
    'formatted_address': formatted.isEmpty ? null : formatted,
    if (a.lat != null && a.lng != null) ...{'lat': a.lat, 'lng': a.lng},
  };
}

class ApiAddressRepository implements AddressRepository {
  ApiAddressRepository({required ApiClient client, String? country}) : _api = client, _country = country; // ignore: prefer_initializing_formals
  final ApiClient _api;
  final String? _country;
  String get _marketCountry => _country ?? marketAvailability.activeMarket.countryCode;

  @override
  Future<List<SavedAddress>> list(String userId) async {
    try {
      return _maps(await _api.getList('/customer/saved-locations')).map(addressFromApi).toList();
    } catch (e) {
      throw toRepositoryException('addresses', e);
    }
  }

  @override
  Future<List<SavedAddress>> save(String userId, SavedAddress address) async {
    try {
      final body = savedLocationBody(address, _marketCountry);
      if (address.id.isNotEmpty) {
        final version = address.version ?? (await list(userId)).where((x) => x.id == address.id).firstOrNull?.version ?? 1;
        // Without a pin the point is removed (the place keeps its address; coverage becomes "unknown").
        await _api.patch('/customer/saved-locations/${Uri.encodeComponent(address.id)}', body: {'version': version, ...body, if (address.lat == null || address.lng == null) 'location': null});
      } else {
        await _api.post('/customer/saved-locations', body: body);
      }
      return await list(userId);
    } catch (e) {
      throw toRepositoryException('addresses', e);
    }
  }

  @override
  Future<List<SavedAddress>> remove(String userId, String id) async {
    try {
      await _api.delete('/customer/saved-locations/${Uri.encodeComponent(id)}');
      return await list(userId);
    } catch (e) {
      throw toRepositoryException('addresses', e);
    }
  }

  @override
  Future<List<SavedAddress>> setDefault(String userId, String id) async {
    try {
      await _api.post('/customer/saved-locations/${Uri.encodeComponent(id)}/default', body: const <String, dynamic>{});
      return await list(userId);
    } catch (e) {
      throw toRepositoryException('addresses', e);
    }
  }
}

/* ------------------------------------------------------------------ payment-method references */

PaymentMethod paymentMethodFromApi(Map<String, dynamic> d) {
  final type = d['type'] as String;
  final expiry = d['expiry'] as Map<String, dynamic>?;
  final exp = expiry == null ? null : '${(expiry['month'] as int).toString().padLeft(2, '0')}/${(expiry['year'] as int).toString().substring(2)}';
  // The provider reference never leaves the server: nothing here could be used to charge anyone.
  return PaymentMethod(
    id: d['id'] as String,
    type: switch (type) { 'CARD' => 'card', 'UPI' => 'upi', _ => 'other' },
    provider: d['provider'] as String?,
    brand: d['brand'] as String?,
    last4: d['last4'] as String?,
    expiry: exp,
    handleMasked: (d['upi_handle_masked'] as String?) ?? (type == 'UPI' ? d['display_label'] as String? : null),
    label: d['display_label'] as String?,
    isDefault: d['is_default'] == true,
    status: (d['status'] as String?) ?? 'ACTIVE',
  );
}

class ApiPaymentMethodRepository implements PaymentMethodRepository {
  ApiPaymentMethodRepository({required ApiClient client}) : _api = client;
  final ApiClient _api;

  @override
  Future<List<PaymentMethod>> list(String userId) async {
    try {
      return _maps(await _api.getList('/customer/payment-methods')).map(paymentMethodFromApi).toList();
    } catch (e) {
      throw toRepositoryException('payments', e);
    }
  }

  @override
  Future<List<PaymentMethod>> setDefault(String userId, String id) async {
    try {
      return _maps(await _api.send('PATCH', '/customer/payment-methods/${Uri.encodeComponent(id)}/default', body: const <String, dynamic>{})).map(paymentMethodFromApi).toList();
    } catch (e) {
      throw toRepositoryException('payments', e);
    }
  }

  @override
  Future<List<PaymentMethod>> remove(String userId, String id) async {
    try {
      return _maps(await _api.delete('/customer/payment-methods/${Uri.encodeComponent(id)}')).map(paymentMethodFromApi).toList();
    } catch (e) {
      throw toRepositoryException('payments', e);
    }
  }
}

/* ------------------------------------------------------------------ notification preferences */

NotificationPreferences preferencesFromApi(Map<String, dynamic> d) {
  final consent = (d['marketing_consent'] as Map<String, dynamic>?) ?? const {};
  final matrix = NotificationMatrix(
    categories: [
      for (final c in _maps(d['categories']))
        NotificationCategoryPrefs(
          category: c['category'] as String,
          name: c['name'] as String,
          description: (c['description'] as String?) ?? '',
          transactional: c['transactional'] == true,
          channels: [for (final ch in _maps(c['channels'])) NotificationCell(channel: ch['channel'] as String, enabled: ch['enabled'] == true, locked: ch['locked'] == true, chosen: ch['chosen'] == true)],
        ),
    ],
    channels: (d['channels'] as List<dynamic>).cast<String>(),
    consentGrantedAt: consent['granted_at'] as String?,
    consentWithdrawnAt: consent['withdrawn_at'] as String?,
  );
  return NotificationPreferences(
    push: matrix.isOn(channel: 'PUSH'),
    orderUpdates: matrix.isOn(category: 'ORDER_UPDATES'),
    paymentUpdates: matrix.isOn(category: 'PAYMENT_UPDATES'),
    promotions: matrix.isOn(category: 'PROMOTIONS'),
    email: matrix.isOn(channel: 'EMAIL'),
    sms: matrix.isOn(channel: 'SMS'),
    matrix: matrix,
  );
}

/// The Module 04 switches onto matrix cells: only what changed, spread over the matrix, never switching a locked cell off.
List<NotificationCellChange> flatCells(NotificationPreferences before, NotificationPreferences after, NotificationMatrix matrix) {
  final cells = <NotificationCellChange>[];
  void spread(bool Function(String category, String channel) match, bool enabled) {
    for (final c in matrix.categories) {
      for (final ch in c.channels) {
        if (!match(c.category, ch.channel) || (ch.locked && !enabled) || cells.any((x) => x.category == c.category && x.channel == ch.channel)) continue;
        cells.add(NotificationCellChange(c.category, ch.channel, enabled));
      }
    }
  }
  if (after.push != before.push) spread((_, ch) => ch == 'PUSH', after.push);
  if (after.email != before.email) spread((_, ch) => ch == 'EMAIL', after.email);
  if (after.sms != before.sms) spread((_, ch) => ch == 'SMS', after.sms);
  if (after.orderUpdates != before.orderUpdates) spread((c, _) => c == 'ORDER_UPDATES' || c == 'PICKUP_UPDATES', after.orderUpdates);
  if (after.paymentUpdates != before.paymentUpdates) spread((c, _) => c == 'PAYMENT_UPDATES', after.paymentUpdates);
  if (after.promotions != before.promotions) spread((c, _) => c == 'PROMOTIONS', after.promotions);
  return cells;
}

class ApiNotificationRepository extends NotificationRepository {
  ApiNotificationRepository({required ApiClient client, required MockAccountStore devStore}) : _api = client, _inbox = MockNotificationRepository(devStore);
  final ApiClient _api;
  final MockNotificationRepository _inbox;
  NotificationPreferences? _current;

  /// The inbox is development data until notification delivery exists (a later module); preferences are real.
  @override
  bool get inboxIsDevelopmentData => true;

  @override
  Future<List<AppNotification>> list(String userId) => _inbox.list(userId);
  @override
  Future<List<AppNotification>> markRead(String userId, String id) => _inbox.markRead(userId, id);
  @override
  Future<List<AppNotification>> markAllRead(String userId) => _inbox.markAllRead(userId);

  @override
  Future<NotificationPreferences> getPreferences(String userId) async {
    try {
      return _current = preferencesFromApi(await _api.get('/customer/notification-preferences'));
    } catch (e) {
      throw toRepositoryException('notifications', e);
    }
  }

  @override
  Future<NotificationPreferences> updatePreferences(String userId, NotificationPreferences prefs) async {
    final current = _current ?? await getPreferences(userId);
    final matrix = current.matrix;
    final cells = matrix == null ? const <NotificationCellChange>[] : flatCells(current, prefs, matrix);
    if (cells.isEmpty) return current;
    return updateCells(userId, cells);
  }

  @override
  Future<NotificationPreferences> updateCells(String userId, List<NotificationCellChange> cells) async {
    try {
      return _current = preferencesFromApi(await _api.patch('/customer/notification-preferences', body: {'preferences': [for (final c in cells) c.toJson()]}));
    } catch (e) {
      throw toRepositoryException('notifications', e);
    }
  }
}

/// The five repositories against the backend. The development store keeps the (development) notification inbox.
AccountRepositories apiAccountRepositories({required ApiClient client, required KeyValueStore devStore, String? country}) => AccountRepositories(
      profile: ApiProfileRepository(client: client, country: country),
      favorites: ApiFavoriteRepository(client: client),
      addresses: ApiAddressRepository(client: client, country: country),
      payments: ApiPaymentMethodRepository(client: client),
      notifications: ApiNotificationRepository(client: client, devStore: MockAccountStore(store: devStore)),
    );
