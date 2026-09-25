import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'core/theme.dart';
import 'screens/account_screens.dart';
import 'screens/auth_screens.dart';
import 'screens/help_screen.dart';
import 'screens/home_screens.dart';
import 'screens/order_screens.dart';
import 'state/app_state.dart';
import 'state/auth_state.dart';

void main() => runApp(const FoodOnTheGoApp());

/// Screens that need an account. Everything else is available to guests.
const protectedPrefixes = ['/my-orders', '/my-profile', '/checkout', '/order-confirmation', '/order-tracking'];

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
        GoRoute(path: '/restaurants', builder: (_, s) => RestaurantsScreen(from: s.uri.queryParameters['from'], to: s.uri.queryParameters['to'])),
        GoRoute(path: '/plan-journey', builder: (_, _) => const PlanJourneyScreen()),
        GoRoute(path: '/my-orders', builder: (_, _) => const MyOrdersScreen()),
        GoRoute(path: '/my-profile', builder: (_, _) => const ProfileScreen()),
      ],
    ),
    GoRoute(path: '/restaurants/:id', builder: (_, s) => RestaurantDetailScreen(id: s.pathParameters['id']!)),
    GoRoute(path: '/restaurants/:id/item/:itemId', builder: (_, s) => ItemScreen(restaurantId: s.pathParameters['id']!, itemId: s.pathParameters['itemId']!)),
    GoRoute(path: '/cart', builder: (_, _) => const CartScreen()),
    GoRoute(path: '/checkout', builder: (_, _) => const CheckoutScreen()),
    GoRoute(path: '/order-confirmation/:number', builder: (_, s) => OrderConfirmationScreen(number: s.pathParameters['number']!)),
    GoRoute(path: '/order-tracking/:number', builder: (_, s) => OrderTrackingScreen(number: s.pathParameters['number']!)),
    GoRoute(path: '/help', builder: (_, _) => const HelpScreen()),
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
  late final GoRouter router = buildRouter(auth);
  @override
  Widget build(BuildContext context) => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => HealthState()),
          ChangeNotifierProvider.value(value: auth),
          ChangeNotifierProvider(create: (_) => CartState()),
          ChangeNotifierProvider(create: (_) => OrdersState()),
        ],
        child: MaterialApp.router(title: 'FoodOnTheGo', theme: Brand.theme(), routerConfig: router, debugShowCheckedModeBanner: false),
      );
}
