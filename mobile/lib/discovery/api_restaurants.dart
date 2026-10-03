/// Restaurants from the backend (Module 23) — mirrors customer-web/src/restaurants/api/restaurantData.ts.
///
/// With RESTAURANT_MODE=api the app loads the restaurants a customer may see (GET /restaurants: approved and inside
/// public coverage — the backend decides, nothing is added or hidden here) and the cuisine taxonomy at start-up, and
/// asks the backend again for one restaurant whenever its page opens. "Open now" is recomputed in the app from the
/// hours it was given, with the backend's own rules, so a page left open does not show a stale state. Whether orders
/// are possible (paused, pickup switched off, area not served) is taken as the backend said.
///
/// Menus come from the backend as well (menu/api_menu.dart, Module 24). Still development data, and labelled as such in
/// the product: carts, orders, ratings and reviews.
/// Route distance and detour are not part of this API (route discovery is a later module).
library;

import '../core/app_config.dart';
import '../data/api_client.dart';
import 'discovery_repository.dart' show globalRestaurants;
import 'restaurant_models.dart';

class RestaurantSnapshot {
  const RestaurantSnapshot({required this.restaurants, required this.cuisines, required this.loadedAt});
  final List<GlobalRestaurant> restaurants;
  /// Cuisine names in the backend's order (the taxonomy, not only what the listed restaurants use).
  final List<String> cuisines;
  final DateTime loadedAt;
}

/// What the backend served (RESTAURANT_MODE=api). Null = not loaded / mock mode.
RestaurantSnapshot? apiRestaurantData;

bool get restaurantsFromApi => AppConfig.restaurantMode == 'api' || restaurantModeOverride == 'api';

/// Tests switch the mode here; builds use the RESTAURANT_MODE dart-define.
String? restaurantModeOverride;

/// The restaurants the app may show: the backend's snapshot in API mode (empty until loaded — never the fixtures),
/// the development fixtures otherwise.
List<GlobalRestaurant> get knownRestaurants => restaurantsFromApi ? apiRestaurantData?.restaurants ?? const [] : globalRestaurants;

class ApiRestaurantRepository {
  ApiRestaurantRepository({ApiClient? client}) : _api = client ?? ApiClient();
  final ApiClient _api;

  static const _methods = {'COUNTER': PickupMethodCode.counter, 'CURBSIDE': PickupMethodCode.curbside, 'DRIVE_THROUGH': PickupMethodCode.driveThrough};
  static const refreshAfter = Duration(seconds: 60);

  static String? _absolute(String? url) => url != null && (url.startsWith('http://') || url.startsWith('https://')) ? url : null;

  /// The public API's restaurant onto the app model. The id stays the one the development menus and carts use for
  /// the same slug (the backend id is [GlobalRestaurant.backendId]); a restaurant the fixtures do not know keeps its
  /// slug as id and has no development menu. Ratings are development figures where the fixtures have them.
  static GlobalRestaurant toRestaurant(Map<String, dynamic> d) {
    final legacy = globalRestaurants.where((r) => r.slug == d['slug']).firstOrNull;
    final address = d['address'] as Map<String, dynamic>;
    final location = d['location'] as Map<String, dynamic>;
    final images = d['images'] as Map<String, dynamic>;
    final hours = d['hours'] as Map<String, dynamic>;
    final pickup = d['pickup'] as Map<String, dynamic>;
    final availability = d['availability'] as Map<String, dynamic>;
    final reason = availability['reason'] as String?;
    // Local fixtures carry web-relative image paths the app cannot load; real uploads (a later module) are URLs.
    final photos = <String>{
      ?_absolute((images['cover'] as Map<String, dynamic>?)?['url'] as String?),
      for (final g in images['gallery'] as List<dynamic>) ?_absolute((g as Map<String, dynamic>)['url'] as String?),
    }.toList();
    final level = d['price_level'] as int?;
    OpeningPeriod period(int day, Map<String, dynamic> p) => OpeningPeriod(day, p['opens_at'] as String, p['closes_at'] as String);
    return GlobalRestaurant(
      id: legacy?.id ?? d['slug'] as String,
      backendId: d['id'] as String,
      slug: d['slug'] as String,
      name: d['name'] as String,
      description: (d['description'] ?? d['short_description'] ?? '') as String,
      countryCode: address['country_code'] as String,
      timezone: d['timezone'] as String,
      lat: (location['latitude'] as num).toDouble(),
      lng: (location['longitude'] as num).toDouble(),
      address: AddressComponents(formatted: address['formatted'] as String, countryCode: address['country_code'] as String, line1: address['line1'] as String?, locality: address['city'] as String?, adminArea: address['region'] as String?, postalCode: address['postal_code'] as String?),
      cuisines: [for (final c in d['cuisines'] as List<dynamic>) (c as Map<String, dynamic>)['name'] as String],
      categories: [for (final c in (d['cuisines'] as List<dynamic>).take(1)) (c as Map<String, dynamic>)['name'] as String],
      images: photos.isNotEmpty ? photos : legacy?.images ?? const [],
      rating: legacy?.rating ?? 0,
      reviewCount: legacy?.reviewCount ?? 0,
      ratingIsSample: legacy != null,
      openingHours: OpeningHours(
        periods: [for (final day in hours['weekly'] as List<dynamic>) for (final p in (day as Map<String, dynamic>)['periods'] as List<dynamic>) period(day['day_of_week'] as int, p as Map<String, dynamic>)],
        special: [
          for (final s in hours['special'] as List<dynamic>)
            SpecialDay(date: (s as Map<String, dynamic>)['date'] as String, closed: s['is_closed'] == true, periods: [for (final p in s['periods'] as List<dynamic>) period(0, p as Map<String, dynamic>)], note: s['note'] as String?),
        ],
      ),
      currency: d['currency'] as String,
      priceLevel: level != null && level >= 1 && level <= 4 ? level : 2,
      prepTimeMin: (pickup['default_prep_minutes'] as int?) ?? 15,
      features: [for (final f in d['features'] as List<dynamic>) (f as Map<String, dynamic>)['name'] as String],
      status: availability['open_state'] == 'TEMPORARILY_CLOSED' ? RestaurantStatus.temporarilyClosed : RestaurantStatus.active,
      // For the screens "accepting" means an order could be placed if the restaurant is open: a pause, pickup switched
      // off and an area that is not being served all mean it cannot.
      acceptingOrders: availability['accepting_orders'] == true && reason != 'AREA_UNAVAILABLE' && reason != 'PICKUP_UNAVAILABLE',
      unavailableReason: reason,
      pickupMethods: [
        for (final m in pickup['methods'] as List<dynamic>)
          PickupMethodInfo(type: _methods[(m as Map<String, dynamic>)['method']] ?? PickupMethodCode.counter, instructions: m['instructions'] as String?, requiresVehicleInfo: m['requires_vehicle_info'] == true),
      ],
      pickupInstructions: pickup['instructions'] as String?,
      phone: d['phone'] as String?,
      website: d['website'] as String?,
      email: d['email'] as String?,
    );
  }

