import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/screens/catalog_item_screen.dart';
import 'package:foodonthego/screens/restaurant_detail_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 07 — Restaurant Details & Menu Browsing (Android). Mirrors the web TEST 1–12 set at the
/// repository + widget level; the device pass is recorded in the tracker as USER DEVICE VERIFICATION.
void main() {
  final repo = MockMenuRepository(latency: Duration.zero);

  group('MockMenuRepository (global menu layer)', () {
    test('categories are dynamic per restaurant and Unicode names survive', () async {
      final cats = await repo.getCategories('ippudo-shizuoka');
      expect(cats.map((c) => c.name), contains('ラーメン'));
      final cats2 = await repo.getCategories('dhaba-junction-ropar');
      expect(cats2.map((c) => c.name), contains('Popular'));
      expect(cats.length, isNot(cats2.length));
    });
    test('prices use the currency’s real minor units (INR ×100, JPY ×1)', () async {
      final inr = (await repo.getItems('dhaba-junction-ropar')).items.first;
      expect(inr.currency, 'INR');
      expect(inr.basePriceMinor % 100, 0);
      expect(inr.basePriceMinor, greaterThanOrEqualTo(5000)); // ≥ ₹50, never ₹0.50
      final jpy = (await repo.getItems('ippudo-shizuoka')).items.first;
      expect(jpy.currency, 'JPY');
      expect(jpy.basePriceMinor, inInclusiveRange(200, 3000)); // ¥ whole yen
    });
    test('search is Unicode-normalised across name, alternate names and description', () async {
      final page = await repo.getItems('ippudo-shizuoka', const MenuFilter(search: 'gyoza'));
      expect(page.items, isNotEmpty);
      expect(page.items.every((i) => i.name.contains('餃子') || i.alternateNames.any((a) => a.toLowerCase().contains('gyoza')) || i.description.toLowerCase().contains('gyoza')), isTrue);
      final none = await repo.getItems('ippudo-shizuoka', const MenuFilter(search: 'zzzz-nothing'));
      expect(none.items, isEmpty);
    });
    test('availability states and the availableOnly filter', () async {
      final all = await repo.getItems('burger-hub');
      expect(all.items.map((i) => i.availability), contains(ItemAvailability.temporarilyUnavailable));
      final onlyAvail = await repo.getItems('burger-hub', const MenuFilter(availableOnly: true));
      expect(onlyAvail.items.every((i) => i.isAvailable), isTrue);
      expect(onlyAvail.total, lessThan(all.total));
      final wings = await repo.getItemBySlug('burger-hub', 'chicken-wings');
      expect(wings!.availability, ItemAvailability.soldOut);
    });
    test('dietary filter narrows to matching tags', () async {
      final tags = await repo.getDietaryTags('dhaba-junction-ropar');
      expect(tags, contains('Vegetarian'));
      final veg = await repo.getItems('dhaba-junction-ropar', const MenuFilter(dietary: ['Vegetarian']));
      expect(veg.items, isNotEmpty);
      expect(veg.items.every((i) => i.dietaryTags.contains('Vegetarian')), isTrue);
    });
    test('large menu paginates by cursor and every page keeps order', () async {
      final first = await repo.getItems('jaipur-thali');
      expect(first.total, greaterThan(90));
      expect(first.nextCursor, isNotNull);
      final second = await repo.getItems('jaipur-thali', MenuFilter(cursor: first.nextCursor));
      expect(second.items.first.id, isNot(first.items.first.id));
    });
    test('restaurant without a menu returns no categories and no items', () async {
      expect(await repo.getCategories('ambala-chai'), isEmpty);
      expect((await repo.getItems('ambala-chai')).total, 0);
    });
    test('unknown slug resolves to null; failure flag raises MenuError', () async {
      expect(await repo.getItemBySlug('burger-hub', 'not-a-real-item'), isNull);
      final failing = MockMenuRepository(latency: Duration.zero)..fail = true;
      expect(() => failing.getCategories('burger-hub'), throwsA(isA<MenuException>()));
    });
  });

  Widget app(Widget home) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => CartState()),
          ChangeNotifierProvider(create: (_) => OrdersState()),
        ],
        child: MaterialApp.router(routerConfig: GoRouter(routes: [GoRoute(path: '/', builder: (_, _) => home), GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))), GoRoute(path: '/plan-journey', builder: (_, _) => const Scaffold(body: Text('plan'))), GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('login'))), GoRoute(path: '/restaurants/:id/item/:item', builder: (_, s) => Scaffold(body: Text('item ${s.pathParameters['item']}')))])),
      );

  group('RestaurantDetailScreen', () {
    testWidgets('TEST 1/7 — opens by slug without a journey: name, address, categories, prices, plan-journey hint', (t) async {
      t.view.physicalSize = const Size(390, 2000);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      await t.pumpWidget(app(RestaurantDetailScreen(id: 'dhaba-junction-ropar', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      expect(find.text('Dhaba Junction'), findsWidgets);
      expect(find.textContaining('NH-205, Rupnagar'), findsWidgets);
      expect(find.textContaining('Plan a journey to see detour'), findsOneWidget);
      expect(find.text('Popular'), findsWidgets); // category chip + heading
      expect(find.textContaining('₹'), findsWidgets);
      expect(find.text('Butter Chicken'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
    testWidgets('TEST 3 — menu search narrows the list and the empty state offers to clear', (t) async {
      t.view.physicalSize = const Size(390, 2000);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      await t.pumpWidget(app(RestaurantDetailScreen(id: 'ippudo-shizuoka', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      expect(find.text('白丸元味'), findsOneWidget);
      await t.enterText(find.byType(TextField).first, 'gyoza');
      await t.pumpAndSettle();
      expect(find.text('白丸元味'), findsNothing);
      expect(find.textContaining('餃子'), findsWidgets);
      await t.enterText(find.byType(TextField).first, 'zzzz');
      await t.pumpAndSettle();
      expect(find.text('No items match'), findsOneWidget);
      await t.tap(find.text('Clear search & filters'));
      await t.pumpAndSettle();
      expect(find.text('白丸元味'), findsOneWidget);
    });
    testWidgets('TEST 4/5 — unavailable items are badged and "Available now" hides them', (t) async {
      t.view.physicalSize = const Size(390, 2000);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      await t.pumpWidget(app(RestaurantDetailScreen(id: 'burger-hub', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField).first, 'family');
      await t.pumpAndSettle();
      expect(find.text('Temporarily unavailable'), findsOneWidget);
      await t.tap(find.text('Available now'));
      await t.pumpAndSettle();
      expect(find.text('Temporarily unavailable'), findsNothing);
      expect(find.text('No items match'), findsOneWidget);
      await t.enterText(find.byType(TextField).first, 'wings');
      await t.pumpAndSettle();
      expect(find.text('Sold out'), findsNothing);
      await t.tap(find.text('Available now'));
      await t.pumpAndSettle();
      expect(find.text('Sold out'), findsOneWidget);
    });
    testWidgets('TEST 9 — no-menu restaurant shows the menu-unavailable state and still renders info', (t) async {
      t.view.physicalSize = const Size(390, 2000);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      await t.pumpWidget(app(RestaurantDetailScreen(id: 'ambala-chai', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      expect(find.text('Menu currently unavailable'), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
    });
    testWidgets('TEST 10 — invalid slug shows not-found with a browse action', (t) async {
      await t.pumpWidget(app(RestaurantDetailScreen(id: 'no-such-place', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      expect(find.text('We couldn’t find that restaurant.'), findsOneWidget);
      expect(find.text('Browse restaurants'), findsOneWidget);
    });
    testWidgets('TEST 8 — Info tab shows hours with the restaurant zone label', (t) async {
      t.view.physicalSize = const Size(390, 2000);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      await t.pumpWidget(app(RestaurantDetailScreen(id: 'grapevine-burgers', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      await t.tap(find.text('Info'));
      await t.pumpAndSettle();
      expect(find.text('Opening hours'), findsOneWidget);
      expect(find.textContaining('10:00 AM – 1:00 AM'), findsNWidgets(7));
      expect(find.textContaining('America/Los_Angeles'), findsOneWidget);
    });
    testWidgets('Catalogue item screen renders a generated item read-only (Module 08 note)', (t) async {
      final gyoza = (await repo.getItems('ippudo-shizuoka', const MenuFilter(search: 'gyoza'))).items.first;
      await t.pumpWidget(app(CatalogItemScreen(restaurantId: 'ippudo-shizuoka', itemSlug: gyoza.slug, restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: repo)));
      await t.pumpAndSettle();
      expect(find.textContaining('Module 08'), findsOneWidget);
      expect(find.textContaining('¥'), findsWidgets);
    });
  });
}
