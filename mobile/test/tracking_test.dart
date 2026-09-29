import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/checkout/checkout_models.dart' show MockConnectivityService;
import 'package:foodonthego/state/tracking_state.dart';

/// Module 14 — event reducer, timeline, deterministic scenarios, tracking state (Android unit tests).
void main() {
  CreateOrderInput input({OrderPaymentStatus pay = OrderPaymentStatus.paid}) => CreateOrderInput(
      paymentAttemptId: 'pay_${DateTime.now().microsecondsSinceEpoch}', checkoutReference: 'ck', customerId: 'u1',
      restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Noida', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652),
      items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'x', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, lineTotalMinor: 25000)],
      pricing: const OrderPricing(currency: 'INR', subtotalMinor: 25000, discountMinor: 0, totalMinor: 25000),
      payment: OrderPaymentSummary(status: pay, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_ref', paidAmountMinor: pay == OrderPaymentStatus.paid ? 25000 : 0, currency: 'INR'),
      pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 29, 9), estimatedReadyTime: DateTime.utc(2026, 9, 29, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', estimatedCustomerArrival: DateTime.utc(2026, 9, 29, 8, 50)),
      journey: const JourneySnapshot(journeyId: 'j', originName: 'Noida', destinationName: 'Agra', originLat: 28.5355, originLng: 77.391), orderNote: '');
  Order run(TrackingScenario sc, Order o) { var cur = o; for (final step in scenarioSteps[sc]!) { cur = reduceOrder(cur, eventForStep(cur, step)).order; } return cur; }
  (MockOrderRepository, MemoryKeyValueStore) repo() { final st = MemoryKeyValueStore(); return (MockOrderRepository(st, latency: Duration.zero), st); }

  test('TEST 1 — normal flow: Confirmed → Awaiting → Accepted → Preparing → Ready → Verification → Picked up → Completed; full history kept', () async {
    final (r, _) = repo(); final o = await r.createFromPayment(input());
    expect(o.orderStatus, OrderStatus.confirmed); expect(o.paymentStatus, OrderPaymentStatus.paid); expect(o.lastEventSequence, 3);
    final statuses = <OrderStatus>[]; var cur = o;
    for (final step in scenarioSteps[TrackingScenario.normal]!) { cur = reduceOrder(cur, eventForStep(cur, step)).order; statuses.add(cur.orderStatus); }
    expect(statuses, [OrderStatus.awaitingRestaurantAcceptance, OrderStatus.accepted, OrderStatus.preparing, OrderStatus.readyForPickup, OrderStatus.pickupVerification, OrderStatus.pickedUp, OrderStatus.completed]);
    expect(cur.events, hasLength(10)); expect(cur.pickupVerificationStatus, PickupVerificationStatus.verified); expect(cur.paymentStatus, OrderPaymentStatus.paid);
    expect(timelineFor(cur).every((s) => s.state == StageState.done), isTrue);
  });

  test('TEST 8 / 9 — duplicate and stale events are ignored; terminal orders refuse fulfilment events', () async {
    final (r, _) = repo(); final o = await r.createFromPayment(input());
    final e = eventForStep(o, scenarioSteps[TrackingScenario.normal]![0]);
    final once = reduceOrder(o, e); final twice = reduceOrder(once.order, e);
    expect(once.applied, isTrue); expect(twice.applied, isFalse); expect(twice.reason, 'duplicate');
    final ready = run(TrackingScenario.ready, o);
    final stale = reduceOrder(ready, OrderEvent(eventId: 'replay', sequence: 4, type: OrderEventType.sentToRestaurant, status: OrderStatus.awaitingRestaurantAcceptance, at: DateTime.now().toUtc(), actor: 'system'));
    expect(stale.applied, isFalse); expect(stale.reason, 'stale'); expect(stale.order.orderStatus, OrderStatus.readyForPickup);
    final rejected = run(TrackingScenario.rejected, o);
    final after = reduceOrder(rejected, eventForStep(rejected, scenarioSteps[TrackingScenario.normal]![2]));
    expect(after.applied, isFalse); expect(after.reason, 'terminal'); expect(after.order.orderStatus, OrderStatus.rejected);
  });

  test('TEST 2 — delay keeps preparing, flags a customer-safe reason and shifts only the ready ETA', () async {
    final (r, _) = repo(); final o = await r.createFromPayment(input());
    var cur = o; for (final step in scenarioSteps[TrackingScenario.delay]!.take(4)) { cur = reduceOrder(cur, eventForStep(cur, step)).order; }
    expect(cur.orderStatus, OrderStatus.preparing); expect(cur.delayed, isTrue); expect(cur.delayReasonKey, 'high_demand');
    expect(cur.etaReadyAt!.difference(o.etaReadyAt!), const Duration(minutes: 15));
    expect(cur.pickup.estimatedCustomerArrival, DateTime.utc(2026, 9, 29, 8, 50));
    expect(timelineFor(cur).firstWhere((s) => s.key == StageKey.preparing).note, 'delayed');
  });

  test('TEST 3 / 4 — rejected stops the timeline with refund pending; cancelled after acceptance keeps refund status separate', () async {
    final (r, _) = repo(); final o = await r.createFromPayment(input());
    final rej = run(TrackingScenario.rejected, o);
    expect(rej.orderStatus, OrderStatus.rejected); expect(rej.paymentStatus, OrderPaymentStatus.refundPending); expect(rej.rejectionReasonKey, 'item_unavailable');
    final tl = timelineFor(rej); expect(tl.firstWhere((s) => s.key == StageKey.accepted).state, StageState.stopped); expect(tl.where((s) => s.state == StageState.done).map((s) => s.key), [StageKey.placed, StageKey.payment]);
    final can = run(TrackingScenario.cancelled, o);
    expect(can.orderStatus, OrderStatus.cancelled); expect(can.paymentStatus, OrderPaymentStatus.refunded); expect(can.cancellationReasonKey, 'restaurant_unavailable');
    expect(timelineFor(can).firstWhere((s) => s.key == StageKey.preparing).note, 'cancelled');
  });

  test('TEST 5 — payment pending has no fulfilment steps', () async {
    final (r, _) = repo(); final o = await r.createFromPayment(input(pay: OrderPaymentStatus.paymentPending));
    expect(o.orderStatus, OrderStatus.paymentPending); expect(scenarioSteps[TrackingScenario.paymentPending], isEmpty);
    expect(timelineFor(o).firstWhere((s) => s.key == StageKey.payment).state, StageState.current);
  });

  test('TEST 6 / 7 — ready and picked-up verification states', () async {
    final (r, _) = repo(); final o = await r.createFromPayment(input());
    expect(run(TrackingScenario.ready, o).pickupVerificationStatus, PickupVerificationStatus.ready);
    final p = run(TrackingScenario.pickedUp, o); expect(p.orderStatus, OrderStatus.pickedUp); expect(p.pickupVerificationStatus, PickupVerificationStatus.verified);
    expect(pickupVisible(p), isTrue); expect(pickupVisible(run(TrackingScenario.rejected, o)), isFalse);
  });

  test('mock service: deterministic advance persisted via the repository; script ends; duplicates / stale via the state are counted, status unchanged', () async {
    final (r, st) = repo(); final o = await r.createFromPayment(input());
    final svc = MockOrderTrackingService(r, st, interval: Duration.zero);
    final tr = TrackingState(orders: r, verifications: MockPickupVerificationRepository(r), tracking: svc, connectivity: MockConnectivityService(), store: st);
    await tr.load(o.orderNumber, customerId: 'u1');
    expect(tr.connection, TrackingConnection.live);
    for (var i = 0; i < 7; i++) { await tr.advance(); }
    expect(tr.order!.orderStatus, OrderStatus.completed); expect(tr.connection, TrackingConnection.completed);
    expect((await r.getByOrderNumber(o.orderNumber, 'u1'))!.events, hasLength(10));
    tr.injectDuplicate(); tr.injectStale();
    expect(tr.ignoredDuplicates + tr.ignoredStale, 2); expect(tr.order!.orderStatus, OrderStatus.completed);
    await tr.advance(); expect(tr.order!.events, hasLength(10));
  });

  test('TEST 10 / 11 / 15 — offline shows the last known status; going online / resume refreshes to the current state', () async {
    final (r, st) = repo(); final o = await r.createFromPayment(input());
    final svc = MockOrderTrackingService(r, st, interval: Duration.zero); final net = MockConnectivityService();
    final tr = TrackingState(orders: r, verifications: MockPickupVerificationRepository(r), tracking: svc, connectivity: net, store: st);
    await tr.load(o.orderNumber, customerId: 'u1'); await tr.advance(); await tr.advance();
    expect(tr.order!.orderStatus, OrderStatus.accepted);
    net.online = false; await tr.connectivityChanged();
    expect(tr.connection, TrackingConnection.offline); expect(tr.order!.orderStatus, OrderStatus.accepted);
    await svc.advance(o.orderNumber); // moves on "server-side" while offline
    expect(tr.order!.orderStatus, OrderStatus.accepted);
    net.online = true; await tr.refresh();
    expect(tr.connection, TrackingConnection.live); expect(tr.order!.orderStatus, OrderStatus.preparing);
    final before = tr.order!.events.length; await tr.refresh(); expect(tr.order!.events.length, before); // idempotent
  });

  test('network_loss scenario reports stale then live around the accepted event', () async {
    final (r, st) = repo(); final o = await r.createFromPayment(input());
    final svc = MockOrderTrackingService(r, st, interval: Duration.zero)..defaultScenario = TrackingScenario.networkLoss;
    final conns = <TrackingConnection>[]; final seen = <OrderEventType>[];
    svc.subscribe(o, onEvent: (e) => seen.add(e.type), onConnection: conns.add);
    await svc.advance(o.orderNumber); await svc.advance(o.orderNumber);
    expect(conns, [TrackingConnection.live, TrackingConnection.stale]);
    await Future<void>.delayed(const Duration(milliseconds: 1700));
    expect(conns.last, TrackingConnection.live); expect(seen, [OrderEventType.sentToRestaurant, OrderEventType.restaurantAccepted]);
  });

  test('TEST 13 — distance helper', () { final m = distanceMeters(28.5355, 77.391, 28.6285, 77.3652); expect(m, greaterThan(10000)); expect(m, lessThan(11500)); });
}
