import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../analytics/analytics.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/markets.dart';
import '../market/market.dart';
import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../account/account_repositories.dart' show SavedAddress;
import '../journey/api_journey.dart';
import '../journey/journey_repositories.dart' show Journey, JourneyErrorCode, JourneyException, JourneyLocation;
import '../core/user_message.dart';

enum DiscoveryStatus { idle, loading, updating, loadingMore, ready, error }

/// Discovery state for the Restaurants screen: debounced search, data-driven filters, sort, corridor,
/// cursor pagination, request cancellation and retry. Works with or without a journey.
///
/// Along a BACKEND journey (Module 26) the backend searches: the corridor, the filters and the orderings are its own,
/// detours and arrival times come from its routing provider, and nothing is estimated or filtered in the app.
class DiscoveryState extends ChangeNotifier {
  DiscoveryState({RestaurantRepository? repository, KeyValueStore? store, this.journeys}) : repo = repository ?? MockRestaurantRepository(), _store = store ?? SecureKeyValueStore();
  final RestaurantRepository repo;
  /// The journey API (tests pass their own; the app uses the shared one).
  final JourneyApi? journeys;
  JourneyApi get _api => journeys ?? journeyApi;
  final KeyValueStore _store;
  /// The manually chosen discovery location (it can be a saved address) — private context, cleared on sign-out.
  static const scopeKey = 'fotg.discovery.scope';
  static const _kScope = scopeKey;
  @visibleForTesting
  KeyValueStore get storeForTest => _store;

  /// Location scope for general discovery (null = ask the customer). Country is a hard boundary.
  DiscoveryScope? scope;
  DiscoveryScope? _manual;
  int _maxRing = 1;
  String _ringKey = '';
  int? ringApplied, nextRing;
  Map<int, int> ringCounts = const {};

  Journey? journey;
  String search = '';
  String _debounced = '';
  Map<String, Object> filters = {};
  SortKey sort = SortKey.recommended;
  int? _corridorOverride;
  String? _corridorJourneyId;
  UnitSystem? unitPreference; // null = market default
  String? selectedId;
  DiscoveryStatus status = DiscoveryStatus.idle;
  List<RouteRestaurantResult> items = const [];
  int total = 0;
  String? nextCursor;
  String? error;
  /// Why the last search failed (provider unavailable, offline, too many requests, …).
  JourneyErrorCode? errorCode;
  /// The backend says the journey is over ('expired') or is not this visitor's ('missing'): nothing is listed.
  String? journeyGone;
  /// Backend journeys: why the list is empty, and what the backend said about the search.
  EmptyReason? emptyReason;
  SearchMeta? searchMeta;
  Timer? _debounce;
  Timer? _settle;
  int _seq = 0;
  bool _first = true;

  /// true when the journey was planned by the backend and the backend searches along it.
  bool get backend => journey?.backend == true;
  List<FilterDefinition> get definitions => backend ? journeyFilterDefinitions(journey!) : repo.getFilterDefinitions(journey);
  /// The orderings on offer: the backend's for a backend journey; without a journey nothing route based.
  List<SortKey> get sorts => backend ? journeySorts(journey!) : [for (final k in SortKey.values) if (k != SortKey.routeOrder && (journey != null || (k != SortKey.lowestDetour && k != SortKey.nearestToRoute))) k];
  /// Corridor slider bounds in metres: the market's policy for a backend journey.
  (int min, int max, int step) get corridorBounds => backend && journey!.search != null ? (journey!.search!.corridorMinM, journey!.search!.corridorMaxM, 500) : (1000, 50000, 1000);
  int get maxRing => _ringKey == _scopeKey ? _maxRing : 1;
  String get _scopeKey => scope == null ? '' : '${scope!.countryCode}|${scope!.adminArea ?? ''}|${scope!.lat ?? ''}|${scope!.lng ?? ''}';
  int? get corridorM => _corridorOverride != null && _corridorJourneyId == journey?.id ? _corridorOverride : (journey != null ? (journey!.search?.corridorM ?? marketFor(journey!.origin.countryCode).corridorM) : null);
  /// Set when the current journey / location is outside active market coverage.
  MarketAvailabilityResult? unavailable;
  int get activeFilterCount => filters.length + (_debounced.isNotEmpty ? 1 : 0);
  UnitSystem get units => resolveUnitSystem(unitPreference, journey?.origin.countryCode ?? scope?.countryCode ?? (items.isEmpty ? null : items.first.restaurant.countryCode));
  String? get currency => journey == null ? null : marketFor(journey!.origin.countryCode).currency;

