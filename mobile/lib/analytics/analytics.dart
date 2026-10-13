/// Behavioural events of the Android app (Module 38) — the ONE door the screens and state classes use.
///
/// The app has no analytics screen: it only emits the ten events of the registry, with the same names and meaning as
/// the web application, to the FoodOnTheGo backend (`POST /analytics/events`). No third-party SDK, no advertising id,
/// no device id.
///
/// A call here is one line, is never awaited and can never fail: no customer flow depends on it or waits for it.
///
/// ENABLED only when the analytics mode is `api`:
///   - `--dart-define=ANALYTICS_MODE=api|off` when given, otherwise
///   - the build's order mode (CART, PICKUP, CHECKOUT, PAYMENT and ORDER all `api` — the app talks to the real backend).
/// Both are build-time values on purpose: a test that switches a domain to its backend with an override does NOT
/// switch analytics on. A test that wants events installs its own emitter with [Analytics.install].
library;

import 'package:flutter/widgets.dart';

import '../auth/api_auth_repository.dart' show ApiAuthRepository;
import '../auth/auth_repository.dart' show SecureKeyValueStore;
import '../core/app_config.dart';
import '../data/api_client.dart';
import '../discovery/api_restaurants.dart' show knownRestaurants;
import 'analytics_emitter.dart';

export 'analytics_emitter.dart' show AnalyticsEmitter, AnalyticsClock, AnalyticsTimerFactory;

const _definedMode = String.fromEnvironment('ANALYTICS_MODE', defaultValue: '');

/// `api` or `off`: what was asked for with ANALYTICS_MODE, otherwise what the order mode says.
String resolveAnalyticsMode({required String defined, required bool ordersOnBackend}) => defined == 'api' || defined == 'off' ? defined : (ordersOnBackend ? 'api' : 'off');

/// The order mode of this BUILD as the app resolves it (orders need payments, checkout, pickup and the cart on the backend).
bool get _buildOrdersOnBackend => AppConfig.cartMode == 'api' && AppConfig.pickupMode == 'api' && AppConfig.checkoutMode == 'api' && AppConfig.paymentMode == 'api' && AppConfig.orderMode == 'api';

/// The analytics mode of this build.
String get analyticsMode => resolveAnalyticsMode(defined: _definedMode, ordersOnBackend: _buildOrdersOnBackend);

/// The app going to the background sends what is queued; coming back gives events kept without a connection another try.
class _FlushWithTheApp with WidgetsBindingObserver {
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused || state == AppLifecycleState.resumed) Analytics.flush();
  }
}

abstract final class Analytics {
  static AnalyticsEmitter? _emitter;
  static bool _decided = false;

  static AnalyticsEmitter? get _current {
    if (!_decided) {
      _decided = true;
      if (analyticsMode == 'api') {
        try {
          _emitter = _build();
        } catch (_) {}
      }
    }
    return _emitter;
  }

  /// The app's own emitter: as the signed-in customer when there is one (the token is optional for this endpoint), and
  /// never with a session-expiry handler — a refused telemetry request must not sign anyone out.
  static AnalyticsEmitter _build() {
    final store = SecureKeyValueStore();
    final emitter = AnalyticsEmitter(api: ApiClient(tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), store: store, appVersion: '${AppConfig.appVersion}+${AppConfig.buildNumber}');
    try {
      WidgetsBinding.instance.addObserver(_FlushWithTheApp());
    } catch (_) {}
    return emitter;
  }

  /// Tests: use this emitter (null = none) instead of what the build would make.
  @visibleForTesting
  static void install(AnalyticsEmitter? emitter) {
    _emitter = emitter;
    _decided = true;
  }

  /// Tests: back to what the build decides.
  @visibleForTesting
  static void uninstall() {
    _emitter = null;
    _decided = false;
  }

  static bool get enabled => _current?.enabled ?? false;

  /// Records an event of the registry. Returns at once, never throws, is never awaited.
  static void track(String name, {Map<String, String?> refs = const {}, Map<String, Object?> properties = const {}, String? onceKey}) {
    try {
      _current?.track(name, refs: refs, properties: properties, onceKey: onceKey);
    } catch (_) {}
  }

