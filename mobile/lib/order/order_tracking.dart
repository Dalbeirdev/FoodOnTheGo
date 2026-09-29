import 'dart:async';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;
import 'order_models.dart';

/// Order tracking (Module 14) — event-driven, provider-neutral.
/// `reduceOrder` is the only place an event changes an order: duplicates (same eventId) and stale events
/// (sequence ≤ lastEventSequence) are ignored and terminal states never regress. The backend enforces the real
/// state machine later; this is defensive client behaviour. Food-ready ETA and customer-arrival ETA stay separate.
enum TrackingConnection { loading, live, stale, offline, error, completed }
enum TrackingScenario { normal, delay, rejected, cancelled, paymentPending, ready, pickedUp, completed, networkLoss }
const terminalOrderStatuses = {OrderStatus.completed, OrderStatus.cancelled, OrderStatus.rejected, OrderStatus.refunded};

class ReduceResult {
  const ReduceResult(this.order, this.applied, this.reason);
  final Order order; final bool applied; final String reason; // applied | duplicate | stale | terminal
}

ReduceResult reduceOrder(Order o, OrderEvent e) {
  if (o.events.any((x) => x.eventId == e.eventId)) return ReduceResult(o, false, 'duplicate');
  if (e.sequence <= o.lastEventSequence) return ReduceResult(o, false, 'stale');
  if (terminalOrderStatuses.contains(o.orderStatus) && e.type != OrderEventType.refundUpdated) return ReduceResult(o, false, 'terminal');
  var next = o.copyWith(events: [...o.events, e], lastEventSequence: e.sequence, updatedAt: e.at, orderStatus: e.status, paymentStatus: e.paymentStatus, etaReadyAt: e.etaReadyAt);
  switch (e.type) {
    case OrderEventType.delayed: next = next.copyWith(delayed: true, delayReasonKey: e.reasonKey ?? 'taking_longer');
    case OrderEventType.readyForPickup: next = next.copyWith(delayed: false, pickupVerificationStatus: PickupVerificationStatus.ready);
    case OrderEventType.pickupVerification: next = next.copyWith(pickupVerificationStatus: PickupVerificationStatus.verificationAvailable);
    case OrderEventType.pickedUp: next = next.copyWith(pickupVerificationStatus: PickupVerificationStatus.verified);
    case OrderEventType.restaurantRejected: next = next.copyWith(rejectionReasonKey: e.reasonKey ?? 'other', pickupVerificationStatus: PickupVerificationStatus.invalid);
    case OrderEventType.cancelled: next = next.copyWith(cancellationReasonKey: e.reasonKey ?? 'other', pickupVerificationStatus: PickupVerificationStatus.invalid);
    default: break;
  }
  return ReduceResult(next, true, 'applied');
}

enum StageKey { placed, payment, accepted, preparing, ready, pickedUp, completed }
enum StageState { done, current, future, stopped }
class TimelineStage {
  const TimelineStage(this.key, this.state, this.at, {this.note});
  final StageKey key; final StageState state; final DateTime? at; final String? note; // delayed | rejected | cancelled
}

List<TimelineStage> timelineFor(Order o) {
  DateTime? at(List<OrderEventType> types) { final m = o.events.where((e) => types.contains(e.type)).toList(); return m.isEmpty ? null : m.last.at; }
  const rank = {OrderStatus.paymentPending: 0, OrderStatus.confirmed: 1, OrderStatus.awaitingRestaurantAcceptance: 1, OrderStatus.accepted: 2, OrderStatus.preparing: 3, OrderStatus.readyForPickup: 4, OrderStatus.pickupVerification: 4, OrderStatus.pickedUp: 5, OrderStatus.completed: 6, OrderStatus.rejected: 1, OrderStatus.cancelled: -1, OrderStatus.refundPending: -1, OrderStatus.refunded: -1};
  final r = rank[o.orderStatus] ?? 0;
  final stopped = o.orderStatus == OrderStatus.rejected || o.orderStatus == OrderStatus.cancelled;
  final cancelRank = o.orderStatus == OrderStatus.cancelled ? [1, ...o.events.where((e) => e.status != null).map((e) => rank[e.status!] ?? 0)].reduce(max) : r;
  final effective = stopped ? cancelRank : r;
  final defs = [(StageKey.placed, 0, at([OrderEventType.orderCreated])), (StageKey.payment, 1, at([OrderEventType.paymentVerified])), (StageKey.accepted, 2, at([OrderEventType.restaurantAccepted])), (StageKey.preparing, 3, at([OrderEventType.preparing])), (StageKey.ready, 4, at([OrderEventType.readyForPickup])), (StageKey.pickedUp, 5, at([OrderEventType.pickedUp])), (StageKey.completed, 6, at([OrderEventType.completed]))];
  return [
    for (final (key, level, stamp) in defs)
      () {
        var state = level < effective || (level == effective && (level == 6 || stopped)) ? StageState.done : level == effective ? StageState.current : StageState.future;
        if (o.orderStatus == OrderStatus.paymentPending) state = level == 0 ? StageState.done : level == 1 ? StageState.current : StageState.future;
        if (o.orderStatus == OrderStatus.confirmed || o.orderStatus == OrderStatus.awaitingRestaurantAcceptance) state = level <= 1 ? StageState.done : level == 2 ? StageState.current : StageState.future;
        String? note;
        if (stopped && level == effective + 1) { state = StageState.stopped; note = o.orderStatus == OrderStatus.rejected ? 'rejected' : 'cancelled'; }
        if (stopped && level > effective + 1) state = StageState.future;
        if (key == StageKey.preparing && o.delayed && state == StageState.current) note = 'delayed';
        return TimelineStage(key, state, stamp, note: note);
      }(),
  ];
}

