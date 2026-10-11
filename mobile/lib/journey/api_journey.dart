/// Journeys on the backend (Module 26) — mirrors customer-web/src/journey/api/apiJourney.ts.
///
///   GET  /places/search?q=              suggestions of the backend's places provider, inside the market's country
///   POST /places/resolve {place_id}     coordinates, time zone and the coverage answer for one suggestion
///   POST /journeys/plan                 the route from the routing provider + an EXPIRING journey context
///   GET  /journeys/{id}                 the journey, for its owner only
///   GET  /journeys/{id}/restaurants     restaurants along the route with ACTUAL detours and arrival estimates
///
/// Nothing about a route is calculated in the app: distances, durations, geometry, detours, arrival times and "open
/// at arrival" are the backend's. A guest's journey is readable only with the token the backend issued when it was
/// planned; the app keeps it in the platform's secure storage and sends it as X-Journey-Token — never in a URL.
library;

import 'dart:convert';

import '../auth/api_auth_repository.dart' show ApiAuthRepository;
import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../core/app_config.dart';
import '../data/api_client.dart';
import '../discovery/api_restaurants.dart';
import '../discovery/restaurant_models.dart';
import '../market/market.dart';
import 'journey_repositories.dart';

bool get journeysFromApi => AppConfig.journeyMode == 'api' || journeyModeOverride == 'api';

/// Tests switch the mode here; builds use the JOURNEY_MODE dart-define.
String? journeyModeOverride;

/* ------------------------------------------------------------------ errors */

const _messages = <JourneyErrorCode, String>{
  JourneyErrorCode.noRoute: 'No road route is available between these locations.',
  JourneyErrorCode.marketUnsupported: "FoodOnTheGo isn't available at one of these locations yet. Choose places in a supported area.",
  JourneyErrorCode.crossBorder: 'Journeys between two countries are not supported yet.',
  JourneyErrorCode.providerUnavailable: 'Route planning is temporarily unavailable. Please try again in a moment.',
  JourneyErrorCode.rateLimited: 'Too many requests. Please wait a moment and try again.',
  JourneyErrorCode.network: 'Cannot reach FoodOnTheGo right now. Check your connection and try again.',
  JourneyErrorCode.expired: 'This journey has expired. Plan it again to see current restaurants.',
  JourneyErrorCode.placeNotFound: 'This place could not be found. Pick another suggestion.',
};

/// The backend's error vocabulary as the journey screens understand it. The server's own message is kept when it
/// explains a field.
JourneyException toJourneyException(Object e, [String fallback = 'Could not prepare the journey. Please try again.']) {
  if (e is JourneyException) return e;
  if (e is! ApiException) return JourneyException(JourneyErrorCode.unexpected, fallback);
  final JourneyErrorCode code;
  if (e.kind == ApiErrorKind.network) {
    code = JourneyErrorCode.network;
  } else {
    code = switch (e.code) {
      'route_not_found' => JourneyErrorCode.noRoute,
      'market_unsupported' || 'market_unavailable' => JourneyErrorCode.marketUnsupported,
      'cross_border_unsupported' => JourneyErrorCode.crossBorder,
      'place_not_found' => JourneyErrorCode.placeNotFound,
      'journey_expired' => JourneyErrorCode.expired,
      'journey_not_found' => JourneyErrorCode.notFound,
      _ => e.status == 429 || e.code == 'provider_rate_limited'
          ? JourneyErrorCode.rateLimited
          : e.status == 503 || e.kind == ApiErrorKind.server
              ? JourneyErrorCode.providerUnavailable
              : e.kind == ApiErrorKind.validation
                  ? JourneyErrorCode.invalid
                  : e.kind == ApiErrorKind.notFound
                      ? JourneyErrorCode.notFound
                      : JourneyErrorCode.unexpected,
    };
  }
  final firstField = e.errors.values.where((v) => v.isNotEmpty).map((v) => v.first).firstOrNull;
  return JourneyException(code, code == JourneyErrorCode.invalid ? firstField ?? e.message : _messages[code] ?? (e.message.isNotEmpty ? e.message : fallback));
}

