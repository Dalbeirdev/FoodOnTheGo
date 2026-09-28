import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/markets.dart';
import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../account/account_repositories.dart' show SavedAddress;
import '../journey/journey_repositories.dart' show Journey, JourneyLocation;

enum DiscoveryStatus { idle, loading, updating, loadingMore, ready, error }

/// Discovery state for the Restaurants screen: debounced search, data-driven filters, sort, corridor,
/// cursor pagination, request cancellation and retry. Works with or without a journey.
class DiscoveryState extends ChangeNotifier {
  DiscoveryState({RestaurantRepository? repository, KeyValueStore? store}) : repo = repository ?? MockRestaurantRepository(), _store = store ?? SecureKeyValueStore();
  final RestaurantRepository repo;
  final KeyValueStore _store;
  static const _kScope = 'fotg.discovery.scope';
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
  Timer? _debounce;
  int _seq = 0;
  bool _first = true;

  List<FilterDefinition> get definitions => repo.getFilterDefinitions(journey);
  int get maxRing => _ringKey == _scopeKey ? _maxRing : 1;
  String get _scopeKey => scope == null ? '' : '${scope!.countryCode}|${scope!.adminArea ?? ''}|${scope!.lat ?? ''}|${scope!.lng ?? ''}';
  int? get corridorM => _corridorOverride != null && _corridorJourneyId == journey?.id ? _corridorOverride : (journey != null ? marketFor(journey!.origin.countryCode).corridorM : null);
  int get activeFilterCount => filters.length + (_debounced.isNotEmpty ? 1 : 0);
  UnitSystem get units => resolveUnitSystem(unitPreference, journey?.origin.countryCode ?? scope?.countryCode ?? (items.isEmpty ? null : items.first.restaurant.countryCode));
  String? get currency => journey == null ? null : marketFor(journey!.origin.countryCode).currency;

  /// Bind (or clear) the active journey, resolve the location scope and reload.
  /// Scope priority: manual → (device location PENDING) → default saved address → last journey origin → device region → none.
  Future<void> bind(Journey? j, {List<SavedAddress> addresses = const [], List<Journey> recentJourneys = const [], String? deviceLocale}) async {
    journey = j?.route == null ? null : j;
    selectedId = null;
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
    if (region != null) return DiscoveryScope(countryCode: region, label: region, source: 'locale');
    return null;
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

  void showMoreAreas() { if (nextRing != null) { _maxRing = nextRing!; _ringKey = _scopeKey; load(); } }

  Future<void> load({bool more = false}) async {
    final my = ++_seq;
    status = more ? DiscoveryStatus.loadingMore : (_first ? DiscoveryStatus.loading : DiscoveryStatus.updating);
    error = null; notifyListeners();
    final q = DiscoveryQuery(search: _debounced, filters: filters, sort: sort, cursor: more ? nextCursor : null, corridorM: corridorM, scope: journey == null ? scope : null, maxRing: maxRing);
    try {
      final page = journey != null ? await repo.getRestaurantsForJourney(journey!, q) : await repo.getRestaurants(q);
      if (my != _seq) return;
      items = more ? [...items, ...page.items] : page.items;
      total = page.total; nextCursor = page.nextCursor; ringApplied = page.ringApplied; nextRing = page.nextRing; ringCounts = page.ringCounts; status = DiscoveryStatus.ready; _first = false;
    } catch (e) {
      if (my != _seq) return;
      error = e.toString().replaceFirst('Exception: ', ''); status = DiscoveryStatus.error;
    }
    notifyListeners();
  }

  void setSearch(String v) {
    search = v; notifyListeners();
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () { if (_debounced != search.trim()) { _debounced = search.trim(); load(); } });
  }
  void setSort(SortKey s) { if (sort == s) return; sort = s; load(); }
  void setFilter(String id, Object? value) { if (value == null || value == false || (value is List && value.isEmpty)) { filters.remove(id); } else { filters[id] = value; } load(); }
  void toggleOption(String id, String option) {
    final cur = List<String>.from((filters[id] as List?)?.cast<String>() ?? const []);
    cur.contains(option) ? cur.remove(option) : cur.add(option);
    setFilter(id, cur);
  }
  void clearFilters() { filters = {}; search = ''; _debounced = ''; load(); }
  void setCorridor(int? m) { _corridorOverride = m; _corridorJourneyId = journey?.id; load(); }
  void widenCorridor() => setCorridor(((corridorM ?? 5000) * 2).clamp(1000, 50000));
  void setUnitPreference(UnitSystem? u) { unitPreference = u; notifyListeners(); }
  void select(String? id) { selectedId = id; notifyListeners(); }
  Future<void> loadMore() => load(more: true);
  Future<void> retry() => load();

  @override
  void dispose() { _debounce?.cancel(); super.dispose(); }
}
