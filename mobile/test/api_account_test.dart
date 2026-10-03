/// Customer account on the backend (Module 25): the mapping of the account API onto the app model, the bodies the
/// repositories send (never a phone, status or payment credential) and the error vocabulary the flows rely on.
/// Payloads are in the shape of the real API (OpenAPI: CustomerProfile, FavoriteRestaurantPage, SavedLocation,
/// PaymentMethodSummary, NotificationPreferences, ReauthChallenge).
library;

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/account/account_repositories.dart';
import 'package:foodonthego/account/api_account.dart';
import 'package:foodonthego/auth/auth_repository.dart' show KeyValueStore;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart' show globalRestaurants;
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> profileDto([Map<String, dynamic> patch = const {}]) => {
      'id': 'd79adb86-3bad-401d-8fc9-171506ee0342', 'name': 'Rahul Sharma', 'display_name': 'Rahul Sharma', 'phone': '+919876543210', 'phone_masked': '+91 ******3210', 'phone_verified': true, 'phone_editable': false,
      'email': 'rahul.sharma@example.com', 'email_verified': false, 'preferred_locale': 'en-IN', 'locale_options': ['en-IN'], 'market': 'IN', 'date_of_birth': '1990-03-15', 'gender': 'MALE',
      'favorite_cuisines': [{'code': 'north_indian', 'name': 'North Indian'}, {'code': 'burgers', 'name': 'Burgers'}], 'vegetarian_only': false, 'search_radius_km': 20, 'avatar': null, 'status': 'ACTIVE',
      'deletion_requested_at': null, 'member_since': '2026-09-30', 'version': 3, 'updated_at': '2026-10-03T08:23:34+00:00',
      ...patch,
    };

Map<String, dynamic> restaurantDto() => {
      'id': '7822b22c-05f3-49cd-ae89-f58e6ee277d9', 'slug': 'burger-hub', 'name': 'Burger Hub', 'branch_label': 'Sector 62 · Noida', 'short_description': 'Burgers.', 'description': 'Burgers and shakes.',
      'cuisines': [{'code': 'burgers', 'name': 'Burgers'}], 'features': [], 'price_level': 2, 'phone': null, 'email': null, 'website': null,
      'address': {'formatted': 'Sector 62, Noida', 'line1': 'Sector 62', 'postal_code': '201309', 'city': 'Noida', 'city_slug': 'noida', 'region': 'Uttar Pradesh', 'region_code': 'IN-UP', 'country_code': 'IN'},
      'location': {'latitude': 28.6285, 'longitude': 77.3652}, 'timezone': 'Asia/Kolkata', 'currency': 'INR', 'images': {'logo': null, 'cover': null, 'gallery': []},
      'hours': {'timezone': 'Asia/Kolkata', 'weekly': [for (var d = 0; d < 7; d++) {'day_of_week': d, 'periods': [{'opens_at': '00:00', 'closes_at': '00:00'}]}], 'special': []},
      'pickup': {'methods': [{'method': 'COUNTER', 'instructions': null, 'requires_vehicle_info': false}], 'asap': true, 'scheduled': false, 'default_prep_minutes': 12, 'minimum_lead_minutes': null, 'instructions': null},
      'availability': {'open_now': true, 'open_state': 'OPEN', 'accepting_orders': true, 'orderable': true, 'reason': null, 'closes_at': null, 'opens_next_at': null, 'checked_at': '2026-10-03T08:00:00+00:00'},
    };

Map<String, dynamic> favoriteDto([Map<String, dynamic> patch = const {}]) => {'restaurant_id': '7822b22c-05f3-49cd-ae89-f58e6ee277d9', 'slug': 'burger-hub', 'name': 'Burger Hub', 'added_at': '2026-10-03T08:23:34+00:00', 'available': true, 'restaurant': restaurantDto(), ...patch};

