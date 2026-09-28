import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/screens/pickup_time_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 10 — Pickup time screen (Android widget tests, TEST 12 representative flow).
void main() {
  final menu = MockMenuRepository(latency: Duration.zero);
  final rest = MockRestaurantRepository(latency: Duration.zero);
  AddItemInput burger() => const AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: 1, unitPriceMinor: 25000);

  Widget app(CartState cart, PickupState pk, {AuthState? auth, bool notAccepting = false}) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider.value(value: auth ?? AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
          ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
          ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => OrdersState()),
          ChangeNotifierProvider.value(value: pk),
        ],
        child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/pickup-time', routes: [
          GoRoute(path: '/pickup-time', builder: (_, _) => PickupTimeScreen(restaurantRepository: rest, menuRepository: menu, simulateNotAccepting: notAccepting)),
          GoRoute(path: '/checkout', builder: (_, _) => const Scaffold(body: Text('checkout screen'))),
          GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('login screen'))),
          GoRoute(path: '/cart', builder: (_, _) => const Scaffold(body: Text('cart screen'))),
          GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))),
          GoRoute(path: '/restaurants/:id', builder: (_, _) => const Scaffold(body: Text('restaurant'))),
        ])),
      );
  void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 2600); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  Future<(CartState, PickupState, MockPickupRepository)> states() async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger());
    final repo = MockPickupRepository(latency: Duration.zero);
    final pk = PickupState(repository: repo, store: MemoryKeyValueStore());
    return (cart, pk, repo);
  }

  testWidgets('TEST 4 — no journey: context, zone, modes; scheduled slots load with a recommended one', (t) async {
    tall(t);
    final (cart, pk, _) = await states();
    await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
    expect(find.text('Burger Hub'), findsOneWidget);
    expect(find.textContaining('Asia/Kolkata'), findsOneWidget);
    expect(find.textContaining('No journey planned'), findsOneWidget);
    expect(find.text('Schedule for later'), findsOneWidget);
    await t.tap(find.text('Schedule for later')); await t.pumpAndSettle();
    expect(find.text('Today'), findsOneWidget);
    expect(find.text('Recommended'), findsWidgets);
    expect(pk.slots.where((s) => s.available).length, greaterThan(0));
  });
  testWidgets('TEST 2 / 7 / 11 — pick a scheduled slot (full ones disabled), guest Continue → login gate with selection kept', (t) async {
    tall(t);
    final (cart, pk, _) = await states();
    await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
    await t.tap(find.text('Schedule for later')); await t.pumpAndSettle();
    // tomorrow has a full schedule regardless of the wall clock
    await t.tap(find.byType(ChoiceChip).at(1)); await t.pumpAndSettle();
    final full = pk.slots.firstWhere((s) => s.capacityStatus == CapacityStatus.full);
    expect(full.available, isFalse);
    final slot = pk.slots.firstWhere((s) => s.available);
    pk.selectSlot(slot); await t.pumpAndSettle();
    expect(pk.status, PickupStatus.selected);
    expect(find.text('Pickup summary'), findsOneWidget);
    expect(find.text('Continue to checkout'), findsOneWidget);
    await t.tap(find.text('Continue to checkout')); await t.pumpAndSettle();
    expect(find.text('login screen'), findsOneWidget); // guest → auth gate
    expect(pk.selection?.slotId, slot.id); // preserved through the gate
  });
  testWidgets('TEST 8 — stale slot: validation fails, customer must choose another time', (t) async {
    tall(t);
    final (cart, pk, repo) = await states();
    await t.pumpWidget(app(cart, pk)); await t.pumpAndSettle();
    await t.tap(find.text('Schedule for later')); await t.pumpAndSettle();
    await t.tap(find.byType(ChoiceChip).at(1)); await t.pumpAndSettle();
    final slot = pk.slots.firstWhere((s) => s.available); pk.selectSlot(slot); await t.pumpAndSettle();
    repo.staleSlot = true;
    await t.tap(find.text('Continue to checkout')); await t.pumpAndSettle();
    expect(find.textContaining('no longer available'), findsOneWidget);
    expect(pk.status, PickupStatus.stale); expect(pk.selection?.slotId, slot.id);
    repo.staleSlot = false;
    await t.tap(find.text('Choose another time')); await t.pumpAndSettle();
    expect(pk.selection, isNull);
  });
  testWidgets('TEST 6 — not accepting orders is a distinct blocking state', (t) async {
    tall(t);
    final (cart, pk, _) = await states();
    await t.pumpWidget(app(cart, pk, notAccepting: true)); await t.pumpAndSettle();
    expect(find.textContaining('not accepting orders right now, so no pickup time'), findsOneWidget);
    expect(find.text('When would you like to pick up?'), findsNothing);
  });
  testWidgets('signed-in customer continues straight to checkout after validation', (t) async {
    tall(t);
    final (cart, pk, _) = await states();
    final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
    await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); });
    await t.pumpWidget(app(cart, pk, auth: auth)); await t.pumpAndSettle();
    await t.tap(find.text('Schedule for later')); await t.pumpAndSettle();
    await t.tap(find.byType(ChoiceChip).at(1)); await t.pumpAndSettle();
    pk.selectSlot(pk.slots.firstWhere((s) => s.available)); await t.pumpAndSettle();
    await t.tap(find.text('Continue to checkout')); await t.pumpAndSettle();
    expect(find.text('checkout screen'), findsOneWidget);
  });
}