bool pickupVisible(Order o) => o.paymentStatus == OrderPaymentStatus.paid && !{OrderStatus.rejected, OrderStatus.cancelled, OrderStatus.paymentPending}.contains(o.orderStatus);

int distanceMeters(double aLat, double aLng, double bLat, double bLng) {
  const r = 6371000.0; double rad(double d) => d * pi / 180;
  final dLat = rad(bLat - aLat), dLng = rad(bLng - aLng);
  final h = pow(sin(dLat / 2), 2) + cos(rad(aLat)) * cos(rad(bLat)) * pow(sin(dLng / 2), 2);
  return (2 * r * asin(sqrt(h))).round();
}

// ---------------------------------------------------------------------------------------------------------
// Live-update abstraction + deterministic mock
// ---------------------------------------------------------------------------------------------------------
abstract class OrderTrackingService {
  /// Starts delivering events; returns a function that unsubscribes.
  void Function() subscribe(Order order, {required void Function(OrderEvent) onEvent, required void Function(TrackingConnection) onConnection});
  /// Idempotent status refresh (polling fallback / manual refresh) — never creates events.
  Future<Order?> refresh(String orderNumber, String customerId);
}

class TrackingStep {
  const TrackingStep(this.type, this.status, {this.paymentStatus, this.reasonKey, this.etaShiftMin = 0, this.actor = 'system', this.dropConnection = false});
  final OrderEventType type; final OrderStatus? status; final OrderPaymentStatus? paymentStatus; final String? reasonKey; final int etaShiftMin; final String actor; final bool dropConnection;
}
const _sent = TrackingStep(OrderEventType.sentToRestaurant, OrderStatus.awaitingRestaurantAcceptance);
const _accepted = TrackingStep(OrderEventType.restaurantAccepted, OrderStatus.accepted, actor: 'restaurant');
const _preparing = TrackingStep(OrderEventType.preparing, OrderStatus.preparing, actor: 'restaurant');
const _delayed = TrackingStep(OrderEventType.delayed, null, actor: 'restaurant', reasonKey: 'high_demand', etaShiftMin: 15);
const _ready = TrackingStep(OrderEventType.readyForPickup, OrderStatus.readyForPickup, actor: 'restaurant');
const _verification = TrackingStep(OrderEventType.pickupVerification, OrderStatus.pickupVerification, actor: 'restaurant');
const _pickedUp = TrackingStep(OrderEventType.pickedUp, OrderStatus.pickedUp, actor: 'restaurant');
const _completed = TrackingStep(OrderEventType.completed, OrderStatus.completed);
const scenarioSteps = <TrackingScenario, List<TrackingStep>>{
  TrackingScenario.normal: [_sent, _accepted, _preparing, _ready, _verification, _pickedUp, _completed],
  TrackingScenario.delay: [_sent, _accepted, _preparing, _delayed, _ready, _verification, _pickedUp, _completed],
  TrackingScenario.rejected: [_sent, TrackingStep(OrderEventType.restaurantRejected, OrderStatus.rejected, actor: 'restaurant', reasonKey: 'item_unavailable', paymentStatus: OrderPaymentStatus.refundPending)],
  TrackingScenario.cancelled: [_sent, _accepted, TrackingStep(OrderEventType.cancelled, OrderStatus.cancelled, actor: 'restaurant', reasonKey: 'restaurant_unavailable', paymentStatus: OrderPaymentStatus.refundPending), TrackingStep(OrderEventType.refundUpdated, null, paymentStatus: OrderPaymentStatus.refunded)],
  TrackingScenario.paymentPending: [],
  TrackingScenario.ready: [_sent, _accepted, _preparing, _ready],
  TrackingScenario.pickedUp: [_sent, _accepted, _preparing, _ready, _verification, _pickedUp],
  TrackingScenario.completed: [_sent, _accepted, _preparing, _ready, _verification, _pickedUp, _completed],
  TrackingScenario.networkLoss: [_sent, TrackingStep(OrderEventType.restaurantAccepted, OrderStatus.accepted, actor: 'restaurant', dropConnection: true), _preparing, _ready, _verification, _pickedUp, _completed],
};

