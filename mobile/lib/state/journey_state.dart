import 'package:flutter/foundation.dart';

import '../analytics/analytics.dart';
import '../auth/auth_repository.dart' show SecureKeyValueStore;
import '../journey/api_journey.dart';
import '../journey/journey_repositories.dart';

/// Centralised journey state — reused later by restaurant discovery, cart, checkout, tracking and ETA.
///
/// noJourney → editing → validating → ready → routeLoading → routeAvailable
///                                  ↘ error (validation / lookup / route) → editing
///
/// With JOURNEY_MODE=api (Module 26) the backend plans the journey and its route in one step: `ready` and
/// `routeLoading` are skipped and nothing about the route is calculated in the app.
enum JourneyStatus { noJourney, editing, validating, ready, routeLoading, routeAvailable, error }

class JourneyState extends ChangeNotifier {
  JourneyState({JourneyRepositories? repositories}) : repos = repositories ?? defaultRepositories();
  final JourneyRepositories repos;

  /// JOURNEY_MODE=api → the backend (places, route, expiring journey context); otherwise the in-app development providers.
  static JourneyRepositories defaultRepositories() => journeysFromApi ? apiJourneyRepositories() : JourneyRepositories.mock(MockJourneyStore(store: SecureKeyValueStore()));

  /// true when journeys are planned by the backend.
  bool get backend => repos.mode == 'api';

  JourneyStatus status = JourneyStatus.noJourney;
  JourneyLocation? origin, destination;
  DateTime? departureAt;
  Map<String, String> errors = {};
  String? error;

  /// Why planning failed (no route, provider unavailable, offline, …) — the screen words each case differently.
  JourneyErrorCode? errorCode;
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

  void _clearError() { error = null; errorCode = null; }
  void setOrigin(JourneyLocation? l) { origin = l; errors.remove('origin'); errors.remove('form'); status = JourneyStatus.editing; _clearError(); notifyListeners(); }
  void setDestination(JourneyLocation? l) { destination = l; errors.remove('destination'); errors.remove('form'); status = JourneyStatus.editing; _clearError(); notifyListeners(); }
  void setDepartureAt(DateTime? at) { departureAt = at; if (status != JourneyStatus.noJourney) status = JourneyStatus.editing; notifyListeners(); }
  void swap() { final o = origin; origin = destination; destination = o; errors = {}; status = JourneyStatus.editing; _clearError(); notifyListeners(); }
  void edit() { _run++; status = JourneyStatus.editing; _clearError(); notifyListeners(); }
  void reset() { _run++; origin = null; destination = null; departureAt = null; errors = {}; _clearError(); journey = null; status = JourneyStatus.noJourney; notifyListeners(); }

  /// Validate + create + load the route. Returns the journey, or null when invalid / failed.
  Future<Journey?> plan() async {
    final id = ++_run;
    status = JourneyStatus.validating; _clearError(); notifyListeners();
    errors = validateJourney(origin, destination);
    if (errors.isNotEmpty) { status = JourneyStatus.editing; notifyListeners(); return null; }
    try {
      Analytics.journeySearchStarted(); // Module 38: telemetry only — never awaited, never fails
      final j = await repos.journeys.create(origin: origin!, destination: destination!, departureAt: departureAt);
      if (id != _run) return null;
      await repos.locations.remember(origin!);
      await repos.locations.remember(destination!);
      // The backend answers with the journey AND its route; the development providers look the route up afterwards.
      if (j.route != null) {
        journey = j; status = JourneyStatus.routeAvailable; notifyListeners();
        await loadRecent();
        return journey;
      }
      journey = j; status = JourneyStatus.ready; notifyListeners();
      await _loadRoute(j, id);
      return journey;
    } catch (e) {
      if (id != _run) return null;
      // No journey was planned: an earlier one no longer matches the form and must not be shown under this error.
      journey = null;
      _fail(e, 'Could not prepare the journey. Please try again.');
      return null;
    }
  }

  void _fail(Object e, String fallback) {
    error = e is JourneyException ? e.message : fallback;
    errorCode = e is JourneyException ? e.code : JourneyErrorCode.unexpected;
    status = JourneyStatus.error; notifyListeners();
  }

  Future<void> _loadRoute(Journey j, int id) async {
    status = JourneyStatus.routeLoading; _clearError(); notifyListeners();
    try {
      final route = await repos.routes.getRoute(j.origin, j.destination);
      if (id != _run) return;
      journey = await repos.journeys.update(j.copyWith(route: route, status: JourneyRecordStatus.routeAvailable));
      status = JourneyStatus.routeAvailable; notifyListeners();
      await loadRecent();
    } catch (e) {
      if (id != _run) return;
      journey = j.copyWith(status: JourneyRecordStatus.error);
      _fail(e, 'Route preparation failed. Please try again.');
    }
  }

  /// A backend journey has no separate route step: "try again" plans it again.
  Future<void> retryRoute() async {
    final j = journey;
    if (backend || j == null) { await plan(); return; }
    await _loadRoute(j, ++_run);
  }

  /// Load a journey by id (e.g. from /restaurants?journey=). Null = unknown, or not this visitor's journey. An expired
  /// backend journey throws a [JourneyException] with code `expired`; so does a journey that cannot be read right now
  /// (offline, …) with its own code — the caller says which.
  Future<Journey?> load(String id) async {
    final known = journey;
    if (known?.id == id) {
      if (known!.expired) throw const JourneyException(JourneyErrorCode.expired, 'This journey has expired. Plan it again to see current restaurants.');
      return known;
    }
    final j = await repos.journeys.get(id);
    if (j == null) return null;
    journey = j; origin = j.origin; destination = j.destination; departureAt = j.departureAt;
    status = j.route != null ? JourneyStatus.routeAvailable : JourneyStatus.ready;
    notifyListeners();
    return j;
  }

  /// The journey is over (expired on the backend): both places stay in the form so it can be planned again.
  void planAgain() { _run++; final j = journey; origin ??= j?.origin; destination ??= j?.destination; departureAt = null; journey = null; errors = {}; _clearError(); status = JourneyStatus.editing; notifyListeners(); }

  void useAgain(Journey j) { _run++; origin = j.origin; destination = j.destination; departureAt = null; errors = {}; _clearError(); journey = null; status = JourneyStatus.editing; notifyListeners(); }
  Future<void> removeRecent(String id) async { await repos.journeys.remove(id); recent = recent.where((j) => j.id != id).toList(); notifyListeners(); }
  Future<List<JourneyLocation>> searchLocations(String q) => repos.locations.search(q);
  Future<JourneyLocation> resolveLocation(JourneyLocation l) => repos.locations.resolve(l);
  Future<List<JourneyLocation>> recentLocations() => repos.locations.recent();
}
