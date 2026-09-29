import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/checkout/checkout_models.dart' show MockConnectivityService;
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/screens/order_tracking_screen.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/tracking_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// Module 14 — tracking screen (Android widget tests): normal progression, ready + QR, rejected, offline, payment pending.
void main() {
  CreateOrderInput input() => CreateOrderInput(
      paymentAttemptId: 'pay_w_${DateTime.now().microsecondsSinceEpoch}', checkoutReference: 'ck', customerId: 'cust-rahul',
      restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, pickupInstructions: 'Collect at the pickup counter next to the entrance.', pickupLocation: 'Counter pickup'),
      items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'x', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000)],
      pricing: const OrderPricing(currency: 'INR', subtotalMinor: 50000, discountMinor: 0, totalMinor: 50000),
      payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_ref', paidAmountMinor: 50000, currency: 'INR'),
      pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 29, 9), estimatedReadyTime: DateTime.utc(2026, 9, 29, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', estimatedCustomerArrival: DateTime.utc(2026, 9, 29, 8, 50)),
      journey: const JourneySnapshot(journeyId: 'j', originName: 'Noida', destinationName: 'Agra', originLat: 28.5355, originLng: 77.391), orderNote: '');

  Future<AuthState> signedIn(WidgetTester t) async { final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero)); await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }); return auth; }
  (TrackingState, MockOrderRepository, MockOrderTrackingService, MockConnectivityService) states(TrackingScenario sc) {
    final st = MemoryKeyValueStore(); final repo = MockOrderRepository(st, latency: Duration.zero); final svc = MockOrderTrackingService(repo, st, interval: Duration.zero)..defaultScenario = sc; final net = MockConnectivityService();
    return (TrackingState(orders: repo, verifications: MockPickupVerificationRepository(repo), tracking: svc, connectivity: net, store: st), repo, svc, net);
  }
  Widget app(TrackingState tr, AuthState auth, String number) => MultiProvider(providers: [ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: tr), ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero)))], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/order-tracking/$number', routes: [
        GoRoute(path: '/order-tracking/:number', builder: (_, s) => OrderTrackingScreen(number: s.pathParameters['number']!)),
        GoRoute(path: '/order/:number', builder: (_, _) => const Scaffold(body: Text('details screen'))),
        GoRoute(path: '/payment', builder: (_, _) => const Scaffold(body: Text('payment screen'))),
      ])));
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 5200); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }

  testWidgets('TEST 1 — status header, separate statuses, timeline advances through the normal flow to completed actions', (t) async {
    tall(t);
    final (tr, repo, _, _) = states(TrackingScenario.normal); final o = await repo.createFromPayment(input()); final auth = await signedIn(t);
    await t.pumpWidget(app(tr, auth, o.orderNumber)); await t.pumpAndSettle();
    expect(find.text('Order confirmed'), findsOneWidget); expect(find.text('Live updates'), findsOneWidget);
    expect(find.text('Paid'), findsWidgets); expect(find.text(o.orderNumber), findsOneWidget);
    await tr.advance(); await t.pumpAndSettle(); expect(find.text('Waiting for the restaurant'), findsOneWidget);
    await tr.advance(); await tr.advance(); await t.pumpAndSettle(); expect(find.text('Your order is being prepared'), findsOneWidget);
    expect(find.textContaining('%'), findsNothing);
    await tr.advance(); await t.pumpAndSettle(); expect(find.text('Ready for pickup'), findsWidgets); expect(find.text('Your order is ready'), findsOneWidget); expect(find.byType(QrImageView), findsOneWidget);
    await tr.advance(); await tr.advance(); await tr.advance(); await t.pumpAndSettle();
    expect(find.text('Order completed'), findsWidgets); expect(find.text('Final status'), findsOneWidget); expect(find.byType(QrImageView), findsNothing);
    expect(find.text('View order'), findsOneWidget); expect(find.text('Reorder'), findsOneWidget);
  });

  testWidgets('TEST 2 / 3 — delay notice with updated ETA; rejected stops the timeline', (t) async {
    tall(t);
    final (tr, repo, _, _) = states(TrackingScenario.delay); final o = await repo.createFromPayment(input()); final auth = await signedIn(t);
    await t.pumpWidget(app(tr, auth, o.orderNumber)); await t.pumpAndSettle();
    for (var i = 0; i < 4; i++) { await tr.advance(); }
    await t.pumpAndSettle();
    expect(find.textContaining('taking a little longer'), findsOneWidget); expect(find.textContaining('high demand'), findsOneWidget); expect(find.textContaining('New estimated ready time'), findsOneWidget);
    final (tr2, repo2, _, _) = states(TrackingScenario.rejected); final o2 = await repo2.createFromPayment(input());
    await t.pumpWidget(app(tr2, auth, o2.orderNumber)); await t.pumpAndSettle();
    await tr2.advance(); await tr2.advance(); await t.pumpAndSettle();
    expect(find.text('Order could not be accepted'), findsOneWidget); expect(find.text('Refund pending'), findsWidgets); expect(find.byType(QrImageView), findsNothing);
  });

  testWidgets('TEST 10 / 11 — offline banner with last known status; reconnect refreshes', (t) async {
    tall(t);
    final (tr, repo, svc, net) = states(TrackingScenario.normal); final o = await repo.createFromPayment(input()); final auth = await signedIn(t);
    await t.pumpWidget(app(tr, auth, o.orderNumber)); await t.pumpAndSettle();
    await tr.advance(); await tr.advance(); await t.pumpAndSettle();
    net.online = false; await tr.connectivityChanged(); await t.pumpAndSettle();
    expect(find.textContaining('You are offline'), findsOneWidget); expect(find.text('Restaurant accepted your order'), findsOneWidget);
    await svc.advance(o.orderNumber);
    net.online = true; await tr.refresh(); await t.pumpAndSettle();
    expect(find.text('Live updates'), findsOneWidget); expect(find.text('Your order is being prepared'), findsOneWidget);
  });

  testWidgets('TEST 5 — payment-pending fixture: no fulfilment progress, check payment status', (t) async {
    tall(t);
    final (tr, _, _, _) = states(TrackingScenario.normal); final auth = await signedIn(t);
    await t.pumpWidget(app(tr, auth, 'FOTG-DEMO-PEND')); await t.pumpAndSettle();
    expect(find.text('Waiting for payment confirmation'), findsOneWidget); expect(find.text('Check payment status'), findsOneWidget); expect(find.byType(QrImageView), findsNothing);
  });

  testWidgets('invalid order → not-found state', (t) async {
    tall(t);
    final (tr, _, _, _) = states(TrackingScenario.normal); final auth = await signedIn(t);
    await t.pumpWidget(app(tr, auth, 'FOTG-NOPE-0000')); await t.pumpAndSettle();
    expect(find.text("We couldn't find that order"), findsOneWidget);
  });
}
