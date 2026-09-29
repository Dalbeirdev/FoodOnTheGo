import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/screens/checkout_screen.dart';
import 'package:foodonthego/screens/legal_screen.dart';
import 'package:foodonthego/screens/payment_screen.dart';
import 'package:foodonthego/state/account_state.dart' hide MockPaymentMethodRepository;
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart' hide PromoStatus, PromoState;
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/payment_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 11 — Checkout review screen (Android widget tests, TEST 1/2/11 representative flows).
void main() {
  AddItemInput burger() => const AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: 2, unitPriceMinor: 25000);
  bool burgerHubOpenNow() { final ist = DateTime.now().toUtc().add(const Duration(hours: 5, minutes: 30)); final m = ist.hour * 60 + ist.minute; return m >= 8 * 60 + 30 && m < 22 * 60 + 30; }

  Widget app(CartState cart, PickupState pk, CheckoutState co, {AuthState? auth}) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider.value(value: auth ?? AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => OrdersState()),
          ChangeNotifierProvider.value(value: pk),
          ChangeNotifierProvider.value(value: co),
          ChangeNotifierProvider(create: (_) => PaymentState(resolver: MockPaymentProviderResolver(latency: Duration.zero), verifier: MockPaymentVerificationService(latency: Duration.zero), store: MemoryKeyValueStore())),
        ],
        child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/checkout', routes: [
          GoRoute(path: '/checkout', builder: (_, _) => const CheckoutScreen()),
          GoRoute(path: '/payment', builder: (_, _) => PaymentScreen(restaurantRepository: MockRestaurantRepository(latency: Duration.zero))),
          GoRoute(path: '/legal/:slug', builder: (_, s) => LegalScreen(slug: s.pathParameters['slug']!)),
          GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('login screen'))),
          GoRoute(path: '/cart', builder: (_, _) => const Scaffold(body: Text('cart screen'))),
          GoRoute(path: '/pickup-time', builder: (_, _) => const Scaffold(body: Text('pickup screen'))),
          GoRoute(path: '/my-profile', builder: (_, _) => const Scaffold(body: Text('profile screen'))),
          GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))),
          GoRoute(path: '/restaurants/:id', builder: (_, _) => const Scaffold(body: Text('restaurant'))),
          GoRoute(path: '/restaurants/:id/item/:slug', builder: (_, _) => const Scaffold(body: Text('item'))),
        ])),
      );
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 3600); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  Future<(CartState, PickupState, CheckoutState)> states({bool withPickup = true}) async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger());
    final pk = PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore());
    final now = DateTime.now().toUtc();
    if (withPickup) pk.selection = PickupSelection(mode: PickupMode.asap, requestedAt: now.add(const Duration(minutes: 20)), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: now.add(const Duration(minutes: 20)), cartId: cart.cart!.id, restaurantId: 'burger-hub');
    final co = CheckoutState(promotions: MockPromotionRepository(latency: Duration.zero), payments: MockPaymentMethodRepository(latency: Duration.zero), checkout: MockCheckoutRepository(latency: Duration.zero), connectivity: MockConnectivityService(), restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), store: MemoryKeyValueStore());
    return (cart, pk, co);
  }
  Future<AuthState> signedIn(WidgetTester t) async {
    final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
    await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); });
    return auth;
  }

  testWidgets('TEST 2 — guest is redirected to login; cart and pickup selection are kept', (t) async {
    tall(t);
    final (cart, pk, co) = await states();
    await t.pumpWidget(app(cart, pk, co)); await t.pumpAndSettle();
    expect(find.text('login screen'), findsOneWidget);
    expect(cart.cart!.items, hasLength(1)); expect(pk.selection, isNotNull);
  });

  testWidgets('TEST 1 — signed-in review shows customer, restaurant, pickup, items, promo, legal, payment and summary', (t) async {
    tall(t);
    final (cart, pk, co) = await states();
    final auth = await signedIn(t);
    await t.pumpWidget(app(cart, pk, co, auth: auth)); await t.pumpAndSettle();
    expect(find.text('Customer'), findsOneWidget);
    expect(find.textContaining('Verified'), findsOneWidget);
    expect(find.text('Burger Hub'), findsOneWidget);
    expect(find.textContaining('Asia/Kolkata'), findsWidgets);
    expect(find.textContaining('Classic Burger × 2'), findsOneWidget);
    expect(find.text('Promo code'), findsOneWidget);
    expect(find.text('Order summary'), findsOneWidget);
    expect(find.text('Continue to secure payment'), findsOneWidget);
    expect(find.text('Terms of Service'), findsOneWidget);
    expect(find.textContaining('Cash at pickup'), findsNothing);
    expect(find.textContaining('CVV'), findsNothing);
    expect(co.summary, isNotNull); expect(co.summary!.totalMinor, 50000);
  });

  testWidgets('TEST 11 — Continue is blocked until terms are accepted, then the payment stage shows the request', (t) async {
    tall(t);
    if (!burgerHubOpenNow()) { markTestSkipped('Burger Hub is closed at this wall-clock time; ASAP pickup would be invalid.'); return; }
    final (cart, pk, co) = await states();
    final auth = await signedIn(t);
    await t.pumpWidget(app(cart, pk, co, auth: auth)); await t.pumpAndSettle();
    await t.tap(find.text('Continue to secure payment')); await t.pumpAndSettle();
    expect(find.text('Please accept the terms and policies to continue.'), findsWidgets);
    expect(co.request, isNull);
    await t.tap(find.byType(Checkbox)); await t.pumpAndSettle();
    await t.tap(find.text('Continue to secure payment')); await t.pumpAndSettle();
    expect(co.request, isNotNull);
    expect(find.text('Ready to pay'), findsOneWidget); // Module 12 payment stage
  });

  testWidgets('legal links open the DRAFT documents', (t) async {
    tall(t);
    final (cart, pk, co) = await states();
    final auth = await signedIn(t);
    await t.pumpWidget(app(cart, pk, co, auth: auth)); await t.pumpAndSettle();
    await t.tap(find.text('Privacy Policy')); await t.pumpAndSettle();
    expect(find.textContaining('DRAFT'), findsWidgets);
  });
}
