import 'dart:convert';

import '../auth/auth_repository.dart' show KeyValueStore;
import '../discovery/restaurant_models.dart' show GlobalRestaurant;

/// Customer account repositories (Module 04 UI, Module 25 backend). Screens only see the interfaces;
/// Mock* implementations persist development data per customer in the injected store, Api* implementations
/// (api_account.dart) talk to the backend. Which one runs follows the sign-in mode (AUTH_MODE).
class RepositoryException implements Exception {
  RepositoryException(this.resource, this.message, {this.code, this.fields = const {}});
  final String resource, message;

  /// Stable backend code when there is one (reauthentication_required, phone_in_use, stale_update, …).
  final String? code;

  /// First message per field of a refused request.
  final Map<String, String> fields;
  @override
  String toString() => message;
}

class Option {
  const Option(this.code, this.name);
  final String code, name;
}

class CustomerProfile {
  const CustomerProfile({
    required this.id, required this.name, required this.phone, required this.email, this.avatarPath, required this.dob, required this.gender, required this.language,
    required this.cuisines, required this.vegetarian, required this.searchRadiusKm, required this.memberSince, this.emailVerified = false, this.deletionRequestedAt,
    this.displayName, this.phoneMasked, this.localeOptions = const [], this.cuisineOptions = const [], this.status = 'active', this.version,
  });
  final String id, name, phone, email, dob, gender, language, memberSince;

  /// Mock: a local file path. API: the photo URL served by the backend.
  final String? avatarPath, deletionRequestedAt, displayName, phoneMasked;

  /// Mock: cuisine names. API: cuisine codes of the taxonomy; [cuisineOptions] carries code → name.
  final List<String> cuisines;
  final List<Option> localeOptions, cuisineOptions;
  final bool vegetarian, emailVerified;
  final int searchRadiusKm;

  /// active | restricted | suspended | deactivated
  final String status;

  /// API: optimistic-concurrency version sent back with edits.
  final int? version;
  bool get phoneVerified => true;
  String get shownName => name.trim().isNotEmpty ? name : (displayName ?? phone);
  String get initials => shownName.trim().split(RegExp(r'\s+')).map((p) => p.isEmpty ? '' : p[0]).take(2).join().toUpperCase();
  String cuisineName(String code) => cuisineOptions.where((o) => o.code == code).firstOrNull?.name ?? code;

  CustomerProfile copyWith({String? name, String? email, String? avatarPath, bool clearAvatar = false, String? dob, String? gender, String? language, List<String>? cuisines, bool? vegetarian, int? searchRadiusKm, bool? emailVerified, String? deletionRequestedAt, String? status, int? version}) => CustomerProfile(
        id: id, name: name ?? this.name, phone: phone, email: email ?? this.email, avatarPath: clearAvatar ? null : (avatarPath ?? this.avatarPath), dob: dob ?? this.dob, gender: gender ?? this.gender, language: language ?? this.language,
        cuisines: cuisines ?? this.cuisines, vegetarian: vegetarian ?? this.vegetarian, searchRadiusKm: searchRadiusKm ?? this.searchRadiusKm, memberSince: memberSince, emailVerified: emailVerified ?? this.emailVerified, deletionRequestedAt: deletionRequestedAt ?? this.deletionRequestedAt,
        displayName: displayName, phoneMasked: phoneMasked, localeOptions: localeOptions, cuisineOptions: cuisineOptions, status: status ?? this.status, version: version ?? this.version);

  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'phone': phone, 'email': email, 'avatarPath': avatarPath, 'dob': dob, 'gender': gender, 'language': language, 'cuisines': cuisines, 'vegetarian': vegetarian, 'searchRadiusKm': searchRadiusKm, 'memberSince': memberSince, 'emailVerified': emailVerified, 'deletionRequestedAt': deletionRequestedAt, 'status': status};
  factory CustomerProfile.fromJson(Map<String, dynamic> j) => CustomerProfile(id: j['id'] as String, name: j['name'] as String, phone: j['phone'] as String, email: (j['email'] as String?) ?? '', avatarPath: j['avatarPath'] as String?, dob: (j['dob'] as String?) ?? '', gender: (j['gender'] as String?) ?? '', language: (j['language'] as String?) ?? 'English', cuisines: ((j['cuisines'] as List?) ?? []).cast<String>(), vegetarian: j['vegetarian'] == true, searchRadiusKm: (j['searchRadiusKm'] as int?) ?? 20, memberSince: j['memberSince'] as String, emailVerified: j['emailVerified'] == true, deletionRequestedAt: j['deletionRequestedAt'] as String?, status: (j['status'] as String?) ?? 'active');
}

