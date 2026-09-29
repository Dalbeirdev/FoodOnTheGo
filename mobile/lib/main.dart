import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'core/theme.dart';
import 'screens/account_pages.dart';
import 'screens/account_screens.dart';
import 'screens/plan_journey_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/restaurants_screen.dart';
import 'screens/restaurant_detail_screen.dart';
import 'screens/item_detail_screen.dart';
import 'screens/cart_screen.dart';
import 'screens/pickup_time_screen.dart';
import 'screens/checkout_screen.dart';
import 'screens/payment_screen.dart';
import 'screens/legal_screen.dart';
import 'screens/auth_screens.dart';
import 'screens/help_screen.dart';
import 'screens/home_screens.dart';
import 'screens/order_screens.dart';
import 'state/app_state.dart';
import 'state/account_state.dart';
import 'state/auth_state.dart';
import 'state/discovery_state.dart';
import 'state/pickup_state.dart';
import 'state/checkout_state.dart';
import 'state/payment_state.dart';
import 'state/order_state.dart';
import 'screens/order_confirmation_screen.dart';
import 'state/journey_state.dart';

void main() => runApp(const FoodOnTheGoApp());

/// Screens that need an account. Everything else is available to guests.
const protectedPrefixes = ['/my-orders', '/my-profile', '/checkout', '/order-confirmation', '/order-tracking', '/favorites', '/addresses', '/payment-methods', '/notifications'];

GoRouter buildRouter(AuthState auth) => GoRouter(
  initialLocation: '/',
  refreshListenable: auth,
  redirect: (context, state) {
    final path = state.uri.path;
    final needsAuth = protectedPrefixes.any((p) => path == p || path.startsWith('$p/'));
    if (needsAuth && !auth.loading && !auth.isAuthenticated) {
      auth.requireLoginFor(state.uri.toString());
      return auth.status == AuthStatus.sessionExpired ? '/login?reason=expired' : '/login';
    }
    return null;
  },
  routes: [
    GoRoute(path: '/', builder: (_, _) => const SplashScreen()),
    ShellRoute(
      builder: (_, state, child) => ShellScreen(location: state.uri.path, child: child),
      routes: [
        GoRoute(path: '/home', builder: (_, _) => const HomeScreen()),
        GoRoute(path: '/restaurants', builder: (_, s) => RestaurantsScreen(journeyId: s.uri.queryParameters['journey'])),
        // Canonical restaurant route is /restaurants/:slug — legacy /restaurant/:slug redirects.
        GoRoute(path: '/restaurant/:id', redirect: (_, s) => '/restaurants/${s.pathParameters['id']}'),
        GoRoute(path: '/plan-journey', builder: (_, _) => const PlanJourneyScreen()),
        GoRoute(path: '/my-orders', builder: (_, _) => const MyOrdersScreen()),
        GoRoute(path: '/my-profile', builder: (_, _) => const ProfileScreen()),
      ],
    ),
    GoRoute(path: '/restaurants/:id', builder: (_, s) => RestaurantDetailScreen(id: s.pathParameters['id']!)),
    GoRoute(path: '/restaurants/:id/item/:itemId', builder: (_, s) => ItemDetailScreen(restaurantId: s.pathParameters['id']!, itemSlug: s.pathParameters['itemId']!, editCartItemId: s.uri.queryParameters['edit'])),
    GoRoute(path: '/cart', builder: (_, _) => const CartScreen()),
    GoRoute(path: '/pickup-time', builder: (_, _) => const PickupTimeScreen()),
    GoRoute(path: '/checkout', builder: (_, _) => const CheckoutScreen()),
    GoRoute(path: '/payment', builder: (_, s) => PaymentScreen(key: ValueKey('payment-${s.uri.queryParameters['mock'] ?? ''}'), mockOutcome: s.uri.queryParameters['mock'])),
    GoRoute(path: '/legal/:slug', builder: (_, s) => LegalScreen(slug: s.pathParameters['slug']!)),
    GoRoute(path: '/order-confirmation/:number', builder: (_, s) => OrderConfirmationScreen(number: s.pathParameters['number']!)),
    GoRoute(path: '/order-tracking/:number', builder: (_, s) => OrderTrackingScreen(number: s.pathParameters['number']!)),
    GoRoute(path: '/help', builder: (_, _) => const HelpScreen()),
    GoRoute(path: '/favorites', builder: (_, _) => const FavoritesScreen()),
    GoRoute(path: '/addresses', builder: (_, _) => const AddressesScreen()),
    GoRoute(path: '/payment-methods', builder: (_, _) => const PaymentMethodsScreen()),
    GoRoute(path: '/notifications', builder: (_, _) => const NotificationsScreen()),
    GoRoute(path: '/login', builder: (_, s) => LoginScreen(reason: s.uri.queryParameters['reason'])),
    GoRoute(path: '/verify-otp', builder: (_, _) => const VerifyOtpScreen()),
    GoRoute(path: '/account-setup', builder: (_, _) => const AccountSetupScreen()),
    GoRoute(path: '/build-info', builder: (_, _) => const BuildInfoScreen()),
  ],
);

class FoodOnTheGoApp extends StatefulWidget {
  const FoodOnTheGoApp({super.key});
  @override
  State<FoodOnTheGoApp> createState() => _FoodOnTheGoAppState();
}

class _FoodOnTheGoAppState extends State<FoodOnTheGoApp> {
  final auth = AuthState();
  final account = AccountState();
  final journey = JourneyState();
  final discovery = DiscoveryState();
  late final GoRouter router = buildRouter(auth);

  @override
  void initState() {
    super.initState();
    auth.addListener(_onAuth);
    // Restore the session at app start so deep links to protected screens are guarded even when Splash is skipped.
    auth.restore();
  }

  void _onAuth() => account.bind(auth.user);

  @override
  void dispose() {
    auth.removeListener(_onAuth);
    super.dispose();
  }
  @override
  Widget build(BuildContext context) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider.value(value: auth),
          ChangeNotifierProvider.value(value: account),
          ChangeNotifierProvider.value(value: journey),
          ChangeNotifierProvider.value(value: discovery),
          ChangeNotifierProvider(create: (_) => CartState()),
          ChangeNotifierProvider(create: (_) => OrdersState()),
          ChangeNotifierProvider(create: (_) => PickupState()),
          ChangeNotifierProvider(create: (_) => CheckoutState()),
          ChangeNotifierProvider(create: (_) => PaymentState()),
          ChangeNotifierProvider(create: (_) => OrderState()),
        ],
        child: MaterialApp.router(title: 'FoodOnTheGo', theme: Brand.theme(), routerConfig: router, debugShowCheckedModeBanner: false),
      );
}