/* ------------------------------------------------------------------ guest journey tokens */

/// The newest five guest tokens. Secure storage, so a journey survives the app being put in the background. A token
/// is kept for an hour after its journey expired — the time the backend still answers "expired" to the owner (without
/// the token the same link would read "not found on this device") — and dropped after that.
class JourneyTokens {
  JourneyTokens(this._store);
  final KeyValueStore _store;
  static const storageKey = 'fotg.journey.tokens';
  static const keptAfterExpiry = Duration(hours: 1);
  Map<String, (String, int)>? _cache;

  Future<Map<String, (String, int)>> _all() async {
    var all = _cache;
    if (all == null) {
      all = {};
      try {
        final raw = await _store.read(storageKey);
        if (raw != null) {
          (jsonDecode(raw) as Map<String, dynamic>).forEach((id, v) => all![id] = ((v as Map<String, dynamic>)['token'] as String, v['expiresAt'] as int));
        }
      } catch (_) {/* unreadable storage: the journey lasts for this run of the app only */}
    }
    final now = DateTime.now().millisecondsSinceEpoch;
    all.removeWhere((_, v) => v.$2 + keptAfterExpiry.inMilliseconds <= now);
    return _cache = all;
  }

  Future<void> _save(Map<String, (String, int)> all) async {
    _cache = all;
    try {
      await _store.write(storageKey, all.isEmpty ? null : jsonEncode({for (final e in all.entries) e.key: {'token': e.value.$1, 'expiresAt': e.value.$2}}));
    } catch (_) {/* storage unavailable */}
  }

  Future<String?> get(String id) async => (await _all())[id]?.$1;

  Future<void> set(String id, String token, DateTime expiresAt) async {
    final all = await _all();
    final kept = all.entries.where((e) => e.key != id).toList();
    await _save({for (final e in kept.skip(kept.length > 4 ? kept.length - 4 : 0)) e.key: e.value, id: (token, expiresAt.millisecondsSinceEpoch)});
  }

  Future<void> forget(String id) async {
    final all = await _all();
    if (all.remove(id) != null) await _save(all);
  }

  Future<Map<String, String>> headers(String id) async {
    final token = await get(id);
    return token == null ? const {} : {'X-Journey-Token': token};
  }
}

/* ------------------------------------------------------------------ mapping */

const _kinds = {'city': LocationKind.city, 'station': LocationKind.station, 'airport': LocationKind.airport, 'landmark': LocationKind.landmark, 'address': LocationKind.landmark, 'saved': LocationKind.saved, 'point': LocationKind.landmark};

PlaceCoverage _coverage(Map<String, dynamic> c) => PlaceCoverage(supported: c['supported'] == true, reason: c['reason'] as String?, city: c['city'] as String?, serviceArea: c['service_area'] as String?);

/// A suggestion is text only: it has no coordinates until the backend resolves it.
JourneyLocation toSuggestion(Map<String, dynamic> d) => JourneyLocation(
      id: d['place_id'] as String, name: d['name'] as String, sub: (d['formatted_address'] as String?) ?? '', kind: _kinds[d['kind']] ?? LocationKind.landmark, source: 'place',
      placeId: d['place_id'] as String, formattedAddress: d['formatted_address'] as String?, countryCode: d['country_code'] as String?, adminArea: d['region'] as String?, locality: d['locality'] as String?,
    );

JourneyLocation toResolved(JourneyLocation l, Map<String, dynamic> d) {
  final loc = d['location'] as Map<String, dynamic>;
  return l.copyWith(
    name: l.name.isEmpty ? (d['place'] as Map<String, dynamic>)['name'] as String : null,
    lat: (loc['latitude'] as num).toDouble(), lng: (loc['longitude'] as num).toDouble(), formattedAddress: loc['formatted_address'] as String?, countryCode: loc['country_code'] as String?,
    adminArea: loc['region'] as String?, locality: loc['locality'] as String?, postalCode: loc['postal_code'] as String?, timezone: loc['timezone'] as String?, coverage: _coverage(d['coverage'] as Map<String, dynamic>),
  );
}

