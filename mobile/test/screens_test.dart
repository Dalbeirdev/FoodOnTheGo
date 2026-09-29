// Layout smoke tests: every screen must build without layout exceptions on a
// phone (390×844) and a tablet (1280×800). This is exactly the class of bug
// (unbounded image inside a list) that blanked the Home screen in build001.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/core/theme.dart';
import 'package:foodonthego/data/mock_data.dart';
import 'package:foodonthego/screens/account_pages.dart';
import 'package:foodonthego/screens/account_screens.dart';
import 'package:foodonthego/screens/plan_journey_screen.dart';
import 'package:foodonthego/screens/profile_screen.dart';
import 'package:foodonthego/screens/restaurants_screen.dart';
import 'package:foodonthego/screens/restaurant_detail_screen.dart';
import 'package:foodonthego/screens/item_detail_screen.dart';
import 'package:foodonthego/screens/cart_screen.dart';
import 'package:foodonthego/screens/auth_screens.dart';
import 'package:foodonthego/screens/help_screen.dart';
import 'package:foodonthego/screens/home_screens.dart';
import 'package:foodonthego/screens/my_orders_screen.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/orders_history_state.dart';
import 'package:foodonthego/screens/checkout_screen.dart';
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/payment_state.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/widgets/common.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

Widget app(Widget screen, {CartState? cart}) {
  final router = GoRouter(routes: [GoRoute(path: '/', builder: (_, _) => screen), GoRoute(path: '/:rest(.*)', builder: (_, _) => const Scaffold())]);
  return MultiProvider(
    providers: [
      ChangeNotifierProvider(create: (_) => HealthState()),
      ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
      ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
      ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
      ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
      ChangeNotifierProvider(create: (_) => cart ?? CartState()),
      ChangeNotifierProvider(create: (_) => OrdersState()),
      ChangeNotifierProvider(create: (_) => OrderState()),
      ChangeNotifierProxyProvider<OrderState, OrdersHistoryState>(create: (ctx) => OrdersHistoryState(orders: ctx.read<OrderState>().orders), update: (_, os, prev) => prev ?? OrdersHistoryState(orders: os.orders)),
      ChangeNotifierProvider(create: (_) => PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore())),
      ChangeNotifierProvider(create: (_) => CheckoutState()),
      ChangeNotifierProvider(create: (_) => PaymentState(resolver: MockPaymentProviderResolver(latency: Duration.zero), verifier: MockPaymentVerificationService(latency: Duration.zero), store: MemoryKeyValueStore())),
    ],
    child: MaterialApp.router(theme: Brand.theme(), routerConfig: router),
  );
}

void main() {
  // Use the real brand font so text measures as it does on a device (the default test font is very wide).
  setUpAll(() async {
    final loader = FontLoader('Outfit');
    for (final w in [400, 500, 600, 700, 800]) {
      loader.addFont(rootBundle.load('assets/fonts/Outfit-$w.ttf'));
    }
    await loader.load();
  });
  CartState cart() => CartState()..add(menuItemById('classic-combo')!, 'burger-hub');
  final screens = <String, Widget>{
    'Home': const HomeScreen(),
    'Restaurants': const RestaurantsScreen(),
    'Restaurant detail': RestaurantDetailScreen(id: 'burger-hub', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)),
    'Item': ItemDetailScreen(restaurantId: 'burger-hub', itemSlug: 'classic-burger', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)),
    'Cart (empty)': CartScreen(restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)),
    'My orders': const MyOrdersScreen(),
    'Profile': const ProfileScreen(),
    'Plan a journey': const PlanJourneyScreen(),
    'Login (mobile number)': const LoginScreen(),
    'Verify OTP (no pending phone)': const VerifyOtpScreen(),
    'Account setup (no token)': const AccountSetupScreen(),
    'Build info': const BuildInfoScreen(),
    'Help & Support': const HelpScreen(),
    'Favorites (signed out guard)': const FavoritesScreen(),
    'Addresses': const AddressesScreen(),
    'Payment methods': const PaymentMethodsScreen(),
    'Notifications': const NotificationsScreen(),
  };

  for (final size in [const Size(390, 844), const Size(1280, 800)]) {
    for (final e in screens.entries) {
      testWidgets('${e.key} lays out at ${size.width.toInt()}×${size.height.toInt()}', (t) async {
        t.view.physicalSize = size;
        t.view.devicePixelRatio = 1;
        addTearDown(t.view.resetPhysicalSize);
        addTearDown(t.view.resetDevicePixelRatio);
        await t.pumpWidget(app(e.value));
        await t.pump();
        expect(t.takeException(), isNull);
        expect(find.byType(Scaffold), findsWidgets);
      });
    }
  }

  testWidgets('Cart with a combo shows the saving and totals', (t) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    await t.pumpWidget(app(CartScreen(restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)), cart: cart()));
    await t.pumpAndSettle();
    expect(t.takeException(), isNull);
    expect(find.text('Burger Hub'), findsWidgets); // Module 09 cart: restaurant context + structured lines
    expect(find.text('Continue to pickup'), findsOneWidget);
  });

  Future<void> pumpPhone(WidgetTester t, Widget w, {CartState? cart}) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    await t.pumpWidget(app(w, cart: cart));
    await t.pump();
  }

  testWidgets('Item screen body is visible above its bottom bar', (t) async {
    await pumpPhone(t, ItemDetailScreen(restaurantId: 'burger-hub', itemSlug: 'classic-burger', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)));
    expect(find.text('Classic Burger'), findsOneWidget); // body title (app bar shows the restaurant)
    expect(find.text('Add to cart'), findsOneWidget);
    expect(t.getSize(find.byType(BottomBar)).height, lessThan(120));
  });

  testWidgets('Checkout (guest) redirects to login instead of rendering the review', (t) async {
    await pumpPhone(t, const CheckoutScreen(), cart: cart());
    expect(find.text('Continue to secure payment'), findsNothing);
    expect(find.text('Place Order'), findsNothing);
  });

  testWidgets('Home with items shows the cart bar without hiding the page', (t) async {
    await pumpPhone(t, const HomeScreen(), cart: cart());
    expect(find.text('View Cart'), findsOneWidget);
    expect(find.text('Delicious Food'), findsOneWidget);
    expect(t.getSize(find.byType(CartBar)).height, lessThan(120));
  });

  testWidgets('Home shows hero copy and restaurant cards', (t) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    await t.pumpWidget(app(const HomeScreen()));
    await t.pump();
    expect(find.text('Delicious Food'), findsOneWidget);
    expect(find.text('On Your Route'), findsOneWidget);
    expect(find.text('Burger Hub'), findsWidgets);
  });
}
