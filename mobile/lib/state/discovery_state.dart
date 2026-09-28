import 'dart:async';

import 'package:flutter/foundation.dart';

import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/markets.dart';
import '../journey/journey_repositories.dart' show Journey;

enum DiscoveryStatus { idle, loading, updating, loadingMore, ready, error }

/// Discovery state for the Restaurants screen: debounced search, data-driven filters, sort, corridor,
/// cursor pagination, request cancellation and retry. Works with or without a journey.
class DiscoveryState extends ChangeNotifier {
  DiscoveryState({RestaurantRepository? repository}) : repo = repository ?? MockRestaurantRepository();
  final RestaurantRepository repo;

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
  int? get corridorM => _corridorOverride != null && _corridorJourneyId == journey?.id ? _corridorOverride : (journey != null ? marketFor(journey!.origin.countryCode).corridorM : null);
  int get activeFilterCount => filters.length + (_debounced.isNotEmpty ? 1 : 0);
  UnitSystem get units => resolveUnitSystem(unitPreference, journey?.origin.countryCode ?? (items.isEmpty ? null : items.first.restaurant.countryCode));
  String? get currency => journey == null ? null : marketFor(journey!.origin.countryCode).currency;

  /// Bind (or clear) the active journey and reload. Called by the screen, never by the journey module.
  Future<void> bind(Journey? j) async {
    journey = j?.route == null ? null : j;
    selectedId = null;
    await load();
  }

  Future<void> load({bool more = false}) async {
    final my = ++_seq;
    status = more ? DiscoveryStatus.loadingMore : (_first ? DiscoveryStatus.loading : DiscoveryStatus.updating);
    error = null; notifyListeners();
    final q = DiscoveryQuery(search: _debounced, filters: filters, sort: sort, cursor: more ? nextCursor : null, corridorM: corridorM);
    try {
      final page = journey != null ? await repo.getRestaurantsForJourney(journey!, q) : await repo.getRestaurants(q);
      if (my != _seq) return;
      items = more ? [...items, ...page.items] : page.items;
      total = page.total; nextCursor = page.nextCursor; status = DiscoveryStatus.ready; _first = false;
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
