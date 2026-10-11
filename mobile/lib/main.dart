import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'core/theme.dart';
import 'discovery/api_restaurants.dart';
import 'journey/api_journey.dart';
import 'market/api_market.dart';
import 'checkout/api_checkout.dart';
import 'order/api_order.dart';
import 'payment/api_payment.dart';
import 'pickup/api_pickup.dart';
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
import 'screens/payment_backend_screen.dart';
import 'screens/legal_screen.dart';
import 'screens/auth_screens.dart';
import 'screens/help_screen.dart';
import 'screens/home_screens.dart';
import 'screens/my_orders_screen.dart';
import 'screens/order_details_screen.dart';
import 'screens/review_screen.dart';
import 'screens/my_reviews_screen.dart';
import 'review/api_review.dart';
import 'auth/auth_repository.dart' show SecureKeyValueStore;
import 'state/app_state.dart';
import 'state/account_state.dart';
import 'state/auth_state.dart';
import 'state/cart_state.dart';
import 'widgets/cart_merge_prompt.dart';
import 'state/discovery_state.dart';
import 'state/pickup_state.dart';
import 'state/checkout_state.dart';
import 'state/payment_state.dart';
import 'state/payment_backend_state.dart';
import 'state/order_state.dart';
import 'state/tracking_state.dart';
import 'screens/order_tracking_screen.dart';
import 'state/orders_history_state.dart';
import 'screens/order_confirmation_screen.dart';
import 'state/journey_state.dart';
import 'auth/api_auth_repository.dart' show ApiAuthRepository;
import 'core/app_config.dart';
import 'data/api_client.dart';
import 'notifications/device_registrar.dart';
import 'notifications/push_notice.dart';
import 'notifications/push_runtime.dart';

/// With MARKET_MODE=api the market the app is served by (currency, locale, units, coverage) is loaded before the
/// first screen, whatever route the app starts on — and with RESTAURANT_MODE=api the restaurants a customer may see
/// (Module 23). If the backend cannot be reached the app still starts; the splash screen tries again and screens
/// that need the backend say so.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Future.wait([
    if (marketFromApi) marketRepository.hydrate().timeout(const Duration(seconds: 8), onTimeout: () => false),
    if (restaurantsFromApi) restaurantApi.hydrate().timeout(const Duration(seconds: 8), onTimeout: () => false),
  ]);
  runApp(const FoodOnTheGoApp());
}