  /// Bind (or clear) the active journey, resolve the location scope and reload.
  /// Scope priority: manual → (device location PENDING) → default saved address → last journey origin → device region → none.
  Future<void> bind(Journey? j, {List<SavedAddress> addresses = const [], List<Journey> recentJourneys = const [], String? deviceLocale}) async {
    final before = journey;
    journey = j?.route == null ? null : j;
    selectedId = null;
    journeyGone = null; emptyReason = null; searchMeta = null;
    // Filters and orderings of the backend's search mean nothing to the in-app one and the other way round (cuisine
    // codes vs names, "Highest rated"); another backend journey starts with its own defaults.
    if ((before?.backend ?? false) != backend || (backend && before?.id != journey?.id)) { filters = {}; if (!sorts.contains(sort)) sort = SortKey.recommended; }
    _manual ??= await _loadManual();
    scope = journey != null ? null : resolveScope(manual: _manual, addresses: addresses, recentJourneys: recentJourneys, locale: deviceLocale ?? PlatformDispatcher.instance.locale.toLanguageTag());
    if (journey == null && scope == null) { items = const []; total = 0; status = DiscoveryStatus.ready; notifyListeners(); return; }
    await load();
  }

  static DiscoveryScope? resolveScope({DiscoveryScope? manual, List<SavedAddress> addresses = const [], List<Journey> recentJourneys = const [], required String locale}) {
    if (manual != null) return manual;
    final region = RegExp(r'[-_]([A-Za-z]{2})$').firstMatch(locale)?.group(1)?.toUpperCase();
    final def = addresses.where((a) => a.isDefault).firstOrNull ?? addresses.firstOrNull;
    if (def != null && region != null) return DiscoveryScope(countryCode: region, adminArea: def.state, locality: def.city, lat: def.lat, lng: def.lng, label: '${def.label} · ${def.city}', source: 'saved-address');
    final j = recentJourneys.firstOrNull;
    if (j != null && j.origin.countryCode != null) return scopeFromLocation(j.origin, 'journey');
    // Device region is only a hint: outside the active markets the scope resolves to the active market (India launch).
    if (fixtureScope == FixtureScope.global) return region != null ? DiscoveryScope(countryCode: region, label: region, source: 'locale') : null;
    return DiscoveryScope(countryCode: marketAvailability.activeMarket.countryCode, label: marketAvailability.activeMarket.displayName, source: 'market');
  }

  static DiscoveryScope? scopeFromLocation(JourneyLocation l, [String source = 'manual']) => l.countryCode == null ? null : DiscoveryScope(countryCode: l.countryCode!, adminArea: l.adminArea, locality: l.locality ?? l.name, lat: l.lat, lng: l.lng, label: l.name, source: source);

  Future<DiscoveryScope?> _loadManual() async {
    final raw = await _store.read(_kScope);
    if (raw == null) return null;
    try { return DiscoveryScope.fromJson(jsonDecode(raw) as Map<String, dynamic>); } catch (_) { return null; }
  }

  /// "Change location": persist a manual scope (or clear it) and reload.
  Future<void> setManualScope(JourneyLocation? l) async {
    _manual = l == null ? null : scopeFromLocation(l);
    await _store.write(_kScope, _manual == null ? null : jsonEncode(_manual!.toJson()));
    scope = journey != null ? null : (_manual ?? scope);
    if (journey == null && scope == null) { items = const []; total = 0; notifyListeners(); return; }
    await load();
  }

  /// Module 39: the customer signed out (or the session ended) — the manually chosen location and the journey's list go.
  void forgetPrivate() {
    _manual = null; scope = null;
    _store.write(_kScope, null).catchError((_) {});
    clearJourney();
  }

