/// Realtime on the backend (Module 32) — Android: the realtime mode (never the socket without the backend orders and
/// a key), the Pusher client's bounded backoff, the private-channel authorization that goes through the API with the
/// session's token, and the live tracker: a notice older than what the screen has is ignored, a newer one makes the
/// order be read (the notice itself is never applied), a lost socket starts the poll, a reconnection stops it and
/// reads once.
library;

import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/checkout/api_checkout.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/order/api_order.dart';
import 'package:foodonthego/order/order_tracking.dart';
import 'package:foodonthego/payment/api_payment.dart';
import 'package:foodonthego/pickup/api_pickup.dart';
import 'package:foodonthego/realtime/pusher_client.dart';
import 'package:stream_channel/stream_channel.dart';

import 'api_checkout_test.dart' show json;
import 'api_order_test.dart' show accepted, customer, number, orderDto;
import 'api_payment_test.dart' show Net;

/// A WebSocket the test plays: what the server would send goes in through [server]; what the client sent is in [sent].
class FakeSocket {
  final server = StreamController<dynamic>();
  final sent = <Map<String, dynamic>>[];
  late final StreamController<dynamic> _toServer = StreamController<dynamic>()..stream.listen((m) => sent.add(jsonDecode(m as String) as Map<String, dynamic>));
  late final StreamChannel<dynamic> channel = StreamChannel<dynamic>(server.stream, _toServer.sink);
  void say(Map<String, dynamic> frame) => server.add(jsonEncode(frame));
  void established(String id) => say({'event': 'pusher:connection_established', 'data': jsonEncode({'socket_id': id, 'activity_timeout': 30})});
  Future<void> drop() => server.close();
}

({ApiOrderRepository orders, Net net, CartBackend cart}) repo() {
  final net = Net();
  final store = MemoryKeyValueStore();
  store.write(ApiAuthRepository.tokenKey, 'customer-session');
  final cart = CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);
  return (orders: ApiOrderRepository(cart), net: net, cart: cart);
}

Future<void> settle([int ms = 30]) => Future<void>.delayed(Duration(milliseconds: ms));