Map<String, dynamic> savedDto([Map<String, dynamic> patch = const {}]) => {
      'id': 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', 'kind': 'HOME', 'label': 'Home',
      'address': {'line1': 'A-203, Green Valley Apartments', 'line2': null, 'locality': 'Sector 62', 'city': 'Noida', 'region': 'Uttar Pradesh', 'postal_code': '201309', 'country_code': 'IN', 'formatted': 'A-203, Green Valley Apartments, Sector 62, Noida, Uttar Pradesh 201309, India'},
      'location': {'latitude': 28.6271, 'longitude': 77.3717}, 'place': null, 'timezone': 'Asia/Kolkata', 'is_default': true,
      'coverage': {'status': 'supported', 'reason': null, 'market': 'IN', 'city': 'Noida', 'service_area': 'Noida · Sector 62 & NH24'}, 'version': 1, 'created_at': '2026-10-03T08:23:34+00:00', 'updated_at': null,
      ...patch,
    };

Map<String, dynamic> paymentDto([Map<String, dynamic> patch = const {}]) => {'id': '31ea2287-344f-40e1-8192-2b402b061558', 'type': 'CARD', 'provider': 'development', 'brand': 'Visa', 'display_label': 'Visa •••• 4242', 'last4': '4242', 'expiry': {'month': 12, 'year': 2028}, 'upi_handle_masked': null, 'is_default': true, 'status': 'ACTIVE', 'created_at': '2026-10-03T08:23:34+00:00', ...patch};

Map<String, dynamic> cell(String channel, bool enabled, {bool locked = false}) => {'channel': channel, 'enabled': enabled, 'locked': locked, 'chosen': false};
Map<String, dynamic> prefsDto() => {
      'categories': [
        {'category': 'ORDER_UPDATES', 'name': 'Order updates', 'description': 'Confirmed, being prepared, ready for pickup', 'transactional': true, 'channels': [cell('PUSH', true), cell('SMS', true), cell('EMAIL', false), cell('IN_APP', true)]},
        {'category': 'PAYMENT_UPDATES', 'name': 'Payment updates', 'description': 'Payments and refunds', 'transactional': true, 'channels': [cell('PUSH', true), cell('SMS', true), cell('EMAIL', true), cell('IN_APP', true)]},
        {'category': 'ACCOUNT_SECURITY', 'name': 'Account security', 'description': 'Sign-ins and changes', 'transactional': true, 'channels': [cell('PUSH', true), cell('SMS', true, locked: true), cell('EMAIL', true), cell('IN_APP', true, locked: true)]},
        {'category': 'PROMOTIONS', 'name': 'Offers', 'description': 'Deals', 'transactional': false, 'channels': [cell('PUSH', false), cell('SMS', false), cell('EMAIL', false), cell('IN_APP', false)]},
      ],
      'channels': ['PUSH', 'SMS', 'EMAIL', 'IN_APP'],
      'marketing_consent': {'granted_at': null, 'withdrawn_at': null},
    };

Map<String, dynamic> challengeDto({String purpose = 'reauth', String? changeId}) => {
      'challenge_id': '9bd56882-e30d-45f4-92d7-389dbdd67979', 'phone_masked': '+91 ******3210', 'expires_at': DateTime.now().toUtc().add(const Duration(minutes: 5)).toIso8601String(),
      'resend_available_at': DateTime.now().toUtc().add(const Duration(seconds: 5)).toIso8601String(), 'attempts_allowed': 5, 'server_time': DateTime.now().toUtc().toIso8601String(), 'delivery': 'live',
      'channel': 'sms', 'resend_channel': 'sms', 'channels': ['sms'], 'purpose': purpose, 'change_id': ?changeId,
    };

Map<String, dynamic> error(String code, String message, [Map<String, List<String>>? fields]) => {'error': {'code': code, 'message': message, 'details': fields == null ? <String, dynamic>{} : {'fields': fields}, 'request_id': 'r1'}};