/// A new profile photo: the bytes to upload plus, for the development data, where the picked file lives.
class AvatarUpload {
  const AvatarUpload({required this.bytes, required this.filename, this.localPath});
  final List<int> bytes;
  final String filename;
  final String? localPath;
}

/// A one-time code the backend sent (re-authentication or phone change). Times are on this device's clock.
class CodeChallenge {
  const CodeChallenge({required this.challengeId, required this.phoneMasked, required this.expiresAt, required this.resendAfter, required this.attemptsAllowed, this.devOtp, this.changeId});
  final String challengeId, phoneMasked;
  final DateTime expiresAt, resendAfter;
  final int attemptsAllowed;
  final String? devOtp, changeId;
}

/// Sensitive account actions (API mode only): each is confirmed with a code sent to a phone.
abstract class AccountSecurity {
  /// Code to the account's own phone; verifying it unlocks sensitive actions on this session for a few minutes.
  Future<CodeChallenge> requestReauth();
  Future<void> verifyReauth(String challengeId, String code);

  /// Code to the NEW number. Throws RepositoryException 'reauthentication_required' when the session is not recent.
  Future<CodeChallenge> requestPhoneChange(String phone);
  Future<CustomerProfile> verifyPhoneChange(String challengeId, String code);
}

class Favorite {
  const Favorite({required this.restaurantId, required this.addedAt, this.slug, this.name, this.available = true, this.restaurant});
  final String restaurantId, addedAt;

  /// API: the restaurant as the backend shows it. Null in mock mode (screens look it up by id) and when the
  /// restaurant is no longer visible to customers ([available] false): the favorite is kept, only the name is shown.
  final String? slug, name;
  final bool available;
  final GlobalRestaurant? restaurant;
  Map<String, dynamic> toJson() => {'restaurantId': restaurantId, 'addedAt': addedAt};
  factory Favorite.fromJson(Map<String, dynamic> j) => Favorite(restaurantId: j['restaurantId'] as String, addedAt: j['addedAt'] as String);
}

enum AddressKind { home, work, other }

/// API: whether FoodOnTheGo serves a saved place right now — decided by the backend for every request, never stored.
class Coverage {
  const Coverage({required this.status, this.reason, this.market, this.city, this.serviceArea});

  /// supported | unsupported | unknown (no coordinates)
  final String status;
  final String? reason, market, city, serviceArea;
  bool get supported => status == 'supported';
}

/// Saved journey location (start / destination shortcut). NOT a delivery address.
class SavedAddress {
  const SavedAddress({required this.id, required this.label, required this.kind, required this.line1, this.line2 = '', required this.locality, required this.city, required this.state, required this.pincode, this.lat, this.lng, this.isDefault = false, this.coverage, this.formattedAddress, this.countryCode, this.version});
  final String id, label, line1, line2, locality, city, state, pincode;
  final AddressKind kind;
  final double? lat, lng;
  final bool isDefault;
  final Coverage? coverage;
  final String? formattedAddress, countryCode;
  final int? version;
  String get formatted => formattedAddress ?? [line1, line2, locality, '$city, $state $pincode'].where((s) => s.trim().isNotEmpty && s.trim() != ',').join(', ');
  SavedAddress copyWith({String? id, String? label, AddressKind? kind, String? line1, String? line2, String? locality, String? city, String? state, String? pincode, double? lat, double? lng, bool clearPin = false, bool? isDefault}) =>
      SavedAddress(id: id ?? this.id, label: label ?? this.label, kind: kind ?? this.kind, line1: line1 ?? this.line1, line2: line2 ?? this.line2, locality: locality ?? this.locality, city: city ?? this.city, state: state ?? this.state, pincode: pincode ?? this.pincode, lat: clearPin ? null : lat ?? this.lat, lng: clearPin ? null : lng ?? this.lng, isDefault: isDefault ?? this.isDefault, coverage: coverage, formattedAddress: formattedAddress, countryCode: countryCode, version: version);
  Map<String, dynamic> toJson() => {'id': id, 'label': label, 'kind': kind.name, 'line1': line1, 'line2': line2, 'locality': locality, 'city': city, 'state': state, 'pincode': pincode, 'lat': lat, 'lng': lng, 'isDefault': isDefault};
  factory SavedAddress.fromJson(Map<String, dynamic> j) => SavedAddress(id: j['id'] as String, label: j['label'] as String, kind: AddressKind.values.byName(j['kind'] as String), line1: j['line1'] as String, line2: (j['line2'] as String?) ?? '', locality: (j['locality'] as String?) ?? '', city: j['city'] as String, state: j['state'] as String, pincode: j['pincode'] as String, lat: (j['lat'] as num?)?.toDouble(), lng: (j['lng'] as num?)?.toDouble(), isDefault: j['isDefault'] == true);
}

