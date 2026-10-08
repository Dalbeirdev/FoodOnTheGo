/// The payment screen on the backend (Module 30) — Android widget tests: the amount is the backend's, the provider's
/// outcome is only reported, VERIFIED shows "order creation is pending" and no order confirmation, a failure offers a
/// new attempt, a phone that kept nothing finds the payment through the checkout — and there is no field for a card,
/// a CVV or a UPI PIN anywhere.
library;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/payment/api_payment.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/screens/payment_backend_screen.dart';
import 'package:foodonthego/state/account_state.dart' hide MockPaymentMethodRepository;
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart' hide PromoStatus, PromoState;
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/payment_backend_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

import 'api_checkout_test.dart' show Scripted, checkoutDto, ck, fp, json, refused;
import 'api_payment_test.dart' show cancelledDto, pay1, pay2, payDto, pendingDto, verifiedDto;

/// A scripted payment backend: every step answers from a function, and is logged. No network, no timers of its own.
class ScriptedPayments extends PaymentBackend {
  ScriptedPayments() : super(CartBackend(store: MemoryKeyValueStore(), client: ApiClient(client: MockClient((_) async => json(200, {'cart': null})), baseUrl: 'http://api.test/api/v1')));
  final log = <String>[];
  Object Function(String step, Map<String, dynamic> args) on = (step, args) => step == 'complete' ? verifiedDto() : payDto();
  ProviderReport? simulated = const ProviderReport.success(reference: 'fakepay_ref0001', signature: 'sig');
  Future<ServerPayment> _step(String step, [Map<String, dynamic> args = const {}]) async {
    log.add(step);
    final answer = on(step, args);
    if (answer is Map<String, dynamic>) return ServerPayment.fromJson(answer);
    throw toPaymentException(answer);
  }
  @override
  Future<ServerPayment> start(String checkoutId, String pricingFingerprint, {String? methodType}) => _step('start', {'checkout': checkoutId, 'fingerprint': pricingFingerprint, 'type': methodType});
  @override
  Future<ServerPayment> show(String paymentId) => _step('show', {'id': paymentId});
  @override
  Future<ServerPayment> complete(String paymentId, ProviderReport report) => _step('complete', {'id': paymentId, ...report.toBody()});
  @override
  Future<ProviderReport?> simulate(String paymentId, String scenario, {String webhook = 'none'}) async { log.add('simulate:$scenario:$webhook'); return simulated; }
}