class Seen {
  final List<http.Request> requests = [];
  http.Request? by(String method, String path) => requests.where((r) => r.method == method && r.url.path.endsWith(path)).firstOrNull;
  Map<String, dynamic> body(String method, String path) => jsonDecode(by(method, path)!.body) as Map<String, dynamic>;
}

ApiClient client(Seen seen, http.Response Function(http.Request) handler) => ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => 't', client: MockClient((r) async { seen.requests.add(r); return handler(r); }));

Future<RepositoryException> refused(Future<Object?> call) async {
  try {
    await call;
  } on RepositoryException catch (e) {
    return e;
  }
  fail('expected the call to be refused');
}

void main() {
  group('profile — the account API onto the app model', () {
    test('keeps what the backend said: identity read-only, locale options, cuisine codes with names, status', () {
      final p = profileFromApi(profileDto(), const [Option('north_indian', 'North Indian'), Option('burgers', 'Burgers'), Option('healthy', 'Healthy')]);
      expect(p.name, 'Rahul Sharma');
      expect(p.phone, '+919876543210');
      expect(p.phoneVerified, isTrue);
      expect(p.emailVerified, isFalse);
      expect(p.gender, 'male');
      expect(p.dob, '1990-03-15');
      expect(p.language, 'en-IN');
      expect(p.localeOptions.single.name, 'English (India)');
      expect(p.cuisines, ['north_indian', 'burgers']);
      expect(p.cuisineOptions.map((o) => o.code), ['north_indian', 'burgers', 'healthy']);
      expect(p.status, 'active');
      expect(p.version, 3);
      final q = profileFromApi(profileDto({'name': null, 'gender': null, 'date_of_birth': null, 'status': 'RESTRICTED', 'avatar': {'url': 'http://x/api/v1/media/avatars/a/b.jpg', 'updated_at': null}}));
      expect(q.name, '');
      expect(q.shownName, 'Rahul Sharma');
      expect([q.gender, q.dob, q.status], ['', '', 'restricted']);
      expect(q.avatarPath, startsWith('http'));
    });

    test('sends only what changed, with the version — never the phone, status or market', () {
      final before = profileFromApi(profileDto());
      final body = profileDiff(before, before.copyWith(name: ' Rahul S ', email: '', gender: 'female', searchRadiusKm: 35));
      expect(body, {'version': 3, 'name': 'Rahul S', 'email': null, 'gender': 'FEMALE', 'search_radius_km': 35});
      expect(profileDiff(before, before.copyWith(cuisines: ['burgers'], vegetarian: true, dob: '', language: 'en-IN')), {'version': 3, 'favorite_cuisines': ['burgers'], 'vegetarian_only': true, 'date_of_birth': null});
    });

    test('reads, edits with the last version, uploads the photo as multipart and removes it', () async {
      final seen = Seen();
      final repo = ApiProfileRepository(country: 'IN', client: client(seen, (r) {
        if (r.url.path.endsWith('/cuisines')) return _json(200, {'data': []});
        if (r.method == 'PATCH') return _json(200, profileDto({'name': 'Rahul S', 'version': 4}));
        if (r.url.path.endsWith('/avatar') && r.method == 'POST') return _json(200, profileDto({'avatar': {'url': 'http://x/api/v1/media/avatars/a/b.jpg', 'updated_at': null}}));
        return _json(200, profileDto());
      }));
      expect((await repo.get('u', name: 'x', phone: 'y', memberSince: 'z')).name, 'Rahul Sharma');
      expect((await repo.update('u', (c) => c.copyWith(name: 'Rahul S'))).version, 4);
      expect(seen.body('PATCH', '/customer/profile'), {'version': 3, 'name': 'Rahul S'});
      expect((await repo.setAvatar('u', const AvatarUpload(bytes: [1, 2, 3], filename: 'me.jpg'))).avatarPath, contains('/media/avatars/'));
      final upload = seen.by('POST', '/customer/profile/avatar')!;
      expect(upload.headers['content-type'], startsWith('multipart/form-data'));
      expect(upload.headers['Authorization'], 'Bearer t');
      await repo.setAvatar('u', null);
      expect(seen.by('DELETE', '/customer/profile/avatar'), isNotNull);
      expect(repo.updatesIdentity, isTrue);
      expect(repo.security, isNotNull);
    });

    test('a refused field becomes a field message; a sensitive action without a recent sign-in keeps its code', () async {
      final seen = Seen();
      final repo = ApiProfileRepository(country: 'IN', client: client(seen, (r) {
        if (r.url.path.endsWith('/cuisines')) return _json(200, {'data': []});
        if (r.url.path.endsWith('/customer/profile') && r.method == 'GET') return _json(200, profileDto());
        if (r.url.path.endsWith('/customer/profile')) return _json(422, error('validation_failed', 'The submitted data is invalid.', {'email': ['Enter a valid email address.']}));
        return _json(403, error('reauthentication_required', 'Please confirm it is you first: we will send a code to your phone.'));
      }));
      final e = await refused(repo.update('u', (c) => c.copyWith(email: 'nope')));
      expect(e.fields['email'], 'Enter a valid email address.');
      expect(e.message, 'Enter a valid email address.');
      final d = await refused(repo.requestDeletion('u', reason: 'Moving'));
      expect(d.code, 'reauthentication_required');
      expect(seen.body('POST', '/customer/account/deletion-request'), {'confirm': true, 'reason': 'Moving'});
    });

    test('re-authentication and phone change carry the challenge; the code itself is never in a response', () async {
      final seen = Seen();
      final repo = ApiProfileRepository(country: 'IN', client: client(seen, (r) {
        if (r.url.path.endsWith('/cuisines')) return _json(200, {'data': []});
        if (r.url.path.endsWith('/account/reauth')) return _json(200, challengeDto());
        if (r.url.path.endsWith('/account/reauth/verify')) return _json(200, {'reauthenticated': true, 'valid_until': 'x'});
        if (r.url.path.endsWith('/phone-change/request')) return _json(200, challengeDto(purpose: 'phone_change', changeId: 'c1'));
        return _json(200, profileDto({'phone': '+919876501234'}));
      }));
      final re = await repo.requestReauth();
      expect(re.challengeId, '9bd56882-e30d-45f4-92d7-389dbdd67979');
      expect(re.devOtp, isNull);
      expect(re.expiresAt.isAfter(DateTime.now()), isTrue);
      await repo.verifyReauth(re.challengeId, '123456');
      expect(seen.body('POST', '/customer/account/reauth/verify'), {'challenge_id': re.challengeId, 'code': '123456'});
      final ch = await repo.requestPhoneChange('98765 01234');
      expect(ch.changeId, 'c1');
      expect(seen.body('POST', '/customer/phone-change/request'), {'phone': '98765 01234', 'country': 'IN'});
      expect((await repo.verifyPhoneChange(ch.challengeId, '123456')).phone, '+919876501234');
    });
  });

  group('favorites', () {
    test('a visible favorite carries the restaurant as the screens know it; a hidden one keeps its name and is unavailable', () {
      final open = favoriteFromApi(favoriteDto());
      expect([open.restaurantId, open.slug, open.name, open.available], ['burger-hub', 'burger-hub', 'Burger Hub', true]);
      expect(open.restaurant?.name, 'Burger Hub');
      final hidden = favoriteFromApi(favoriteDto({'slug': 'vadodara-expressway-grill', 'name': 'Expressway Grill', 'available': false, 'restaurant': null}));
      // the screens keep addressing a fixture restaurant by the id the development data uses for that slug
      final fixtureId = globalRestaurants.where((r) => r.slug == 'vadodara-expressway-grill').firstOrNull?.id ?? 'vadodara-expressway-grill';
      expect([hidden.restaurantId, hidden.available, hidden.restaurant, hidden.name], [fixtureId, false, null, 'Expressway Grill']);
    });

    test('lists every page, adds and removes by slug and reloads the list; a hidden restaurant cannot be added', () async {
      final seen = Seen();
      final repo = ApiFavoriteRepository(client: client(seen, (r) {
        if (r.method == 'GET') return _json(200, {'data': [favoriteDto()], 'meta': {'current_page': 1, 'last_page': 1, 'total': 1}});
        if (r.method == 'DELETE') return http.Response('', 204);
        if (r.url.path.endsWith('/hidden-kitchen')) return _json(404, error('restaurant_not_found', 'This restaurant does not exist.'));
        return _json(201, {'data': favoriteDto(), 'added': true});
      }));
      expect((await repo.list('u')).map((f) => f.restaurantId), ['burger-hub']);
      await repo.add('u', 'burger-hub');
      expect(seen.by('POST', '/customer/favorites/burger-hub'), isNotNull);
      await repo.remove('u', 'burger-hub');
      expect(seen.by('DELETE', '/customer/favorites/burger-hub'), isNotNull);
      expect(seen.requests.where((r) => r.method == 'GET').length, 3);
      expect((await refused(repo.add('u', 'hidden-kitchen'))).code, 'restaurant_not_found');
    });
  });

  group('saved journey locations', () {
    test('maps the address, the pin and the coverage the backend decided', () {
      final home = addressFromApi(savedDto());
      expect([home.id, home.kind, home.label, home.locality, home.city, home.state, home.pincode, home.lat, home.lng, home.isDefault, home.version, home.countryCode], ['df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', AddressKind.home, 'Home', 'Sector 62', 'Noida', 'Uttar Pradesh', '201309', 28.6271, 77.3717, true, 1, 'IN']);
      expect([home.coverage?.status, home.coverage?.city, home.coverage?.serviceArea], ['supported', 'Noida', 'Noida · Sector 62 & NH24']);
      expect(home.formatted, 'A-203, Green Valley Apartments, Sector 62, Noida, Uttar Pradesh 201309, India');
      final abroad = addressFromApi(savedDto({'kind': 'OTHER', 'coverage': {'status': 'unsupported', 'reason': 'MARKET_UNSUPPORTED', 'market': null, 'city': null, 'service_area': null}}));
      expect([abroad.kind, abroad.coverage?.status, abroad.coverage?.reason], [AddressKind.other, 'unsupported', 'MARKET_UNSUPPORTED']);
      final text = addressFromApi(savedDto({'location': null, 'coverage': {'status': 'unknown', 'reason': 'NO_COORDINATES', 'market': null, 'city': null, 'service_area': null}}));
      expect([text.lat, text.lng, text.coverage?.status], [null, null, 'unknown']);
    });

    test('composes the formatted line, uppercases the kind, defaults the country to the market and sends a pin only when there is one', () {
      const a = SavedAddress(id: '', label: ' Home ', kind: AddressKind.home, line1: 'A-203', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309');
      expect(savedLocationBody(a, 'IN'), {'kind': 'HOME', 'label': 'Home', 'line1': 'A-203', 'line2': null, 'locality': 'Sector 62', 'city': 'Noida', 'region': 'Uttar Pradesh', 'postal_code': '201309', 'country_code': 'IN', 'formatted_address': 'A-203, Sector 62, Noida, Uttar Pradesh 201309'});
      const pin = SavedAddress(id: '', label: 'Pin', kind: AddressKind.other, line1: '', locality: '', city: '', state: '', pincode: '', lat: 28.55, lng: 77.35, countryCode: 'AE');
      final body = savedLocationBody(pin, 'IN');
      expect([body['lat'], body['lng'], body['country_code'], body['formatted_address']], [28.55, 77.35, 'AE', null]);
    });

    test('creates with POST, edits with PATCH + version (removing the pin when none is given), sets the default and deletes', () async {
      final seen = Seen();
      final repo = ApiAddressRepository(country: 'IN', client: client(seen, (r) {
        if (r.method == 'GET') return _json(200, [savedDto()]);
        if (r.method == 'DELETE') return http.Response('', 204);
        if (r.url.path.endsWith('/default')) return _json(200, savedDto());
        return _json(r.method == 'POST' ? 201 : 200, savedDto());
      }));
      await repo.save('u', const SavedAddress(id: '', label: 'Work', kind: AddressKind.work, line1: 'Tower B', locality: 'Sector 142', city: 'Noida', state: 'Uttar Pradesh', pincode: '201305', lat: 28.4987, lng: 77.4115));
      expect(seen.body('POST', '/customer/saved-locations'), containsPair('kind', 'WORK'));
      expect(seen.body('POST', '/customer/saved-locations'), containsPair('lat', 28.4987));
      await repo.save('u', const SavedAddress(id: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', version: 1, label: 'Home (Noida)', kind: AddressKind.home, line1: 'A-203', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309'));
      final patch = seen.body('PATCH', '/customer/saved-locations/df2d09ac-56ca-4dbe-be7f-ba5ea89df14e');
      expect(patch['version'], 1);
      expect(patch['label'], 'Home (Noida)');
      expect(patch.containsKey('location') && patch['location'] == null, isTrue);
      await repo.setDefault('u', 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e');
      expect(seen.by('POST', '/customer/saved-locations/df2d09ac-56ca-4dbe-be7f-ba5ea89df14e/default'), isNotNull);
      await repo.remove('u', 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e');
      expect(seen.by('DELETE', '/customer/saved-locations/df2d09ac-56ca-4dbe-be7f-ba5ea89df14e'), isNotNull);
    });
  });

  group('payment-method references', () {
    test('shows safe metadata only — brand, last four, expiry, masked UPI handle — never a number or CVV', () {
      final card = paymentMethodFromApi(paymentDto());
      expect([card.type, card.brand, card.last4, card.expiry, card.isDefault, card.status, card.provider, card.providerRef, card.usable, card.removable], ['card', 'Visa', '4242', '12/28', true, 'ACTIVE', 'development', null, true, true]);
      final upi = paymentMethodFromApi(paymentDto({'type': 'UPI', 'brand': null, 'display_label': 'UPI ra***@okaxis', 'last4': null, 'expiry': null, 'upi_handle_masked': 'ra***@okaxis', 'is_default': false}));
      expect([upi.type, upi.handleMasked], ['upi', 'ra***@okaxis']);
      final expired = paymentMethodFromApi(paymentDto({'brand': 'Mastercard', 'last4': '4444', 'expiry': {'month': 1, 'year': 2024}, 'status': 'EXPIRED', 'is_default': false}));
      expect([expired.status, expired.expiry, expired.usable], ['EXPIRED', '01/24', false]);
      expect(paymentMethodFromApi(paymentDto({'type': 'WALLET', 'brand': null, 'display_label': 'Paytm wallet', 'last4': null, 'expiry': null})).type, 'other');
      final json = jsonEncode([card.toJson(), upi.toJson(), expired.toJson()]);
      expect(RegExp(r'\b\d{13,19}\b').hasMatch(json), isFalse);
      expect(json.toLowerCase(), isNot(contains('cvv')));
    });

    test('lists, makes default and removes through the backend, which answers the remaining list', () async {
      final seen = Seen();
      final two = [paymentDto(), paymentDto({'id': 'b', 'type': 'UPI', 'upi_handle_masked': 'ra***@okaxis', 'display_label': 'UPI', 'is_default': false})];
      final repo = ApiPaymentMethodRepository(client: client(seen, (r) => _json(200, r.method == 'GET' ? two : [paymentDto({'id': 'b', 'type': 'UPI', 'upi_handle_masked': 'ra***@okaxis', 'display_label': 'UPI', 'is_default': true})])));
      expect((await repo.list('u')).map((m) => m.type), ['card', 'upi']);
      final after = await repo.setDefault('u', 'b');
      expect([after.single.id, after.single.isDefault], ['b', true]);
      expect(seen.by('PATCH', '/customer/payment-methods/b/default'), isNotNull);
      expect((await repo.remove('u', '31ea2287-344f-40e1-8192-2b402b061558')).length, 1);
      expect(seen.by('DELETE', '/customer/payment-methods/31ea2287-344f-40e1-8192-2b402b061558'), isNotNull);
    });
  });

  group('notification preferences', () {
    test('keeps the matrix and derives the Module 04 switches from it', () {
      final p = preferencesFromApi(prefsDto());
      expect([p.push, p.orderUpdates, p.paymentUpdates, p.promotions, p.email, p.sms], [true, true, true, false, true, true]);
      expect(p.matrix!.categories.map((c) => c.category), ['ORDER_UPDATES', 'PAYMENT_UPDATES', 'ACCOUNT_SECURITY', 'PROMOTIONS']);
      expect(p.matrix!.categories[2].cell('SMS')!.locked, isTrue);
      expect(p.matrix!.categories[3].transactional, isFalse);
    });

    test('a flat switch spreads over the matrix without touching a locked cell; only changes are sent', () {
      final before = preferencesFromApi(prefsDto());
      final sms = flatCells(before, before.copyWith(sms: false), before.matrix!);
      expect(sms.map((c) => c.category), ['ORDER_UPDATES', 'PAYMENT_UPDATES', 'PROMOTIONS']);
      expect(sms.every((c) => c.channel == 'SMS' && !c.enabled), isTrue);
      expect(flatCells(before, before.copyWith(promotions: true), before.matrix!).length, 4);
      expect(flatCells(before, before.copyWith(push: true), before.matrix!), isEmpty);
    });

    test('reads the matrix and PATCHes cells; a locked cell is refused by the backend; the inbox stays development data', () async {
      final seen = Seen();
      var refuse = false;
      final repo = ApiNotificationRepository(client: client(seen, (r) => refuse ? _json(422, error('validation_failed', 'The submitted data is invalid.', {'preferences.0.enabled': ['Security notices on this channel cannot be switched off.']})) : _json(200, prefsDto())), devStore: MockAccountStore(store: MemoryStore(), latency: Duration.zero));
      expect(repo.inboxIsDevelopmentData, isTrue);
      expect((await repo.getPreferences('u')).promotions, isFalse);
      await repo.updateCells('u', const [NotificationCellChange('PROMOTIONS', 'EMAIL', true)]);
      expect(seen.body('PATCH', '/customer/notification-preferences'), {'preferences': [{'category': 'PROMOTIONS', 'channel': 'EMAIL', 'enabled': true}]});
      refuse = true;
      expect((await refused(repo.updateCells('u', const [NotificationCellChange('ACCOUNT_SECURITY', 'SMS', false)]))).message, 'Security notices on this channel cannot be switched off.');
    });
  });

  group('which implementation runs', () {
    test('the backend bundle: real profile, favorites, places, payment references and preferences; development inbox', () {
      final repos = apiAccountRepositories(client: client(Seen(), (_) => _json(200, {})), devStore: MemoryStore(), country: 'IN');
      expect(repos.profile, isA<ApiProfileRepository>());
      expect(repos.profile.updatesIdentity, isTrue);
      expect(repos.favorites, isA<ApiFavoriteRepository>());
      expect(repos.addresses, isA<ApiAddressRepository>());
      expect(repos.payments, isA<ApiPaymentMethodRepository>());
      expect(repos.notifications.inboxIsDevelopmentData, isTrue);
      final mock = AccountRepositories.mock(MockAccountStore(store: MemoryStore()));
      expect(mock.profile.updatesIdentity, isFalse);
      expect(mock.profile.security, isNull);
    });
  });
}

/// In-memory key/value store for the development inbox in these tests.
class MemoryStore implements KeyValueStore {
  final _m = <String, String>{};
  @override
  Future<String?> read(String key) async => _m[key];
  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      _m.remove(key);
    } else {
      _m[key] = value;
    }
  }
}