JourneyLocation toRecentPlace(Map<String, dynamic> d) {
  final loc = d['location'] as Map<String, dynamic>;
  return JourneyLocation(
    id: 'recent-${d['id']}', name: d['label'] as String, sub: (d['formatted_address'] as String?) ?? '', kind: LocationKind.recent, lat: (loc['latitude'] as num).toDouble(), lng: (loc['longitude'] as num).toDouble(), source: 'recent',
    placeId: (d['place'] as Map<String, dynamic>?)?['id'] as String?, formattedAddress: d['formatted_address'] as String?, countryCode: d['country_code'] as String?, timezone: d['timezone'] as String?,
  );
}

JourneyLocation _toEnd(Map<String, dynamic> d, Map<String, dynamic> coverage) {
  final loc = d['location'] as Map<String, dynamic>;
  final lat = (loc['latitude'] as num).toDouble(), lng = (loc['longitude'] as num).toDouble();
  final place = d['place'] as Map<String, dynamic>?;
  final source = d['source'] as String? ?? 'coordinates';
  return JourneyLocation(
    id: (place?['id'] as String?) ?? '$source:$lat,$lng', name: (d['label'] as String?) ?? (d['formatted_address'] as String?) ?? 'Pinned location', sub: (d['formatted_address'] as String?) ?? '',
    kind: _kinds[d['kind']] ?? LocationKind.landmark, lat: lat, lng: lng, source: source == 'saved_location' ? 'saved-address' : source == 'place' ? 'place' : 'device',
    placeId: place?['id'] as String?, formattedAddress: d['formatted_address'] as String?, countryCode: d['country_code'] as String?, adminArea: d['region'] as String?, locality: d['locality'] as String?,
    timezone: d['timezone'] as String?, coverage: _coverage(coverage),
  );
}

RouteSummary toRoute(Map<String, dynamic> d) {
  final provider = d['provider'] as Map<String, dynamic>;
  final meters = (d['distance_meters'] as num).toInt(), seconds = (d['duration_seconds'] as num).toInt();
  final minutes = (seconds / 60).round();
  return RouteSummary(
    provider: provider['name'] as String, mode: provider['mode'] as String, distanceKm: (meters / 1000).round(), durationMin: minutes < 1 ? 1 : minutes,
    // GeoJSON positions are [lng, lat]; the map widgets take [lat, lng].
    geometry: [for (final p in (d['geometry'] as Map<String, dynamic>)['coordinates'] as List<dynamic>) [((p as List<dynamic>)[1] as num).toDouble(), (p[0] as num).toDouble()]],
    waypoints: const [], isEstimate: provider['mode'] == 'development', distanceM: meters, durationS: seconds, flags: [for (final f in d['flags'] as List<dynamic>) f as String], trafficAware: d['traffic_aware'] == true,
  );
}

Journey toJourney(Map<String, dynamic> d) {
  final coverage = d['coverage'] as Map<String, dynamic>;
  final search = d['search'] as Map<String, dynamic>;
  final departure = d['departure'] as Map<String, dynamic>;
  int n(String key) => (search[key] as num).toInt();
  return Journey(
    id: d['id'] as String, origin: _toEnd(d['origin'] as Map<String, dynamic>, coverage['origin'] as Map<String, dynamic>), destination: _toEnd(d['destination'] as Map<String, dynamic>, coverage['destination'] as Map<String, dynamic>),
    departureAt: departure['given'] == true && departure['at'] != null ? DateTime.parse(departure['at'] as String) : null, createdAt: DateTime.parse(d['created_at'] as String),
    status: JourneyRecordStatus.routeAvailable, route: toRoute(d['route'] as Map<String, dynamic>), routeGeometryRef: d['id'] as String, backend: true, owner: d['owner'] as String?, expiresAt: DateTime.parse(d['expires_at'] as String).toUtc(),
    coverage: JourneyCoverage(alongRoute: coverage['along_route'] as String, serviceableAreas: (coverage['serviceable_areas'] as num).toInt()),
    search: JourneySearch(corridorM: n('corridor_width_meters'), corridorMinM: n('corridor_min_meters'), corridorMaxM: n('corridor_max_meters'), maxDetourMin: n('max_detour_minutes'), maxDetourMaxMin: n('max_detour_max_minutes'), candidateCap: n('candidate_cap'), pageSize: n('page_size'), sorts: [for (final s in search['sorts'] as List<dynamic>) s as String]),
  );
}