void main() {
  tearDown(() { cartModeOverride = null; pickupModeOverride = null; checkoutModeOverride = null; paymentModeOverride = null; orderModeOverride = null; realtimeModeOverride = null; });

  group('realtime mode', () {
    test('live updates need the backend orders, REALTIME_MODE=api and a key — otherwise the app polls', () {
      expect(realtimeFromApi, isFalse);
      cartModeOverride = 'api'; pickupModeOverride = 'api'; checkoutModeOverride = 'api'; paymentModeOverride = 'api'; orderModeOverride = 'api';
      expect(realtimeFromApi, isFalse, reason: 'REALTIME_MODE is off by default');
      realtimeModeOverride = 'api';
      expect(realtimeFromApi, equals(AppConfigKeyPresent.value), reason: 'with the mode on, only a configured key opens the socket (none in unit tests)');
    });
  });

  group('the Pusher client', () {
    test('backoff is exponential, bounded and jittered', () {
      final c = PusherClient(host: 'h', port: 1, key: 'k', scheme: 'http', authorize: (_, _) async => null, baseBackoff: const Duration(seconds: 2), maxBackoff: const Duration(seconds: 30));
      for (var i = 0; i < 20; i++) {
        final d0 = c.backoff(0).inMilliseconds, d3 = c.backoff(3).inMilliseconds, d9 = c.backoff(9).inMilliseconds;
        expect(d0, inInclusiveRange(1000, 2000));
        expect(d3, inInclusiveRange(8000, 16000));
        expect(d9, inInclusiveRange(15000, 30000), reason: 'never above the bound');
      }
    });

    test('a private channel is subscribed only after the backend authorized it with the session token; a refusal subscribes nothing', () async {
      final sock = FakeSocket();
      final asked = <String>[];
      var answer = 'test-key:signature';
      final c = PusherClient(host: 'h', port: 1, key: 'k', scheme: 'http', connector: (_) => sock.channel, authorize: (socketId, channel) async { asked.add('$socketId $channel'); return answer.isEmpty ? null : answer; });
      final states = <RealtimeTransport>[];
      c.transportChanges.listen(states.add);
      final events = <String>[];
      c.subscribe('customer.order.abc', (event, data) => events.add('$event ${data['version']}'));
      await settle();
      expect(c.transport, RealtimeTransport.connecting);
      sock.established('123.456');
      await settle();
      expect([c.transport, c.socketId, asked], [RealtimeTransport.connected, '123.456', ['123.456 private-customer.order.abc']]);
      expect(sock.sent.last, {'event': 'pusher:subscribe', 'data': {'channel': 'private-customer.order.abc', 'auth': 'test-key:signature'}});
      sock.say({'event': 'order.updated', 'channel': 'private-customer.order.abc', 'data': jsonEncode({'version': 2, 'event_sequence': 2})});
      sock.say({'event': 'order.updated', 'channel': 'private-customer.order.other', 'data': jsonEncode({'version': 9})});   // not ours: ignored
      sock.say({'event': 'pusher:ping', 'data': {}});
      await settle();
      expect(events, ['order.updated 2']);
      expect(sock.sent.last['event'], 'pusher:pong');
      // A refused authorization (another customer's order, a signed-out session): no subscribe frame is ever sent.
      answer = '';
      final before = sock.sent.length;
      c.subscribe('customer.order.notmine', (_, _) {});
      await settle();
      expect(sock.sent.length, before);
      expect(states, contains(RealtimeTransport.connected));
      await c.close();
      expect(c.transport, RealtimeTransport.off);
    });

    test('a dropped socket is reported and reconnected with backoff; closing stops everything', () async {
      var connections = 0;
      final sockets = <FakeSocket>[];
      final c = PusherClient(host: 'h', port: 1, key: 'k', scheme: 'http', baseBackoff: const Duration(milliseconds: 20), maxBackoff: const Duration(milliseconds: 40),
          connector: (_) { connections++; final s = FakeSocket(); sockets.add(s); return s.channel; }, authorize: (_, _) async => 'k:s');
      final states = <RealtimeTransport>[];
      c.transportChanges.listen(states.add);
      c.subscribe('customer.order.abc', (_, _) {});
      await settle();
      sockets.first.established('1.1');
      await settle();
      await sockets.first.drop();
      await settle(120);
      expect(connections, greaterThanOrEqualTo(2), reason: 'reconnected after the drop');
      expect(states, containsAllInOrder([RealtimeTransport.connecting, RealtimeTransport.connected, RealtimeTransport.disconnected]));
      sockets.last.established('2.2');
      await settle();
      expect([c.transport, c.socketId], [RealtimeTransport.connected, '2.2']);
      expect(sockets.last.sent.any((f) => f['event'] == 'pusher:subscribe'), isTrue, reason: 'the channel is subscribed again on the new connection');
      await c.close();
    });
  });

  group('the live tracker', () {
    test('a notice is never applied: an older one is ignored, a newer one makes the order be read and only new lines reach the screen; the socket away → polling, back → one read', () async {
      final r = repo();
      var answer = orderDto();
      r.net.answer = (_) => json(200, {'order': answer});
      final sock = FakeSocket();
      final client = PusherClient(host: 'h', port: 1, key: 'k', scheme: 'http', connector: (_) => sock.channel, authorize: (_, _) async => 'k:s');
      final transports = <RealtimeTransport>[];
      final service = ApiOrderTrackingService(r.orders, every: const Duration(milliseconds: 150), realtime: client, onTransport: transports.add);   // slower than the connection below: no poll before the socket is up
      final first = orderFromApi(answer, customer);
      final events = <String>[]; final conn = <TrackingConnection>[];
      final stop = service.subscribe(first, onEvent: (e) => events.add('${e.sequence} ${e.type.name}'), onConnection: conn.add);
      await settle();
      sock.established('1.1');
      await settle(60);
      final readsAfterConnect = r.net.calls.where((c) => c.url.path.endsWith('/customer/orders/$number')).length;
      expect(readsAfterConnect, greaterThanOrEqualTo(1), reason: 'connected: a read to resync');
      await settle(350);   // more than two poll periods: connected, nothing is polled
      expect(r.net.calls.where((c) => c.url.path.endsWith('/customer/orders/$number')).length, readsAfterConnect, reason: 'no polling while the socket is connected');
      expect(events, isEmpty);
      // An older notice (the screen has version 1 / sequence 1 already): nothing is read.
      sock.say({'event': 'order.updated', 'channel': 'private-customer.order.${first.publicId}', 'data': jsonEncode({'version': 1, 'event_sequence': 1, 'status': 'WAITING_FOR_RESTAURANT'})});
      await settle(40);
      expect(r.net.calls.where((c) => c.url.path.endsWith('/customer/orders/$number')).length, readsAfterConnect);
      // A newer notice: the order is read; the timeline line the backend has is what the screen gets — the notice said nothing about items or money.
      answer = orderDto(status: 'ACCEPTED', timeline: accepted);
      sock.say({'event': 'order.updated', 'channel': 'private-customer.order.${first.publicId}', 'data': jsonEncode({'version': 2, 'event_sequence': 2, 'status': 'ACCEPTED'})});
      await settle(60);
      expect(events, ['3 restaurantAccepted']);
      expect(r.net.calls.where((c) => c.url.path.endsWith('/customer/orders/$number')).length, readsAfterConnect + 1);
      // The socket drops: polling takes over (the reads keep coming) and the screen is told.
      await sock.drop();
      await settle(400);
      expect(transports.last, isIn([RealtimeTransport.disconnected, RealtimeTransport.reconnecting]));
      expect(conn, contains(TrackingConnection.stale), reason: 'the screen was told the socket is away; the polls that follow report live again');
      final polled = r.net.calls.where((c) => c.url.path.endsWith('/customer/orders/$number')).length;
      expect(polled, greaterThan(readsAfterConnect + 1), reason: 'polling while the socket is away');
      stop();
      final afterStop = r.net.calls.length;
      await settle(80);
      expect(r.net.calls.length, afterStop, reason: 'stopped: no more reads');
      await client.close();
    });

    test('without a realtime client the tracker is the Module 31 poll and says so', () async {
      final r = repo();
      r.net.answer = (_) => json(200, {'order': orderDto()});
      final transports = <RealtimeTransport>[];
      final service = ApiOrderTrackingService(r.orders, every: const Duration(milliseconds: 20), onTransport: transports.add);
      final stop = service.subscribe(orderFromApi(orderDto(), customer), onEvent: (_) {}, onConnection: (_) {});
      await settle(70);
      expect(transports, [RealtimeTransport.off]);
      expect(r.net.calls.length, greaterThanOrEqualTo(2));
      stop();
    });
  });
}

/// Whether a Reverb key was compiled into this test build (it is not: the key is a --dart-define of the app builds).
class AppConfigKeyPresent {
  static final bool value = const String.fromEnvironment('REVERB_APP_KEY', defaultValue: '').isNotEmpty;
}
