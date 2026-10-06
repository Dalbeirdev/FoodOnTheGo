/// Pickup availability on the backend (Module 28) — Android: the mapping of the pickup API onto the app model (every
/// time, capacity state and recommendation is the backend's, shown on the wall clock the backend wrote), what the
/// gateway sends (a mode, a method, a slot key — never a time, a restaurant or a customer; the cart's own identity;
/// an idempotency key with the same-key retry), the error vocabulary, the pickup state on that gateway (choose, a
/// slot that filled up, a stale selection, a changed cart, NO CONNECTION = nothing is confirmed, the cart as owner of
/// the choice) and the screen. Payloads are in the shape of the real API (OpenAPI: PickupAvailability,
/// PickupSelection).
library;

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/api_pickup.dart';
import 'package:foodonthego/screens/pickup_time_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

const restaurantId = '0a000000-0000-4000-8000-0000000000a1';
final guestToken = 'gc1.abc123.${'a' * 64}.${'b' * 32}';

http.Response json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});
http.Response failure(int status, String code, [String message = 'x', Map<String, dynamic> details = const {}]) => json(status, {'error': {'code': code, 'message': message, 'details': details, 'request_id': 'req-1'}});

/* ---------------------------------------------------------------- payloads in the API's shape */
/// The backend's clock in these tests: 2026-10-07 12:00 in Asia/Kolkata (06:30 UTC).
final now = DateTime.utc(2026, 10, 7, 6, 30);
String utc(DateTime d) => '${d.toUtc().toIso8601String().split('.').first}+00:00';
String wall(DateTime d, int offsetMinutes) {
  final l = d.toUtc().add(Duration(minutes: offsetMinutes));
  final sign = offsetMinutes < 0 ? '-' : '+';
  final a = offsetMinutes.abs();
  return '${l.toIso8601String().split('.').first}$sign${(a ~/ 60).toString().padLeft(2, '0')}:${(a % 60).toString().padLeft(2, '0')}';
}
String localDate(DateTime d, int offsetMinutes) => wall(d, offsetMinutes).substring(0, 10);
String keyOf(DateTime start) => 'ps1_${(start.millisecondsSinceEpoch ~/ 1000).toRadixString(36)}_15_0123456789ab';

Map<String, dynamic> issue(String code, {String severity = 'BLOCKING', String? message, Map<String, dynamic> details = const {}}) => {'code': code, 'severity': severity, 'message': message ?? '$code message', 'details': details};
Map<String, dynamic> slotDto(DateTime start, {String capacity = 'AVAILABLE', bool recommended = false, int offset = 330, int? delta}) => {
      'slot_key': keyOf(start), 'starts_at': utc(start), 'ends_at': utc(start.add(const Duration(minutes: 15))), 'local_starts_at': wall(start, offset), 'local_date': localDate(start, offset),
      'available': capacity != 'FULL', 'capacity_state': capacity, 'recommended': recommended, 'reason': capacity == 'FULL' ? 'SLOT_FULL' : null, 'arrival_vs_pickup_delta_seconds': delta,
    };
Map<String, dynamic> selectionDto({String mode = 'SCHEDULED', DateTime? at, String status = 'SELECTED', List<Map<String, dynamic>> issues = const [], String method = 'COUNTER', int offset = 330, DateTime? arrival, int? delta, int cartVersion = 4}) {
  final pickupAt = at ?? now.add(const Duration(minutes: 30));
  return {
    'id': '7a000000-0000-4000-8000-000000000001', 'mode': mode, 'method': method, 'pickup_at': utc(pickupAt), 'local_pickup_at': wall(pickupAt, offset), 'local_date': localDate(pickupAt, offset),
    'slot_key': mode == 'ASAP' ? null : keyOf(pickupAt), 'slot_ends_at': mode == 'ASAP' ? null : utc(pickupAt.add(const Duration(minutes: 15))), 'timezone': 'Asia/Kolkata', 'cart_version': cartVersion,
    'status': status, 'valid': status == 'SELECTED', 'reserved': false, 'issues': issues, 'estimated_prep_minutes': 20, 'traveler_arrival_at': arrival == null ? null : utc(arrival), 'arrival_vs_pickup_delta_seconds': delta,
    'selected_at': utc(now), 'validated_at': utc(now), 'expires_at': utc(now.add(const Duration(days: 7))), 'revalidate_after': utc(now.add(const Duration(seconds: 60))), 'version': 1,
  };
}
final slot1230 = now.add(const Duration(minutes: 30)), slot1245 = now.add(const Duration(minutes: 45)), slot1300 = now.add(const Duration(minutes: 60));
final tomorrow1000 = DateTime.utc(2026, 10, 8, 4, 30);
List<Map<String, dynamic>> defaultSlots() => [slotDto(slot1230), slotDto(slot1245, capacity: 'LIMITED'), slotDto(slot1300, capacity: 'FULL'), slotDto(tomorrow1000)];

Map<String, dynamic> pickupDto({
  bool asapAvailable = true, String? asapReason, bool asapEnabled = true, bool scheduledEnabled = true, bool asapRecommended = false, List<Map<String, dynamic>>? slots, Map<String, dynamic>? selection,
  List<Map<String, dynamic>> issues = const [], Map<String, dynamic>? journey, Map<String, dynamic>? recommended, List<String> methods = const ['COUNTER'], String? method = 'COUNTER', int cartVersion = 4,
  String timezone = 'Asia/Kolkata', int offset = 330, DateTime? generatedAt,
}) {
  final list = slots ?? defaultSlots();
  final asapAt = now.add(const Duration(minutes: 20));
  final blocked = issues.any((i) => i['severity'] == 'BLOCKING' && i['code'] != 'NO_PICKUP_SLOTS');
  return {
    'restaurant': {'id': restaurantId, 'slug': 'burger-hub', 'name': 'Burger Hub', 'timezone': timezone}, 'timezone': timezone, 'cart_version': cartVersion, 'pickup_enabled': true, 'can_select': !blocked,
    'methods': [for (final m in methods) {'method': m, 'instructions': m == 'COUNTER' ? 'Collect at the counter.' : null, 'requires_vehicle_info': m != 'COUNTER'}], 'method': method,
    'preparation': blocked ? null : {'estimated_minutes': 20, 'buffer_minutes': 5, 'minimum_lead_minutes': 10, 'ready_in_minutes': 20},
    'earliest_pickup_at': asapAvailable ? utc(asapAt) : null,
    'asap': {'enabled': asapEnabled, 'available': asapAvailable, 'pickup_at': asapAvailable ? utc(asapAt) : null, 'local_pickup_at': asapAvailable ? wall(asapAt, offset) : null, 'estimated_ready_in_minutes': asapAvailable ? 20 : null, 'recommended': asapRecommended, 'reason': asapReason, 'arrival_vs_pickup_delta_seconds': null},
    'scheduled': {'enabled': scheduledEnabled, 'interval_minutes': 15, 'horizon_minutes': 2880, 'horizon_ends_at': utc(now.add(const Duration(days: 2))), 'slot_count': list.length, 'available_slot_count': list.where((s) => s['available'] == true).length, 'truncated': false},
    'slots': list, 'recommended': recommended, 'journey': journey, 'selection': selection, 'issues': issues,
    'generated_at': utc(generatedAt ?? now), 'revalidate_after': utc((generatedAt ?? now).add(const Duration(seconds: 60))),
  };
}
Map<String, dynamic> journeyDto({String status = 'ACTIVE', DateTime? arrival}) => {
      'status': status, 'traveler_arrival_at': arrival == null ? null : utc(arrival), 'local_traveler_arrival_at': arrival == null ? null : wall(arrival, 330), 'requires_refresh': status == 'STALE', 'estimate_source': 'development', 'arrival_buffer_minutes': 0,
    };