void main() {
  final now = DateTime.now().toUtc();
  AddItemInput burger() => const AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: 2, unitPriceMinor: 25000);
  CheckoutRequest req() => CheckoutRequest(idempotencyKey: 'pay_${'c' * 40}', customerId: 'cust-asha', cartId: 'cart-1', restaurantId: 'burger-hub', pickupSelection: PickupSelection(mode: PickupMode.scheduled, requestedAt: now.add(const Duration(minutes: 75)), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: now.add(const Duration(minutes: 75)), cartId: 'cart-1', restaurantId: 'burger-hub'), currency: 'INR', orderNote: '', termsAccepted: true, termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', acceptedAt: now, paymentMethodId: 'upi', displayedTotalMinor: 50000, createdAt: now, checkoutId: ck, pricingFingerprint: fp);

  Widget app(CartState cart, CheckoutState co, PaymentBackendState pay) => MultiProvider(
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
          ChangeNotifierProvider(create: (_) => OrderState(store: MemoryKeyValueStore())),
        ],
        child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/payment', routes: [
          GoRoute(path: '/payment', builder: (_, _) => const PaymentBackendScreen()),
          GoRoute(path: '/order-confirmation/:number', builder: (_, _) => const Scaffold(body: Text('order confirmation screen'))),
          GoRoute(path: '/checkout', builder: (_, _) => const Scaffold(body: Text('checkout screen'))),
          GoRoute(path: '/home', builder: (_, _) => const Scaffold(body: Text('home screen'))),
        ])),
      );
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 2600); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }

  /// The screen on a scripted backend. With [request] the checkout was confirmed on this phone; with [checkouts]
  /// the phone kept nothing and the backend names the checkout that is being paid for.
  Future<(CartState, CheckoutState, PaymentBackendState, ScriptedPayments)> states({bool request = true, Scripted? checkouts, String? paymentCheckoutId}) async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger());
    final co = CheckoutState(promotions: MockPromotionRepository(latency: Duration.zero), payments: MockPaymentMethodRepository(latency: Duration.zero), checkout: MockCheckoutRepository(latency: Duration.zero), connectivity: MockConnectivityService(), restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), store: MemoryKeyValueStore(), backend: checkouts);
    if (request) co.request = req();
    co.paymentCheckoutId = paymentCheckoutId;
    final backend = ScriptedPayments();
    // A long interval: a test that ends while a payment is unsettled leaves no timer of the screen's behind.
    final pay = PaymentBackendState(backend: backend, store: MemoryKeyValueStore(), launcher: (_) => null, pollEvery: const Duration(hours: 1));
    addTearDown(pay.dispose);
    return (cart, co, pay, backend);
  }
  // While the provider's screen (or its development sheet) is open a progress bar runs: the screen never "settles".
  Future<void> step(WidgetTester t) async { for (var i = 0; i < 4; i++) { await t.pump(const Duration(milliseconds: 60)); } }
  Finder id(String identifier) => find.byWidgetPredicate((w) => w is Semantics && w.properties.identifier == identifier);

  testWidgets('TEST 1 — the backend\'s amount, Pay, the provider\'s outcome reported, VERIFIED with "order creation is pending" — and no order confirmation', (t) async {
    tall(t);
    final (cart, co, pay, backend) = await states();
    await t.pumpWidget(app(cart, co, pay)); await t.pumpAndSettle();
    expect(find.text('Payment summary'), findsOneWidget);
    expect(find.textContaining('Burger Hub'), findsWidgets);
    expect(find.textContaining('₹500.00 · INR'), findsOneWidget);
    expect(find.text('Ready to pay'), findsOneWidget);
    expect(find.byType(TextField), findsNothing); // no card / CVV / UPI PIN inputs
    expect(find.textContaining('Cash at pickup'), findsNothing);
    expect(backend.log, isEmpty); // arriving starts nothing

    await t.tap(find.text('Pay ₹500.00 securely')); await step(t);
    expect(backend.log, ['start']);
    expect(id('pay-view-PROCESSING'), findsOneWidget);
    expect(find.text('Development payment provider'), findsOneWidget);
    expect(find.textContaining('development payment provider — it stands in'), findsOneWidget);
    expect(id('pay-mode-FAKE'), findsOneWidget);

    await t.tap(find.text('Pay successfully')); await t.pumpAndSettle();
    expect(backend.log, ['start', 'simulate:SUCCESS:none', 'complete']);
    expect(id('pay-view-VERIFIED'), findsOneWidget); expect(id('pay-status-VERIFIED'), findsOneWidget);
    expect(find.text('Payment verified'), findsOneWidget);
    expect(find.text('Payment received. We\'re confirming your order.'), findsOneWidget);
    expect(id('pay-order-pending'), findsOneWidget);
    expect(find.textContaining('placing your order'), findsOneWidget);   // Module 31: the order is the server's to create; the screen waits for its number
    // Paid is not ordered: no order confirmation, no order number, the cart is not emptied by the app.
    expect(find.text('order confirmation screen'), findsNothing);
    expect(find.textContaining('FOTG-'), findsNothing);
    expect(find.textContaining('Order confirmed'), findsNothing);
    expect(cart.count, 2);
    expect(id('pay-submit'), findsNothing); expect(id('pay-retry'), findsNothing);
    expect(find.text('UPI · as••@upi'), findsOneWidget);

    await t.tap(find.text('Back to home')); await t.pumpAndSettle();
    expect(find.text('home screen'), findsOneWidget);
    pay.reset();   // Module 31: the state keeps asking for the order number after VERIFIED; the test ends that
  });

  testWidgets('TEST 2 — a failed payment shows the backend\'s safe words and a retry that makes a NEW attempt', (t) async {
    tall(t);
    final (cart, co, pay, backend) = await states();
    var starts = 0;
    backend.simulated = const ProviderReport.failed(errorCode: 'BAD_REQUEST_ERROR', errorReason: 'payment_failed');
    backend.on = (step, args) => switch (step) {
      'start' => ++starts == 1 ? payDto() : payDto(id: pay2, attempt: 2),
      'complete' => payDto(status: 'FAILED', next: 'SELECT_ANOTHER_METHOD', failed: {'category': 'PAYMENT_DECLINED', 'code': 'PAYMENT_DECLINED', 'message': 'The payment was declined. Nothing was charged.', 'retryable': true}),
      _ => payDto(),
    };
    await t.pumpWidget(app(cart, co, pay)); await t.pumpAndSettle();
    await t.tap(find.text('Pay ₹500.00 securely')); await step(t);
    await t.tap(find.text('Payment fails')); await t.pumpAndSettle();
    expect(id('pay-view-FAILED'), findsOneWidget);
    expect(find.text('Payment failed'), findsOneWidget);
    expect(find.text('The payment was declined. Nothing was charged.'), findsOneWidget);
    expect(find.textContaining('BAD_REQUEST_ERROR'), findsNothing); // no provider detail on the screen
    expect(find.text('Back to checkout'), findsOneWidget);

    await t.tap(find.text('Try again with another method')); await step(t);
    expect(backend.log.where((s) => s == 'start'), hasLength(2));
    expect(find.textContaining('attempt 2'), findsOneWidget);
    expect(find.text('Development payment provider'), findsOneWidget);
  });

  testWidgets('TEST 3 — a pending payment warns against paying twice, offers "check status" and no Pay button', (t) async {
    tall(t);
    final (cart, co, pay, backend) = await states();
    backend.simulated = null; // the app hears nothing from the provider
    var shows = 0;
    backend.on = (step, args) => step == 'show' ? (++shows == 1 ? pendingDto() : verifiedDto()) : payDto();
    await t.pumpWidget(app(cart, co, pay)); await t.pumpAndSettle();
    await t.tap(find.text('Pay ₹500.00 securely')); await step(t);
    await t.tap(find.text('Bank has not answered (pending)')); await t.pumpAndSettle();
    expect(id('pay-view-PENDING'), findsOneWidget);
    expect(find.text('We\'re confirming your payment. This can take a moment.'), findsOneWidget);
    expect(id('pay-do-not-pay-again'), findsOneWidget);
    expect(id('pay-submit'), findsNothing); expect(id('pay-retry'), findsNothing); expect(id('pay-back'), findsNothing);
    // The development provider can settle it later; so can the customer ask.
    expect(find.text('Bank confirms later'), findsOneWidget);

    await t.tap(find.text('Check payment status')); await t.pumpAndSettle();
    expect(id('pay-view-VERIFIED'), findsOneWidget);
    expect(backend.log.where((s) => s == 'start'), hasLength(1));
    pay.reset();   // Module 31: the state keeps asking for the order number after VERIFIED; the test ends that
  });

  testWidgets('TEST 4 — a checkout that changed sends the customer back to review it; nothing is offered to pay', (t) async {
    tall(t);
    final (cart, co, pay, backend) = await states();
    backend.on = (step, args) => step == 'start' ? refused(409, 'checkout_stale', 'Your order changed.') : payDto();
    await t.pumpWidget(app(cart, co, pay)); await t.pumpAndSettle();
    await t.tap(find.text('Pay ₹500.00 securely')); await step(t);
    expect(id('pay-view-ERROR'), findsOneWidget);
    expect(find.textContaining('This order has changed or its time has run out'), findsOneWidget);
    expect(id('pay-submit'), findsNothing); expect(id('pay-retry'), findsNothing);
    await t.tap(find.text('Review my order again')); await t.pumpAndSettle();
    expect(find.text('checkout screen'), findsOneWidget);
    expect(co.request, isNull);
  });

  testWidgets('TEST 5 — a phone that kept nothing finds the payment through the checkout the backend names', (t) async {
    tall(t);
    final checkouts = Scripted()..on = (step, args) => {...checkoutDto(status: 'PAYMENT_PENDING'), 'payment': {'status': 'READY_FOR_PAYMENT', 'attempt': {'id': pay1, 'status': 'CANCELLED', 'attempt_number': 1}}};
    final (cart, co, pay, backend) = await states(request: false, checkouts: checkouts, paymentCheckoutId: ck);
    backend.on = (step, args) => step == 'show' ? cancelledDto() : payDto(id: pay2, attempt: 2);
    await t.pumpWidget(app(cart, co, pay)); await t.pumpAndSettle();
    expect(checkouts.log, ['show']); expect(backend.log, ['show']);
    expect(id('pay-view-CANCELLED'), findsOneWidget);
    expect(find.text('The payment was cancelled. Nothing was charged.'), findsOneWidget);
    expect(find.textContaining('₹500.00 · INR'), findsOneWidget);
    await t.tap(find.text('Try again')); await step(t);
    expect(backend.log, ['show', 'start']);
  });

  testWidgets('TEST 6 — without a confirmed checkout there is nothing to pay', (t) async {
    tall(t);
    final (cart, co, pay, backend) = await states(request: false);
    await t.pumpWidget(app(cart, co, pay)); await t.pumpAndSettle();
    expect(id('pay-none'), findsOneWidget);
    expect(find.text('No payment is prepared. Return to checkout to continue.'), findsOneWidget);
    expect(backend.log, isEmpty);
    await t.tap(find.text('Back to checkout')); await t.pumpAndSettle();
    expect(find.text('checkout screen'), findsOneWidget);
  });
}
