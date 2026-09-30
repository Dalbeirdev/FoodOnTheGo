import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/state/auth_state.dart' show AuthState, AuthStatus;
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _challenge({String delivery = 'development'}) => {
      'challenge_id': '11111111-1111-4111-8111-111111111111', 'phone_masked': '+91 ******3210', 'expires_at': '2026-09-30T10:05:00+00:00',
      'resend_available_at': '2026-09-30T10:00:30+00:00', 'attempts_allowed': 5, 'server_time': '2026-09-30T10:00:00+00:00', 'delivery': delivery,
    };
Map<String, dynamic> _principal({String? name = 'Rahul Sharma', bool complete = true}) => {
      'principal_type': 'CUSTOMER', 'id': 'c0ffee00-0000-4000-8000-000000000001', 'status': 'ACTIVE', 'name': name, 'email': null, 'phone': '+919876543210',
      'phone_masked': '+91 ******3210', 'phone_verified': true, 'profile_complete': complete, 'preferred_locale': null, 'market': 'IN', 'member_since': '2026-01-12',
    };
Map<String, dynamic> _session({String? name = 'Rahul Sharma', bool complete = true}) => {'token': '12|customer-token', 'token_type': 'Bearer', 'expires_at': null, 'new_account': !complete, 'principal': _principal(name: name, complete: complete)};
Map<String, dynamic> _error(String code, {Map<String, dynamic>? details}) => {'error': {'code': code, 'message': 'x', 'details': ?details}};

/// Repository wired to a scripted backend. Every request is recorded.
({ApiAuthRepository repo, MemoryKeyValueStore store, List<http.Request> requests, List<http.Response> Function() queue}) _setup(List<http.Response> responses, {DateTime? now, String devOtp = '123456'}) {
  final store = MemoryKeyValueStore();
  final requests = <http.Request>[];
  final queue = [...responses];
  final client = MockClient((r) async {
    requests.add(r);
    return queue.removeAt(0);
  });
  late final ApiAuthRepository repo;
  repo = ApiAuthRepository(
    store: store,
    now: () => now ?? DateTime.parse('2026-09-30T10:00:00Z'),
    devOtp: devOtp,
    client: ApiClient(baseUrl: 'http://api.test/api/v1', client: client, tokenProvider: () => store.read(ApiAuthRepository.tokenKey), onUnauthenticated: () {
      store.write(ApiAuthRepository.tokenKey, null);
      repo.onSessionEnded?.call();
    }),
  );
  return (repo: repo, store: store, requests: requests, queue: () => queue);
}

Future<AuthException> _failure(Future<Object?> call) async {
  try {
    await call;
  } on AuthException catch (e) {
    return e;
  }
  fail('expected a failure');
}