/// One end of a journey as the API takes it — exactly one form: a saved place by its id, a provider place by its id
/// (the backend resolves it again: the text and coordinates the app holds are never trusted), or coordinates.
Map<String, dynamic> toEndBody(JourneyLocation l) {
  if (l.savedLocationId != null) return {'saved_location_id': l.savedLocationId, 'label': l.name};
  // A searched place — also when it comes back from the recent places — travels as its id.
  if ((l.source == 'place' || l.source == 'recent') && l.placeId != null) return {'place_id': l.placeId};
  return {'lat': l.lat, 'lng': l.lng, 'label': l.name, if (l.formattedAddress != null) 'formatted_address': l.formattedAddress};
}

/* ------------------------------------------------------------------ discovery along the journey */

const _sorts = {SortKey.recommended: 'recommended', SortKey.lowestDetour: 'lowest_detour', SortKey.routeOrder: 'route_order', SortKey.nearestToRoute: 'nearest_to_route', SortKey.fastestPickup: 'fastest_pickup', SortKey.highestRated: 'highest_rated'};
const _empty = {'NO_SERVICE_COVERAGE': EmptyReason.coverage, 'NO_RESTAURANTS_IN_CORRIDOR': EmptyReason.corridor, 'NO_REACHABLE_RESTAURANTS': EmptyReason.unreachable, 'FILTERS': EmptyReason.filters};

/// The orderings the backend offers for a journey, in its order ("Highest rated" since Module 34: by the real rating, unrated last).
List<SortKey> journeySorts(Journey journey) {
  final byCode = {for (final e in _sorts.entries) e.value: e.key};
  final sorts = [for (final s in journey.search?.sorts ?? const <String>[]) ?byCode[s]];
  return sorts.isEmpty ? _sorts.keys.toList() : sorts;
}

/// The restaurant's state at the expected arrival, in the vocabulary of the cards.
Availability arrivalAvailability(Map<String, dynamic> context) {
  final a = context['availability_at_arrival'] as Map<String, dynamic>;
  final arrival = DateTime.parse((context['arrival'] as Map<String, dynamic>)['estimated_at'] as String).toUtc();
  bool within(String? iso, int minutes) => iso != null && DateTime.parse(iso).toUtc().difference(arrival).inSeconds <= minutes * 60;
  final open = a['open_now'] == true;
  final reason = a['reason'] as String?;
  final status = a['open_state'] == 'TEMPORARILY_CLOSED'
      ? AvailabilityStatus.temporarilyClosed
      : open
          ? (within(a['closes_at'] as String?, 45) ? AvailabilityStatus.closingSoon : AvailabilityStatus.open)
          : within(a['opens_next_at'] as String?, 60)
              ? AvailabilityStatus.openingSoon
              : AvailabilityStatus.closed;
  final next = (open ? a['closes_at'] : a['opens_next_at']) as String?;
  return Availability(status: status, acceptingOrders: a['accepting_orders'] == true && reason != 'AREA_UNAVAILABLE' && reason != 'PICKUP_UNAVAILABLE', nextChangeAt: next == null ? null : DateTime.parse(next).toUtc());
}

