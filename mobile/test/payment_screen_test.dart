import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/screens/order_screens.dart';
import 'package:foodonthego/screens/payment_screen.dart';
import 'package:foodonthego/state/account_state.dart' hide MockPaymentMethodRepository;
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart' hide PromoStatus, PromoState;
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/payment_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 12 — Payment screen (Android widget tests): success handoff, failure actions, back button while processing.
void main() {
  final now = DateTime.now().toUtc();
  AddItemInput burger() => const AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: 2, unitPriceMinor: 25000);
  CheckoutRequest req() => CheckoutRequest(idempotencyKey: 'ck-w1', customerId: 'u1', cartId: 'cart-1', restaurantId: 'burger-hub', pickupSelection: PickupSelection(mode: PickupMode.asap, requestedAt: now.add(const Duration(minutes: 20)), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: now.add(const Duration(minutes: 20)), cartId: 'cart-1', restaurantId: 'burger-hub'), currency: 'INR', orderNote: '', termsAccepted: true, termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', acceptedAt: now, paymentMethodId: 'upi', displayedTotalMinor: 50000, createdAt: now);

  Widget app(CartState cart, CheckoutState co, PaymentState pay, {String? mock}) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => OrdersState()),
          ChangeNotifierProvider(create: (_) => PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore())),
          ChangeNotifierProvider.value(value: co),
          ChangeNotifierProvider.value(value: pay),
        ],
        child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/payment', routes: [
          GoRoute(path: '/payment', builder: (_, _) => PaymentScreen(mockOutcome: mock, restaurantRepository: MockRestaurantRepository(latency: Duration.zero))),
          GoRoute(path: '/order-confirmation/:number', builder: (_, s) => OrderConfirmationScreen(number: s.pathParameters['number']!)),
          GoRoute(path: '/checkout', builder: (_, _) => const Scaffold(body: Text('checkout screen'))),
          GoRoute(path: '/', builder: (_, _) => const Scaffold(body: Text('home'))),
        ])),
      );
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 2400); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  Future<(CartState, CheckoutState, PaymentState, MockPaymentProviderResolver)> states({Duration timeout = const Duration(seconds: 8)}) async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger());
    final co = CheckoutState(promotions: MockPromotionRepository(latency: Duration.zero), payments: MockPaymentMethodRepository(latency: Duration.zero), checkout: MockCheckoutRepository(latency: Duration.zero), connectivity: MockConnectivityService(), restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), store: MemoryKeyValueStore());
    co.request = req();
    final resolver = MockPaymentProviderResolver(latency: Duration.zero);
    final store = MemoryKeyValueStore();
    final pay = PaymentState(resolver: resolver, repository: MockPaymentRepository(store), verifier: MockPaymentVerificationService(latency: Duration.zero), store: store, providerTimeout: timeout);
    return (cart, co, pay, resolver);
  }

  testWidgets('TEST 1 — summary, method, Pay button; success → verified → handoff card (no order claims)', (t) async {
    tall(t);
    final (cart, co, pay, _) = await states();
    await t.pumpWidget(app(cart, co, pay, mock: 'success')); await t.pumpAndSettle();
    expect(find.text('Payment summary'), findsOneWidget);
    expect(find.textContaining('Burger Hub'), findsWidgets);
    expect(find.textContaining('₹500.00'), findsWidgets);
    expect(find.textContaining('INR'), findsWidgets);
    expect(find.text('Ready to pay'), findsOneWidget);
    expect(find.byType(TextField), findsNothing); // no card / CVV / UPI PIN inputs
    expect(find.textContaining('Cash at pickup'), findsNothing);
    await t.tap(find.textContaining('Pay ₹500.00')); await t.pump();
    await t.pumpAndSettle();
    expect(find.text('Payment confirmed'), findsWidgets);
    await t.pump(const Duration(milliseconds: 1600)); await t.pumpAndSettle();
    expect(find.textContaining('Order creation and the confirmation page arrive with Module 13'), findsOneWidget);
    expect(find.textContaining('Payment reference'), findsOneWidget);
    expect(find.text('Order Confirmed'), findsNothing);
    expect(await pay.repo.listForCheckout('ck-w1'), hasLength(1));
  });

  testWidgets('TEST 2 / 8 — failure shows Retry, method choice and Back to checkout; cart kept', (t) async {
    tall(t);
    final (cart, co, pay, _) = await states();
    await t.pumpWidget(app(cart, co, pay, mock: 'failure')); await t.pumpAndSettle();
    await t.tap(find.textContaining('Pay ₹500.00')); await t.pumpAndSettle();
    expect(find.text('Payment failed'), findsOneWidget);
    expect(find.text('Retry payment'), findsOneWidget);
    expect(find.text('Back to checkout'), findsOneWidget);
    expect(find.text('Choose a payment method'), findsOneWidget);
    expect(cart.cart!.items, hasLength(1)); expect(co.request, isNotNull);
    await t.tap(find.text('Credit / debit card')); await t.pumpAndSettle();
    expect(find.text('Ready to pay'), findsOneWidget);
    expect(pay.attempt!.methodId, 'card');
    expect(await pay.repo.listForCheckout('ck-w1'), hasLength(2));
  });

  testWidgets('TEST 4 — pending: do-not-pay-again warning and Check payment status', (t) async {
    tall(t);
    final (cart, co, pay, _) = await states();
    await t.pumpWidget(app(cart, co, pay, mock: 'pending')); await t.pumpAndSettle();
    await t.tap(find.textContaining('Pay ₹500.00')); await t.pumpAndSettle();
    expect(find.text('Payment pending'), findsWidgets);
    expect(find.textContaining('Do not pay again'), findsOneWidget);
    expect(find.text('Check payment status'), findsOneWidget);
    expect(find.textContaining('Pay ₹500.00'), findsNothing);
  });

  testWidgets('TEST 12 — back button while processing is intercepted; cancelling ends the attempt safely', (t) async {
    tall(t);
    final (cart, co, pay, _) = await states();
    await t.pumpWidget(app(cart, co, pay, mock: 'timeout')); await t.pumpAndSettle();
    await t.tap(find.textContaining('Pay ₹500.00')); await t.pump(const Duration(milliseconds: 50));
    expect(find.text('Waiting for payment…'), findsWidgets);
    final NavigatorState nav = t.state(find.byType(Navigator).first);
    await nav.maybePop(); await t.pump(const Duration(milliseconds: 300));
    expect(find.text('Payment in progress'), findsOneWidget);
    await t.tap(find.text('Cancel payment').last); await t.pumpAndSettle();
    expect(find.text('Payment cancelled'), findsOneWidget);
    expect(pay.status, PaymentStatus.cancelled);
    expect(find.text('Try again'), findsOneWidget);
  });
}