void main() {
  group('ApiAuthRepository — customer phone + OTP against the backend (Module 21)', () {
    test('requests a code for the active market, translates server time, shows the local test code only for development delivery', () async {
      final s = _setup([_json(200, _challenge())], now: DateTime.parse('2026-09-30T11:00:00Z')); // device clock one hour ahead
      final otp = await s.repo.requestOtp('+919876543210');
      expect(s.requests.single.url.path, '/api/v1/auth/customer/otp/request');
      expect(jsonDecode(s.requests.single.body), {'phone': '+919876543210', 'country': 'IN'});
      expect(s.requests.single.headers.containsKey('Authorization'), isFalse);
      expect(otp.expiresAt.difference(DateTime.parse('2026-09-30T11:00:00Z')), const Duration(minutes: 5));
      expect(otp.resendAfter.difference(DateTime.parse('2026-09-30T11:00:00Z')), const Duration(seconds: 30));
      expect([otp.attemptsAllowed, otp.devOtp], [5, '123456']);

      final live = _setup([_json(200, {..._challenge(delivery: 'live'), 'channel': 'whatsapp', 'resend_channel': 'sms'})]);
      final sent = await live.repo.requestOtp('+919876543210');
      expect([sent.devOtp, sent.channel, sent.resendChannel], [null, 'whatsapp', 'sms']);
      expect([otp.channel, otp.resendChannel], [null, null]); // development: nothing was sent, so no channel is shown
      final production = _setup([_json(200, _challenge())], devOtp: '');
      expect((await production.repo.requestOtp('+919876543210')).devOtp, isNull);
    });

    test('existing customer: verifies with the challenge and stores the token in the secure store', () async {
      final s = _setup([_json(200, _challenge()), _json(200, _session())]);
      await s.repo.requestOtp('+919876543210');
      final result = await s.repo.verifyOtp('+919876543210', '123456');
      expect(jsonDecode(s.requests[1].body), {'phone': '+919876543210', 'country': 'IN', 'challenge_id': '11111111-1111-4111-8111-111111111111', 'code': '123456', 'device_name': 'android'});
      expect((result as Authenticated).user.name, 'Rahul Sharma');
      expect(await s.store.read(ApiAuthRepository.tokenKey), '12|customer-token');
    });

    test('new customer: not a session until the profile step, then the same token becomes the session', () async {
      final s = _setup([_json(200, _challenge()), _json(201, _session(name: null, complete: false)), _json(200, _principal(name: 'Asha Verma'))]);
      await s.repo.requestOtp('+919876543210');
      expect(await s.repo.verifyOtp('+919876543210', '123456'), isA<SetupRequired>());
      expect(await s.store.read(ApiAuthRepository.tokenKey), isNull);
      expect(await s.repo.getCurrentUser(), isNull);

      final user = await s.repo.completeSetup('pending-profile', name: ' Asha Verma ', acceptTerms: true);
      expect(s.requests[2].method, 'PATCH');
      expect(jsonDecode(s.requests[2].body), {'name': 'Asha Verma', 'accept_terms': true});
      expect(s.requests[2].headers['Authorization'], 'Bearer 12|customer-token');
      expect(user.name, 'Asha Verma');
      expect(await s.store.read(ApiAuthRepository.tokenKey), '12|customer-token');
    });

    test('maps backend errors onto the messages the screens already show', () async {
      final cases = <(int, Map<String, dynamic>, AuthErrorCode, int?)>[
        (422, _error('otp_invalid', details: {'attempts_left': 2}), AuthErrorCode.invalidOtp, 2),
        (422, _error('otp_invalid'), AuthErrorCode.expiredOtp, null),
        (422, _error('otp_expired'), AuthErrorCode.expiredOtp, null),
        (429, _error('otp_attempts_exceeded', details: {'attempts_left': 0}), AuthErrorCode.tooManyAttempts, 0),
        (403, _error('account_not_active'), AuthErrorCode.accountBlocked, null),
        (500, _error('internal_error'), AuthErrorCode.unexpected, null),
      ];
      for (final (status, body, code, left) in cases) {
        final s = _setup([_json(200, _challenge()), _json(status, body)]);
        await s.repo.requestOtp('+919876543210');
        final e = await _failure(s.repo.verifyOtp('+919876543210', '000000'));
        expect([e.code, e.attemptsLeft], [code, left]);
        expect(await s.store.read(ApiAuthRepository.tokenKey), isNull);
      }
      final noChallenge = _setup([]);
      expect((await _failure(noChallenge.repo.verifyOtp('+919876543210', '123456'))).code, AuthErrorCode.expiredOtp);
    });

    test('429 tells the customer how long to wait; delivery, market, validation and network failures stay distinct', () async {
      var s = _setup([_json(429, _error('otp_resend_too_soon', details: {'retry_after_seconds': 24}))]);
      var e = await _failure(s.repo.requestOtp('+919876543210'));
      expect([e.code, e.retryAfterSeconds], [AuthErrorCode.rateLimited, 24]);
      expect(e.message, contains('24 seconds'));

      s = _setup([_json(429, _error('rate_limited', details: {'retry_after_seconds': 120}))]);
      expect((await _failure(s.repo.requestOtp('+919876543210'))).message, contains('2 minutes'));
      s = _setup([_json(503, _error('otp_delivery_failed'))]);
      expect((await _failure(s.repo.requestOtp('+919876543210'))).code, AuthErrorCode.sendFailed);
      s = _setup([_json(404, _error('market_unavailable'))]);
      expect((await _failure(s.repo.requestOtp('+15550001111'))).code, AuthErrorCode.invalidPhone);
      s = _setup([_json(422, _error('validation_failed', details: {'fields': {'phone': ['Enter a valid mobile number for India.']}}))]);
      e = await _failure(s.repo.requestOtp('+9112345'));
      expect([e.code, e.message], [AuthErrorCode.invalidPhone, 'Enter a valid mobile number for India.']);

      final offline = ApiAuthRepository(store: MemoryKeyValueStore(), client: ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => throw http.ClientException('offline'))));
      expect((await _failure(offline.requestOtp('+919876543210'))).code, AuthErrorCode.network);
    });

    test('session restore validates the stored token; 401 ends the session once; 403 ends it; network keeps the token', () async {
      final none = _setup([]);
      expect(await none.repo.refreshSession(), isNull);
      expect(none.requests, isEmpty);

      final ok = _setup([_json(200, _principal())]);
      await ok.store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      expect((await ok.repo.refreshSession())?.name, 'Rahul Sharma');
      expect(ok.requests.single.headers['Authorization'], 'Bearer 12|customer-token');

      final expired = _setup([_json(401, _error('unauthenticated'))]);
      var ended = 0;
      expired.repo.onSessionEnded = () => ended++;
      await expired.store.write(ApiAuthRepository.tokenKey, '12|old');
      expect((await _failure(expired.repo.refreshSession())).code, AuthErrorCode.sessionExpired);
      expect([await expired.store.read(ApiAuthRepository.tokenKey), ended], [null, 1]);
      expect(await expired.repo.refreshSession(), isNull); // nothing is retried without a token

      final suspended = _setup([_json(403, _error('account_not_active'))]);
      await suspended.store.write(ApiAuthRepository.tokenKey, '13|suspended');
      expect((await _failure(suspended.repo.refreshSession())).code, AuthErrorCode.sessionExpired);
      expect(await suspended.store.read(ApiAuthRepository.tokenKey), isNull);

      final store = MemoryKeyValueStore();
      await store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      final offline = ApiAuthRepository(store: store, client: ApiClient(baseUrl: 'http://api.test', tokenProvider: () => store.read(ApiAuthRepository.tokenKey), client: MockClient((_) async => throw http.ClientException('offline'))));
      expect((await _failure(offline.refreshSession())).code, AuthErrorCode.network);
      expect(await store.read(ApiAuthRepository.tokenKey), '12|customer-token');
    });

    test('logout revokes on the backend and always clears the local token; profile update uses the session', () async {
      final s = _setup([_json(200, _principal(name: 'Rahul S.')), _json(204, null)]);
      await s.store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      expect((await s.repo.updateProfile(name: 'Rahul S.')).name, 'Rahul S.');
      await s.repo.logout();
      expect([s.requests[1].method, s.requests[1].url.path], ['POST', '/api/v1/auth/logout']);
      expect(await s.store.read(ApiAuthRepository.tokenKey), isNull);
    });

    test('AuthState: a session rejected by the backend becomes "session expired" exactly once', () async {
      final s = _setup([_json(200, _principal()), _json(401, _error('unauthenticated'))]);
      await s.store.write(ApiAuthRepository.tokenKey, '12|customer-token');
      final state = AuthState(repository: s.repo);
      await state.restore();
      expect(state.status, AuthStatus.authenticated);
      await state.restore();
      expect(state.status, AuthStatus.sessionExpired);
      expect(state.user, isNull);
      await state.restore();
      expect(state.status, AuthStatus.loggedOut);
      expect(s.requests.length, 2);
    });
  });
}