/* ---------------------------------------------------------------- gateways */
class Recorder {
  final calls = <http.Request>[];
  http.Response Function(http.Request request) answer = (_) => json(200, {'pickup': pickupDto()});
  late final client = MockClient((request) async { calls.add(request); return answer(request); });
  http.Request last(String method, String fragment) => calls.lastWhere((c) => c.method == method && c.url.path.contains(fragment));
  Map<String, dynamic> body(http.Request r) => jsonDecode(r.body) as Map<String, dynamic>;
}

({PickupBackend pickup, Recorder net, MemoryKeyValueStore store}) gateway({bool signedIn = false}) {
  final net = Recorder();
  final store = MemoryKeyValueStore();
  final cart = CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);
  store.write(CartBackend.tokenKey, guestToken);
  if (signedIn) store.write(ApiAuthRepository.tokenKey, 'customer-session');
  return (pickup: PickupBackend(cart), net: net, store: store);
}

/// A scripted pickup backend for the state and screen tests: it answers with [pickup] / [stored] and keeps a log.
class ScriptedPickup extends PickupBackend {
  ScriptedPickup() : super(CartBackend(store: MemoryKeyValueStore(), client: ApiClient(client: MockClient((_) async => json(200, {'cart': null})), baseUrl: 'http://api.test/api/v1')));
  Map<String, dynamic> pickup = pickupDto();
  Map<String, dynamic>? stored;
  final log = <String>[];
  final fail = <String, Object>{};
  /// A failure that depends on the whole step (e.g. only for one method).
  Object? Function(String step)? failWhen;
  /// What a successful choice stores (default: the slot or ASAP that was asked for).
  Map<String, dynamic> Function(PickupMode mode, String? slotKey)? onSelect;
  void _step(String name) { log.add(name); final e = fail[name.split(' ').first] ?? failWhen?.call(name); if (e != null) throw toPickupException(e); }
  @override
  Future<Map<String, dynamic>> availability({String? method}) async { _step('availability${method == null ? '' : ' $method'}'); return {...pickup, 'selection': stored}; }
  @override
  Future<Map<String, dynamic>?> selection() async { _step('selection'); return stored; }
  @override
  Future<Map<String, dynamic>> select({required PickupMode mode, String? slotKey, String? method, int? cartVersion}) async {
    _step('select ${mode.name} ${slotKey ?? '-'} ${method ?? '-'} v$cartVersion');
    final slot = (pickup['slots'] as List).cast<Map<String, dynamic>>().where((s) => s['slot_key'] == slotKey).firstOrNull;
    stored = onSelect?.call(mode, slotKey) ?? (mode == PickupMode.asap ? selectionDto(mode: 'ASAP', at: now.add(const Duration(minutes: 20))) : selectionDto(at: DateTime.parse(slot!['starts_at'] as String)));
    return stored!;
  }
  @override
  Future<void> clear() async { _step('clear'); stored = null; }
}

final offline = ApiException(0, 'No connection');
ApiException refused(int status, String code, String message) => ApiException(status, message, code: code);

