import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/screens/my_orders_screen.dart';
import 'package:foodonthego/screens/order_details_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/orders_history_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 15 — My Orders + Order Details (Android widget tests): list / groups / search / paging / empty / error,
/// details with refunds, Track order, reorder with price change and cross-restaurant cart confirmation.
void main() {
  Future<AuthState> signedIn(WidgetTester t) async { final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero)); await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }); return auth; }
  (OrderState, MockOrderRepository) orderState() { final repo = MockOrderRepository(MemoryKeyValueStore(), latency: Duration.zero); return (OrderState(orders: repo, verifications: MockPickupVerificationRepository(repo), receipts: MockReceiptRepository(), store: MemoryKeyValueStore()), repo); }
  final reorder = MockReorderService(restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), latency: Duration.zero);
  Widget app(OrderState os, AuthState auth, String location, {CartState? cart}) => MultiProvider(providers: [
        ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os),
        ChangeNotifierProvider(create: (_) => OrdersHistoryState(orders: os.orders)),
        ChangeNotifierProvider(create: (_) => cart ?? CartState(repository: MemoryCartRepository())),
        ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
      ], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: location, routes: [
        GoRoute(path: '/my-orders', builder: (_, _) => const MyOrdersScreen()),
        GoRoute(path: '/order/:number', builder: (_, s) => OrderDetailsScreen(number: s.pathParameters['number']!, startReorder: s.uri.queryParameters['reorder'] == '1', reorderService: reorder)),
        GoRoute(path: '/order-tracking/:number', builder: (_, s) => Scaffold(body: Text('tracking ${s.pathParameters['number']}'))),
        GoRoute(path: '/cart', builder: (_, _) => const Scaffold(body: Text('cart screen'))),
        GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('restaurants screen'))),
        GoRoute(path: '/restaurants/:slug', builder: (_, _) => const Scaffold(body: Text('restaurant screen'))),
      ])));
  void tall(WidgetTester t, [double h = 3600]) { t.view.physicalSize = Size(390, h); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  Future<void> tapText(WidgetTester t, String text) async { final f = find.text(text).first; await t.ensureVisible(f); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }

  testWidgets('TEST 1 / 4 / 9 — empty state, then seeded history renders grouped cards, badges and state-dependent actions', (t) async {
    tall(t, 7000);
    final (os, repo) = orderState(); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/my-orders')); await t.pumpAndSettle();
    expect(find.text('No orders yet'), findsOneWidget); expect(find.text('Explore restaurants'), findsOneWidget);
    await repo.seedDemoHistory('cust-rahul', 12);
    await tapText(t, 'Seed 12 sample orders');
    expect(find.text('Showing 5 of 12'), findsOneWidget); expect(find.textContaining('FOTG-SEED-0001'), findsOneWidget);
    expect(find.text('Load more orders'), findsOneWidget); expect(find.text('Track order'), findsWidgets); expect(find.text('Reorder'), findsWidgets);
    expect(find.text('Refunded'), findsOneWidget); expect(find.text('Refund pending'), findsOneWidget);
    await tapText(t, 'Load more orders'); expect(find.text('Showing 10 of 12'), findsOneWidget);
    await tapText(t, 'Load more orders'); expect(find.text('Showing 12 of 12'), findsOneWidget); expect(find.text('Load more orders'), findsNothing);
  });

  testWidgets('TEST 5 / 6 / 7 — group chips, search and sort filter the summaries; filtered-empty offers clear filters', (t) async {
    tall(t);
    final (os, repo) = orderState(); await repo.seedDemoHistory('cust-rahul', 12); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/my-orders')); await t.pumpAndSettle();
    await tapText(t, 'Cancelled'); expect(find.text('Showing 4 of 4'), findsOneWidget); expect(find.text('Track order'), findsNothing);
    await tapText(t, 'Ongoing'); expect(find.text('Showing 3 of 3'), findsOneWidget); expect(find.text('Reorder'), findsNothing);
    await t.enterText(find.byType(TextField), 'route 5'); await t.testTextInput.receiveAction(TextInputAction.search); await t.pumpAndSettle();
    expect(find.text('No ongoing orders'), findsOneWidget);
    await tapText(t, 'Clear filters'); expect(find.text('Showing 5 of 12'), findsOneWidget);
    await t.enterText(find.byType(TextField), 'route 5'); await t.testTextInput.receiveAction(TextInputAction.search); await t.pumpAndSettle();
    expect(find.text('Showing 2 of 2'), findsOneWidget); expect(find.text('Route 5 Diner'), findsNWidgets(2));
    await t.enterText(find.byType(TextField), ''); await t.testTextInput.receiveAction(TextInputAction.search); await t.pumpAndSettle();
    await t.tap(find.byType(DropdownButton<OrderSort>)); await t.pumpAndSettle(); await t.tap(find.text('Oldest first').last); await t.pumpAndSettle();
    expect(find.textContaining('FOTG-SEED-0012'), findsOneWidget); expect(find.textContaining('FOTG-SEED-0001'), findsNothing);
  });

  testWidgets('TEST 8 — load failure shows retry; retry recovers', (t) async {
    tall(t);
    final (os, repo) = orderState(); await repo.seedDemoHistory('cust-rahul', 3); final auth = await signedIn(t);
    repo.fail = true;
    await t.pumpWidget(app(os, auth, '/my-orders')); await t.pumpAndSettle();
    expect(find.text('Unable to load orders'), findsOneWidget);
    repo.fail = false; await tapText(t, 'Try again'); expect(find.text('Showing 3 of 3'), findsOneWidget);
  });

  testWidgets('TEST 10 / 14 / 15 — details snapshot: USD order, partial refund with remaining amount, rejected notice, timeline, receipt', (t) async {
    tall(t, 5200);
    final (os, repo) = orderState(); await repo.seedDemoHistory('cust-rahul', 12); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/FOTG-SEED-0006')); await t.pumpAndSettle(); // INR cancelled, qty 2 → ₹1,280 paid, ₹320 refunded, ₹960 remaining
    expect(find.text('Order details'), findsOneWidget); expect(find.text('Order status: Cancelled'), findsOneWidget); expect(find.text('Payment status: Partially refunded'), findsOneWidget);
    expect(find.textContaining('This order was cancelled'), findsOneWidget);
    expect(find.text('Refunded'), findsOneWidget); expect(find.text('Remaining paid'), findsOneWidget); expect(find.text('₹320.00'), findsWidgets); expect(find.text('₹960.00'), findsOneWidget); expect(find.text('₹1,280.00'), findsWidgets);
    expect(find.text('Order timeline'), findsOneWidget); expect(find.text('Order placed'), findsOneWidget);
    expect(find.text('Track order'), findsNothing); expect(find.text('Check availability & prices'), findsWidgets);
    await tapText(t, 'View receipt'); expect(find.textContaining('Not a tax invoice'), findsOneWidget);
    await t.pumpWidget(app(os, auth, '/order/FOTG-SEED-0003')); await t.pumpAndSettle(); // USD picked up
    expect(find.text('Order status: Picked up'), findsOneWidget); expect(find.text('\$18.99'), findsWidgets); expect(find.textContaining('Card ending in 4242'), findsOneWidget);
    expect(find.textContaining('4242424242'), findsNothing);
    await t.pumpWidget(app(os, auth, '/order/FOTG-SEED-0005')); await t.pumpAndSettle(); // JPY rejected
    expect(find.text('一風堂 静岡店'), findsOneWidget); expect(find.textContaining('could not accept this order'), findsWidgets); expect(find.text('¥980'), findsWidgets);
  });

  testWidgets('TEST 11 / 16 — ongoing order shows Track order; unknown or foreign order → not found', (t) async {
    tall(t, 5200);
    final (os, repo) = orderState(); await repo.seedDemoHistory('cust-rahul', 12); await repo.seedDemoHistory('someone-else', 1); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/FOTG-SEED-0002')); await t.pumpAndSettle(); // preparing
    expect(find.text('Order status: Preparing'), findsOneWidget); expect(find.text('Track order'), findsOneWidget); expect(find.text('Check availability & prices'), findsNothing);
    await tapText(t, 'Track order'); expect(find.text('tracking FOTG-SEED-0002'), findsOneWidget);
    await t.pumpWidget(app(os, auth, '/order/FOTG-NOPE-0000')); await t.pumpAndSettle();
    expect(find.text("We couldn't find that order"), findsOneWidget);
  });

  testWidgets('TEST 12 / 13 — reorder validates the current menu (price change), builds a NEW cart, and asks before replacing another restaurant\'s cart', (t) async {
    tall(t, 5200);
    final (os, repo) = orderState(); final auth = await signedIn(t);
    final old = await repo.createFromPayment(CreateOrderInput(paymentAttemptId: 'pa1', checkoutReference: 'ck', customerId: 'cust-rahul',
        restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'x', countryCode: 'IN', timezone: 'Asia/Kolkata'),
        items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [OrderOptionSnapshot(groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0)], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 20000, lineTotalMinor: 40000), OrderItemSnapshot(lineId: 'l2', menuItemId: 'chicken-wings', itemName: 'Chicken Wings', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 22000, lineTotalMinor: 22000)],
        pricing: const OrderPricing(currency: 'INR', subtotalMinor: 62000, discountMinor: 0, totalMinor: 62000),
        payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_old', paidAmountMinor: 62000, currency: 'INR'),
        pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 1, 9), estimatedReadyTime: DateTime.utc(2026, 9, 1, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'), orderNote: ''));
    await repo.applyEvents(old.orderNumber, [for (final (i, tp, st) in [(2, OrderEventType.restaurantAccepted, OrderStatus.accepted), (3, OrderEventType.preparing, OrderStatus.preparing), (4, OrderEventType.readyForPickup, OrderStatus.readyForPickup), (5, OrderEventType.pickedUp, OrderStatus.pickedUp), (6, OrderEventType.completed, OrderStatus.completed)]) OrderEvent(eventId: 'e$i', sequence: i, type: tp, status: st, at: DateTime.utc(2026, 9, 1, 10, i), actor: 'restaurant')]);
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(const AddItemInput(menuItemId: 'route-5-diner:pie', itemSlug: 'pie', itemName: 'Apple pie', image: '', basePriceMinor: 500, currency: 'USD', restaurantId: 'kettleman-diner', restaurantSlug: 'route-5-diner', restaurantName: 'Route 5 Diner', restaurantCurrency: 'USD', unitPriceMinor: 500));
    await t.pumpWidget(app(os, auth, '/order/${old.orderNumber}?reorder=1', cart: cart)); await t.pumpAndSettle();
    expect(find.text('Price changed'), findsOneWidget); expect(find.text('Was ₹200.00 each · now ₹250.00'), findsOneWidget);
    expect(find.text('Item no longer available'), findsOneWidget); expect(find.text('Open restaurant menu'), findsWidgets);
    expect(find.text('1 item(s) can be added at current prices · ₹500.00'), findsOneWidget);
    await tapText(t, 'Add 1 item(s) to a new cart');
    expect(find.textContaining('Your cart has items from Route 5 Diner'), findsOneWidget); expect(cart.cart!.items.length, 1);
    await tapText(t, 'Keep current cart'); expect(find.text('Replace cart'), findsNothing); expect(cart.cart!.restaurantId, 'kettleman-diner');
    await tapText(t, 'Add 1 item(s) to a new cart'); await tapText(t, 'Replace cart');
    expect(find.text('cart screen'), findsOneWidget);
    expect(cart.cart!.restaurantId, 'burger-hub'); expect(cart.cart!.items.single.quantity, 2); expect(cart.cart!.items.single.unitPriceMinor, 25000);
    expect(cart.cart!.items.single.itemName, 'Classic Burger');
  });
}
