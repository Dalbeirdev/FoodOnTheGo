// Module 33 — push devices, the push payload allow-list and the in-app inbox on the API (no Firebase, no network: a fake
// token source and a mocked HTTP client).
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/account/api_account.dart';
import 'package:foodonthego/auth/auth_repository.dart' show KeyValueStore;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/notifications/device_registrar.dart';
import 'package:foodonthego/notifications/push_notice.dart';
import 'package:foodonthego/notifications/push_token_source.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class MemStore implements KeyValueStore {
  final map = <String, String>{};
  @override
  Future<String?> read(String key) async => map[key];
  @override
  Future<void> write(String key, String? value) async { if (value == null) { map.remove(key); } else { map[key] = value; } }
}

http.Response json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json'});

void main() {
  group('push payload (PushNotice)', () {
    test('accepts only the allow-listed targets and a well-formed order reference; routes never come from the payload', () {
      final ready = PushNotice.fromData({'type': 'order.ready', 'notification_id': 'n-1', 'target': 'ORDER_TRACKING', 'reference': 'FOTG-20261009-ABC123', 'category': 'PICKUP_UPDATES'}, title: 'Ready');
      expect(ready!.route, '/order-tracking/FOTG-20261009-ABC123');
      expect(PushNotice.fromData({'target': 'ORDER_DETAIL', 'reference': 'FOTG-20261009-ABC123'})!.route, '/order/FOTG-20261009-ABC123');
      expect(PushNotice.fromData({'target': 'PAYMENT_STATUS', 'reference': 'FOTG-20261009-ABC123'})!.route, '/order/FOTG-20261009-ABC123');
      expect(PushNotice.fromData({'target': 'ACCOUNT_SECURITY'})!.route, '/my-profile');
      expect(PushNotice.fromData({'target': 'NONE'})!.route, isNull);
      expect(PushNotice.fromData({'target': 'https://evil.example/steal'}), isNull, reason: 'an unknown target is dropped');
      expect(PushNotice.fromData({'target': 'ORDER_TRACKING', 'reference': '../../admin'}), isNull, reason: 'a malformed reference is dropped');
      expect(PushNotice.fromData({'target': 'ORDER_TRACKING'}), isNull, reason: 'an order target needs an order number');
      expect(PushNotice.routeFor('ORDER_TRACKING', 'nope'), '/my-orders');
      // Module 37: a support notice names its case by the case number.
      expect(PushNotice.fromData({'target': 'SUPPORT_CASE', 'reference': 'CS-ABCD2345'})!.route, '/support/cases/CS-ABCD2345');
      expect(PushNotice.fromData({'target': 'SUPPORT_CASE', 'reference': 'FOTG-20261009-ABC123'}), isNull, reason: 'an order number is not a case number');
    });
  });

  group('fake token source', () {
    test('is deterministic per installation, carries the simulated outcome in the token and reports a permission', () async {
      final a = FakePushTokenSource('inst-0000000000000001');
      expect(await a.token(), 'fake-token-inst-0000000000000001');
      expect(await FakePushTokenSource('inst-0000000000000001', marker: 'unregistered').token(), 'fake-token-unregistered-inst-0000000000000001');
      expect(a.provider, 'FAKE');
      expect(await a.permissionState(), 'GRANTED');
      final seen = <String>[];
      a.onRefresh.listen(seen.add);
      a.rotate('fake-token-rotated');
      await Future<void>.delayed(Duration.zero);
      expect(seen, ['fake-token-rotated']);
      await a.dispose();
    });
  });

  group('device registrar', () {
    late MemStore store;
    late List<http.Request> calls;
    late http.Response Function(http.Request) answer;
    late ApiClient api;

    setUp(() {
      store = MemStore();
      store.map['fotg.auth.token.customer'] = 'session-token';
      calls = [];
      answer = (r) => json(201, {'device': {'id': 'dev-1', 'platform': 'ANDROID', 'push_provider': 'FAKE', 'active': true, 'notifications_permission_state': 'GRANTED'}});
      api = ApiClient(client: MockClient((r) async { calls.add(r); return answer(r); }), baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read('fotg.auth.token.customer'));
    });

    test('generates one random installation id, binds the installation with PUT /customer/devices/current and never sends a customer id', () async {
      final source = FakePushTokenSource('placeholder');
      final registrar = DeviceRegistrar(api: api, store: store, source: source, appVersion: '0.1.0+37', locale: 'en-IN', timezone: 'Asia/Kolkata');
      final id = await registrar.installationId();
      expect(id.length, 32);
      expect(RegExp(r'^[0-9a-f]{32}$').hasMatch(id), isTrue);
      expect(await registrar.installationId(), id, reason: 'created once, kept in the store');
      expect(store.map[DeviceRegistrar.installationKey], id);

      final reg = await registrar.bind();
      expect(reg!.id, 'dev-1');
      expect(reg.active, isTrue);
      expect(calls.single.method, 'PUT');
      expect(calls.single.url.path, '/api/v1/customer/devices/current');
      expect(calls.single.headers['Authorization'], 'Bearer session-token');
      final body = jsonDecode(calls.single.body) as Map<String, dynamic>;
      expect(body['installation_id'], id);
      expect(body['platform'], 'ANDROID');
      expect(body['push_provider'], 'FAKE');
      expect(body['push_token'], 'fake-token-placeholder');
      expect(body['notifications_permission_state'], 'GRANTED');
      expect(body['app_version'], '0.1.0+37');
      expect(body.containsKey('customer_id'), isFalse);
    });

    test('a token refresh re-binds while signed in and not otherwise; a failure is kept, never thrown', () async {
      final source = FakePushTokenSource('p');
      final registrar = DeviceRegistrar(api: api, store: store, source: source);
      var signedIn = false;
      registrar.listen(() => signedIn);
      source.rotate('fake-token-new');
      await Future<void>.delayed(Duration.zero);
      expect(calls, isEmpty, reason: 'signed out: nothing is bound');
      signedIn = true;
      source.rotate('fake-token-new-2');
      await Future<void>.delayed(const Duration(milliseconds: 10));
      expect(calls.length, 1);

      answer = (_) => json(422, {'error': {'code': 'validation_failed', 'message': 'invalid'}});
      expect(await registrar.bind(), isNull);
      expect(registrar.lastError, 'validation_failed');
      await registrar.dispose();
    });

    test('unbind names the installation with DELETE /customer/devices/current', () async {
      final registrar = DeviceRegistrar(api: api, store: store, source: FakePushTokenSource('p'));
      answer = (_) => http.Response('', 204);
      expect(await registrar.unbind(), isTrue);
      expect(calls.single.method, 'DELETE');
      expect(jsonDecode(calls.single.body)['installation_id'], await registrar.installationId());
    });
  });

  group('inbox on the API', () {
    test('maps the backend notification to the app model with an allow-listed route and the kind of its category', () {
      final n = notificationFromApi({
        'id': 'n-1', 'category': 'PICKUP_UPDATES', 'type': 'order.ready', 'priority': 'HIGH', 'transactional': true, 'title': 'Ready for pickup', 'body': 'Order FOTG-20261009-ABC123 is ready at Burger Hub.',
        'action': {'target': 'ORDER_TRACKING', 'reference': 'FOTG-20261009-ABC123'}, 'subject': {'type': 'ORDER', 'id': 'o-1', 'reference': 'FOTG-20261009-ABC123'}, 'read': false, 'read_at': null, 'created_at': '2026-10-09T10:00:00Z',
      });
      expect([n.kind, n.route, n.read, n.type, n.priority], ['orders', '/order-tracking/FOTG-20261009-ABC123', false, 'order.ready', 'HIGH']);
      final s = notificationFromApi({'id': 'n-2', 'category': 'ACCOUNT_SECURITY', 'type': 'account.phone_changed', 'title': 'Phone changed', 'body': 'x', 'action': {'target': 'ACCOUNT_SECURITY', 'reference': null}, 'subject': {}, 'read': true, 'created_at': '2026-10-09T10:00:00Z'});
      expect([s.kind, s.route, s.read], ['updates', '/my-profile', true]);
      expect(notificationRoute('ORDER_DETAIL', 'not-an-order'), '/my-orders');
      expect(notificationRoute('WEIRD', null), isNull);
      expect(notificationRoute('SUPPORT_CASE', 'CS-ABCD2345'), '/support/cases/CS-ABCD2345');
      expect(notificationRoute('SUPPORT_CASE', 'not-a-case'), '/support');
    });

    test('the preference matrix carries the channel status of the server', () {
      final p = preferencesFromApi({
        'categories': [{'category': 'ORDER_UPDATES', 'name': 'Orders', 'description': '', 'transactional': true, 'channels': [{'channel': 'PUSH', 'enabled': true, 'locked': false, 'chosen': false}]}],
        'channels': ['PUSH', 'SMS', 'EMAIL', 'IN_APP'], 'marketing_consent': {'granted_at': null, 'withdrawn_at': null},
        'channel_status': {'IN_APP': 'AVAILABLE', 'PUSH': 'DEVELOPMENT', 'EMAIL': 'PENDING', 'SMS': 'UNAVAILABLE'},
      });
      expect(p.matrix!.channelStatus, {'IN_APP': 'AVAILABLE', 'PUSH': 'DEVELOPMENT', 'EMAIL': 'PENDING', 'SMS': 'UNAVAILABLE'});
    });
  });
}
