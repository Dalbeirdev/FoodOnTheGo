import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/cart_validation.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/screens/cart_screen.dart';
import 'package:foodonthego/screens/item_detail_screen.dart';
import 'package:foodonthego/screens/pickup_time_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 09 — Cart screen (Android widget tests, TEST 15 representative flow).
void main() {
  final menu = MockMenuRepository(latency: Duration.zero);
  final rest = MockRestaurantRepository(latency: Duration.zero);
  AddItemInput burger({int qty = 2, int unit = 35000, String note = 'No onion please'}) => AddItemInput(
        menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR',
        selectedVariants: const [SelectedOption(groupId: 'classic-burger:size', groupName: 'Size', optionId: 'size:large', optionName: 'Large', priceAdjustmentMinor: 7000)],
        selectedModifiers: const [SelectedOption(groupId: 'classic-burger:addons', groupName: 'Add-ons', optionId: 'addons:cheese', optionName: 'Extra Cheese', priceAdjustmentMinor: 3000)],
        specialInstructions: note, quantity: qty, unitPriceMinor: unit);
  AddItemInput fries() => const AddItemInput(menuItemId: 'french-fries', itemSlug: 'french-fries', itemName: 'French Fries', image: '', basePriceMinor: 12000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: 1, unitPriceMinor: 12000);

  Widget app(CartState cart, {StaleSimulation simulate = StaleSimulation.none, String initial = '/cart'}) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => OrdersState()),
        ],
        child: MaterialApp.router(routerConfig: GoRouter(initialLocation: initial, routes: [
          GoRoute(path: '/cart', builder: (_, _) => CartScreen(restaurantRepository: rest, menuRepository: menu, simulate: simulate)),
          GoRoute(path: '/pickup-time', builder: (_, _) => PickupTimeScreen(restaurantRepository: rest)),
          GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))),
          GoRoute(path: '/plan-journey', builder: (_, _) => const Scaffold(body: Text('plan'))),
          GoRoute(path: '/restaurants/:id', builder: (_, s) => Scaffold(body: Text('restaurant ${s.pathParameters['id']}'))),
          GoRoute(path: '/restaurants/:id/item/:slug', builder: (_, s) => ItemDetailScreen(restaurantId: s.pathParameters['id']!, itemSlug: s.pathParameters['slug']!, editCartItemId: s.uri.queryParameters['edit'], restaurantRepository: rest, menuRepository: menu)),
        ])),
      );
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 2400); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }

  testWidgets('TEST 1 — empty cart state with Explore / Plan actions and no Continue', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    await t.pumpWidget(app(cart)); await t.pumpAndSettle();
    expect(find.text('Your cart is empty.'), findsOneWidget);
    expect(find.text('Explore restaurants'), findsOneWidget); expect(find.text('Plan a journey'), findsOneWidget);
    expect(find.text('Continue to pickup'), findsNothing);
  });
  testWidgets('TEST 2 / 3 / 4 — structured lines, restaurant context, totals, quantity', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(burger()); cart.addItem(fries());
    await t.pumpWidget(app(cart)); await t.pumpAndSettle();
    expect(find.text('Burger Hub'), findsOneWidget);
    expect(find.textContaining('Sector 62, Noida'), findsOneWidget);
    expect(find.text('Size: Large (+₹70.00)'), findsOneWidget);
    expect(find.text('Add-ons: Extra Cheese (+₹30.00)'), findsOneWidget);
    expect(find.text('“No onion please”'), findsOneWidget);
    expect(find.text('₹700.00'), findsOneWidget); // line total 2 × 350
    expect(find.text('₹820.00'), findsWidgets); // subtotal + estimated total + bottom bar
    expect(find.textContaining('GST'), findsNothing);
    await t.tap(find.bySemanticsLabel('Increase quantity Classic Burger')); await t.pumpAndSettle();
    expect(find.text('₹1,050.00'), findsOneWidget);
    expect(find.text('₹1,170.00'), findsWidgets);
    expect(cart.count, 4);
  });
  testWidgets('TEST 6 / 7 — remove asks first; removing the last item empties the cart', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(burger(qty: 1));
    await t.pumpWidget(app(cart)); await t.pumpAndSettle();
    await t.tap(find.bySemanticsLabel('Remove Classic Burger')); await t.pumpAndSettle();
    expect(find.text('Remove this item?'), findsOneWidget);
    await t.tap(find.text('Keep')); await t.pumpAndSettle();
    expect(cart.count, 1);
    await t.tap(find.bySemanticsLabel('Remove Classic Burger')); await t.pumpAndSettle();
    await t.tap(find.text('Yes, remove')); await t.pumpAndSettle();
    expect(cart.count, 0); expect(find.text('Your cart is empty.'), findsOneWidget);
  });
  testWidgets('TEST 5 — Edit restores the configuration and saves changes back to the same line', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(burger());
    await t.pumpWidget(app(cart)); await t.pumpAndSettle();
    await t.tap(find.bySemanticsLabel('Edit Classic Burger')); await t.pumpAndSettle();
    expect(find.text('Editing your cart item'), findsOneWidget);
    expect(find.text('₹700.00'), findsOneWidget); // 2 × 350 restored
    expect(find.widgetWithText(TextField, 'No onion please'), findsOneWidget);
    await t.tap(find.text('Jumbo')); await t.tap(find.text('Bacon')); await t.pumpAndSettle();
    await t.tap(find.text('Save changes')); await t.pumpAndSettle();
    expect(cart.items.length, 1);
    expect(cart.items.single.selectedVariants.single.optionName, 'Jumbo');
    expect(cart.items.single.selectedModifiers.map((o) => o.optionName), ['Extra Cheese', 'Bacon']);
    expect(cart.items.single.unitPriceMinor, 46000); expect(cart.items.single.quantity, 2);
    expect(find.text('Size: Jumbo (+₹130.00)'), findsOneWidget);
  });
  testWidgets('TEST 11 / 12 — price change and unavailable item block Continue until reviewed', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(burger(unit: 30000));
    await t.pumpWidget(app(cart)); await t.pumpAndSettle();
    expect(find.text('Price updated from ₹300.00 to ₹350.00 each.'), findsOneWidget);
    expect(find.text('Review cart'), findsOneWidget);
    await t.tap(find.text('Accept updated price')); await t.pumpAndSettle();
    expect(find.text('Continue to pickup'), findsOneWidget);
    expect(cart.items.single.unitPriceMinor, 35000);
    final cart2 = CartState(repository: MemoryCartRepository()); await cart2.ready; cart2.addItem(burger());
    await t.pumpWidget(app(cart2, simulate: StaleSimulation.unavailable)); await t.pumpAndSettle();
    expect(find.text('This item is no longer available.'), findsOneWidget);
    expect(find.text('Review cart'), findsOneWidget);
  });
  testWidgets('TEST 13 — promo area applies / rejects with integer money and Continue opens the pickup stage', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(burger());
    await t.pumpWidget(app(cart)); await t.pumpAndSettle();
    await t.enterText(find.widgetWithText(TextField, 'CODE'), 'welcome10'); await t.tap(find.text('Apply')); await t.pumpAndSettle();
    expect(find.textContaining('WELCOME10 applied'), findsOneWidget);
    expect(find.text('−₹70.00'), findsOneWidget);
    expect(find.text('₹630.00'), findsWidgets);
    await t.tap(find.text('Continue to pickup')); await t.pumpAndSettle();
    expect(find.text('Pickup time'), findsWidgets);
    expect(find.textContaining('Nothing has been ordered'), findsOneWidget);
  });
}