/// SECURITY BOUNDARY: provider references + display hints only. Never card numbers, CVV or UPI PINs.
/// In API mode the reference itself never leaves the server ([providerRef] is null).
class PaymentMethod {
  const PaymentMethod({required this.id, required this.type, this.providerRef, this.provider, this.brand, this.last4, this.expiry, this.holder, this.handleMasked, this.label, this.balance, this.isDefault = false, this.status = 'ACTIVE'});

  /// card | upi | other (a provider-held wallet or bank method) | wallet (development) | cash (development)
  final String id, type;
  final String? providerRef, provider, brand, last4, expiry, holder, handleMasked, label;
  final int? balance;
  final bool isDefault;

  /// ACTIVE | EXPIRED | REVOKED | UNAVAILABLE
  final String status;
  bool get removable => type == 'card' || type == 'upi' || type == 'other';
  bool get usable => status == 'ACTIVE';
  PaymentMethod copyWith({bool? isDefault}) => PaymentMethod(id: id, type: type, providerRef: providerRef, provider: provider, brand: brand, last4: last4, expiry: expiry, holder: holder, handleMasked: handleMasked, label: label, balance: balance, isDefault: isDefault ?? this.isDefault, status: status);
  Map<String, dynamic> toJson() => {'id': id, 'type': type, 'providerRef': providerRef, 'brand': brand, 'last4': last4, 'expiry': expiry, 'holder': holder, 'handleMasked': handleMasked, 'balance': balance, 'isDefault': isDefault};
  factory PaymentMethod.fromJson(Map<String, dynamic> j) => PaymentMethod(id: j['id'] as String, type: j['type'] as String, providerRef: j['providerRef'] as String?, brand: j['brand'] as String?, last4: j['last4'] as String?, expiry: j['expiry'] as String?, holder: j['holder'] as String?, handleMasked: j['handleMasked'] as String?, balance: j['balance'] as int?, isDefault: j['isDefault'] == true);
}

class AppNotification {
  const AppNotification({required this.id, required this.kind, required this.title, required this.text, required this.at, required this.read, this.route});
  final String id, kind, title, text, at;
  final bool read;
  final String? route;
  AppNotification copyWith({bool? read}) => AppNotification(id: id, kind: kind, title: title, text: text, at: at, read: read ?? this.read, route: route);
  Map<String, dynamic> toJson() => {'id': id, 'kind': kind, 'title': title, 'text': text, 'at': at, 'read': read, 'route': route};
  factory AppNotification.fromJson(Map<String, dynamic> j) => AppNotification(id: j['id'] as String, kind: j['kind'] as String, title: j['title'] as String, text: j['text'] as String, at: j['at'] as String, read: j['read'] == true, route: j['route'] as String?);
}

/// One cell of the backend's category × channel matrix.
class NotificationCell {
  const NotificationCell({required this.channel, required this.enabled, required this.locked, required this.chosen});
  final String channel;
  final bool enabled, locked, chosen;
}

class NotificationCategoryPrefs {
  const NotificationCategoryPrefs({required this.category, required this.name, required this.description, required this.transactional, required this.channels});
  final String category, name, description;
  final bool transactional;
  final List<NotificationCell> channels;
  NotificationCell? cell(String channel) => channels.where((c) => c.channel == channel).firstOrNull;
}