  /// Sends what is queued. Nothing in the app waits for it.
  static Future<void> flush() async {
    try {
      await _current?.flush();
    } catch (_) {}
  }

  /// The local opt-out (default: on). There is no settings screen for it yet — the privacy module comes later.
  static Future<void> setConsent(bool allowed) async {
    try {
      await _current?.setConsent(allowed);
    } catch (_) {}
  }

  /* ------------------------------------------------------------------ the ten events, one line at each call site */

  /// The restaurant as the backend knows it (its location id, else its slug) for the id the app's states use.
  static String? restaurantRef(String? appId) {
    if (appId == null || appId.isEmpty) return null;
    try {
      final r = knownRestaurants.where((x) => x.id == appId || x.slug == appId || x.backendId == appId).firstOrNull;
      return r == null ? null : (r.backendId ?? r.slug);
    } catch (_) {
      return null;
    }
  }

  /// 1 — the traveller submitted the journey form (one per submission).
  static void journeySearchStarted() => track('journey_search_started');

  /// 2 — the restaurants on the route of a planned journey are shown, also when there are none. Once per journey.
  static void journeyResultsViewed(String journeyId, int results) => track('journey_results_viewed', refs: {'journey': journeyId}, properties: {'results': results}, onceKey: 'journey_results_viewed:$journeyId');

  /// 3 — the restaurant page is open and its details are shown. Once per restaurant. [journeyId] = opened in a
  /// journey context; [listed] = the restaurant is in the list the customer was browsing.
  static void restaurantViewed(String restaurant, {String? journeyId, bool listed = false}) =>
      track('restaurant_viewed', refs: {'restaurant': restaurant, 'journey': journeyId}, properties: {'origin': journeyId != null ? 'JOURNEY' : (listed ? 'BROWSE' : 'DIRECT')}, onceKey: 'restaurant_viewed:$restaurant');

  /// 4 — the restaurant's menu is displayed. Once per restaurant.
  static void menuViewed(String restaurant, {String? journeyId}) => track('menu_viewed', refs: {'restaurant': restaurant, 'journey': journeyId}, onceKey: 'menu_viewed:$restaurant');

  /// The detail of a menu item is open. Once per item.
  static void itemViewed(String restaurant, String itemId) => track('item_viewed', refs: {'restaurant': restaurant, 'item': itemId}, onceKey: 'item_viewed:$itemId');

  /// 5 — the BACKEND accepted an item into the cart (one per accepted add; never for an edit of a line).
  static void itemAddedToCart(String restaurant, {String? itemId, required int quantity}) => track('item_added_to_cart', refs: {'restaurant': restaurant, 'item': itemId}, properties: {'quantity': quantity});

  /// The pickup time screen is shown. Once per restaurant.
  static void pickupViewed({String? restaurant}) => track('pickup_viewed', refs: {'restaurant': restaurant}, onceKey: 'pickup_viewed:${restaurant ?? '-'}');

  /// 6 — the BACKEND accepted a pickup time (one per accepted selection).
  static void pickupSelected({String? restaurant, required bool asap, String? method, bool? recommended}) =>
      track('pickup_selected', refs: {'restaurant': restaurant}, properties: {'mode': asap ? 'ASAP' : 'SCHEDULED', 'method': method, 'recommended': recommended});

  /// 7 — the BACKEND opened a checkout. Once per checkout id.
  static void checkoutStarted(String checkoutId, {String? restaurant}) => track('checkout_started', refs: {'checkout': checkoutId, 'restaurant': restaurant}, onceKey: 'checkout_started:$checkoutId');

  /// 8 — the traveller pressed Pay and the BACKEND created a payment attempt. Once per checkout id: paying again is
  /// not another start. [method] is the backend's method type; one the registry does not name is OTHER.
  static void paymentStarted(String checkoutId, {String? method}) => track('payment_started',
      refs: {'checkout': checkoutId}, properties: {'method': method == null ? null : (const {'CARD', 'UPI', 'WALLET', 'NET_BANKING'}.contains(method) ? method : 'OTHER')}, onceKey: 'payment_started:$checkoutId');
}