/// Screens that need an account. Everything else is available to guests.
const protectedPrefixes = ['/my-orders', '/my-reviews', '/order/', '/my-profile', '/checkout', '/payment', '/order-confirmation', '/order-tracking', '/favorites', '/addresses', '/payment-methods', '/notifications'];

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
    // With the backend (PAYMENT_MODE=api) the payment step is the backend's; otherwise the development payment.
    GoRoute(path: '/payment', builder: (_, s) => paymentsFromApi ? const PaymentBackendScreen() : PaymentScreen(key: ValueKey('payment-${s.uri.queryParameters['mock'] ?? ''}'), mockOutcome: s.uri.queryParameters['mock'])),
    GoRoute(path: '/legal/:slug', builder: (_, s) => LegalScreen(slug: s.pathParameters['slug']!)),
    GoRoute(path: '/order-confirmation/:number', builder: (_, s) => OrderConfirmationScreen(number: s.pathParameters['number']!)),
    GoRoute(path: '/order-tracking/:number', builder: (_, s) => OrderTrackingScreen(number: s.pathParameters['number']!)),
    GoRoute(path: '/order/:number', builder: (_, s) => OrderDetailsScreen(number: s.pathParameters['number']!, startReorder: s.uri.queryParameters['reorder'] == '1')),
    GoRoute(path: '/order/:number/review', builder: (_, s) => ReviewScreen(number: s.pathParameters['number']!, draftStore: SecureKeyValueStore())),
    GoRoute(path: '/my-reviews', builder: (_, _) => const MyReviewsScreen()),
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
  late final account = AccountState(onUnauthenticated: auth.expire);
  final journey = JourneyState();
  final discovery = DiscoveryState();
  // The cart: the backend's when the build says so (CART_MODE=api), otherwise the device cart. With the backend
  // cart an item added from a journey carries that journey (its id, and the token that proves a guest's journey).
  late final CartBackend? cartBackend = cartsFromApi ? (CartBackend()..onUnauthenticated = auth.expire) : null;
  late final cart = CartState(
    backend: cartBackend,
    journeyRef: () async {
      final j = journey.journey;
      if (j == null || !j.backend || j.expired) return null;
      return (id: j.id, headers: await journeyApi.tokens.headers(j.id));
    },
  );
  // Pickup times: the backend's when the build says so (PICKUP_MODE=api, with the cart on the backend) — asked for
  // and chosen as the cart's owner. Otherwise the development slot engine in the app.
  late final pickup = PickupState(backend: pickupFromApi && cartBackend != null ? PickupBackend(cartBackend!) : null)..onCartOutdated = () { cart.reload(); };
  // Checkout on the backend (Module 29) goes out as the cart's owner does: it shares the cart's API client.
  late final checkout = CheckoutState(backend: checkoutFromApi && cartBackend != null ? CheckoutBackend(cartBackend!) : null);
  // Payments on the backend (Module 30) go out as the checkout does: as the signed-in customer.
  late final PaymentBackendState? paymentBackend = paymentsFromApi && cartBackend != null ? PaymentBackendState(backend: PaymentBackend(cartBackend!)) : null;
  String? _checkoutOwner;
  String? _deviceOwner;
  late final GoRouter router = buildRouter(auth);
  // Module 33: push (Firebase when configured, the development token source otherwise) and this installation's binding to the
  // signed-in customer. Started after the first frame; a notification problem never blocks the app or a sign-in.
  PushRuntime? push;
  DeviceRegistrar? devices;
  bool _permissionAsked = false;
  final messenger = GlobalKey<ScaffoldMessengerState>();

  @override
  void initState() {
    super.initState();
    auth.addListener(_onAuth);
    cart.addListener(_onCart);
    cart.ready.then((_) { _cartReady = true; _onCart(); });
    if (journeysFromApi) journeyApi.onUnauthenticated = auth.expire;
    // Restore the session at app start so deep links to protected screens are guarded even when Splash is skipped.
    auth.restore();
    auth.restore();
    if (AppConfig.authMode == 'api' && AppConfig.pushMode != 'off') _startPush();
  }

  Future<void> _startPush() async {
    final store = SecureKeyValueStore();
    final client = ApiClient(tokenProvider: () => store.read(ApiAuthRepository.tokenKey), onUnauthenticated: auth.expire);
    final runtime = await PushRuntime.start(installationId: () => DeviceRegistrar.installationIdFrom(store));
    final source = runtime.source;
    if (source == null || !mounted) return;
    final registrar = DeviceRegistrar(api: client, store: store, source: source, appVersion: '${AppConfig.appVersion}+${AppConfig.buildNumber}', locale: 'en-IN', timezone: DateTime.now().timeZoneName);
    registrar.listen(() => auth.isAuthenticated);
    ApiAuthRepository.installationIdProvider = registrar.installationId;
    runtime.foreground.listen(_onPushForeground);
    runtime.opened.listen(_onPushOpened);
    setState(() { push = runtime; devices = registrar; });
    if (auth.isAuthenticated) _bindDevice();
  }

  // After a sign-in: the OS permission is asked once per app session, in context, then the installation is bound.
  Future<void> _bindDevice() async {
    final d = devices;
    if (d == null) return;
    if (!_permissionAsked) { _permissionAsked = true; await d.requestPermission(); } else { await d.bind(); }
  }

  void _onPushForeground(PushNotice n) {
    account.refreshNotifications();
    final route = n.route;
    final here = router.routerDelegate.currentConfiguration.uri.path;
    if (route != null && here == route) return; // the live screen already shows the change — no second alert
    messenger.currentState?.showSnackBar(SnackBar(content: Text([n.title, n.body].whereType<String>().where((x) => x.isNotEmpty).join(' — ')), action: route == null ? null : SnackBarAction(label: 'View', onPressed: () => _open(route))));
  }

  void _onPushOpened(PushNotice n) { final route = n.route; if (route != null) _open(route); }

  // Navigation only: the target screen signs the customer in first and the backend decides what this customer may see.
  void _open(String route) {
    if (auth.isAuthenticated) { router.go(route); return; }
    auth.requireLoginFor(route);
    router.go('/login');
  }

  // Signing in claims the cart built as a guest; signing out goes back to a guest cart.
  void _onAuth() {
    account.bind(auth.user); cart.onSessionChanged(auth.isAuthenticated);
    // Module 33: the installation follows the session — bound on sign-in; the logout body unbinds it on the backend.
    if (auth.isAuthenticated && auth.user?.id != _deviceOwner) { _deviceOwner = auth.user?.id; _bindDevice(); } else if (!auth.isAuthenticated) { _deviceOwner = null; }
    // Signing out, or another customer on this phone: nothing of the earlier checkout may be shown or paid.
    final owner = auth.user?.id;
    if (checkout.backendMode && _checkoutOwner != null && _checkoutOwner != owner) { checkout.reset(); paymentBackend?.reset(); }
    _checkoutOwner = owner;
  }

  // The pickup choice belongs to the cart: when the cart appears, changes or goes, the backend's judgement of the
  // choice is read again (a changed cart may have made the chosen time unreachable).
  // Not before the cart has been read once: "no cart yet" is not "no cart".
  bool _cartReady = false;
  void _onCart() { if (_cartReady) pickup.syncWithCart(cart.cart); }

  @override
  void dispose() {
    auth.removeListener(_onAuth);
    cart.removeListener(_onCart);
    devices?.dispose();
    push?.dispose();
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
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => OrdersState()),
          ChangeNotifierProvider.value(value: pickup),
          ChangeNotifierProvider.value(value: checkout),
          ChangeNotifierProvider(create: (_) => PaymentState()),
          if (paymentBackend != null) ChangeNotifierProvider<PaymentBackendState>.value(value: paymentBackend!),
          Provider<DeviceRegistrar?>.value(value: devices),
          Provider<PushRuntime?>.value(value: push),
          // Orders (Module 31): the backend's when the build says so (ORDER_MODE=api, with payments on the backend) — read as the signed-in customer.
          // Reviews (Module 34): the backend's when the build says so (REVIEW_MODE=api) — eligibility, the review and the rating are the server's.
          ChangeNotifierProvider(create: (_) {
            if (!ordersFromApi || cartBackend == null) return OrderState();
            final orders = ApiOrderRepository(cartBackend!);
            final reviewApi = reviewsFromApi ? ReviewApi(cartBackend!.client) : null;
            return OrderState(orders: orders, verifications: ApiPickupVerificationRepository(orders), reviews: reviewApi == null ? null : ApiReviewRepository(reviewApi), reviewEligibility: reviewApi == null ? null : ApiReviewEligibilityService(reviewApi), reviewConfig: reviewApi == null ? null : ApiReviewConfigProvider(reviewApi));
          }),
          ChangeNotifierProxyProvider<OrderState, OrdersHistoryState>(create: (ctx) => OrdersHistoryState(orders: ctx.read<OrderState>().orders), update: (_, os, prev) => prev ?? OrdersHistoryState(orders: os.orders)),
          ChangeNotifierProxyProvider<OrderState, TrackingState>(create: (ctx) { final os = ctx.read<OrderState>(); final api = os.orders; return TrackingState(orders: api, verifications: os.verifications, realtime: api is ApiOrderRepository && realtimeFromApi && cartBackend != null ? customerRealtimeClient(cartBackend!) : null); }, update: (_, os, prev) => prev ?? TrackingState(orders: os.orders, verifications: os.verifications)),
        ],
        child: MaterialApp.router(title: 'FoodOnTheGo', theme: Brand.theme(), routerConfig: router, scaffoldMessengerKey: messenger, debugShowCheckedModeBanner: false, builder: (context, child) => CartMergePrompt(child: child ?? const SizedBox.shrink())),
      );
}