/// API: the matrix the backend keeps; security notices stay on where a cell is locked.
class NotificationMatrix {
  const NotificationMatrix({required this.categories, required this.channels, this.consentGrantedAt, this.consentWithdrawnAt});
  final List<NotificationCategoryPrefs> categories;
  final List<String> channels;
  final String? consentGrantedAt, consentWithdrawnAt;
  bool isOn({String? category, String? channel}) => categories.any((c) => (category == null || c.category == category) && c.channels.any((ch) => (channel == null || ch.channel == channel) && ch.enabled));
}

class NotificationCellChange {
  const NotificationCellChange(this.category, this.channel, this.enabled);
  final String category, channel;
  final bool enabled;
  Map<String, dynamic> toJson() => {'category': category, 'channel': channel, 'enabled': enabled};
}

class NotificationPreferences {
  const NotificationPreferences({this.push = true, this.orderUpdates = true, this.paymentUpdates = true, this.promotions = false, this.email = true, this.sms = true, this.matrix});
  final bool push, orderUpdates, paymentUpdates, promotions, email, sms;

  /// API only; null for the development data.
  final NotificationMatrix? matrix;
  NotificationPreferences copyWith({bool? push, bool? orderUpdates, bool? paymentUpdates, bool? promotions, bool? email, bool? sms}) => NotificationPreferences(push: push ?? this.push, orderUpdates: orderUpdates ?? this.orderUpdates, paymentUpdates: paymentUpdates ?? this.paymentUpdates, promotions: promotions ?? this.promotions, email: email ?? this.email, sms: sms ?? this.sms, matrix: matrix);
  Map<String, dynamic> toJson() => {'push': push, 'orderUpdates': orderUpdates, 'paymentUpdates': paymentUpdates, 'promotions': promotions, 'email': email, 'sms': sms};
  factory NotificationPreferences.fromJson(Map<String, dynamic> j) => NotificationPreferences(push: j['push'] != false, orderUpdates: j['orderUpdates'] != false, paymentUpdates: j['paymentUpdates'] != false, promotions: j['promotions'] == true, email: j['email'] != false, sms: j['sms'] != false);
}

abstract class ProfileRepository {
  Future<CustomerProfile> get(String userId, {required String name, required String phone, String? email, required String memberSince});
  Future<CustomerProfile> update(String userId, CustomerProfile Function(CustomerProfile) change);
  Future<CustomerProfile> setAvatar(String userId, AvatarUpload? upload);

  /// Throws RepositoryException 'reauthentication_required' when the backend wants a fresh code first.
  Future<CustomerProfile> requestDeletion(String userId, {String? reason});

  /// true when name / e-mail edits already reach the sign-in identity (the backend profile IS the identity).
  bool get updatesIdentity => false;
  AccountSecurity? get security => null;
}

abstract class FavoriteRepository {
  Future<List<Favorite>> list(String userId);
  Future<List<Favorite>> add(String userId, String restaurantId);
  Future<List<Favorite>> remove(String userId, String restaurantId);
}

abstract class AddressRepository {
  Future<List<SavedAddress>> list(String userId);
  Future<List<SavedAddress>> save(String userId, SavedAddress address);
  Future<List<SavedAddress>> remove(String userId, String id);
  Future<List<SavedAddress>> setDefault(String userId, String id);
}

abstract class PaymentMethodRepository {
  Future<List<PaymentMethod>> list(String userId);
  Future<List<PaymentMethod>> setDefault(String userId, String id);
  Future<List<PaymentMethod>> remove(String userId, String id);
}

abstract class NotificationRepository {
  Future<List<AppNotification>> list(String userId);
  Future<List<AppNotification>> markRead(String userId, String id);
  Future<List<AppNotification>> markAllRead(String userId);
  Future<NotificationPreferences> getPreferences(String userId);
  Future<NotificationPreferences> updatePreferences(String userId, NotificationPreferences prefs);

  /// API: set single cells of the matrix. The development data has no matrix.
  Future<NotificationPreferences> updateCells(String userId, List<NotificationCellChange> cells) => throw UnsupportedError('The development data keeps no notification matrix.');

  /// true when the inbox is development data (notification delivery is a later module).
  bool get inboxIsDevelopmentData => false;
}