  /// The journey of the link could not be opened (unknown, over, or unreachable): nothing of an earlier one stays listed.
  void clearJourney() { _seq++; journey = null; items = const []; total = 0; nextCursor = null; searchMeta = null; emptyReason = null; unavailable = null; journeyGone = null; error = null; errorCode = null; status = DiscoveryStatus.ready; notifyListeners(); }

  void showMoreAreas() { if (nextRing != null) { _maxRing = nextRing!; _ringKey = _scopeKey; load(); } }

  Future<void> load({bool more = false}) async {
    final my = ++_seq;
    status = more ? DiscoveryStatus.loadingMore : (_first ? DiscoveryStatus.loading : DiscoveryStatus.updating);
    error = null; errorCode = null; notifyListeners();
    final j = journey;
    // The corridor is sent only when the customer moved it: otherwise the journey's own width applies.
    final q = DiscoveryQuery(search: _debounced, filters: filters, sort: sort, cursor: more ? nextCursor : null, corridorM: backend ? (_corridorJourneyId == j?.id ? _corridorOverride : null) : corridorM, scope: j == null ? scope : null, maxRing: maxRing);
    try {
      final page = j == null ? await repo.getRestaurants(q) : (j.backend ? await _api.discover(j, q) : await repo.getRestaurantsForJourney(j, q));
      if (my != _seq) return;
      items = more ? [...items, ...page.items] : page.items;
      total = page.total; unavailable = page.unavailable; nextCursor = page.nextCursor; ringApplied = page.ringApplied; nextRing = page.nextRing; ringCounts = page.ringCounts; emptyReason = page.emptyReason; searchMeta = page.searchMeta; status = DiscoveryStatus.ready; _first = false;
      if (j != null && !more) Analytics.journeyResultsViewed(j.id, page.total); // Module 38: once per journey, also when the list is empty
    } catch (e) {
      if (my != _seq) return;
      errorCode = e is JourneyException ? e.code : null;
      if (errorCode == JourneyErrorCode.expired || errorCode == JourneyErrorCode.notFound) {
        // The journey is over or is not this visitor's: no stale list stays on screen.
        journeyGone = errorCode == JourneyErrorCode.expired ? 'expired' : 'missing';
        items = const []; total = 0; nextCursor = null; searchMeta = null; emptyReason = null;
      }
      error = e is JourneyException ? e.message : userMessage(e); status = DiscoveryStatus.error;
    }
    notifyListeners();
  }

  void setSearch(String v) {
    search = v; notifyListeners();
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () { if (_debounced != search.trim()) { _debounced = search.trim(); load(); } });
  }
  /// A slider reports every step while it is dragged. Along a backend journey one request is sent once it settles
  /// (each new corridor is a new search on the backend); the value on screen follows the finger at once.
  void _reload() {
    if (!backend) { load(); return; }
    notifyListeners();
    _settle?.cancel();
    _settle = Timer(const Duration(milliseconds: 350), load);
  }
  void setSort(SortKey s) { if (sort == s) return; sort = s; load(); }
  void setFilter(String id, Object? value) { if (value == null || value == false || (value is List && value.isEmpty)) { filters.remove(id); } else { filters[id] = value; } _reload(); }
  void toggleOption(String id, String option) {
    final cur = List<String>.from((filters[id] as List?)?.cast<String>() ?? const []);
    cur.contains(option) ? cur.remove(option) : cur.add(option);
    setFilter(id, cur);
  }
  void clearFilters() { filters = {}; search = ''; _debounced = ''; load(); }
  void setCorridor(int? m) { _corridorOverride = m; _corridorJourneyId = journey?.id; _reload(); }
  void widenCorridor() => setCorridor(((corridorM ?? 5000) * 2).clamp(corridorBounds.$1, corridorBounds.$2));
  void setUnitPreference(UnitSystem? u) { unitPreference = u; notifyListeners(); }
  void select(String? id) { selectedId = id; notifyListeners(); }
  Future<void> loadMore() => load(more: true);
  Future<void> retry() => load();

  @override
  void dispose() { _debounce?.cancel(); _settle?.cancel(); super.dispose(); }
}
