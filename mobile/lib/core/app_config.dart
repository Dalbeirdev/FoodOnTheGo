/// Build-time configuration injected with `--dart-define`.
///
/// Local (emulator):  --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://10.0.2.2:8001/api/v1
/// Local (phone/LAN): --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.221:8001/api/v1
/// Local (USB):       adb reverse tcp:8001 tcp:8001 then API_BASE_URL=http://127.0.0.1:8001/api/v1
class AppConfig {
  static const String env = String.fromEnvironment('APP_ENV', defaultValue: 'local');
  static const String apiBaseUrl =
      String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8001/api/v1');
  /// 'api' = sign in against the backend (Module 21); 'mock' = the in-app development mock (default, used by tests).
  static const String authMode = String.fromEnvironment('AUTH_MODE', defaultValue: 'mock');

  /// 'api' = market, currency, units and coverage come from the backend (Module 22); 'mock' = bundled fixtures (default, used by tests).
  static const String marketMode = String.fromEnvironment('MARKET_MODE', defaultValue: 'mock');

  /// 'api' = restaurants, opening hours, cuisines, pickup methods and availability come from the backend (Module 23);
  /// 'mock' = bundled fixtures (default, used by tests). Menus follow this mode too (Module 24); carts, orders and reviews are development data in both.
  static const String restaurantMode = String.fromEnvironment('RESTAURANT_MODE', defaultValue: 'mock');

  /// 'api' = places, journey planning, the route and the restaurants along it come from the backend (Module 26: the
  /// routing provider's route, actual detours, arrival estimates); 'mock' = the in-app development providers (default, used by tests).
  static const String journeyMode = String.fromEnvironment('JOURNEY_MODE', defaultValue: 'mock');

  /// 'api' = the cart is the backend's (Module 27: one per guest token or signed-in customer; prices, validation and
  /// issues are the server's); 'mock' = the device-only development cart (default, used by tests).
  static const String cartMode = String.fromEnvironment('CART_MODE', defaultValue: 'mock');

  /// 'api' = pickup times are the backend's (Module 28: ASAP, slots, capacity state and the arrival-aware
  /// recommendation on the server's clock in the restaurant's time zone; the choice is stored with the cart and
  /// validated again on every read). Needs CART_MODE=api. 'mock' = the development slot engine in the app (default, used by tests).
  static const String pickupMode = String.fromEnvironment('PICKUP_MODE', defaultValue: 'mock');
  /// 'api' = checkout is the backend's (final validation, promotion, configured taxes and fees, the total, the legal
  /// documents, the pickup-time hold, the payment preparation — Module 29); 'mock' = the development checkout.
  /// Needs CART_MODE=api and PICKUP_MODE=api.
  static const String checkoutMode = String.fromEnvironment('CHECKOUT_MODE', defaultValue: 'mock');

  /// 'api' = payments are the backend's (Module 30: payment attempts, the provider's own checkout screen, server-side
  /// verification, recovery after a restart); 'mock' = the development payment in the app (default, used by tests).
  /// Needs CHECKOUT_MODE=api. Which provider is used — and in which mode — is the backend's configuration, not the app's.
  static const String paymentMode = String.fromEnvironment('PAYMENT_MODE', defaultValue: 'mock');

  /// LOCAL BUILDS ONLY: the backend's fixed test code, shown on the OTP screen so testers can sign in.
  /// Empty in staging / production builds.
  static const String devOtp = String.fromEnvironment('DEV_OTP', defaultValue: '');
  static const String buildLabel = String.fromEnvironment('BUILD_LABEL', defaultValue: 'dev');
  static const String gitCommit = String.fromEnvironment('GIT_COMMIT', defaultValue: 'no-git');
  static const String appVersion = '0.1.0';
  static const int buildNumber = 34;

  static bool get isLocal => env == 'local';
  static bool get isProduction => env == 'production';

  /// Safe, secret-free summary for the Build Info screen and logs.
  static Map<String, String> get summary => {
        'Environment': env.toUpperCase(),
        'API base URL': apiBaseUrl,
        'Authentication': authMode == 'api' ? 'Backend (phone + OTP)' : 'Development mock',
        'Market data': marketMode == 'api' ? 'Backend (PostGIS coverage)' : 'Development fixtures',
        'Restaurants': restaurantMode == 'api' ? 'Backend, menus included (orders and reviews still development data)' : 'Development fixtures',
        'Journeys': journeyMode == 'api' ? 'Backend (places, route, detours and arrival times from the routing provider)' : 'Development providers in the app',
        'Cart': cartMode == 'api' ? 'Backend (priced and validated by the server)' : 'Development cart on this device',
        'Checkout': cartMode == 'api' && pickupMode == 'api' && checkoutMode == 'api' ? 'Backend (final validation, promotion, configured taxes and fees, total, pickup-time hold)' : 'Development checkout in the app',
        'Payments': cartMode == 'api' && pickupMode == 'api' && checkoutMode == 'api' && paymentMode == 'api' ? 'Backend (payment attempts verified by the server with the payment provider; the provider and its mode are set on the server; orders are not created yet)' : 'Development payment in the app — no money moves',
        'Pickup times': cartMode == 'api' && pickupMode == 'api' ? 'Backend (ASAP, slots and capacity on the server clock; a chosen time is selected, not reserved)' : 'Development slot engine in the app',
        'Account': authMode == 'api' ? 'Backend (profile, favorites, saved places, payment references, notification preferences)' : 'Development data',
        'App version': '$appVersion (build $buildNumber)',
        'Build label': buildLabel,
        'Git commit': gitCommit,
      };
}
