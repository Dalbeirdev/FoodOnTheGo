import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/screens/item_detail_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 08 — Item Details & Customization (Android widget tests, TEST 15 representative flow).
void main() {
  final menu = MockMenuRepository(latency: Duration.zero);
  final rest = MockRestaurantRepository(latency: Duration.zero);

  Widget app(Widget home, CartState cart, {GoRouter? router}) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => OrdersState()),
        ],
        child: MaterialApp.router(routerConfig: router ?? GoRouter(routes: [GoRoute(path: '/', builder: (_, _) => home), GoRoute(path: '/cart', builder: (_, _) => const Scaffold(body: Text('cart'))), GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))), GoRoute(path: '/restaurants/:id', builder: (_, _) => const Scaffold(body: Text('restaurant')))])),
      );
  Widget item(String rid, String slug) => ItemDetailScreen(restaurantId: rid, itemSlug: slug, restaurantRepository: rest, menuRepository: menu);
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 2400); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }

  testWidgets('TEST 1 — simple item adds straight to the cart', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    final roti = (await menu.getItems('dhaba-junction-ropar', const MenuFilter(search: 'roti'))).items.first;
    await t.pumpWidget(app(item('dhaba-junction-ropar', roti.slug), cart)); await t.pumpAndSettle();
    expect(find.text('Tandoori Roti'), findsOneWidget);
    expect(find.text('Required'), findsNothing);
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(cart.count, 1); expect(cart.subtotalMinor, 2500); expect(cart.currency, 'INR');
    expect(find.text('Added to your cart'), findsOneWidget);
  });
  testWidgets('TEST 2 — required group without default blocks until chosen (USD fixture)', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    final it = (await menu.getItems('grapevine-burgers')).items.first;
    await t.pumpWidget(app(item('grapevine-burgers', it.slug), cart)); await t.pumpAndSettle();
    expect(find.text('Choose your side'), findsOneWidget);
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(find.text('Please choose an option.'), findsOneWidget); expect(cart.count, 0);
    await t.tap(find.text('Fries')); await t.pumpAndSettle();
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(cart.count, 1); expect(cart.currency, 'USD');
  });
  testWidgets('TEST 3 / 4 / 5 / 6 / 7 — options, max rule, quantity, instructions, sold-out option', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    await t.pumpWidget(app(item('burger-hub', 'classic-burger'), cart)); await t.pumpAndSettle();
    expect(find.text('Classic Burger'), findsOneWidget);
    expect(find.textContaining('₹250.00'), findsWidgets);
    await t.tap(find.text('Large')); await t.pumpAndSettle();
    expect(find.text('₹320.00'), findsOneWidget);
    await t.tap(find.text('Extra Cheese')); await t.tap(find.text('Bacon')); await t.tap(find.text('Jalapeños')); await t.pumpAndSettle();
    expect(find.text('₹420.00'), findsOneWidget);
    expect(find.textContaining('3 of 3 selected'), findsOneWidget);
    // sold-out option is not tappable
    await t.tap(find.text('Fried Egg'), warnIfMissed: false); await t.pumpAndSettle();
    expect(find.text('₹420.00'), findsOneWidget);
    await t.tap(find.byIcon(Icons.add)); await t.tap(find.byIcon(Icons.add)); await t.pumpAndSettle();
    expect(find.text('₹1,260.00'), findsOneWidget);
    await t.enterText(find.byType(TextField), '  pack   separately '); await t.pumpAndSettle();
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(cart.count, 3);
    final line = cart.items.single;
    expect(line.quantity, 3); expect(line.unitPriceMinor, 42000); expect(line.specialInstructions, 'pack separately');
    expect(line.selectedVariants.map((o) => o.optionName), ['Large']);
    expect(line.selectedModifiers.map((o) => o.optionName), ['Extra Cheese', 'Bacon', 'Jalapeños']);
  });
  testWidgets('TEST 8 — sold-out item disables Add to cart', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    await t.pumpWidget(app(item('burger-hub', 'chicken-wings'), cart)); await t.pumpAndSettle();
    expect(find.text('This item is sold out.'), findsOneWidget);
    await t.tap(find.text('Add to cart'), warnIfMissed: false); await t.pumpAndSettle();
    expect(cart.count, 0);
  });
  testWidgets('TEST 10 — different restaurant asks before replacing the cart', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    await t.pumpWidget(app(item('burger-hub', 'classic-burger'), cart)); await t.pumpAndSettle();
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(cart.cart!.restaurantId, 'burger-hub');
    final roti = (await menu.getItems('dhaba-junction-ropar', const MenuFilter(search: 'roti'))).items.first;
    await t.pumpWidget(app(item('dhaba-junction-ropar', roti.slug), cart)); await t.pumpAndSettle();
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(find.text('Start a new cart?'), findsOneWidget);
    await t.tap(find.text('Cancel')); await t.pumpAndSettle();
    expect(cart.cart!.restaurantId, 'burger-hub');
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    await t.tap(find.text('Clear cart & continue')); await t.pumpAndSettle();
    expect(cart.cart!.restaurantId, 'dhaba-junction-ropar'); expect(cart.count, 1);
  });
  testWidgets('TEST 13 / 14 — Unicode JPY item with Unicode option names and no decimals', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    final ramen = (await menu.getItems('ippudo-shizuoka')).items.first;
    await t.pumpWidget(app(item('ippudo-shizuoka', ramen.slug), cart)); await t.pumpAndSettle();
    expect(find.text('白丸元味'), findsOneWidget);
    expect(find.text('麺の硬さ'), findsOneWidget);
    expect(find.text('¥890'), findsWidgets);
    await t.tap(find.text('チャーシュー')); await t.pumpAndSettle();
    expect(find.text('¥1,140'), findsOneWidget);
    expect(find.text('Sold out'), findsOneWidget); // 味玉
  });
  testWidgets('moving from one item straight to another shows the other item, and "Add to cart" adds that one', (t) async {
    tall(t);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    // Two items of one restaurant that need no choice, so each can be added with one tap.
    final simple = (await menu.getItems('dhaba-junction-ropar')).items.where((i) => i.isAvailable && !i.customizable).toList();
    expect(simple.length, greaterThanOrEqualTo(2));
    final first = simple[0], second = simple[1];
    // The same route for both items, as in the app: the router keeps the page and only its parameters change.
    final router = GoRouter(initialLocation: '/restaurants/dhaba-junction-ropar/item/${first.slug}', routes: [
      GoRoute(path: '/restaurants/:id/item/:itemId', builder: (_, s) => item(s.pathParameters['id']!, s.pathParameters['itemId']!)),
      GoRoute(path: '/cart', builder: (_, _) => const Scaffold(body: Text('cart'))),
    ]);
    await t.pumpWidget(app(const SizedBox(), cart, router: router)); await t.pumpAndSettle();
    expect(find.text(first.name), findsWidgets);
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(find.text('Added to your cart'), findsOneWidget);
    expect(cart.items.single.menuItemId, first.id);

    router.go('/restaurants/dhaba-junction-ropar/item/${second.slug}'); await t.pumpAndSettle();
    expect(find.text(second.name), findsWidgets);
    expect(find.text(first.name), findsNothing); // nothing of the previous item is left on the screen
    await t.tap(find.text('Add to cart')); await t.pumpAndSettle();
    expect(cart.items.map((i) => i.menuItemId), [first.id, second.id]);
  });
  testWidgets('invalid slug → not-found with a way back', (t) async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    await t.pumpWidget(app(item('burger-hub', 'no-such-item'), cart)); await t.pumpAndSettle();
    expect(find.text('We couldn’t find that item.'), findsOneWidget);
    expect(find.text('Back to the menu'), findsOneWidget);
  });
}