RouteRestaurantResult toRouteResult(Map<String, dynamic> d) {
  final c = d['route_context'] as Map<String, dynamic>;
  final detour = c['detour'] as Map<String, dynamic>;
  return RouteRestaurantResult(
    // The rating is the one the backend sends with the result (Module 34); the sample ratings the development fixtures
    // attach to known slugs are never shown or used along a backend journey.
    restaurant: ApiRestaurantRepository.toRestaurant(d['restaurant'] as Map<String, dynamic>, sampleRatings: false),
    availability: arrivalAvailability(c), distanceFromRouteM: (c['distance_to_route_meters'] as num).toInt(), detourDistanceM: (detour['distance_meters'] as num).toInt(), detourDurationMin: (detour['minutes'] as num).toInt(),
    estimatedArrival: DateTime.parse((c['arrival'] as Map<String, dynamic>)['estimated_at'] as String).toUtc(),
    // When the food is ready is a pickup-slot question (a later module): nothing is promised here.
    estimatedPickupReady: null, routePosition: (c['route_progress'] as num).toDouble(), atArrival: true, orderableAtArrival: c['orderable_at_arrival'] == true,
  );
}

/// The discovery query of the screen as request parameters. Only filters the backend really applies are sent.
Map<String, String?> toDiscoveryQuery(Journey journey, DiscoveryQuery q) {
  final s = journey.search;
  int clamp(num v, int min, int max) => v.round().clamp(min, max);
  final detour = q.filters['detourTime'], cuisine = q.filters['cuisine'], rating = q.filters['rating'];
  final search = q.search.trim();
  return {
    'sort': _sorts[q.sort] ?? 'recommended',
    'corridor_width_meters': q.corridorM == null ? null : '${clamp(q.corridorM!, s?.corridorMinM ?? 500, s?.corridorMaxM ?? 10000)}',
    'max_detour_minutes': detour is num ? '${clamp(detour, 1, s?.maxDetourMaxMin ?? 60)}' : null,
    'open_at_arrival': q.filters['openNow'] == true ? 'true' : null,
    'min_rating': rating is num && rating >= 1 && rating <= 5 ? '$rating' : null,
    'cuisine': cuisine is List && cuisine.isNotEmpty ? cuisine.join(',') : null,
    'q': search.isEmpty ? null : (search.length > 80 ? search.substring(0, 80) : search),
    'page[number]': q.cursor != null && RegExp(r'^\d+$').hasMatch(q.cursor!) ? q.cursor : '1',
    'page[size]': '${q.limit > 25 ? 25 : q.limit}',
  };
}

/// The filters of a backend journey: exactly the ones the API applies — no control here is decoration.
List<FilterDefinition> journeyFilterDefinitions(Journey journey) {
  final snapshot = apiRestaurantData;
  final options = <FilterOption>[
    if (snapshot != null)
      for (final name in snapshot.cuisines)
        if (snapshot.cuisineCodes[name] != null && snapshot.restaurants.any((r) => r.cuisines.contains(name))) FilterOption(snapshot.cuisineCodes[name]!, name),
  ];
  return [
    if (options.isNotEmpty) FilterDefinition(id: 'cuisine', labelKey: 'filter.cuisine', kind: FilterKind.multi, options: options),
    const FilterDefinition(id: 'openNow', labelKey: 'filter.openAtArrival', kind: FilterKind.toggle),
    FilterDefinition(id: 'detourTime', labelKey: 'filter.detourTime', kind: FilterKind.max, unit: FilterUnit.minutes, min: 5, max: (journey.search?.maxDetourMaxMin ?? 60).toDouble(), step: 5, journeyOnly: true),
    // Module 34: the minimum rating is applied by the backend on the real rating; an unrated restaurant never passes it.
    const FilterDefinition(id: 'rating', labelKey: 'filter.rating', kind: FilterKind.min, unit: FilterUnit.rating, min: 3, max: 4.5, step: 0.5),
  ];
}

/* ------------------------------------------------------------------ the API object */

/// One object for everything a backend journey needs: the client (with the customer's session when there is one),
/// the guest tokens, what this run of the app remembers (a guest's recent places, the journeys planned — the backend
/// keeps no journey history) and what the last search said about each restaurant.
class JourneyApi {
  JourneyApi({ApiClient? client, KeyValueStore? store, Future<bool> Function()? signedIn}) {
    final s = store ?? SecureKeyValueStore();
    tokens = JourneyTokens(s);
    _signedIn = signedIn ?? () async => await s.read(ApiAuthRepository.tokenKey) != null;
    this.client = client ?? ApiClient(tokenProvider: () => s.read(ApiAuthRepository.tokenKey), onUnauthenticated: () => onUnauthenticated?.call());
  }

