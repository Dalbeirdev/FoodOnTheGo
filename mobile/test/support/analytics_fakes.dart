/// Fakes for the behavioural-event emitter tests (Module 38): a clock that only moves when told, timers that only fire
/// when told, a recording HTTP layer in the shape of the real endpoint, and an emitter wired to all three.
library;

import 'dart:async';
import 'dart:convert';

import 'package:foodonthego/analytics/analytics.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The wall clock of a test. It is a LOCAL time on purpose: what is sent must be UTC all the same.
class TestClock {
  TestClock([DateTime? start]) : now = (start ?? DateTime.utc(2026, 10, 13, 9, 15)).toLocal();
  DateTime now;
  void advance(Duration d) => now = now.add(d);
  DateTime call() => now;
}

class FakeTimer implements Timer {
  FakeTimer(this._owner, this.delay, this._callback);
  final FakeTimers _owner;
  final Duration delay;
  final void Function() _callback;
  bool _active = true;
  @override
  bool get isActive => _active;
  @override
  int get tick => 0;
  @override
  void cancel() { _active = false; _owner.pending.remove(this); }
  void fire() { if (!_active) return; _active = false; _owner.pending.remove(this); _callback(); }
}

/// Every timer the emitter asks for. None fires by itself.
class FakeTimers {
  final pending = <FakeTimer>[];
  int started = 0;
  int mostAtOnce = 0;
  Timer create(Duration delay, void Function() callback) {
    final t = FakeTimer(this, delay, callback);
    started++;
    pending.add(t);
    if (pending.length > mostAtOnce) mostAtOnce = pending.length;
    return t;
  }

  /// The time passes: what is pending fires.
  void fire() { for (final t in List.of(pending)) { t.fire(); } }
}

/// The 202 the endpoint always gives.
http.Response accepted([int count = 0]) => http.Response(jsonEncode({'accepted': count, 'duplicates': 0, 'rejected': [], 'stored': true, 'session': {'idle_minutes': 30, 'max_hours': 12}}), 202, headers: {'content-type': 'application/json'});
http.Response refused(int status, [String code = 'server_error']) => http.Response(jsonEncode({'error': {'code': code, 'message': 'x', 'request_id': 'req-1'}}), status, headers: {'content-type': 'application/json'});

/// The analytics endpoint: every request is kept; [answer] decides the reply (throw = no connection).
class AnalyticsNet {
  final calls = <http.Request>[];
  http.Response Function(http.Request request) answer = (_) => accepted();
  late final client = MockClient((request) async { calls.add(request); return answer(request); });
  void offline() => answer = (_) => throw http.ClientException('offline');
  void online() => answer = (_) => accepted();

  List<Map<String, dynamic>> get bodies => [for (final c in calls) jsonDecode(c.body) as Map<String, dynamic>];
  /// Every event of every request, in the order sent.
  List<Map<String, dynamic>> get events => [for (final b in bodies) ...(b['events'] as List).cast<Map<String, dynamic>>()];
  List<String> get names => [for (final e in events) e['name'] as String];
}

/// A store that counts what is asked of it (a disabled emitter asks nothing).
class CountingStore extends MemoryKeyValueStore {
  int reads = 0, writes = 0;
  @override
  Future<String?> read(String key) { reads++; return super.read(key); }
  @override
  Future<void> write(String key, String? value) { writes++; return super.write(key, value); }
}

class AnalyticsRig {
  AnalyticsRig({this.token, bool enabled = true, KeyValueStore? store, AnalyticsNet? net, TestClock? clock, String appVersion = '0.1.0+42'})
      : store = store ?? MemoryKeyValueStore(), net = net ?? AnalyticsNet(), clock = clock ?? TestClock() {
    emitter = AnalyticsEmitter(
      api: ApiClient(client: this.net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () async => token),
      store: this.store, appVersion: appVersion, enabled: enabled, clock: this.clock.call, timer: timers.create,
    );
  }
  final KeyValueStore store;
  final AnalyticsNet net;
  final TestClock clock;
  final timers = FakeTimers();
  String? token;
  late final AnalyticsEmitter emitter;
}

const restaurantId = '0a000000-0000-4000-8000-0000000000a1';
const journeyUuid = 'e25471f7-460a-4c66-a9c5-178224f765e2';
const menuItemId = '1b000000-0000-4000-8000-000000000001';
const checkoutId = '6a000000-0000-4000-8000-000000000001';

final uuidV4 = RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
final utcStamp = RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$');