/// Shared persistence + simulation for the development mocks.
/// `failResources` (e.g. {'favorites'}) forces error states so screens can be tested.
class MockAccountStore {
  MockAccountStore({required this.store, this.latency = const Duration(milliseconds: 350), DateTime Function()? now}) : _now = now ?? DateTime.now;
  final KeyValueStore store;
  final Duration latency;
  final DateTime Function() _now;
  final Set<String> failResources = {};
  static const seededCustomer = 'cust-rahul';

  DateTime now() => _now();
  String key(String r, String u) => 'fotg.mock.account.$r.$u';
  Future<void> simulate(String resource) async {
    await Future<void>.delayed(latency);
    if (failResources.contains(resource)) throw RepositoryException(resource, "We couldn't load your $resource right now. Please try again.");
  }

  Future<List<T>> readList<T>(String r, String u, T Function(Map<String, dynamic>) from, List<Map<String, dynamic>> Function() seed) async {
    final raw = await store.read(key(r, u));
    if (raw != null) return (jsonDecode(raw) as List).map((e) => from(e as Map<String, dynamic>)).toList();
    final s = seed();
    await store.write(key(r, u), jsonEncode(s));
    return s.map(from).toList();
  }

  Future<List<T>> writeList<T>(String r, String u, List<T> list, Map<String, dynamic> Function(T) to) async {
    await store.write(key(r, u), jsonEncode(list.map(to).toList()));
    return list;
  }

  String ago(double hours) => _now().subtract(Duration(minutes: (hours * 60).round())).toIso8601String();
  String uid() => _now().microsecondsSinceEpoch.toRadixString(36);
}

class MockProfileRepository extends ProfileRepository {
  MockProfileRepository(this.s);
  final MockAccountStore s;
  @override
  Future<CustomerProfile> get(String userId, {required String name, required String phone, String? email, required String memberSince}) async {
    await s.simulate('profile');
    final raw = await s.store.read(s.key('profile', userId));
    if (raw != null) return CustomerProfile.fromJson(jsonDecode(raw) as Map<String, dynamic>).copyWith(name: name, email: email ?? '');
    final seeded = userId == MockAccountStore.seededCustomer;
    final p = CustomerProfile(id: userId, name: name, phone: phone, email: email ?? '', dob: seeded ? '1990-03-15' : '', gender: seeded ? 'male' : '', language: 'English', cuisines: seeded ? ['Indian', 'Fast Food', 'Healthy'] : [], vegetarian: false, searchRadiusKm: 20, memberSince: memberSince);
    await s.store.write(s.key('profile', userId), jsonEncode(p.toJson()));
    return p;
  }

  @override
  Future<CustomerProfile> update(String userId, CustomerProfile Function(CustomerProfile) change) async {
    await s.simulate('profile');
    final raw = await s.store.read(s.key('profile', userId));
    if (raw == null) throw RepositoryException('profile', 'Profile not found.');
    final current = CustomerProfile.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    var next = change(current);
    if (next.email != current.email) next = next.copyWith(emailVerified: false);
    await s.store.write(s.key('profile', userId), jsonEncode(next.toJson()));
    return next;
  }

  @override
  Future<CustomerProfile> setAvatar(String userId, AvatarUpload? upload) => update(userId, (p) => p.copyWith(avatarPath: upload?.localPath, clearAvatar: upload == null));
  @override
  Future<CustomerProfile> requestDeletion(String userId, {String? reason}) => update(userId, (p) => p.copyWith(deletionRequestedAt: s.now().toIso8601String()));
}

class MockFavoriteRepository implements FavoriteRepository {
  MockFavoriteRepository(this.s);
  final MockAccountStore s;
  List<Map<String, dynamic>> _seed(String u) => u == MockAccountStore.seededCustomer ? [for (final (i, id) in ['burger-hub', 'pizza-point', 'spice-nest', 'brew-bites'].indexed) Favorite(restaurantId: id, addedAt: s.ago(24.0 * (i + 2))).toJson()] : [];
  Future<List<Favorite>> _read(String u) => s.readList('favorites', u, Favorite.fromJson, () => _seed(u));
  @override
  Future<List<Favorite>> list(String userId) async { await s.simulate('favorites'); return _read(userId); }
  @override
  Future<List<Favorite>> add(String userId, String restaurantId) async {
    await s.simulate('favorites');
    final list = await _read(userId);
    if (list.any((f) => f.restaurantId == restaurantId)) return list;
    return s.writeList('favorites', userId, [Favorite(restaurantId: restaurantId, addedAt: s.now().toIso8601String()), ...list], (f) => f.toJson());
  }
  @override
  Future<List<Favorite>> remove(String userId, String restaurantId) async {
    await s.simulate('favorites');
    return s.writeList('favorites', userId, (await _read(userId)).where((f) => f.restaurantId != restaurantId).toList(), (f) => f.toJson());
  }
}