Cart cartOf({String id = 'cart-1', int? version}) => Cart(
      id: id, restaurantId: restaurantId, restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', currency: 'INR', createdAt: now, updatedAt: now,
      items: [CartItem(id: 'line-1', menuItemId: 'classic-burger', itemSlug: 'classic-burger', restaurantId: restaurantId, itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', selectedVariants: const [], selectedModifiers: const [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, addedAt: now)],
      server: version == null ? null : ServerCartState(id: id, version: version, expired: false, owner: 'guest', restaurantPublicId: restaurantId, restaurantVisible: true, subtotalMinor: 25000, itemCount: 1, validationStatus: 'VALID', canProceed: true),
    );

Future<void> settle() => Future<void>.delayed(Duration.zero);

void main() {
  group('mapping the pickup API', () {
    test('slots, capacity states and the recommendation are the backend\'s; a full slot is listed and cannot be chosen', () {
      final a = toAnswer(pickupDto(slots: [slotDto(slot1230, recommended: true), slotDto(slot1245, capacity: 'LIMITED'), slotDto(slot1300, capacity: 'FULL'), slotDto(tomorrow1000)], recommended: {'mode': 'SCHEDULED', 'slot_key': keyOf(slot1230), 'pickup_at': utc(slot1230), 'local_pickup_at': wall(slot1230, 330), 'basis': 'KITCHEN', 'arrival_vs_pickup_delta_seconds': null}), cartId: 'cart-1', restaurantId: restaurantId);
      final s = a.server;
      expect(s.restaurantName, 'Burger Hub');
      expect(s.cartVersion, 4);
      expect(s.canSelect, isTrue);
      expect(s.asapAvailable, isTrue);
      expect(s.asapAt, now.add(const Duration(minutes: 20)));
      expect(s.prepMinutes, 20);
      expect(s.slots.map((x) => x.slot.id), [keyOf(slot1230), keyOf(slot1245), keyOf(slot1300), keyOf(tomorrow1000)]);
      expect(s.slots.map((x) => x.slot.capacityStatus), [CapacityStatus.available, CapacityStatus.limited, CapacityStatus.full, CapacityStatus.available]);
      expect(s.slots[0].slot.recommended, isTrue);
      expect(s.slots[2].slot.available, isFalse);
      expect(s.slots[2].slot.reasonUnavailable, SlotReason.full);
      expect(s.days, ['2026-10-07', '2026-10-08']);
      expect(s.recommendedMode, PickupMode.scheduled);
      expect(s.recommendedSlotId, keyOf(slot1230));
      expect(s.recommendedBasis, 'KITCHEN');
      expect(a.selection, isNull);
    });

    test('the wall clock is the backend\'s: the offset comes from its local timestamps, also for a zone the app keeps no rules for', () {
      expect(offsetOf('2026-10-07T12:30:00+05:30'), 330);
      expect(offsetOf('2026-11-01T01:30:00-04:00'), -240);
      expect(offsetOf('2026-10-07T07:00:00Z'), 0);
      expect(offsetOf(null), isNull);

      // New York on the night the clocks go back (2026-11-01): 01:30 EDT, then 01:30 EST one hour later.
      final first = DateTime.utc(2026, 11, 1, 5, 30), second = DateTime.utc(2026, 11, 1, 6, 30);
      final a = toAnswer(pickupDto(timezone: 'America/New_York', offset: -240, generatedAt: DateTime.utc(2026, 11, 1, 5, 0), asapAvailable: false, slots: [slotDto(first, offset: -240), slotDto(second, offset: -300)]), cartId: 'c', restaurantId: restaurantId).server;
      expect(a.offsetAt(first), -240);
      expect(a.offsetAt(second), -300);
      // An instant between two answers takes the offset of the one before it; one before all of them takes the first.
      expect(a.offsetAt(first.add(const Duration(minutes: 20))), -240);
      expect(a.offsetAt(DateTime.utc(2026, 11, 1, 5, 0)), -240);
      expect(a.localToday, '2026-11-01');
      expect(toAnswer(pickupDto(), cartId: 'c', restaurantId: restaurantId).server.localToday, '2026-10-07');
    });

    test('the stored selection in the shape checkout already reads, with the backend\'s verdict on it', () {
      final arrival = now.add(const Duration(minutes: 40));
      final dto = selectionDto(at: slot1245, arrival: arrival, delta: 300);
      final sel = toSelection(dto, cartId: 'cart-1', restaurantId: restaurantId)!;
      expect(sel.mode, PickupMode.scheduled);
      expect(sel.slotId, keyOf(slot1245));
      expect(sel.requestedAt, slot1245);
      expect(sel.estimatedCustomerArrival, arrival);
      expect(sel.cartId, 'cart-1');
      final state = toSelectionState(dto)!;
      expect(state.valid, isTrue);
      expect(state.offsetMinutes, 330);
      expect(state.arrivalDeltaSeconds, 300);
      expect(toSelection(null, cartId: 'c', restaurantId: 'r'), isNull);
      expect(toSelection(selectionDto(mode: 'ASAP'), cartId: 'c', restaurantId: 'r')!.slotId, isNull);
    });

    test('a selection the backend no longer accepts says why', () {
      PickupInvalidReason? reason(String code) => toValidation(selectionDto(status: 'STALE', issues: [issue(code)])).reason;
      expect(toValidation(selectionDto()).ok, isTrue);
      expect(toValidation(null).reason, PickupInvalidReason.slotMissing);
      expect(reason('SLOT_FULL'), PickupInvalidReason.slotUnavailable);
      expect(reason('SLOT_EXPIRED'), PickupInvalidReason.past);
      expect(reason('RESTAURANT_NOT_ACCEPTING'), PickupInvalidReason.notAccepting);
      expect(reason('PICKUP_DISABLED'), PickupInvalidReason.restaurantInactive);
      expect(reason('CART_CHANGED'), PickupInvalidReason.cartInvalid);
      expect(reason('RESTAURANT_CLOSED'), PickupInvalidReason.outsideSchedule);
    });

    test('issues: what blocks everything is told apart from "no time left"', () {
      final closed = toAnswer(pickupDto(asapAvailable: false, asapReason: 'RESTAURANT_CLOSED', slots: [], issues: [issue('NO_PICKUP_SLOTS', message: 'No pickup times are available.', details: {'cause': 'FULLY_BOOKED'})]), cartId: 'c', restaurantId: restaurantId).server;
      expect(closed.blocking, isNull);
      expect(closed.noTimes!.details['cause'], 'FULLY_BOOKED');
      expect(closed.asapReason, 'RESTAURANT_CLOSED');
      final paused = toAnswer(pickupDto(asapAvailable: false, slots: [], issues: [issue('RESTAURANT_NOT_ACCEPTING', message: 'Not accepting orders right now.')]), cartId: 'c', restaurantId: restaurantId).server;
      expect(paused.canSelect, isFalse);
      expect(paused.blocking!.code, 'RESTAURANT_NOT_ACCEPTING');
      expect(paused.prepMinutes, isNull);
    });
  });

  group('the pickup gateway', () {
    test('asks as the cart\'s owner: the guest token in a header, nothing about a time, a place or a customer', () async {
      final g = gateway();
      final j = await g.pickup.availability();
      expect(j['timezone'], 'Asia/Kolkata');
      final call = g.net.last('GET', '/cart/pickup-availability');
      expect(call.headers['X-Cart-Token'], guestToken);
      expect(call.headers.containsKey('Authorization'), isFalse);
      expect(call.url.query, isEmpty);
      expect(call.body, isEmpty);
      await g.pickup.availability(method: 'CURBSIDE');
      expect(g.net.last('GET', '/cart/pickup-availability').url.queryParameters, {'method': 'CURBSIDE'});
    });

    test('a signed-in customer is the customer: the session, never the guest token', () async {
      final g = gateway(signedIn: true);
      await g.pickup.availability();
      final call = g.net.last('GET', '/cart/pickup-availability');
      expect(call.headers['Authorization'], 'Bearer customer-session');
      expect(call.headers.containsKey('X-Cart-Token'), isFalse);
    });

    test('a choice is a mode, a method and a slot key — never a time, a restaurant, a currency or a customer', () async {
      final g = gateway();
      g.net.answer = (_) => json(200, {'selection': selectionDto(at: slot1245)});
      final stored = await g.pickup.select(mode: PickupMode.scheduled, slotKey: keyOf(slot1245), method: 'COUNTER', cartVersion: 4);
      expect(stored['reserved'], isFalse);
      final call = g.net.last('POST', '/cart/pickup-selection');
      expect(g.net.body(call), {'mode': 'SCHEDULED', 'slot_key': keyOf(slot1245), 'method': 'COUNTER', 'cart_version': 4});
      expect(call.headers['Idempotency-Key'], matches(RegExp(r'^pku-[A-Za-z0-9._:-]{12,116}$')));

      g.net.answer = (_) => json(200, {'selection': selectionDto(mode: 'ASAP')});
      await g.pickup.select(mode: PickupMode.asap);
      expect(g.net.body(g.net.last('POST', '/cart/pickup-selection')), {'mode': 'ASAP'});
    });

    test('RETRY: after a dropped connection the choice is asked for once more with the SAME idempotency key', () async {
      final g = gateway();
      var attempts = 0;
      g.net.answer = (_) { attempts++; if (attempts == 1) throw http.ClientException('connection reset'); return json(200, {'selection': selectionDto(at: slot1230)}); };
      await g.pickup.select(mode: PickupMode.scheduled, slotKey: keyOf(slot1230));
      final posts = g.net.calls.where((c) => c.method == 'POST').toList();
      expect(posts, hasLength(2));
      expect(posts[0].headers['Idempotency-Key'], isNotNull);
      expect(posts[1].headers['Idempotency-Key'], posts[0].headers['Idempotency-Key']);
    });

    test('reads and clears the stored choice', () async {
      final g = gateway();
      g.net.answer = (_) => json(200, {'selection': null});
      expect(await g.pickup.selection(), isNull);
      g.net.answer = (_) => json(200, {'selection': selectionDto(mode: 'ASAP')});
      expect((await g.pickup.selection())!['mode'], 'ASAP');
      g.net.answer = (_) => json(200, {'selection': null});
      await g.pickup.clear();
      expect(g.net.last('DELETE', '/cart/pickup-selection').body, isEmpty);
    });

    test('speaks the backend error vocabulary, and says "offline" only when there was no answer at all', () async {
      final g = gateway();
      Future<PickupApiException> selectFailing(http.Response Function(http.Request) answer) async {
        g.net.answer = answer;
        try { await g.pickup.select(mode: PickupMode.scheduled, slotKey: keyOf(slot1300)); } on PickupApiException catch (e) { return e; }
        fail('no exception');
      }
      final full = await selectFailing((_) => failure(409, 'slot_full', 'That pickup time has just filled up.'));
      expect(full.code, PickupErrorCode.slotFull);
      expect(full.message, 'That pickup time has just filled up.');
      expect((await selectFailing((_) => failure(409, 'slot_expired'))).code, PickupErrorCode.slotExpired);
      expect((await selectFailing((_) => failure(422, 'slot_invalid', 'x', {'reason': 'TAMPERED'}))).details['reason'], 'TAMPERED');
      expect((await selectFailing((_) => failure(422, 'slot_outside_horizon'))).code, PickupErrorCode.slotInvalid);
      expect((await selectFailing((_) => failure(409, 'asap_unavailable'))).code, PickupErrorCode.asapUnavailable);
      expect((await selectFailing((_) => failure(409, 'cart_version_conflict'))).code, PickupErrorCode.cartChanged);
      expect((await selectFailing((_) => failure(409, 'cart_invalid'))).code, PickupErrorCode.cartInvalid);
      expect((await selectFailing((_) => failure(410, 'cart_expired'))).code, PickupErrorCode.cartExpired);
      expect((await selectFailing((_) => failure(409, 'restaurant_not_accepting'))).code, PickupErrorCode.notAccepting);
      expect((await selectFailing((_) => failure(409, 'pickup_disabled'))).code, PickupErrorCode.pickupDisabled);
      expect((await selectFailing((_) => failure(422, 'pickup_method_unavailable'))).code, PickupErrorCode.methodUnavailable);
      expect((await selectFailing((_) => failure(404, 'cart_not_found'))).code, PickupErrorCode.noCart);
      expect((await selectFailing((_) => failure(500, 'server_error'))).code, PickupErrorCode.error);
      final none = await selectFailing((_) => throw http.ClientException('no route to host'));
      expect(none.code, PickupErrorCode.offline);
      expect(none.message, contains('needs a connection'));
    });
  });

  group('the pickup state on the backend', () {
    PickupState stateOn(ScriptedPickup backend, {MemoryKeyValueStore? store}) => PickupState(backend: backend, store: store ?? MemoryKeyValueStore());

    test('shows the backend\'s answer: ASAP first when it is possible, the days and slots as they were sent', () async {
      final b = ScriptedPickup();
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      expect(b.log, ['availability']);
      expect(s.status, PickupStatus.available);
      expect(s.mode, PickupMode.asap);
      expect(s.estimate!.earliestPickupAt, now.add(const Duration(minutes: 20)));
      expect(s.estimate!.prepMinutes, 20);
      expect(s.days, ['2026-10-07', '2026-10-08']);
      expect(s.date, '2026-10-07');
      expect(s.slots.map((x) => x.id), [keyOf(slot1230), keyOf(slot1245), keyOf(slot1300)]);
      expect(s.settings!.modes, [PickupMode.asap, PickupMode.scheduled]);
      expect(s.settings!.intervalMinutes, 15);
      expect(s.selection, isNull);
      // Another day is already in the answer: no request, and the tab is the customer's.
      await s.setDate('2026-10-08');
      expect(s.slots.map((x) => x.id), [keyOf(tomorrow1000)]);
      s.setMode(PickupMode.scheduled);
      await s.refresh();
      expect(s.mode, PickupMode.scheduled);
      expect(s.date, '2026-10-08');
      expect(b.log, ['availability', 'availability']);
    });

    test('opens on the slots when ASAP is not possible', () async {
      final b = ScriptedPickup()..pickup = pickupDto(asapAvailable: false, asapReason: 'RESTAURANT_CLOSED');
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      expect(s.mode, PickupMode.scheduled);
      expect(s.estimate!.earliestPickupAt, slot1230);
      expect(s.server!.asapReason, 'RESTAURANT_CLOSED');
    });

    test('an ASAP choice at a restaurant that has switched ASAP off since opens on the slots — never on a mode that is not offered', () async {
      final b = ScriptedPickup()
        ..pickup = pickupDto(asapEnabled: false, asapAvailable: false, asapReason: 'ASAP_DISABLED')
        ..stored = selectionDto(mode: 'ASAP', status: 'INVALID', issues: [issue('ASAP_DISABLED', message: 'This restaurant no longer offers ASAP pickup.')]);
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      expect(s.settings!.modes, [PickupMode.scheduled]);
      expect(s.mode, PickupMode.scheduled);
      expect(s.status, PickupStatus.stale);
      expect(s.slots, isNotEmpty);
    });

    test('a choice is sent as a slot key with the cart version, and counts once the backend has stored it', () async {
      final b = ScriptedPickup();
      final store = MemoryKeyValueStore();
      final s = stateOn(b, store: store);
      await s.loadForCart(cartOf(version: 4));
      final before = s.slots[1];
      s.selectSlot(before);
      expect(s.busy, isTrue);
      expect(s.selection, isNull, reason: 'nothing is selected until the backend says so');
      await settle();
      expect(b.log.last, 'select scheduled ${keyOf(slot1245)} COUNTER v4');
      expect(s.busy, isFalse);
      expect(s.status, PickupStatus.selected);
      expect(s.selection!.slotId, keyOf(slot1245));
      expect(s.selection!.requestedAt, slot1245);
      expect(s.serverSelection!.valid, isTrue);
      expect(s.mode, PickupMode.scheduled);
      await settle();
      expect(await store.read(PickupState.key), contains(keyOf(slot1245)), reason: 'the last confirmed choice is kept to be shown without a connection');

      await s.choose(PickupMode.asap);
      expect(b.log.last, 'select asap - COUNTER v4');
      expect(s.selection!.mode, PickupMode.asap);
      expect(s.selection!.slotId, isNull);
    });

    test('a full or passed slot cannot be tapped into a request', () async {
      final b = ScriptedPickup();
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      s.selectSlot(s.slots[2]);
      await settle();
      expect(b.log, ['availability']);
      expect(s.selection, isNull);
    });

    test('SLOT JUST FILLED UP: the backend\'s words are shown, the times are asked for again, nothing is selected', () async {
      final b = ScriptedPickup()..fail['select'] = refused(409, 'slot_full', 'That pickup time has just filled up. Please choose another.');
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      b.pickup = pickupDto(slots: [slotDto(slot1230), slotDto(slot1245, capacity: 'FULL'), slotDto(slot1300, capacity: 'FULL')]);
      await s.choose(PickupMode.scheduled, slotKey: keyOf(slot1245));
      expect(b.log, ['availability', 'select scheduled ${keyOf(slot1245)} COUNTER v4', 'availability']);
      expect(s.error, 'That pickup time has just filled up. Please choose another.');
      expect(s.selection, isNull);
      expect(s.status, PickupStatus.available);
      expect(s.slots[1].available, isFalse);
      expect(s.needsConnection, isFalse);
    });

    test('CART CHANGED under the screen: the app is told to read the cart again', () async {
      final b = ScriptedPickup()..fail['select'] = refused(409, 'cart_version_conflict', 'Your cart changed.');
      final s = stateOn(b);
      var reloads = 0;
      s.onCartOutdated = () => reloads++;
      await s.loadForCart(cartOf(version: 4));
      await s.choose(PickupMode.asap);
      expect(reloads, 1);
      expect(s.error, 'Your cart changed.');
      expect(s.selection, isNull);
    });

    test('a stored choice the backend no longer accepts is STALE: it is shown as such and cannot be validated', () async {
      final b = ScriptedPickup()..stored = selectionDto(at: slot1300, status: 'STALE', issues: [issue('SLOT_FULL', message: 'Your pickup time is no longer available.')]);
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 5));
      expect(s.status, PickupStatus.stale);
      expect(s.staleReason, PickupInvalidReason.slotUnavailable);
      expect(s.serverSelection!.issues.single.message, 'Your pickup time is no longer available.');
      final verdict = await s.revalidate();
      expect(verdict.ok, isFalse);
      expect(verdict.reason, PickupInvalidReason.slotUnavailable);
      expect(s.status, PickupStatus.stale);
      // Choosing another time replaces it.
      await settle();
      await s.choose(PickupMode.scheduled, slotKey: keyOf(slot1230));
      expect(s.status, PickupStatus.selected);
      expect(s.staleReason, isNull);
    });

    test('before checkout the backend judges the choice again; "Change time" clears it on the backend', () async {
      final b = ScriptedPickup();
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      await s.choose(PickupMode.scheduled, slotKey: keyOf(slot1230));
      expect((await s.revalidate()).ok, isTrue);
      expect(b.log.last, 'selection');
      s.clear();
      expect(s.selection, isNull);
      expect(s.status, PickupStatus.available);
      await settle();
      expect(b.log.last, 'clear');
      expect((await s.revalidate()).reason, PickupInvalidReason.slotMissing);
    });

    test('NO CONNECTION: the last known selection stays visible but nothing is confirmed, and checkout cannot validate it', () async {
      final b = ScriptedPickup();
      final store = MemoryKeyValueStore();
      final first = stateOn(b, store: store);
      await first.loadForCart(cartOf(version: 4));
      await first.choose(PickupMode.scheduled, slotKey: keyOf(slot1245));
      await settle();

      // The app starts again without a connection.
      final gone = ScriptedPickup()..fail['availability'] = offline..fail['selection'] = offline..fail['select'] = offline;
      final s = stateOn(gone, store: store);
      await settle(); await settle();
      expect(s.selection!.slotId, keyOf(slot1245), reason: 'the last choice the backend confirmed is kept on the device to be shown');
      await s.syncWithCart(cartOf(version: 4));
      expect(s.needsConnection, isTrue);
      await s.loadForCart(cartOf(version: 4));
      expect(s.status, PickupStatus.error);
      expect(s.needsConnection, isTrue);
      expect(s.error, contains('needs a connection'));
      expect(s.selection!.slotId, keyOf(slot1245));
      await expectLater(s.revalidate(), throwsA(isA<PickupApiException>().having((e) => e.code, 'code', PickupErrorCode.offline)));
      // A choice cannot be made either — and nothing pretends it was.
      await s.choose(PickupMode.asap);
      expect(s.needsConnection, isTrue);
      expect(s.selection!.mode, PickupMode.scheduled);

      // Back online: the backend's answer replaces what the device remembered.
      gone.fail.clear();
      await s.refresh();
      expect(s.needsConnection, isFalse);
      expect(s.selection, isNull, reason: 'this backend has no stored choice: the remembered one does not survive its answer');
      expect(s.status, PickupStatus.available);
    });

    test('what the device kept never overrides what the backend already said', () async {
      final store = MemoryKeyValueStore();
      await store.write(PickupState.key, jsonEncode(toSelection(selectionDto(at: slot1300), cartId: 'cart-1', restaurantId: restaurantId)!.toJson()));
      final b = ScriptedPickup();
      final s = stateOn(b, store: store);
      // The cart is gone before the device store has been read.
      await s.syncWithCart(null);
      await settle(); await settle();
      expect(s.selection, isNull);
      expect(s.status, PickupStatus.notSelected);
    });

    test('THE CART OWNS THE CHOICE: it is read again when the cart appears or changes, and forgotten when the cart goes', () async {
      final b = ScriptedPickup()..stored = selectionDto(mode: 'ASAP', at: now.add(const Duration(minutes: 20)));
      final s = stateOn(b);
      await s.syncWithCart(cartOf(version: 4));
      expect(b.log, ['selection']);
      expect(s.selection!.mode, PickupMode.asap);
      expect(s.status, PickupStatus.selected);
      // The same cart at the same version: nothing to ask.
      await s.syncWithCart(cartOf(version: 4));
      expect(b.log, ['selection']);
      // The cart changed: the backend may have made the choice stale.
      b.stored = selectionDto(mode: 'ASAP', status: 'STALE', issues: [issue('CART_CHANGED', message: 'Your cart changed.')]);
      await s.syncWithCart(cartOf(version: 5));
      expect(b.log, ['selection', 'selection']);
      expect(s.status, PickupStatus.stale);
      expect(s.staleReason, PickupInvalidReason.cartInvalid);
      // Signing in may hand over another cart: the choice that came with it is read.
      b.stored = selectionDto(at: slot1230);
      await s.syncWithCart(cartOf(id: 'cart-2', version: 1));
      expect(s.selection!.cartId, 'cart-2');
      expect(s.status, PickupStatus.selected);
      // No cart: no choice.
      await s.syncWithCart(null);
      expect(s.selection, isNull);
      expect(s.status, PickupStatus.notSelected);
      expect(b.log, hasLength(3));
    });

    test('another pickup method is another question to the backend', () async {
      final b = ScriptedPickup()..pickup = pickupDto(methods: ['COUNTER', 'CURBSIDE']);
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      expect(s.settings!.methods.map((m) => m.id), ['COUNTER', 'CURBSIDE']);
      b.pickup = pickupDto(methods: ['COUNTER', 'CURBSIDE'], method: 'CURBSIDE');
      await s.setMethod('CURBSIDE');
      expect(b.log.last, 'availability CURBSIDE');
      expect(s.method, 'CURBSIDE');
      await s.choose(PickupMode.asap);
      expect(b.log.last, 'select asap - CURBSIDE v4');
    });

    test('a picked method the restaurant has switched off since falls back to the restaurant\'s own default', () async {
      final b = ScriptedPickup()..pickup = pickupDto(methods: ['COUNTER', 'CURBSIDE']);
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      b.pickup = pickupDto(methods: ['COUNTER', 'CURBSIDE'], method: 'CURBSIDE');
      await s.setMethod('CURBSIDE');
      // Curbside is switched off in the dashboard.
      b.pickup = pickupDto();
      b.failWhen = (step) => step == 'availability CURBSIDE' ? refused(422, 'pickup_method_unavailable', 'Curbside pickup is not offered.') : null;
      await s.refresh();
      expect(b.log.sublist(b.log.length - 2), ['availability CURBSIDE', 'availability']);
      expect(s.status, PickupStatus.available);
      expect(s.method, 'COUNTER');
      expect(s.error, isNull);
    });

    test('an empty or missing cart has no pickup times — that is not an error', () async {
      final b = ScriptedPickup()..fail['availability'] = refused(409, 'cart_empty', 'Your cart is empty.');
      final s = stateOn(b);
      await s.loadForCart(cartOf(version: 4));
      expect(s.status, PickupStatus.notSelected);
      expect(s.error, isNull);
      expect(s.server, isNull);
    });

    test('without a backend the development engine still runs (mock mode is untouched)', () {
      final s = PickupState(store: MemoryKeyValueStore());
      expect(s.backendMode, isFalse);
      expect(pickupFromApi, isFalse, reason: 'tests run with PICKUP_MODE=mock unless a test says otherwise');
    });
  });

  group('the pickup screen on the backend', () {
    final menu = MockMenuRepository(latency: Duration.zero);
    final rest = MockRestaurantRepository(latency: Duration.zero);
    AddItemInput burger() => const AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', selectedVariants: [], selectedModifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000);

    Widget app(CartState cart, PickupState pk) => MultiProvider(
          providers: [
            ChangeNotifierProvider(create: (_) => HealthState()),
            ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
            ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
            ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
            ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
            ChangeNotifierProvider.value(value: cart),
            ChangeNotifierProvider(create: (_) => OrdersState()),
            ChangeNotifierProvider.value(value: pk),
          ],
          child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/pickup-time', routes: [
            GoRoute(path: '/pickup-time', builder: (_, _) => PickupTimeScreen(restaurantRepository: rest, menuRepository: menu)),
            GoRoute(path: '/checkout', builder: (_, _) => const Scaffold(body: Text('checkout screen'))),
            GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('login screen'))),
            GoRoute(path: '/cart', builder: (_, _) => const Scaffold(body: Text('cart screen'))),
            GoRoute(path: '/plan-journey', builder: (_, _) => const Scaffold(body: Text('plan screen'))),
            GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))),
            GoRoute(path: '/restaurants/:id', builder: (_, _) => const Scaffold(body: Text('restaurant'))),
          ])),
        );
    void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 3000); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
    Future<(CartState, PickupState)> states(ScriptedPickup backend, {MemoryKeyValueStore? store}) async {
      final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger());
      return (cart, PickupState(backend: backend, store: store ?? MemoryKeyValueStore()));
    }
    Finder id(String identifier) => find.byWidgetPredicate((w) => w is Semantics && w.properties.identifier == identifier);
    Finder idStarting(String prefix) => find.byWidgetPredicate((w) => w is Semantics && (w.properties.identifier?.startsWith(prefix) ?? false));

    testWidgets('ASAP and the journey are the backend\'s, on the restaurant\'s wall clock; a choice is "selected, not reserved"', (t) async {
      tall(t);
      final arrival = now.add(const Duration(minutes: 25));
      final b = ScriptedPickup()..pickup = pickupDto(asapRecommended: true, journey: journeyDto(arrival: arrival), recommended: {'mode': 'ASAP', 'slot_key': null, 'pickup_at': utc(now.add(const Duration(minutes: 20))), 'local_pickup_at': wall(now.add(const Duration(minutes: 20)), 330), 'basis': 'KITCHEN', 'arrival_vs_pickup_delta_seconds': -300});
      b.onSelect = (mode, _) => selectionDto(mode: 'ASAP', at: now.add(const Duration(minutes: 20)), arrival: arrival, delta: -300);
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(find.text('Burger Hub'), findsOneWidget);
      expect(find.textContaining('Asia/Kolkata'), findsOneWidget);
      expect(find.textContaining('GMT+5:30'), findsWidgets);
      expect(find.textContaining('Estimated preparation 20'), findsNWidgets(2), reason: 'the context line and the ASAP note');
      expect(id('pk-asap-time-recommended'), findsOneWidget);
      expect(find.textContaining('Ready in about 12:20'), findsOneWidget);
      expect(find.text('Recommended'), findsOneWidget);
      expect(id('pk-journey-ACTIVE'), findsOneWidget);
      expect(find.textContaining('Your estimated arrival ~12:25'), findsOneWidget);
      expect(find.textContaining('development estimate'), findsOneWidget);
      expect(id('pk-continue-off'), findsOneWidget);
      expect(id('pk-not-reserved'), findsNothing);

      await t.tap(find.text('Pick up as soon as possible')); await t.pumpAndSettle();
      expect(b.log.last, 'select asap - COUNTER v4', reason: 'the cart version is the one the backend answered with');
      expect(id('pk-summary-asap'), findsOneWidget);
      expect(find.text('Selected: as soon as possible'), findsOneWidget);
      expect(id('pk-not-reserved'), findsOneWidget);
      expect(find.textContaining('selected, not reserved'), findsOneWidget);
      expect(find.textContaining('reserved for you'), findsNothing);
      expect(id('pk-continue'), findsOneWidget);

      // Continue: the backend judges the choice once more, then the guest is asked to sign in.
      await t.tap(find.text('Continue to checkout')); await t.pumpAndSettle();
      expect(b.log.last, 'selection');
      expect(find.text('login screen'), findsOneWidget);
    });

    testWidgets('slots show the backend capacity state: a full one is listed and cannot be chosen; choosing one fills the summary', (t) async {
      tall(t);
      final b = ScriptedPickup()..pickup = pickupDto(asapAvailable: false, asapReason: 'CLOSES_TOO_SOON', slots: [slotDto(slot1230, recommended: true), slotDto(slot1245, capacity: 'LIMITED'), slotDto(slot1300, capacity: 'FULL'), slotDto(tomorrow1000)]);
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      // ASAP is not possible, so the screen opens on the slots.
      expect(idStarting('pk-slot-'), findsNWidgets(3));
      expect(id('pk-slot-available-rec-off-${slot1230.millisecondsSinceEpoch ~/ 1000}'), findsOneWidget);
      expect(id('pk-slot-limited-std-off-${slot1245.millisecondsSinceEpoch ~/ 1000}'), findsOneWidget);
      expect(id('pk-slot-full-std-off-${slot1300.millisecondsSinceEpoch ~/ 1000}'), findsOneWidget);
      expect(find.textContaining('12:30'), findsWidgets);
      expect(find.text('Limited'), findsOneWidget);
      expect(find.text('Full'), findsOneWidget);
      expect(find.text('Today'), findsOneWidget);
      expect(id('pk-recommended-note'), findsNothing, reason: 'no journey: the note about the arrival is not shown');

      await t.tap(id('pk-slot-full-std-off-${slot1300.millisecondsSinceEpoch ~/ 1000}')); await t.pumpAndSettle();
      expect(b.log.where((l) => l.startsWith('select')), isEmpty);

      await t.tap(id('pk-slot-limited-std-off-${slot1245.millisecondsSinceEpoch ~/ 1000}')); await t.pumpAndSettle();
      expect(b.log.last, 'select scheduled ${keyOf(slot1245)} COUNTER v4');
      expect(id('pk-summary-scheduled'), findsOneWidget);
      expect(id('pk-slot-limited-std-on-${slot1245.millisecondsSinceEpoch ~/ 1000}'), findsOneWidget);
      expect(find.byWidgetPredicate((w) => w is Text && (w.data ?? '').startsWith('12:45') && (w.data ?? '').endsWith('GMT+5:30')), findsOneWidget, reason: 'the summary names the time with the restaurant clock it belongs to');
      expect(id('pk-not-reserved'), findsOneWidget);

      // The ASAP tab says why it is not offered, in the backend's reason.
      await t.tap(find.text('As soon as possible')); await t.pumpAndSettle();
      expect(id('pk-asap-unavailable-CLOSES_TOO_SOON'), findsOneWidget);
      expect(find.textContaining('closes before this order could be ready'), findsOneWidget);
      expect(id('pk-summary-scheduled'), findsOneWidget, reason: 'looking at the other tab does not drop the stored choice');
    });

    testWidgets('a slot that filled up while the customer was looking: the backend\'s words, fresh times, nothing selected', (t) async {
      tall(t);
      final b = ScriptedPickup()..pickup = pickupDto(asapAvailable: false, asapReason: 'RESTAURANT_CLOSED')..fail['select'] = refused(409, 'slot_full', 'That pickup time has just filled up. Please choose another.');
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      b.pickup = pickupDto(asapAvailable: false, asapReason: 'RESTAURANT_CLOSED', slots: [slotDto(slot1230, capacity: 'FULL'), slotDto(slot1245), slotDto(slot1300, capacity: 'FULL')]);
      await t.tap(id('pk-slot-available-std-off-${slot1230.millisecondsSinceEpoch ~/ 1000}')); await t.pumpAndSettle();
      expect(id('pk-notice'), findsOneWidget);
      expect(find.text('That pickup time has just filled up. Please choose another.'), findsOneWidget);
      expect(id('pk-slot-full-std-off-${slot1230.millisecondsSinceEpoch ~/ 1000}'), findsOneWidget);
      expect(find.text('Choose a pickup time to continue.'), findsOneWidget);
      expect(id('pk-continue-off'), findsOneWidget);
    });

    testWidgets('a restaurant that is not accepting orders: the backend\'s reason, no chooser, no Continue', (t) async {
      tall(t);
      final b = ScriptedPickup()..pickup = pickupDto(asapAvailable: false, slots: [], issues: [issue('RESTAURANT_NOT_ACCEPTING', message: 'Burger Hub is not accepting orders right now.')]);
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(id('pk-blocked-RESTAURANT_NOT_ACCEPTING'), findsOneWidget);
      expect(find.text('Burger Hub is not accepting orders right now.'), findsOneWidget);
      expect(find.text('When would you like to pick up?'), findsNothing);
      expect(id('pk-continue-off'), findsOneWidget);
    });

    testWidgets('no time left: the backend says why, and the stale journey is named as such', (t) async {
      tall(t);
      final b = ScriptedPickup()..pickup = pickupDto(asapEnabled: false, asapAvailable: false, asapReason: 'ASAP_DISABLED', slots: [], journey: journeyDto(status: 'STALE'), issues: [issue('NO_PICKUP_SLOTS', message: 'Every pickup time in the next two days is taken.', details: {'cause': 'FULLY_BOOKED'})]);
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(id('pk-empty-FULLY_BOOKED'), findsOneWidget);
      expect(find.text('Every pickup time in the next two days is taken.'), findsOneWidget);
      expect(id('pk-journey-STALE'), findsOneWidget);
      expect(find.text('Plan your journey again'), findsOneWidget);
      expect(find.text('As soon as possible'), findsNothing, reason: 'only one mode is offered: no mode switch');
    });

    testWidgets('a stale selection shows the backend\'s issue and blocks Continue until another time is chosen', (t) async {
      tall(t);
      final b = ScriptedPickup()..stored = selectionDto(at: slot1300, status: 'STALE', issues: [issue('SLOT_FULL', message: 'Your pickup time is no longer available. Please choose another.')]);
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(id('pk-stale-SLOT_FULL'), findsOneWidget);
      expect(find.text('Your pickup time is no longer available. Please choose another.'), findsOneWidget);
      expect(id('pk-continue-off'), findsOneWidget);
      await t.tap(find.text('Choose another time')); await t.pumpAndSettle();
      expect(b.log, contains('clear'));
      expect(id('pk-stale-SLOT_FULL'), findsNothing);
    });

    testWidgets('NO CONNECTION: "Needs connection to confirm availability" — the last known selection is shown, Continue is off', (t) async {
      tall(t);
      final store = MemoryKeyValueStore();
      await store.write(PickupState.key, jsonEncode(toSelection(selectionDto(at: slot1245), cartId: 'cart-1', restaurantId: restaurantId)!.toJson()));
      final b = ScriptedPickup()..fail['availability'] = offline..fail['selection'] = offline;
      final (cart, pk) = await states(b, store: store);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(id('pk-needs-connection'), findsOneWidget);
      expect(find.text('Needs connection to confirm availability.'), findsNWidgets(2));
      expect(find.text('Last known selection'), findsOneWidget);
      expect(id('pk-summary-scheduled-unconfirmed'), findsOneWidget);
      expect(find.textContaining('12:45'), findsWidgets);
      expect(id('pk-continue-off'), findsOneWidget);
      expect(id('pk-error'), findsNothing);
      expect(find.text('When would you like to pick up?'), findsNothing, reason: 'no times are offered from memory');

      // Back online.
      b.fail.clear();
      await t.tap(find.text('Try again')); await t.pumpAndSettle();
      expect(id('pk-needs-connection'), findsNothing);
      expect(find.text('Pickup summary'), findsOneWidget);
      expect(find.text('Choose a pickup time to continue.'), findsOneWidget);
    });

    testWidgets('CONNECTION LOST AFTER THE TIMES WERE LOADED: the old times are not offered from memory; the choice is shown as unconfirmed', (t) async {
      tall(t);
      final b = ScriptedPickup();
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      await t.tap(find.text('Schedule for later')); await t.pumpAndSettle();
      await t.tap(id('pk-slot-limited-std-off-${slot1245.millisecondsSinceEpoch ~/ 1000}')); await t.pumpAndSettle();
      expect(id('pk-summary-scheduled'), findsOneWidget);
      expect(idStarting('pk-slot-'), findsNWidgets(3));

      // The connection goes; the app comes back to the front and asks again.
      b.fail['availability'] = offline; b.fail['selection'] = offline; b.fail['select'] = offline;
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused); await t.pump();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed); await t.pumpAndSettle();
      expect(id('pk-needs-connection'), findsOneWidget);
      expect(idStarting('pk-slot-'), findsNothing, reason: 'a slot of the earlier answer may be full or gone by now');
      expect(find.text('When would you like to pick up?'), findsNothing);
      expect(id('pk-summary-scheduled-unconfirmed'), findsOneWidget);
      expect(find.text('Last known selection'), findsOneWidget);
      expect(id('pk-continue-off'), findsOneWidget);

      b.fail.clear();
      await t.tap(find.text('Try again')); await t.pumpAndSettle();
      expect(id('pk-needs-connection'), findsNothing);
      expect(idStarting('pk-slot-'), findsNWidgets(3));
      expect(id('pk-summary-scheduled'), findsOneWidget);
      expect(id('pk-continue'), findsOneWidget);
    });

    testWidgets('ON RESUME the pickup times are asked for again', (t) async {
      tall(t);
      final b = ScriptedPickup();
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(b.log, ['availability']);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await t.pump();
      expect(b.log, ['availability']);
      b.pickup = pickupDto(asapAvailable: false, asapReason: 'RESTAURANT_CLOSED');
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(b.log, ['availability', 'availability']);
      await t.tap(find.text('As soon as possible')); await t.pumpAndSettle();
      expect(id('pk-asap-unavailable-RESTAURANT_CLOSED'), findsOneWidget);
    });

    testWidgets('the answer is asked for again when the backend says it has aged (its own interval)', (t) async {
      tall(t);
      final b = ScriptedPickup();
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(b.log, ['availability']);
      await t.pump(const Duration(seconds: 59));
      expect(b.log, ['availability']);
      await t.pump(const Duration(seconds: 2)); await t.pump();
      expect(b.log, ['availability', 'availability']);
    });

    testWidgets('two pickup methods: the customer picks one and the times are asked for that method', (t) async {
      tall(t);
      final b = ScriptedPickup()..pickup = pickupDto(methods: ['COUNTER', 'CURBSIDE']);
      final (cart, pk) = await states(b);
      await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
      expect(id('pk-method-counter'), findsOneWidget);
      expect(id('pk-method-curbside'), findsOneWidget);
      b.pickup = pickupDto(methods: ['COUNTER', 'CURBSIDE'], method: 'CURBSIDE');
      await t.tap(find.text('Curbside pickup')); await t.pumpAndSettle();
      expect(b.log.last, 'availability CURBSIDE');
    });
  });
}