OrderEvent eventForStep(Order o, TrackingStep s, {DateTime? now}) {
  final seq = o.lastEventSequence + 1;
  final base = o.etaReadyAt ?? o.pickup.estimatedReadyTime;
  return OrderEvent(eventId: '${o.publicId}-$seq', sequence: seq, type: s.type, status: s.status, paymentStatus: s.paymentStatus, at: (now ?? DateTime.now()).toUtc(), actor: s.actor, reasonKey: s.reasonKey, etaReadyAt: s.etaShiftMin > 0 ? base.add(Duration(minutes: s.etaShiftMin)) : null);
}

int nextStepIndex(Order o, TrackingScenario s) { final applied = o.events.map((e) => e.type).toSet(); return scenarioSteps[s]!.indexWhere((st) => !applied.contains(st.type)); }

/// Deterministic scripted scenarios — never random, never a real restaurant. Scenario is captured per order.
class MockOrderTrackingService implements OrderTrackingService {
  MockOrderTrackingService(this._orders, this._store, {this.interval = const Duration(seconds: 6)});
  final OrderRepository _orders; final KeyValueStore _store;
  /// Auto-advance period; Duration.zero = manual only (tests / dev button).
  Duration interval;
  TrackingScenario defaultScenario = TrackingScenario.normal;
  final Map<String, Order> _current = {};
  final Map<String, ({void Function(OrderEvent) onEvent, void Function(TrackingConnection) onConnection})> _listeners = {};
  final Map<String, Timer> _timers = {};
  final Map<String, TrackingScenario> _scenarios = {};

  Future<TrackingScenario> scenarioFor(Order o) async {
    final cached = _scenarios[o.orderNumber]; if (cached != null) return cached;
    try { final raw = await _store.read('fotg.tracking.scenario.${o.orderNumber}'); final saved = raw == null ? null : TrackingScenario.values.where((s) => s.name == raw).firstOrNull; if (saved != null) { _scenarios[o.orderNumber] = saved; return saved; } } catch (_) {}
    final s = o.paymentStatus == OrderPaymentStatus.paymentPending ? TrackingScenario.paymentPending : defaultScenario;
    _scenarios[o.orderNumber] = s; await _store.write('fotg.tracking.scenario.${o.orderNumber}', s.name).catchError((_) {}); return s;
  }
  Future<void> setScenarioFor(Order o, TrackingScenario s) async { _scenarios[o.orderNumber] = s; await _store.write('fotg.tracking.scenario.${o.orderNumber}', s.name).catchError((_) {}); }

  @override
  void Function() subscribe(Order order, {required void Function(OrderEvent) onEvent, required void Function(TrackingConnection) onConnection}) {
    final n = order.orderNumber;
    _listeners[n] = (onEvent: onEvent, onConnection: onConnection); _current[n] = order;
    onConnection(TrackingConnection.live);
    _schedule(n);
    return () { _listeners.remove(n); _timers.remove(n)?.cancel(); };
  }
  void _schedule(String n) {
    _timers.remove(n)?.cancel();
    if (interval == Duration.zero) return;
    _timers[n] = Timer(interval, () async { await advance(n); if (_listeners.containsKey(n)) _schedule(n); });
  }
  Future<OrderEvent?> _chain = Future.value();
  /// Emits the next scripted event. Serialized so a double tap never emits the same step twice (idempotent per step).
  Future<OrderEvent?> advance(String n) { final next = _chain.then((_) => _advanceNow(n), onError: (_) => _advanceNow(n)); _chain = next; return next; }
  Future<OrderEvent?> _advanceNow(String n) async {
    final o = _current[n]; final l = _listeners[n];
    if (o == null) return null;
    final s = await scenarioFor(o);
    final i = nextStepIndex(o, s); if (i < 0) return null;
    final step = scenarioSteps[s]![i];
    final e = eventForStep(o, step);
    final updated = await _orders.applyEvents(n, [e]); if (updated != null) _current[n] = updated;
    if (l == null) return e;
    if (step.dropConnection) { l.onConnection(TrackingConnection.stale); Timer(interval == Duration.zero ? const Duration(milliseconds: 1500) : interval, () { final ll = _listeners[n]; if (ll != null) { ll.onConnection(TrackingConnection.live); ll.onEvent(e); } }); return e; }
    l.onEvent(e); return e;
  }
  /// Development: re-send the last event (must be ignored).
  void injectDuplicate(String n) { final o = _current[n]; final l = _listeners[n]; if (o != null && l != null && o.events.isNotEmpty) l.onEvent(o.events.last); }
  /// Development: send an older event with a fresh id (must not regress the status).
  void injectStale(String n) { final o = _current[n]; final l = _listeners[n]; if (o == null || l == null) return; final old = o.events.where((e) => e.type == OrderEventType.sentToRestaurant).firstOrNull ?? o.events.first; l.onEvent(OrderEvent(eventId: '${old.eventId}-replay', sequence: max(0, old.sequence - 1), type: old.type, status: old.status, paymentStatus: old.paymentStatus, at: old.at, actor: old.actor, reasonKey: old.reasonKey)); }
  @override
  Future<Order?> refresh(String orderNumber, String customerId) async { final o = await _orders.getByOrderNumber(orderNumber, customerId); if (o != null) _current[orderNumber] = o; return o; }
}