class MockAddressRepository implements AddressRepository {
  MockAddressRepository(this.s);
  final MockAccountStore s;
  List<Map<String, dynamic>> _seed(String u) => u == MockAccountStore.seededCustomer
      ? [
          const SavedAddress(id: 'home', label: 'Home', kind: AddressKind.home, line1: 'A-203, Green Valley Apartments', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309', lat: 28.6271, lng: 77.3717, isDefault: true).toJson(),
          const SavedAddress(id: 'work', label: 'Work', kind: AddressKind.work, line1: 'Tower B, ABC Corporate Park', locality: 'Sector 142', city: 'Noida', state: 'Uttar Pradesh', pincode: '201305', lat: 28.4987, lng: 77.4115).toJson(),
        ]
      : [];
  Future<List<SavedAddress>> _read(String u) => s.readList('addresses', u, SavedAddress.fromJson, () => _seed(u));
  @override
  Future<List<SavedAddress>> list(String userId) async { await s.simulate('addresses'); return _read(userId); }
  @override
  Future<List<SavedAddress>> save(String userId, SavedAddress address) async {
    await s.simulate('addresses');
    final list = await _read(userId);
    List<SavedAddress> next;
    if (address.id.isNotEmpty) {
      if (!list.any((a) => a.id == address.id)) throw RepositoryException('addresses', 'That address no longer exists.');
      next = list.map((a) => a.id == address.id ? address.copyWith(isDefault: a.isDefault) : a).toList();
    } else {
      next = [...list, address.copyWith(id: 'addr-${s.uid()}', isDefault: list.isEmpty)];
    }
    return s.writeList('addresses', userId, next, (a) => a.toJson());
  }
  @override
  Future<List<SavedAddress>> remove(String userId, String id) async {
    await s.simulate('addresses');
    final list = (await _read(userId)).where((a) => a.id != id).toList();
    if (list.isNotEmpty && !list.any((a) => a.isDefault)) list[0] = list[0].copyWith(isDefault: true);
    return s.writeList('addresses', userId, list, (a) => a.toJson());
  }
  @override
  Future<List<SavedAddress>> setDefault(String userId, String id) async {
    await s.simulate('addresses');
    return s.writeList('addresses', userId, (await _read(userId)).map((a) => a.copyWith(isDefault: a.id == id)).toList(), (a) => a.toJson());
  }
}

class MockPaymentMethodRepository implements PaymentMethodRepository {
  MockPaymentMethodRepository(this.s);
  final MockAccountStore s;
  List<Map<String, dynamic>> _seed(String u) => u == MockAccountStore.seededCustomer
      ? [
          const PaymentMethod(id: 'pm-card-1', type: 'card', providerRef: 'token_mock_4f8a', brand: 'Visa', last4: '3456', expiry: '12/28', holder: 'Rahul Sharma', isDefault: true).toJson(),
          const PaymentMethod(id: 'pm-upi-1', type: 'upi', providerRef: 'token_mock_9c1d', handleMasked: 'ra***@okaxis', holder: 'Rahul Sharma').toJson(),
          const PaymentMethod(id: 'pm-wallet', type: 'wallet', balance: 250).toJson(),
          const PaymentMethod(id: 'pm-cash', type: 'cash').toJson(),
        ]
      : [const PaymentMethod(id: 'pm-wallet', type: 'wallet', balance: 0).toJson(), const PaymentMethod(id: 'pm-cash', type: 'cash', isDefault: true).toJson()];
  Future<List<PaymentMethod>> _read(String u) => s.readList('payments', u, PaymentMethod.fromJson, () => _seed(u));
  @override
  Future<List<PaymentMethod>> list(String userId) async { await s.simulate('payments'); return _read(userId); }
  @override
  Future<List<PaymentMethod>> setDefault(String userId, String id) async {
    await s.simulate('payments');
    return s.writeList('payments', userId, (await _read(userId)).map((m) => m.copyWith(isDefault: m.id == id)).toList(), (m) => m.toJson());
  }
  @override
  Future<List<PaymentMethod>> remove(String userId, String id) async {
    await s.simulate('payments');
    final list = await _read(userId);
    final target = list.where((m) => m.id == id).firstOrNull;
    if (target == null || !target.removable) throw RepositoryException('payments', 'This payment method cannot be removed.');
    var next = list.where((m) => m.id != id).toList();
    if (target.isDefault && next.isNotEmpty) next = [for (final (i, m) in next.indexed) m.copyWith(isDefault: i == 0)];
    return s.writeList('payments', userId, next, (m) => m.toJson());
  }
}

class MockNotificationRepository extends NotificationRepository {
  MockNotificationRepository(this.s);
  final MockAccountStore s;
  List<Map<String, dynamic>> _seed(String u) => u == MockAccountStore.seededCustomer
      ? [
          AppNotification(id: 'n1', kind: 'orders', title: 'Your order is ready for pickup', text: 'Burger Hub', at: s.ago(0.03), read: false, route: '/my-orders').toJson(),
          AppNotification(id: 'n2', kind: 'offers', title: 'Special offer just for you!', text: 'Get 20% off on your next order', at: s.ago(1), read: false).toJson(),
          AppNotification(id: 'n3', kind: 'orders', title: 'Order confirmed', text: 'Your order at Pizza Point has been confirmed', at: s.ago(3), read: false, route: '/my-orders').toJson(),
          AppNotification(id: 'n4', kind: 'updates', title: 'New restaurant nearby', text: 'Spice Route is now available on your route', at: s.ago(24), read: true, route: '/restaurants').toJson(),
          AppNotification(id: 'n5', kind: 'offers', title: 'Price drop alert', text: 'Your favorite item is now at a lower price', at: s.ago(48), read: true).toJson(),
        ]
      : [AppNotification(id: 'welcome', kind: 'updates', title: 'Welcome to FoodOnTheGo', text: 'Plan a journey to find restaurants on your route.', at: s.now().toIso8601String(), read: false, route: '/plan-journey').toJson()];
  Future<List<AppNotification>> _read(String u) => s.readList('notifications', u, AppNotification.fromJson, () => _seed(u));
  @override
  Future<List<AppNotification>> list(String userId) async { await s.simulate('notifications'); return _read(userId); }
  @override
  Future<List<AppNotification>> markRead(String userId, String id) async {
    await s.simulate('notifications');
    return s.writeList('notifications', userId, (await _read(userId)).map((n) => n.id == id ? n.copyWith(read: true) : n).toList(), (n) => n.toJson());
  }
  @override
  Future<List<AppNotification>> markAllRead(String userId) async {
    await s.simulate('notifications');
    return s.writeList('notifications', userId, (await _read(userId)).map((n) => n.copyWith(read: true)).toList(), (n) => n.toJson());
  }
  @override
  Future<NotificationPreferences> getPreferences(String userId) async {
    await s.simulate('notifications');
    final raw = await s.store.read(s.key('notification-prefs', userId));
    return raw == null ? const NotificationPreferences() : NotificationPreferences.fromJson(jsonDecode(raw) as Map<String, dynamic>);
  }
  @override
  Future<NotificationPreferences> updatePreferences(String userId, NotificationPreferences prefs) async {
    await s.simulate('notifications');
    await s.store.write(s.key('notification-prefs', userId), jsonEncode(prefs.toJson()));
    return prefs;
  }
}

/// Bundle of the five repositories the app wires: the development data, or the backend (api_account.dart).
class AccountRepositories {
  const AccountRepositories({required this.profile, required this.favorites, required this.addresses, required this.payments, required this.notifications});
  final ProfileRepository profile;
  final FavoriteRepository favorites;
  final AddressRepository addresses;
  final PaymentMethodRepository payments;
  final NotificationRepository notifications;

  factory AccountRepositories.mock(MockAccountStore s) => AccountRepositories(profile: MockProfileRepository(s), favorites: MockFavoriteRepository(s), addresses: MockAddressRepository(s), payments: MockPaymentMethodRepository(s), notifications: MockNotificationRepository(s));
}
