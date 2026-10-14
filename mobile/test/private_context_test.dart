import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/analytics/analytics_emitter.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/auth/private_context.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:foodonthego/journey/api_journey.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/notifications/device_registrar.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/payment_backend_state.dart';
import 'package:foodonthego/state/payment_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:foodonthego/state/private_state.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Module 39: a customer sign-out leaves no journey, checkout, pickup, payment, chosen location or review draft behind
/// on this phone — and keeps the guest cart token, the analytics / installation ids and the development switches.
/// The Android twin of customer-web/src/auth/privateContext.test.tsx.
const _private = [
  'fotg.journey.tokens', 'fotg.checkout.request', 'fotg.checkout.terms', 'fotg.payment.attempt', 'fotg.payment.current', 'fotg.pickup.selection',
  'fotg.discovery.scope', 'fotg.review.draft.ord_01HZX3', 'fotg.review.draft.ord_01HZX4',
];
const _kept = [
  'fotg.cart.token', 'fotg.cart.v1', 'fotg.analytics.session', 'fotg.analytics.buffer', 'fotg.analytics.optout', 'fotg.installation.id',
  'fotg.journey.mode', 'fotg.checkout.mode', 'fotg.payment.mode', 'fotg.review.draft.mode',
];

Future<void> _seed(KeyValueStore store) async {
  for (final k in _private) {
    await store.write(k, '{"lat":28.6,"lng":77.2,"label":"Home · Delhi"}');
  }
  for (final k in _kept) {
    await store.write(k, 'kept');
  }
}

Future<void> _expectCleared(KeyValueStore store) async {
  for (final k in _private) {
    expect(await store.read(k), isNull, reason: k);
  }
  for (final k in _kept) {
    expect(await store.read(k), 'kept', reason: k);
  }
}

http.Response _json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json'});
const _me = {
  'principal_type': 'CUSTOMER', 'id': 'c0ffee00-0000-4000-8000-000000000001', 'status': 'ACTIVE', 'name': 'Rahul Sharma', 'email': null, 'phone': '+919876543210',
  'phone_masked': '+91 ******3210', 'phone_verified': true, 'profile_complete': true, 'preferred_locale': null, 'market': 'IN', 'member_since': '2026-01-12',
};

/// The app's wiring: one store for the token and the private context, the repository's client ends the session on a 401.
({AuthState auth, MemoryKeyValueStore store, List<http.Request> requests}) _signedIn(List<http.Response> responses) {
  final store = MemoryKeyValueStore();
  final requests = <http.Request>[];
  final queue = [...responses];
  late final ApiAuthRepository repo;
  repo = ApiAuthRepository(
    store: store,
    devOtp: '',
    client: ApiClient(baseUrl: 'http://api.test/api/v1', client: MockClient((r) async { requests.add(r); return queue.removeAt(0); }), tokenProvider: () => store.read(ApiAuthRepository.tokenKey), onUnauthenticated: () {
      store.write(ApiAuthRepository.tokenKey, null);
      repo.onSessionEnded?.call();
    }),
  );
  return (auth: AuthState(repository: repo, privateStore: store), store: store, requests: requests);
}

