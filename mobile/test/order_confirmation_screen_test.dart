import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/screens/order_confirmation_screen.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/payment_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// Module 13 — Order confirmation screen (Android widget tests): confirmed order, QR + code, Track order, pending, not found.
void main() {
  CreateOrderInput input({String customer = 'cust-rahul'}) => CreateOrderInput(
      paymentAttemptId: 'pay_dev_w1', checkoutReference: 'ck-1', customerId: customer,
      restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, pickupInstructions: 'Collect at the pickup counter next to the entrance.', pickupLocation: 'Counter pickup'),
      items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [OrderOptionSnapshot(groupName: 'Size', optionName: 'Large', priceAdjustmentMinor: 7000)], modifiers: [], specialInstructions: 'No onion', quantity: 2, unitPriceMinor: 32000, lineTotalMinor: 64000)],
      pricing: const OrderPricing(currency: 'INR', subtotalMinor: 64000, discountMinor: 6400, promoCode: 'WELCOME10', totalMinor: 57600),
      payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_dev_ref', paidAmountMinor: 57600, currency: 'INR'),
      pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 29, 9), estimatedReadyTime: DateTime.utc(2026, 9, 29, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'),
      journey: const JourneySnapshot(journeyId: 'j', originName: 'Noida', destinationName: 'Agra'), orderNote: 'Please include cutlery');

  Future<AuthState> signedIn(WidgetTester t) async {
    final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
    await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); });
    return auth;
  }
  (OrderState, MockOrderRepository) states() { final st = MemoryKeyValueStore(); final repo = MockOrderRepository(st, latency: Duration.zero); return (OrderState(orders: repo, verifications: MockPickupVerificationRepository(repo), receipts: MockReceiptRepository(), payments: MockPaymentRepository(st), store: st), repo); }
  Widget app(OrderState os, AuthState auth, String number) => MultiProvider(providers: [ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os)], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/order-confirmation/$number', routes: [
        GoRoute(path: '/order-confirmation/:number', builder: (_, s) => OrderConfirmationScreen(number: s.pathParameters['number']!)),
        GoRoute(path: '/order-tracking/:number', builder: (_, _) => const Scaffold(body: Text('tracking screen'))),
        GoRoute(path: '/payment', builder: (_, _) => const Scaffold(body: Text('payment screen'))),
        GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))),
        GoRoute(path: '/help', builder: (_, _) => const Scaffold(body: Text('help'))),
      ])));
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 4600); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }

  testWidgets('TEST 1 / 2 / 3 / 13 — confirmed order: number, statuses, QR + code, pickup, restaurant, items, pricing, payment, receipt', (t) async {
    tall(t);
    final (os, repo) = states(); final o = await repo.createFromPayment(input()); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, o.orderNumber)); await t.pumpAndSettle();
    expect(find.text(o.orderNumber), findsWidgets);
    expect(find.textContaining('Order status: Confirmed'), findsOneWidget);
    expect(find.textContaining('Payment status: Paid'), findsOneWidget);
    expect(find.byType(QrImageView), findsOneWidget);
    expect(t.getSize(find.byType(QrImageView)).width, greaterThanOrEqualTo(200));
    final pv = os.verification!;
    expect(find.text(pv.code), findsOneWidget);
    expect(find.text('Burger Hub'), findsWidgets);
    expect(find.textContaining('Classic Burger × 2'), findsOneWidget);
    expect(find.textContaining('Size: Large'), findsOneWidget);
    expect(find.textContaining('₹576.00'), findsWidgets);
    expect(find.textContaining('GST'), findsNothing);
    expect(find.text('pay_dev_ref'), findsOneWidget);
    expect(find.text('Track order'), findsOneWidget);
    await t.tap(find.text('View receipt')); await t.pumpAndSettle();
    expect(find.text('ORDER RECEIPT (development)'), findsOneWidget);
    expect(find.textContaining('Not a tax invoice'), findsOneWidget);
  });

  testWidgets('TEST 7 — Track order navigates to /order-tracking/:number', (t) async {
    tall(t);
    final (os, repo) = states(); final o = await repo.createFromPayment(input()); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, o.orderNumber)); await t.pumpAndSettle();
    await t.tap(find.text('Track order')); await t.pumpAndSettle();
    expect(find.text('tracking screen'), findsOneWidget);
  });

  testWidgets('TEST 4 — payment pending fixture: no confirmed / paid claim, Check payment status', (t) async {
    tall(t);
    final (os, _) = states(); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, 'FOTG-DEMO-PEND')); await t.pumpAndSettle();
    expect(find.text('Payment confirmation is still in progress'), findsOneWidget);
    expect(find.text('Check payment status'), findsOneWidget);
    expect(find.byType(QrImageView), findsNothing);
    expect(find.textContaining('Payment status: Paid'), findsNothing);
  });

  testWidgets('TEST 5 — invalid order: not-found state with recovery actions', (t) async {
    tall(t);
    final (os, _) = states(); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, 'FOTG-NOPE-0000')); await t.pumpAndSettle();
    expect(find.text("We couldn't find that order"), findsOneWidget);
    expect(find.text('Continue browsing'), findsOneWidget);
  });
}
