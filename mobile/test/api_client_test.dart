import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(int status, Object body, [Map<String, String> headers = const {}]) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json', ...headers});

Future<ApiException> _failure(Future<Object?> call) async {
  try {
    await call;
  } on ApiException catch (e) {
    return e;
  }
  fail('expected the request to fail');
}

void main() {
  group('ApiClient (Module 20 backend contract)', () {
    test('uses the configured base URL, sends a request id, query and token', () async {
      late http.Request seen;
      final api = ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => '12|token', client: MockClient((r) async { seen = r; return _json(200, {'status': 'ok'}); }));
      await api.get('/markets/current', query: {'country': 'IN', 'skip': null});
      expect(seen.url.toString(), 'http://api.test/api/v1/markets/current?country=IN');
      expect(seen.headers['X-Request-Id'], matches(RegExp(r'^[A-Za-z0-9._-]{8,64}$')));
      expect(seen.headers['Authorization'], 'Bearer 12|token');
    });

    test('public calls send no token; idempotency key is forwarded', () async {
      late http.Request seen;
      final api = ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => '12|token', client: MockClient((r) async { seen = r; return _json(201, {'ok': true}); }));
      await api.post('/orders', body: {'a': 1}, auth: false, idempotencyKey: 'checkout-0001-aaaa-bbbb');
      expect(seen.headers.containsKey('Authorization'), isFalse);
      expect(seen.headers['Idempotency-Key'], 'checkout-0001-aaaa-bbbb');
    });

    test('422 exposes field errors from the error envelope', () async {
      final api = ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => _json(422, {'error': {'code': 'validation_failed', 'message': 'The submitted data is invalid.', 'details': {'fields': {'country': ['Must be an ISO code.']}}, 'request_id': 'req-12345678'}})));
      final e = await _failure(api.get('/markets/current'));
      expect([e.status, e.kind, e.code, e.field('country'), e.requestId], [422, ApiErrorKind.validation, 'validation_failed', 'Must be an ISO code.', 'req-12345678']);
    });

    test('401 notifies the session handler only for authenticated calls', () async {
      var calls = 0;
      final api = ApiClient(baseUrl: 'http://api.test', tokenProvider: () async => '12|expired', onUnauthenticated: () => calls++, client: MockClient((_) async => _json(401, {'error': {'code': 'unauthenticated', 'message': 'Authentication is required.'}})));
      expect((await _failure(api.get('/auth/me', auth: false))).kind, ApiErrorKind.unauthenticated);
      expect(calls, 0);
      await _failure(api.get('/auth/me'));
      expect(calls, 1);
    });

    test('403, 404 and 409 keep the backend code', () async {
      final bodies = {403: 'forbidden', 404: 'market_unavailable', 409: 'idempotency_key_reused'};
      for (final entry in bodies.entries) {
        final api = ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => _json(entry.key, {'error': {'code': entry.value, 'message': 'x'}})));
        final e = await _failure(api.get('/x'));
        expect([e.status, e.code], [entry.key, entry.value]);
      }
    });

    test('429 exposes the retry delay', () async {
      final api = ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => _json(429, {'error': {'code': 'rate_limited', 'message': 'Too many requests.', 'details': {'retry_after_seconds': 42}}}, {'retry-after': '42'})));
      final e = await _failure(api.post('/auth/login', body: {}));
      expect([e.kind, e.retryAfterSeconds], [ApiErrorKind.rateLimited, 42]);
    });

    test('5xx never shows server text; non-JSON gateway errors and network failures are handled', () async {
      var api = ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => _json(500, {'error': {'code': 'internal_error', 'message': 'SQLSTATE[42P01] relation missing'}})));
      var e = await _failure(api.get('/config'));
      expect(e.kind, ApiErrorKind.server);
      expect(e.message, isNot(contains('SQLSTATE')));

      api = ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => http.Response('<html>Bad gateway</html>', 502)));
      expect((await _failure(api.get('/config'))).kind, ApiErrorKind.server);

      api = ApiClient(baseUrl: 'http://api.test', client: MockClient((_) async => throw http.ClientException('offline')));
      e = await _failure(api.get('/health'));
      expect([e.status, e.kind], [0, ApiErrorKind.network]);
      expect(e.requestId, isNotNull);
    });
  });
}