void main() {
  group('PrivateContext — which keys are the customer\'s private context', () {
    test('the fixed keys are the ones the states really use', () {
      expect(PrivateContext.keys, containsAll(<String>[
        JourneyTokens.storageKey, CheckoutState.requestKey, CheckoutState.termsKey, PaymentBackendState.attemptKey, PaymentState.currentKey,
        PickupState.key, DiscoveryState.scopeKey,
      ]));
      // …and none of the kept ones.
      for (final k in [CartBackend.tokenKey, AnalyticsEmitter.sessionKey, AnalyticsEmitter.bufferKey, AnalyticsEmitter.optOutKey, DeviceRegistrar.installationKey]) {
        expect(PrivateContext.isPrivateKey(k), isFalse, reason: k);
      }
    });

    test('names the private keys (review drafts by prefix); a development mode switch is never one', () {
      for (final k in _private) {
        expect(PrivateContext.isPrivateKey(k), isTrue, reason: k);
      }
      for (final k in _kept) {
        expect(PrivateContext.isPrivateKey(k), isFalse, reason: k);
      }
    });

    test('clear() removes them and keeps the rest', () async {
      final store = MemoryKeyValueStore();
      await _seed(store);
      await PrivateContext(store).clear();
      await _expectCleared(store);
    });

    test('a store that cannot list its keys still loses every fixed key', () async {
      final store = _PlainStore();
      await _seed(store);
      await PrivateContext(store).clear();
      for (final k in PrivateContext.keys) {
        expect(await store.read(k), isNull, reason: k);
      }
      for (final k in _kept) {
        expect(await store.read(k), 'kept', reason: k);
      }
    });
  });

  group('AuthState — every way a session ends clears the private context', () {
    test('Sign Out: the token and the private context go; the cart token, analytics ids and mode switches stay', () async {
      final s = _signedIn([_json(200, _me), _json(204, null)]);
      await s.store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      await s.auth.restore();
      expect(s.auth.isAuthenticated, isTrue);
      await _seed(s.store);

      await s.auth.logout();

      expect(s.requests.last.url.path, '/api/v1/auth/logout');
      expect(await s.store.read(ApiAuthRepository.tokenKey), isNull);
      await _expectCleared(s.store);
    });

    test('Sign Out without a connection still clears it', () async {
      final store = MemoryKeyValueStore();
      final repo = ApiAuthRepository(store: store, devOtp: '', client: ApiClient(baseUrl: 'http://api.test/api/v1', client: MockClient((_) async => throw http.ClientException('offline')), tokenProvider: () => store.read(ApiAuthRepository.tokenKey)));
      final auth = AuthState(repository: repo, privateStore: store);
      await store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      await _seed(store);
      await auth.logout();
      expect(await store.read(ApiAuthRepository.tokenKey), isNull);
      await _expectCleared(store);
    });

    test('a 401 on a stored token (session expired / revoked) clears it', () async {
      final s = _signedIn([_json(200, _me), _json(401, {'error': {'code': 'unauthenticated', 'message': 'Unauthenticated.'}})]);
      await s.store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      await s.auth.restore();
      await _seed(s.store);

      await s.auth.refreshUser(); // any authenticated call answered 401

      expect(s.auth.status, AuthStatus.sessionExpired);
      await s.auth.privateContextCleared;
      expect(await s.store.read(ApiAuthRepository.tokenKey), isNull);
      await _expectCleared(s.store);
    });

    test('a 401 seen by another client (cart, journey, account) ends the session the same way', () async {
      final store = MemoryKeyValueStore();
      final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero), privateStore: store);
      await _seed(store);
      auth.expire(); // CartBackend / JourneyApi / AccountState call this on a 401
      await auth.privateContextCleared;
      await _expectCleared(store);
    });

    test('a session found expired at launch clears it', () async {
      var clock = DateTime(2026, 10, 14, 9);
      final repo = MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero, now: () => clock);
      await repo.requestOtp(MockAuthRepository.existingCustomer);
      await repo.verifyOtp(MockAuthRepository.existingCustomer, MockAuthRepository.devOtp);
      final store = MemoryKeyValueStore();
      final auth = AuthState(repository: repo, privateStore: store);
      await _seed(store);
      clock = clock.add(MockAuthRepository.sessionTtl + const Duration(minutes: 1));

      await auth.restore();

      expect(auth.status, AuthStatus.sessionExpired);
      await auth.privateContextCleared;
      await _expectCleared(store);
    });

    test('a signed-out restore (no session) leaves a guest\'s context alone', () async {
      final store = MemoryKeyValueStore();
      final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero), privateStore: store);
      await store.write('fotg.journey.tokens', 'guest-journey');
      await auth.restore();
      expect(auth.status, AuthStatus.loggedOut);
      expect(await store.read('fotg.journey.tokens'), 'guest-journey');
    });
  });

  group('forgetPrivateState — the in-memory copies start clean', () {
    test('checkout, payment, journey (and its guest tokens), discovery and pickup forget the customer', () async {
      final store = MemoryKeyValueStore();
      final checkout = CheckoutState(store: store);
      final payment = PaymentState(store: store);
      final journey = JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: store, latency: Duration.zero)));
      final journeyApi = JourneyApi(store: store, signedIn: () async => false);
      final discovery = DiscoveryState(store: store);
      final pickup = PickupState(store: store);
      await Future<void>.delayed(Duration.zero); // the states' own restore from storage

      const home = JourneyLocation(id: 'home', name: 'Home', sub: 'Sector 21, Gurugram', kind: LocationKind.saved, lat: 28.47, lng: 77.03, countryCode: 'IN');
      journey.setOrigin(home);
      journey.setDestination(const JourneyLocation(id: 'work', name: 'Work', sub: 'Connaught Place', kind: LocationKind.saved, lat: 28.63, lng: 77.22, countryCode: 'IN'));
      await journeyApi.tokens.set('jrn_1', 'guest-journey-token', DateTime.now().add(const Duration(hours: 2)));
      journeyApi.sessionPlaces.add(home);
      checkout.setTermsAccepted(true);
      discovery.scope = const DiscoveryScope(countryCode: 'IN', locality: 'Gurugram', lat: 28.47, lng: 77.03, label: 'Home · Gurugram', source: 'manual');
      await store.write(DiscoveryState.scopeKey, '{"countryCode":"IN","label":"Home · Gurugram","source":"manual"}');
      pickup.selection = PickupSelection(mode: PickupMode.asap, requestedAt: DateTime.utc(2026, 10, 14, 9), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: DateTime.utc(2026, 10, 14, 9, 20), cartId: 'cart-1', restaurantId: 'r-1');

      forgetPrivateState(checkout: checkout, payment: payment, journey: journey, journeyApi: journeyApi, discovery: discovery, pickup: pickup);
      await Future<void>.delayed(Duration.zero);

      expect(journey.origin, isNull);
      expect(journey.destination, isNull);
      expect(journey.journey, isNull);
      expect(journey.recent, isEmpty);
      expect(journeyApi.sessionPlaces, isEmpty);
      expect(await journeyApi.tokens.get('jrn_1'), isNull);
      expect(checkout.termsAccepted, isFalse);
      expect(checkout.request, isNull);
      expect(payment.attempt, isNull);
      expect(discovery.scope, isNull);
      expect(pickup.selection, isNull);
      expect(pickup.status, PickupStatus.notSelected);
      for (final k in PrivateContext.keys) {
        expect(await store.read(k), isNull, reason: k);
      }
    });
  });
}

/// A store without key listing (like the stores some older tests use).
class _PlainStore implements KeyValueStore {
  final _m = <String, String>{};
  @override
  Future<String?> read(String key) async => _m[key];
  @override
  Future<void> write(String key, String? value) async => value == null ? _m.remove(key) : _m[key] = value;
}
