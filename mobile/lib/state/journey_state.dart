import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show SecureKeyValueStore;
import '../journey/journey_repositories.dart';

/// Centralised journey state — reused later by restaurant discovery, cart, checkout, tracking and ETA.
///
/// noJourney → editing → validating → ready → routeLoading → routeAvailable
///                                  ↘ error (validation / lookup / route) → editing
enum JourneyStatus { noJourney, editing, validating, ready, routeLoading, routeAvailable, error }

class JourneyState extends ChangeNotifier {
  JourneyState({JourneyRepositories? repositories}) : repos = repositories ?? JourneyRepositories.mock(MockJourneyStore(store: SecureKeyValueStore()));
  final JourneyRepositories repos;

  JourneyStatus status = JourneyStatus.noJourney;
  JourneyLocation? origin, destination;
  DateTime? departureAt;
  Map<String, String> errors = {};
  String? error;
  Journey? journey;
  List<Journey> recent = const [];
  bool recentLoading = false;
  int _run = 0;

  bool get busy => status == JourneyStatus.validating || status == JourneyStatus.ready || status == JourneyStatus.routeLoading;

  Future<void> loadRecent() async {
    recentLoading = true; notifyListeners();
    try { recent = await repos.journeys.recent(); } catch (_) { recent = const []; }
    recentLoading = false; notifyListeners();
  }

  void setOrigin(JourneyLocation? l) { origin = l; errors.remove('origin'); errors.remove('form'); status = JourneyStatus.editing; error = null; notifyListeners(); }
  void setDestination(JourneyLocation? l) { destination = l; errors.remove('destination'); errors.remove('form'); status = JourneyStatus.editing; error = null; notifyListeners(); }
  void setDepartureAt(DateTime? at) { departureAt = at; if (status != JourneyStatus.noJourney) status = JourneyStatus.editing; notifyListeners(); }
  void swap() { final o = origin; origin = destination; destination = o; errors = {}; status = JourneyStatus.editing; error = null; notifyListeners(); }
  void edit() { _run++; status = JourneyStatus.editing; error = null; notifyListeners(); }
  void reset() { _run++; origin = null; destination = null; departureAt = null; errors = {}; error = null; journey = null; status = JourneyStatus.noJourney; notifyListeners(); }

  /// Validate + create + load the route. Returns the journey, or null when invalid / failed.
  Future<Journey?> plan() async {
    final id = ++_run;
    status = JourneyStatus.validating; error = null; notifyListeners();
    errors = validateJourney(origin, destination);
    if (errors.isNotEmpty) { status = JourneyStatus.editing; notifyListeners(); return null; }
    try {
      final j = await repos.journeys.create(origin: origin!, destination: destination!, departureAt: departureAt);
      if (id != _run) return null;
      await repos.locations.remember(origin!);
      await repos.locations.remember(destination!);
      journey = j; status = JourneyStatus.ready; notifyListeners();
      await _loadRoute(j, id);
      return journey;
    } catch (e) {
      if (id != _run) return null;
      error = e is JourneyException ? e.message : 'Could not prepare the journey. Please try again.';
      status = JourneyStatus.error; notifyListeners();
      return null;
    }
  }

  Future<void> _loadRoute(Journey j, int id) async {
    status = JourneyStatus.routeLoading; error = null; notifyListeners();
    try {
      final route = await repos.routes.getRoute(j.origin, j.destination);
      if (id != _run) return;
      journey = await repos.journeys.update(j.copyWith(route: route, status: JourneyRecordStatus.routeAvailable));
      status = JourneyStatus.routeAvailable; notifyListeners();
      await loadRecent();
    } catch (e) {
      if (id != _run) return;
      journey = j.copyWith(status: JourneyRecordStatus.error);
      error = e is JourneyException ? e.message : 'Route preparation failed. Please try again.';
      status = JourneyStatus.error; notifyListeners();
    }
  }

  Future<void> retryRoute() async { final j = journey; if (j != null) await _loadRoute(j, ++_run); }

  /// Load a journey by id (e.g. from /restaurants?journey=) — returns null when unknown.
  Future<Journey?> load(String id) async {
    if (journey?.id == id) return journey;
    final j = await repos.journeys.get(id);
    if (j == null) return null;
    journey = j; origin = j.origin; destination = j.destination; departureAt = j.departureAt;
    status = j.route != null ? JourneyStatus.routeAvailable : JourneyStatus.ready;
    notifyListeners();
    return j;
  }

  void useAgain(Journey j) { _run++; origin = j.origin; destination = j.destination; departureAt = null; errors = {}; error = null; journey = null; status = JourneyStatus.editing; notifyListeners(); }
  Future<void> removeRecent(String id) async { await repos.journeys.remove(id); recent = recent.where((j) => j.id != id).toList(); notifyListeners(); }
  Future<List<JourneyLocation>> searchLocations(String q) => repos.locations.search(q);
  Future<List<JourneyLocation>> recentLocations() => repos.locations.recent();
}