  late final ApiClient client;
  late final JourneyTokens tokens;
  late final Future<bool> Function() _signedIn;

  /// Set by the app: a customer session the backend no longer accepts ends here as it does everywhere else.
  void Function()? onUnauthenticated;

  final List<JourneyLocation> sessionPlaces = [];
  final List<Journey> sessionJourneys = [];
  String? _contextJourney;
  final Map<String, RouteRestaurantResult> _context = {};

  Future<bool> signedIn() => _signedIn();

  Future<ResultPage> discover(Journey journey, DiscoveryQuery q) async {
    final Map<String, dynamic> page;
    try {
      page = await client.get('/journeys/${Uri.encodeComponent(journey.id)}/restaurants', query: toDiscoveryQuery(journey, q), headers: await tokens.headers(journey.id));
    } catch (e) {
      throw toJourneyException(e, 'Restaurants along this journey could not be loaded. Please try again.');
    }
    final items = [for (final r in page['data'] as List<dynamic>) toRouteResult(r as Map<String, dynamic>)];
    _remember(journey.id, items);
    final m = page['meta'] as Map<String, dynamic>;
    final search = m['search'] as Map<String, dynamic>, provider = m['provider'] as Map<String, dynamic>, counts = m['counts'] as Map<String, dynamic>;
    final current = (m['current_page'] as num).toInt(), last = (m['last_page'] as num).toInt();
    final emptyReason = _empty[m['empty_reason']];
    return ResultPage(
      items: items, total: (m['total'] as num).toInt(), nextCursor: current < last ? '${current + 1}' : null, corridorM: (search['corridor_width_meters'] as num).toInt(), emptyReason: emptyReason,
      searchMeta: SearchMeta(calculatedAt: DateTime.parse(m['calculated_at'] as String).toUtc(), cached: m['cached'] == true, providerName: provider['name'] as String, providerMode: provider['mode'] as String, maxDetourMin: (search['max_detour_minutes'] as num).toInt(), corridorM: (search['corridor_width_meters'] as num).toInt(), detourRequested: (counts['detour_requested'] as num).toInt()),
      // A route with no served area along it is the "we do not cover this route yet" state — an answer, not an error.
      unavailable: emptyReason == EmptyReason.coverage ? const MarketAvailabilityResult.unavailable(UnavailableReason.route) : null,
    );
  }

  void _remember(String journeyId, List<RouteRestaurantResult> items) {
    if (_contextJourney != journeyId) { _context.clear(); _contextJourney = journeyId; }
    for (final x in items) { _context[x.restaurant.slug] = x; }
  }

  /// What the last search of this journey said about a restaurant (detour, arrival) — so the restaurant page, the cart
  /// and the pickup screens show the backend's figures instead of estimating anything. Null = the journey did not
  /// find this restaurant: it then has no route figures at all.
  RouteRestaurantResult? storedContext(String journeyId, String slug) => _contextJourney == journeyId ? _context[slug] : null;

  void reset() { sessionPlaces.clear(); sessionJourneys.clear(); _context.clear(); _contextJourney = null; }
}

JourneyApi? _shared;
JourneyApi get journeyApi => _shared ??= JourneyApi();
set journeyApi(JourneyApi api) => _shared = api;

/* ------------------------------------------------------------------ repositories */

class ApiLocationRepository implements LocationRepository {
  ApiLocationRepository(this.api);
  final JourneyApi api;

  @override
  Future<List<JourneyLocation>> search(String query) async {
    final q = query.trim();
    if (q.length < 2) return const [];
    try {
      return [for (final p in (await api.client.get('/places/search', query: {'q': q}))['data'] as List<dynamic>) toSuggestion(p as Map<String, dynamic>)];
    } catch (e) {
      throw toJourneyException(e, 'Location search failed. Please try again.');
    }
  }