  /// Loads every restaurant the customer may see and the cuisine taxonomy into [apiRestaurantData]. Returns false
  /// when the backend cannot be reached — the previous snapshot, if any, is kept and nothing is invented. "No market
  /// serves customers here" is an answer, not an outage: the list is then empty.
  Future<bool> hydrate() async {
    try {
      final restaurants = <GlobalRestaurant>[];
      var last = 1;
      for (var page = 1; page <= last && page <= 20; page++) {
        final res = await _api.get('/restaurants', auth: false, query: {'page[size]': '100', 'page[number]': '$page'});
        restaurants.addAll([for (final r in res['data'] as List<dynamic>) toRestaurant(r as Map<String, dynamic>)]);
        last = ((res['meta'] as Map<String, dynamic>)['last_page'] as num?)?.toInt() ?? 1;
      }
      List<String> cuisines;
      try {
        cuisines = [for (final c in (await _api.get('/cuisines', auth: false))['data'] as List<dynamic>) (c as Map<String, dynamic>)['name'] as String];
      } catch (_) {
        cuisines = apiRestaurantData?.cuisines ?? const [];
      }
      apiRestaurantData = RestaurantSnapshot(restaurants: restaurants, cuisines: cuisines, loadedAt: DateTime.now());
      return true;
    } on ApiException catch (e) {
      if (e.code == 'market_unavailable') {
        apiRestaurantData = RestaurantSnapshot(restaurants: const [], cuisines: const [], loadedAt: DateTime.now());
        return true;
      }
      return apiRestaurantData != null;
    } catch (_) {
      return apiRestaurantData != null;
    }
  }

  /// Before a list is shown: reload the snapshot when it is older than a minute (pauses, approvals, edits).
  Future<void> ensureFresh() async {
    final loadedAt = apiRestaurantData?.loadedAt;
    if (loadedAt == null || DateTime.now().difference(loadedAt) > refreshAfter) await hydrate();
  }

  /// The current state of one restaurant, straight from the backend. Null = the customer may not see it (never
  /// existed, not approved, suspended, out of coverage) — it is then removed from the snapshot as well. When the
  /// request itself fails, the snapshot's version is returned so the page can still be read; otherwise it rethrows.
  Future<GlobalRestaurant?> fetchBySlug(String slug) async {
    final snapshot = apiRestaurantData;
    try {
      final restaurant = toRestaurant(await _api.get('/restaurants/${Uri.encodeComponent(slug)}', auth: false));
      if (snapshot != null) {
        final others = snapshot.restaurants.where((r) => r.slug != slug);
        apiRestaurantData = RestaurantSnapshot(restaurants: [...others, restaurant], cuisines: snapshot.cuisines, loadedAt: snapshot.loadedAt);
      }
      return restaurant;
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.notFound) {
        if (snapshot != null) apiRestaurantData = RestaurantSnapshot(restaurants: snapshot.restaurants.where((r) => r.slug != slug).toList(), cuisines: snapshot.cuisines, loadedAt: snapshot.loadedAt);
        return null;
      }
      final known = snapshot?.restaurants.where((r) => r.slug == slug || r.id == slug).firstOrNull;
      if (known != null) return known;
      rethrow;
    }
  }
}

ApiRestaurantRepository _shared = ApiRestaurantRepository();
ApiRestaurantRepository get restaurantApi => _shared;
set restaurantApi(ApiRestaurantRepository repo) => _shared = repo;
