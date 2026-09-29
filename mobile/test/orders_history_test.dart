import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/order/order_history.dart';
import 'package:foodonthego/order/order_models.dart';
import 'package:foodonthego/order/order_tracking.dart';

/// Module 15 — history + reorder domain (Android): grouping, paging, search/sort, seeded history, reorder resolution.
void main() {
  Order seed(String n, OrderStatus os, OrderPaymentStatus ps, {String restaurant = 'Burger Hub', String slug = 'burger-hub', int unit = 25000, String item = 'classic-burger', String cur = 'INR', List<OrderOptionSnapshot> variants = const [OrderOptionSnapshot(groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0)], List<OrderOptionSnapshot> modifiers = const [], DateTime? created}) {
    final at = created ?? DateTime.utc(2026, 9, 1, 9);
    return Order(publicId: 'P$n', orderNumber: n, customerId: 'u1',
        restaurant: RestaurantSnapshot(id: slug, slug: slug, name: restaurant, formattedAddress: 'x', countryCode: 'IN', timezone: 'Asia/Kolkata'),
        items: [OrderItemSnapshot(lineId: 'l1', menuItemId: item, itemName: 'Classic Burger', image: '', variants: variants, modifiers: modifiers, specialInstructions: '', quantity: 2, unitPriceMinor: unit, lineTotalMinor: unit * 2)],
        pricing: OrderPricing(currency: cur, subtotalMinor: unit * 2, discountMinor: 0, totalMinor: unit * 2), orderStatus: os, paymentStatus: ps,
        payment: OrderPaymentSummary(status: ps, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'ref', paidAmountMinor: unit * 2, currency: cur),
        pickup: PickupSnapshot(mode: 'asap', requestedAt: at, estimatedReadyTime: at, restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'),
        pickupCodeReference: 'pv', paymentAttemptId: 'pa$n', checkoutReference: 'ck', orderNote: '', events: const [], lastEventSequence: 0, createdAt: at, updatedAt: at);
  }

  test('grouping: ongoing / completed / cancelled are UI mappings of the order status; payment status stays separate', () {
    expect(groupOf(OrderStatus.paymentPending), OrderGroup.ongoing); expect(groupOf(OrderStatus.preparing), OrderGroup.ongoing); expect(groupOf(OrderStatus.pickupVerification), OrderGroup.ongoing);
    expect(groupOf(OrderStatus.pickedUp), OrderGroup.completed); expect(groupOf(OrderStatus.completed), OrderGroup.completed);
    expect(groupOf(OrderStatus.cancelled), OrderGroup.cancelled); expect(groupOf(OrderStatus.rejected), OrderGroup.cancelled); expect(groupOf(OrderStatus.refunded), OrderGroup.cancelled);
    expect(isTrackable(OrderStatus.paymentPending), isFalse); expect(isTrackable(OrderStatus.readyForPickup), isTrue); expect(isTrackable(OrderStatus.completed), isFalse);
    expect(isReviewable(OrderStatus.completed), isTrue); expect(isReviewable(OrderStatus.cancelled), isFalse);
    expect(hasRefund(OrderPaymentStatus.partiallyRefunded), isTrue); expect(hasRefund(OrderPaymentStatus.paid), isFalse);
    final s = OrderSummary.of(seed('FOTG-1', OrderStatus.completed, OrderPaymentStatus.paid));
    expect(s.itemCount, 2); expect(s.itemPreview, 'Classic Burger × 2'); expect(s.reorderEligible, isTrue);
    expect(OrderSummary.of(seed('FOTG-2', OrderStatus.preparing, OrderPaymentStatus.paid)).reorderEligible, isFalse);
    expect(OrderSummary.of(seed('FOTG-3', OrderStatus.paymentPending, OrderPaymentStatus.paymentPending)).reorderEligible, isFalse);
  });

  test('pageSummaries: filter by group, search by order number or restaurant, sort, cursor paging', () {
    final all = [for (var i = 0; i < 12; i++) OrderSummary.of(seed('FOTG-${i.toString().padLeft(4, '0')}', i % 3 == 0 ? OrderStatus.completed : i % 3 == 1 ? OrderStatus.preparing : OrderStatus.cancelled, OrderPaymentStatus.paid, restaurant: i.isEven ? 'Burger Hub' : 'Route 5 Diner', created: DateTime.utc(2026, 9, 1 + i)))];
    final p1 = pageSummaries(all, const OrderListQuery());
    expect(p1.items.length, 5); expect(p1.total, 12); expect(p1.nextCursor, '5'); expect(p1.items.first.orderNumber, 'FOTG-0011');
    final p2 = pageSummaries(all, OrderListQuery(cursor: p1.nextCursor)); expect(p2.items.length, 5); expect(p2.nextCursor, '10');
    final p3 = pageSummaries(all, OrderListQuery(cursor: p2.nextCursor)); expect(p3.items.length, 2); expect(p3.nextCursor, isNull);
    expect(pageSummaries(all, const OrderListQuery(sort: OrderSort.oldest)).items.first.orderNumber, 'FOTG-0000');
    expect(pageSummaries(all, const OrderListQuery(group: OrderGroup.ongoing)).total, 4);
    expect(pageSummaries(all, const OrderListQuery(group: OrderGroup.cancelled)).total, 4);
    expect(pageSummaries(all, const OrderListQuery(query: 'route 5')).total, 6);
    expect(pageSummaries(all, const OrderListQuery(query: 'fotg-0007')).total, 1);
    expect(pageSummaries(all, const OrderListQuery(query: 'zzz')).total, 0);
    expect(pageSummaries(all, const OrderListQuery(cursor: '999')).items, isEmpty);
  });

  test('MockOrderRepository.listSummaries pages the customer\'s orders only; seedDemoHistory is idempotent and mixes currencies / refunds', () async {
    final repo = MockOrderRepository(MemoryKeyValueStore(), latency: Duration.zero);
    expect((await repo.listSummaries('u1', const OrderListQuery())).total, 0);
    expect(await repo.seedDemoHistory('u1', 12), 12); expect(await repo.seedDemoHistory('u1', 12), 0);
    final page = await repo.listSummaries('u1', const OrderListQuery());
    expect(page.total, 12); expect(page.items.length, 5); expect(page.items.first.orderNumber, 'FOTG-SEED-0001');
    expect((await repo.listSummaries('someone-else', const OrderListQuery())).total, 0);
    final all = await repo.listForCustomer('u1');
    expect(all.map((o) => o.pricing.currency).toSet(), containsAll(['INR', 'USD', 'EUR', 'JPY']));
    final partial = all.firstWhere((o) => o.paymentStatus == OrderPaymentStatus.partiallyRefunded);
    expect(partial.payment.refundedAmountMinor, lessThan(partial.payment.paidAmountMinor));
    final full = all.firstWhere((o) => o.paymentStatus == OrderPaymentStatus.refunded);
    expect(full.payment.refundedAmountMinor, full.payment.paidAmountMinor);
    // Snapshot round-trips the refunded amount.
    expect(Order.fromJson(partial.toJson()).payment.refundedAmountMinor, partial.payment.refundedAmountMinor);
    // Authorization: details are only served to the owner.
    expect(await repo.getByOrderNumber('FOTG-SEED-0001', 'someone-else'), isNull);
    expect((await repo.getByOrderNumber('FOTG-SEED-0001', 'u1'))!.items.first.itemName, 'Classic Burger');
    repo.fail = true; expect(() => repo.listSummaries('u1', const OrderListQuery()), throwsStateError);
  });

  test('reducer: a full REFUNDED update records the refunded amount = paid amount (provider-authoritative later)', () {
    final o = seed('FOTG-R', OrderStatus.cancelled, OrderPaymentStatus.refundPending);
    final next = reduceOrder(o, OrderEvent(eventId: 'e1', sequence: 1, type: OrderEventType.refundUpdated, status: null, paymentStatus: OrderPaymentStatus.refunded, at: DateTime.utc(2026, 9, 2), actor: 'system')).order;
    expect(next.paymentStatus, OrderPaymentStatus.refunded); expect(next.payment.refundedAmountMinor, 50000);
  });

  group('MockReorderService', () {
    final svc = MockReorderService(restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), latency: Duration.zero);
    test('price changed → new cart input carries the CURRENT price, old price kept for display', () async {
      final plan = await svc.plan(seed('FOTG-A', OrderStatus.completed, OrderPaymentStatus.paid, unit: 20000));
      expect(plan.restaurantStatus, ReorderRestaurantStatus.ok); expect(plan.currencyChanged, isFalse);
      final l = plan.lines.single; expect(l.status, ReorderLineStatus.priceChanged); expect(l.oldUnitMinor, 20000); expect(l.newUnitMinor, 25000);
      expect(l.input, isNotNull); expect(l.input!.unitPriceMinor, 25000); expect(l.input!.quantity, 2); expect(l.input!.selectedVariants.single.optionName, 'Regular');
      expect(plan.addable.length, 1); expect(plan.reviewCount, 1);
    });
    test('same price → ok; sold-out modifier → modifier_missing (no input); sold-out item → unavailable', () async {
      final ok = await svc.plan(seed('FOTG-B', OrderStatus.completed, OrderPaymentStatus.paid));
      expect(ok.lines.single.status, ReorderLineStatus.ok);
      final mm = await svc.plan(seed('FOTG-C', OrderStatus.completed, OrderPaymentStatus.paid, modifiers: const [OrderOptionSnapshot(groupName: 'Add-ons', optionName: 'Fried Egg', priceAdjustmentMinor: 4000)]));
      expect(mm.lines.single.status, ReorderLineStatus.modifierMissing); expect(mm.lines.single.missingOptions, ['Add-ons: Fried Egg']); expect(mm.lines.single.input, isNull);
      final un = await svc.plan(seed('FOTG-D', OrderStatus.completed, OrderPaymentStatus.paid, item: 'chicken-wings'));
      expect(un.lines.single.status, ReorderLineStatus.unavailable); expect(un.unavailableCount, 1); expect(un.addable, isEmpty);
      final missingVariant = await svc.plan(seed('FOTG-E', OrderStatus.completed, OrderPaymentStatus.paid, variants: const []));
      expect(missingVariant.lines.single.status, ReorderLineStatus.modifierMissing); expect(missingVariant.lines.single.missingOptions, ['Size: —']);
    });
    test('restaurant no longer on the platform → not_found, every line unavailable', () async {
      final plan = await svc.plan(seed('FOTG-F', OrderStatus.completed, OrderPaymentStatus.paid, slug: 'gone-restaurant', restaurant: 'Gone'));
      expect(plan.restaurantStatus, ReorderRestaurantStatus.notFound); expect(plan.restaurantName, 'Gone'); expect(plan.addable, isEmpty);
    });
  });
}