  @override
  Future<JourneyLocation> resolve(JourneyLocation location) async {
    if (location.source != 'place' || location.placeId == null || location.lat != null) return location;
    try {
      return toResolved(location, await api.client.post('/places/resolve', body: {'place_id': location.placeId}));
    } catch (e) {
      throw toJourneyException(e, 'This place could not be loaded. Please try again.');
    }
  }

  /// Signed in: the recent places the backend keeps (Module 25 — bounded, clearable in the account). Guests: this run
  /// of the app only.
  @override
  Future<List<JourneyLocation>> recent() async {
    if (!await api.signedIn()) return List.of(api.sessionPlaces);
    try {
      return [for (final r in (await api.client.getList('/customer/recent-locations')).take(5)) toRecentPlace(r as Map<String, dynamic>)];
    } catch (_) {
      return const [];
    }
  }

  /// Signed in: the backend records the searched places of a planned journey itself. Guests: remembered for this run
  /// of the app, never sent anywhere. Only a place that was searched for is remembered — never the device's position,
  /// never a saved place.
  @override
  Future<void> remember(JourneyLocation location) async {
    if (location.lat == null || location.placeId == null || (location.source != 'place' && location.source != 'recent') || await api.signedIn()) return;
    api.sessionPlaces..removeWhere((l) => l.id == location.id)..insert(0, location.copyWith(kind: LocationKind.recent));
    if (api.sessionPlaces.length > 5) api.sessionPlaces.removeRange(5, api.sessionPlaces.length);
  }
}

class ApiJourneyRepository implements JourneyRepository {
  ApiJourneyRepository(this.api);
  final JourneyApi api;

  @override
  Future<Journey> create({required JourneyLocation origin, required JourneyLocation destination, DateTime? departureAt}) async {
    final Map<String, dynamic> dto;
    try {
      dto = await api.client.post('/journeys/plan', body: {'origin': toEndBody(origin), 'destination': toEndBody(destination), if (departureAt != null) 'departure_at': departureAt.toUtc().toIso8601String()});
    } catch (e) {
      throw toJourneyException(e);
    }
    final journey = toJourney(dto);
    final token = dto['guest_token'] as String?;
    if (token != null) await api.tokens.set(journey.id, token, journey.expiresAt!);
    // "Recent journeys" in this mode is a list for this run of the app: the backend keeps no journey history.
    api.sessionJourneys..removeWhere((j) => j.origin.name == journey.origin.name && j.destination.name == journey.destination.name)..insert(0, journey);
    if (api.sessionJourneys.length > 5) api.sessionJourneys.removeRange(5, api.sessionJourneys.length);
    return journey;
  }

  /// null = not this visitor's journey (or gone). An expired journey throws with code `expired`; its token is kept
  /// (see [JourneyTokens]) so the same link keeps reading "expired", not "not found".
  @override
  Future<Journey?> get(String id) async {
    try {
      return toJourney(await api.client.get('/journeys/${Uri.encodeComponent(id)}', headers: await api.tokens.headers(id)));
    } catch (e) {
      final error = toJourneyException(e, 'The journey could not be loaded. Please try again.');
      if (error.code == JourneyErrorCode.notFound) return null;
      throw error;
    }
  }

  /// The backend owns the journey: there is nothing to save from the app.
  @override
  Future<Journey> update(Journey journey) async => journey;

  @override
  Future<List<Journey>> recent() async => List.of(api.sessionJourneys);

  @override
  Future<void> remove(String id) async {
    api.sessionJourneys.removeWhere((j) => j.id == id);
    await api.tokens.forget(id);
  }
}

/// In this mode the route arrives with the planned journey; a separate route lookup never happens.
class ApiRouteRepository implements RouteRepository {
  @override
  Future<RouteSummary> getRoute(JourneyLocation origin, JourneyLocation destination) async => throw const JourneyException(JourneyErrorCode.unexpected, 'The route is part of the planned journey.');
}

JourneyRepositories apiJourneyRepositories([JourneyApi? api]) {
  final a = api ?? journeyApi;
  return JourneyRepositories(locations: ApiLocationRepository(a), journeys: ApiJourneyRepository(a), routes: ApiRouteRepository(), mode: 'api');
}
