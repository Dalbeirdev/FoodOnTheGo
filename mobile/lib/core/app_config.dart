/// Build-time configuration injected with `--dart-define`.
///
/// Local (emulator):  --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://10.0.2.2:8001/api/v1
/// Local (phone/LAN): --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.221:8001/api/v1
/// Local (USB):       adb reverse tcp:8001 tcp:8001 then API_BASE_URL=http://127.0.0.1:8001/api/v1
///
/// Module 39 (C-03): the environment is no longer decided by APP_ENV alone. A `--flavor production` build that forgot
/// `--dart-define=APP_ENV=production` used to run as "local": development panels, the fake-provider sheet, the
/// development code on the OTP screen and an `http://10.0.2.2` API. Now the Gradle flavor (Flutter's [appFlavor])
/// decides first — see [BuildEnvironment] — and a production build with an unusable configuration shows
/// [UnconfiguredBuildScreen] instead of running on development data.
library;

import 'package:flutter/services.dart' show appFlavor;

/// The pure decisions behind [AppConfig.env], [AppConfig.apiBaseUrl] and [AppConfig.configurationProblems], kept apart
/// so that unit tests can feed any flavor / environment / URL (a test run itself has no flavor).
class BuildEnvironment {
  BuildEnvironment._();

  /// The API of a local build when API_BASE_URL is not given: the developer PC as seen from the Android emulator.
  static const localDefaultApiBaseUrl = 'http://10.0.2.2:8001/api/v1';

  /// The environment the app runs as. The `production` flavor is always production and the `staging` flavor is never
  /// local, whatever APP_ENV says; without a flavor (tests, `flutter run -d web-server`) APP_ENV decides as before.
  static String effectiveEnv({required String? flavor, required String definedEnv}) {
    if (flavor == 'production') return 'production';
    if (flavor == 'staging' && (definedEnv == 'local' || definedEnv.isEmpty)) return 'staging';
    return definedEnv.isEmpty ? 'local' : definedEnv;
  }

  /// The API base URL: the one given with API_BASE_URL; without it only a LOCAL build falls back to the emulator's
  /// plain-HTTP address — a staging or production build gets none (and is then "not configured").
  static String apiBaseUrl({required String env, required String definedUrl}) {
    if (definedUrl.isNotEmpty) return definedUrl;
    return env == 'local' ? localDefaultApiBaseUrl : '';
  }

  /// The realtime (Reverb) schemes that give an encrypted WebSocket (wss://) — see PusherClient.uri.
  static const secureRealtimeSchemes = {'https', 'wss'};

  /// The push mode of a real build: Firebase Cloud Messaging ('fake' is the development token source, 'off' no push).
  static const realPushMode = 'fcm';

  /// What makes a PRODUCTION build unusable; empty when it may run (and always empty for other environments).
  /// Besides the API and the domain modes (Module 39 follow-up): the WebSocket must be encrypted (REVERB_SCHEME
  /// `https` / `wss`; the define defaults to `http`), push must be the real provider (PUSH_MODE=fcm; the define
  /// defaults to `off`) and no DEV_OTP may be compiled in (the value is never shown — only that one is set).
  static List<String> configurationProblems({
    required String env,
    required String apiBaseUrl,
    required Map<String, String> modes,
    required String realtimeScheme,
    required String pushMode,
    required String devOtp,
  }) {
    if (env != 'production') return const [];
    return [
      if (apiBaseUrl.isEmpty) 'API_BASE_URL is not set'
      else if (!apiBaseUrl.startsWith('https://')) 'API_BASE_URL is not an https:// address',
      for (final e in modes.entries)
        if (e.value != 'api') '${e.key} is "${e.value}", not "api"',
      if (!secureRealtimeSchemes.contains(realtimeScheme)) 'REVERB_SCHEME is "$realtimeScheme", not "https" or "wss"',
      if (pushMode != realPushMode) 'PUSH_MODE is "$pushMode", not "$realPushMode"',
      if (devOtp.isNotEmpty) 'DEV_OTP is set (a production build must not carry a development code)',
    ];
  }
}

