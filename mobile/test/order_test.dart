import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/payment_state.dart';

/// Module 13 — order domain + confirmation state (Android unit tests).
void main() {
  CreateOrderInput input({String attempt = 'pay_dev_a1', String customer = 'u1', OrderPaymentStatus pay = OrderPaymentStatus.paid, String currency = 'INR', int total = 61200}) => CreateOrderInput(
      paymentAttemptId: attempt, checkoutReference: 'ck-1', customerId: customer,
      restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, pickupInstructions: 'Collect at the pickup counter.', pickupLocation: 'Counter pickup'),
      items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [OrderOptionSnapshot(groupName: 'Size', optionName: 'Large', priceAdjustmentMinor: 7000)], modifiers: [OrderOptionSnapshot(groupName: 'Extras', optionName: 'Cheese', priceAdjustmentMinor: 2000)], specialInstructions: 'No onion', quantity: 2, unitPriceMinor: 34000, lineTotalMinor: 68000)],
      pricing: OrderPricing(currency: currency, subtotalMinor: 68000, discountMinor: 6800, promoCode: 'WELCOME10', totalMinor: total),
      payment: OrderPaymentSummary(status: pay, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_dev_ref', paidAmountMinor: total, currency: currency),
      pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 29, 9), estimatedReadyTime: DateTime.utc(2026, 9, 29, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'),
      journey: const JourneySnapshot(journeyId: 'jrn-1', originName: 'Noida', destinationName: 'Agra'), orderNote: 'Please include cutlery');

  (OrderState, MockOrderRepository, MemoryKeyValueStore) make({MemoryKeyValueStore? store}) {
    final st = store ?? MemoryKeyValueStore();
    final repo = MockOrderRepository(st, latency: Duration.zero);
    return (OrderState(orders: repo, verifications: MockPickupVerificationRepository(repo), receipts: MockReceiptRepository(), payments: MockPaymentRepository(st), store: st), repo, st);
  }

  test('TEST 1 / 2 / 3 — order created from a verified payment: public references, separate statuses, snapshot, pickup code + opaque QR token', () async {
    final (state, repo, _) = make();
    final o = await repo.createFromPayment(input());
    expect(o.orderNumber, matches(RegExp(r'^FOTG-[A-Z2-9]{4}-[A-Z2-9]{4}$')));
    expect(o.publicId.length, greaterThan(12));
    expect(o.orderStatus, OrderStatus.confirmed); expect(o.paymentStatus, OrderPaymentStatus.paid);
    expect(o.items.single.allOptions, hasLength(2)); expect(o.pricing.totalMinor, 61200); expect(o.pricing.taxes, isEmpty);
    expect(o.events.map((e) => e.type).toList(), [OrderEventType.orderCreated, OrderEventType.paymentVerified, OrderEventType.orderConfirmed]);
    await state.load(o.orderNumber, customerId: 'u1', customerName: 'Dev');
    expect(state.status, ConfirmationStatus.confirmed);
    final pv = state.verification!;
    expect(pv.code, matches(RegExp(r'^[A-Z2-9]{6}$'))); expect(pv.qrToken, matches(RegExp(r'^pv_dev_[0-9a-f]{32}$')));
    expect(pv.status, PickupVerificationStatus.verificationAvailable);
    final json = pv.toJson().toString();
    expect(json.contains('Classic Burger') || json.contains('pay_dev_ref') || json.contains('9876'), isFalse); // no order / payment / personal data in the token record
    expect(state.receipt!.kind, 'ORDER_RECEIPT'); expect(state.receipt!.customerName, 'Dev');
  });

  test('idempotent — the same payment attempt never creates a second order or pickup code', () async {
    final (_, repo, st) = make();
    final a = await repo.createFromPayment(input(attempt: 'pay_same'));
    final b = await repo.createFromPayment(input(attempt: 'pay_same'));
    expect(b.orderNumber, a.orderNumber);
    expect(await repo.listForCustomer('u1'), hasLength(1));
    expect(await repo.verifications(), hasLength(1));
    expect(await st.read(MockOrderRepository.ordersKey), isNotNull); // persisted for reload
  });

  test('TEST 6 — reload: a fresh state over the same store restores the order without creating anything', () async {
    final store = MemoryKeyValueStore();
    final (first, repo, _) = make(store: store);
    final o = await repo.createFromPayment(input());
    await first.load(o.orderNumber, customerId: 'u1');
    final (again, repo2, _) = make(store: store);
    await again.load(o.orderNumber, customerId: 'u1');
    expect(again.status, ConfirmationStatus.confirmed); expect(again.order!.publicId, o.publicId);
    expect(await repo2.listForCustomer('u1'), hasLength(1));
  });

  test('TEST 4 — payment pending fixture: no confirmed / paid claim, no pickup code', () async {
    final (state, _, _) = make();
    await state.load('FOTG-DEMO-PEND', customerId: 'u1');
    expect(state.status, ConfirmationStatus.paymentPending);
    expect(state.order!.orderStatus, OrderStatus.paymentPending); expect(state.order!.paymentStatus, OrderPaymentStatus.paymentPending);
    expect(state.verification, isNull);
  });

  test('cancelled fixture and TEST 5 — invalid order number', () async {
    final (state, _, _) = make();
    await state.load('FOTG-DEMO-CANC', customerId: 'u1');
    expect(state.status, ConfirmationStatus.cancelled); expect(state.order!.paymentStatus, OrderPaymentStatus.refundPending);
    await state.load('FOTG-NOPE-0000', customerId: 'u1');
    expect(state.status, ConfirmationStatus.orderNotFound);
  });

  test('authorization-ready — another customer\'s order is not found for this account', () async {
    final (state, repo, _) = make();
    final o = await repo.createFromPayment(input(customer: 'someone-else'));
    await state.load(o.orderNumber, customerId: 'u1');
    expect(state.status, ConfirmationStatus.orderNotFound);
  });

  test('failed to load → failedToLoad, retry recovers', () async {
    final (state, repo, _) = make();
    final o = await repo.createFromPayment(input());
    repo.fail = true;
    await state.load(o.orderNumber, customerId: 'u1');
    expect(state.status, ConfirmationStatus.failedToLoad);
    repo.fail = false;
    await state.reload(customerId: 'u1');
    expect(state.status, ConfirmationStatus.confirmed);
  });

  test('pending-<attempt> reference resolves to the created order or to a pending state; never creates anything', () async {
    final store = MemoryKeyValueStore();
    final (state, repo, _) = make(store: store);
    final pays = MockPaymentRepository(store);
    final a = await pays.createAttempt(checkoutReference: 'ck-9', checkoutSnapshot: 's', provider: 'mock-razorpay', currency: 'INR', amountMinor: 100, methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'burger-hub');
    await pays.transition(a.publicId, PaymentStatus.ready); await pays.transition(a.publicId, PaymentStatus.pending);
    await state.load('pending-${a.publicId}', customerId: 'u1');
    expect(state.status, ConfirmationStatus.paymentPending); expect(state.redirectTo, isNull);
    final o = await repo.createFromPayment(input(attempt: a.publicId));
    await state.load('pending-${a.publicId}', customerId: 'u1');
    expect(state.redirectTo, '/order-confirmation/${o.orderNumber}');
    expect(await repo.listForCustomer('u1'), hasLength(1));
  });

  test('TEST 9 / 10 — USD order keeps its own currency and restaurant zone in the snapshot', () async {
    final (state, repo, _) = make();
    final o = await repo.createFromPayment(CreateOrderInput(paymentAttemptId: 'pay_usd', checkoutReference: 'ck-2', customerId: 'u1',
        restaurant: const RestaurantSnapshot(id: 'kettleman-diner', slug: 'route-5-diner', name: 'Route 5 Diner · Καφέ Δρόμος', formattedAddress: '33400 Bernard Dr, Kettleman City, CA 93239, USA', countryCode: 'US', timezone: 'America/Los_Angeles'),
        items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'x', itemName: 'Œufs en meurette — मुंबई 🍳', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 1899, lineTotalMinor: 1899)],
        pricing: const OrderPricing(currency: 'USD', subtotalMinor: 1899, discountMinor: 0, taxes: [PricingLine(id: 'tax', label: 'Sales tax (CA)', amountMinor: 152)], totalMinor: 2051),
        payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'card', methodLabel: 'Credit / debit card', providerDisplayName: 'Payment provider (development sandbox)', reference: 'pay_usd', paidAmountMinor: 2051, currency: 'USD', maskedDetails: 'Card ending in 4242'),
        pickup: PickupSnapshot(mode: 'scheduled', requestedAt: DateTime.utc(2026, 9, 29, 19, 30), estimatedReadyTime: DateTime.utc(2026, 9, 29, 19, 20), restaurantTimezone: 'America/Los_Angeles', methodType: 'drive_through', methodLabel: 'Drive-through pickup'), orderNote: ''));
    await state.load(o.orderNumber, customerId: 'u1');
    expect(state.order!.pricing.currency, 'USD'); expect(state.order!.pickup.restaurantTimezone, 'America/Los_Angeles'); expect(state.order!.pricing.taxes.single.label, 'Sales tax (CA)');
    expect(state.order!.items.single.itemName, contains('मुंबई'));
    expect(state.order!.payment.maskedDetails, 'Card ending in 4242');
  });
}