class AppConfig {
  /// APP_ENV as given with `--dart-define` (default `local`). Use [env], which also takes the build flavor into account.
  static const String definedEnv = String.fromEnvironment('APP_ENV', defaultValue: 'local');
  static const String definedApiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: '');

  /// Module 39: the environment this build runs as — the flavor first, then APP_ENV ([BuildEnvironment.effectiveEnv]).
  static final String env = BuildEnvironment.effectiveEnv(flavor: appFlavor, definedEnv: definedEnv);
  /// Module 39: API_BASE_URL; the plain-HTTP emulator default only in a local build ([BuildEnvironment.apiBaseUrl]).
  static final String apiBaseUrl = BuildEnvironment.apiBaseUrl(env: env, definedUrl: definedApiBaseUrl);
  /// 'api' = sign in against the backend (Module 21); 'mock' = the in-app development mock (default, used by tests).
  static const String authMode = String.fromEnvironment('AUTH_MODE', defaultValue: 'mock');

  /// 'api' = market, currency, units and coverage come from the backend (Module 22); 'mock' = bundled fixtures (default, used by tests).
  static const String marketMode = String.fromEnvironment('MARKET_MODE', defaultValue: 'mock');

  /// 'api' = restaurants, opening hours, cuisines, pickup methods and availability come from the backend (Module 23);
  /// 'mock' = bundled fixtures (default, used by tests). Menus follow this mode too (Module 24). Carts, orders and reviews have their own modes.
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

  /// 'api' = orders are the backend's (Module 31: created on the server from the verified payment, read by order
  /// number, tracked by polling until Module 32); 'mock' = the development orders in the app (default, used by tests).
  /// Needs PAYMENT_MODE=api.
  static const String orderMode = String.fromEnvironment('ORDER_MODE', defaultValue: 'mock');
  /// Module 34: api = reviews, ratings and the restaurant's replies come from the backend (needs ORDER_MODE=api: a review is
  /// about a backend order); mock = the development reviews kept on this device (default, used by tests).
  static const String reviewMode = String.fromEnvironment('REVIEW_MODE', defaultValue: 'mock');
  /// Module 32: api = live order updates over WebSocket (Laravel Reverb; needs ORDER_MODE=api); off = poll only.
  static const String realtimeMode = String.fromEnvironment('REALTIME_MODE', defaultValue: 'off');
  static const String reverbHost = String.fromEnvironment('REVERB_HOST', defaultValue: '10.0.2.2');
  static const int reverbPort = int.fromEnvironment('REVERB_PORT', defaultValue: 8085);
  /// The backend's REVERB_APP_KEY — public to clients by design; the secret never reaches the app.
  static const String reverbKey = String.fromEnvironment('REVERB_APP_KEY', defaultValue: '');
  static const String reverbScheme = String.fromEnvironment('REVERB_SCHEME', defaultValue: 'http');

  /// Module 33: how this build receives push. fcm = Firebase Cloud Messaging (needs FIREBASE_* below; no google-services.json —
  /// the options come from these defines); fake = a deterministic token for a machine without a Firebase project (the backend's
  /// development provider); off = no push (the in-app inbox still works). Real FCM = PENDING FIREBASE CONFIGURATION.
  static const String pushMode = String.fromEnvironment('PUSH_MODE', defaultValue: 'off');
  /// Firebase CLIENT configuration (the Android app's, public by design — never the server's service account).
  static const String firebaseApiKey = String.fromEnvironment('FIREBASE_API_KEY', defaultValue: '');
  static const String firebaseAppId = String.fromEnvironment('FIREBASE_APP_ID', defaultValue: '');
  static const String firebaseSenderId = String.fromEnvironment('FIREBASE_SENDER_ID', defaultValue: '');
  static const String firebaseProjectId = String.fromEnvironment('FIREBASE_PROJECT_ID', defaultValue: '');
  /// LOCAL ONLY: a word in the fake token the development provider reacts to (unregistered | transient | quota | authfail).
  static const String pushFakeMarker = String.fromEnvironment('PUSH_FAKE_MARKER', defaultValue: '');

  /// LOCAL BUILDS ONLY: the backend's fixed test code, shown on the OTP screen so testers can sign in.
  /// Empty in staging / production builds.
  static const String devOtp = String.fromEnvironment('DEV_OTP', defaultValue: '');
  static const String buildLabel = String.fromEnvironment('BUILD_LABEL', defaultValue: 'dev');
  static const String gitCommit = String.fromEnvironment('GIT_COMMIT', defaultValue: 'no-git');
  static const String appVersion = '0.1.0';
  static const int buildNumber = 42;

  static bool get isLocal => env == 'local';
  static bool get isProduction => env == 'production';

  /// Module 39: why this build may not run (production only): no https API, a domain still on development data, an
  /// unencrypted WebSocket, push not on FCM, or a DEV_OTP compiled in.
  static List<String> get configurationProblems => BuildEnvironment.configurationProblems(env: env, apiBaseUrl: apiBaseUrl, modes: const {
        'AUTH_MODE': authMode,
        'MARKET_MODE': marketMode,
        'RESTAURANT_MODE': restaurantMode,
        'JOURNEY_MODE': journeyMode,
        'CART_MODE': cartMode,
        'PICKUP_MODE': pickupMode,
        'CHECKOUT_MODE': checkoutMode,
        'PAYMENT_MODE': paymentMode,
        'ORDER_MODE': orderMode,
        'REVIEW_MODE': reviewMode,
      }, realtimeScheme: reverbScheme, pushMode: pushMode, devOtp: devOtp);

  /// Module 39 (C-03): the backend's local test code may be shown only in a LOCAL build, never only because DEV_OTP
  /// was defined.
  static String get localDevOtp => isLocal ? devOtp : '';

  /// Safe, secret-free summary for the Build Info screen and logs.
  static Map<String, String> get summary => {
        'Environment': env.toUpperCase(),
        'API base URL': apiBaseUrl,
        'Authentication': authMode == 'api' ? 'Backend (phone + OTP)' : 'Development mock',
        'Market data': marketMode == 'api' ? 'Backend (PostGIS coverage)' : 'Development fixtures',
        'Restaurants': restaurantMode == 'api' ? 'Backend, menus included' : 'Development fixtures',
        'Journeys': journeyMode == 'api' ? 'Backend (places, route, detours and arrival times from the routing provider)' : 'Development providers in the app',
        'Cart': cartMode == 'api' ? 'Backend (priced and validated by the server)' : 'Development cart on this device',
        'Checkout': cartMode == 'api' && pickupMode == 'api' && checkoutMode == 'api' ? 'Backend (final validation, promotion, configured taxes and fees, total, pickup-time hold)' : 'Development checkout in the app',
        'Payments': cartMode == 'api' && pickupMode == 'api' && checkoutMode == 'api' && paymentMode == 'api' ? 'Backend (payment attempts verified by the server with the payment provider; the provider and its mode are set on the server)' : 'Development payment in the app — no money moves',
        'Orders': cartMode == 'api' && pickupMode == 'api' && checkoutMode == 'api' && paymentMode == 'api' && orderMode == 'api' ? 'Backend (created by the server from the verified payment; order number, pickup code and status are the server\'s; tracking by polling until realtime arrives)' : 'Development orders on this device',
        'Reviews': cartMode == 'api' && pickupMode == 'api' && checkoutMode == 'api' && paymentMode == 'api' && orderMode == 'api' && reviewMode == 'api' ? 'Backend (one review per completed order; eligibility, moderation and the rating are the server\'s)' : 'Development reviews on this device',
        'Live updates': realtimeMode == 'api' && orderMode == 'api' && reverbKey.isNotEmpty ? 'WebSocket (Laravel Reverb at $reverbHost:$reverbPort) with polling as the fallback' : 'Polling only (REALTIME_MODE=off or no key)',
        'Pickup times': cartMode == 'api' && pickupMode == 'api' ? 'Backend (ASAP, slots and capacity on the server clock; a chosen time is selected, not reserved)' : 'Development slot engine in the app',
        'Account': authMode == 'api' ? 'Backend (profile, favorites, saved places, payment references, notification preferences)' : 'Development data',
        'App version': '$appVersion (build $buildNumber)',
        'Build label': buildLabel,
        'Git commit': gitCommit,
      };
}
