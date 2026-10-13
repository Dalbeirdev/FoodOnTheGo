import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../analytics/analytics.dart';
import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../cart/cart_models.dart';
import '../discovery/restaurant_models.dart';
import '../journey/journey_repositories.dart' show Journey;
import '../pickup/api_pickup.dart';
import '../pickup/pickup_models.dart';
import '../pickup/pickup_repository.dart';

export '../pickup/api_pickup.dart' show PickupIssue, ServerPickup, ServerSelection, ServerSlot;
export '../pickup/pickup_models.dart';

enum PickupStatus { notSelected, loadingSlots, available, selected, validating, stale, unavailable, error }

/// Centralized pickup state — Android. Mirrors the web PickupContext state machine.
///
/// Two sources behind one interface:
///  - with a [backend] (Module 28, PICKUP_MODE=api) every time, capacity state, recommendation and validity is the
///    server's. The app asks, shows the answer and sends the choice (a mode, a method, a slot key). The choice lives
///    with the cart on the server; the last one the backend confirmed is also kept on the device so that it can be
///    SHOWN without a connection — marked as needing a connection, never taken for valid ([needsConnection]).
///  - without one, the development slot engine of Module 10 runs in the app and the selection is kept on the device.
///
/// A selection is a choice, not a reservation.
class PickupState extends ChangeNotifier {
  PickupState({PickupRepository? repository, EtaService? eta, KeyValueStore? store, this.backend}) : repo = repository ?? MockPickupRepository(), etaService = eta ?? MockEtaService(), _store = store ?? SecureKeyValueStore() {
    _restore();
  }
  static const key = 'fotg.pickup.selection';
  final PickupRepository repo;
  final EtaService etaService;
  final KeyValueStore _store;

  /// The backend's pickup engine, when the build uses it.
  final PickupBackend? backend;
  bool get backendMode => backend != null;

  PickupStatus status = PickupStatus.notSelected;
  PickupMode mode = PickupMode.asap;
  String? date;
  List<String> days = const [];
  List<PickupSlot> slots = const [];
  PickupSettings? settings;
  PickupEstimate? estimate;
  DateTime? eta;
  PickupSelection? selection;
  String? error;
  PickupInvalidReason? staleReason;

  /// Backend mode: what the server said beyond the fields above (issues, recommendation, journey freshness).
  ServerPickup? server;
  /// Backend mode: the stored choice as the server judged it last.
  ServerSelection? serverSelection;
  /// A choice is on its way to the backend.
  bool busy = false;
  /// The pickup method the answer is for.
  String? method;
  /// The method the customer picked. Until they pick one the backend's default counts — a method the restaurant
  /// switched off later must not turn every question into a refusal.
  String? _chosenMethod;
  /// Backend mode: the last attempt to reach the backend failed for lack of a connection. Whatever is shown is the
  /// last known state and confirms nothing.
  bool needsConnection = false;
  /// Set by the app: the backend said the cart moved on, so the cart should be read again.
  void Function()? onCartOutdated;

  int _seq = 0;
  bool _first = true;
  /// The backend has spoken (or the cart is gone): what the device kept from an earlier run no longer counts.
  bool _answered = false;
  String _cartId = '', _restaurantId = '';
  bool _synced = false;
  String? _syncedFor;
  ({GlobalRestaurant restaurant, Cart cart, Journey? journey, int prepMinutes})? _last;

  Future<void> _restore() async {
    try {
      final raw = await _store.read(key);
      if (raw != null && raw.isNotEmpty && !_answered) { selection = PickupSelection.fromJson(jsonDecode(raw) as Map<String, dynamic>); mode = selection!.mode; status = PickupStatus.selected; notifyListeners(); }
    } catch (_) {}
  }
  void _persist() { _store.write(key, selection == null ? null : jsonEncode(selection!.toJson())).catchError((_) {}); }

  Future<void> load({required GlobalRestaurant restaurant, required Cart cart, Journey? journey, required int prepMinutes, String? forDate, DateTime? now}) async {
    _last = (restaurant: restaurant, cart: cart, journey: journey, prepMinutes: prepMinutes);
    if (backendMode) {
      // The preparation time, the clock and the journey's arrival are the backend's: nothing of them is sent.
      _cartId = cart.id; _restaurantId = restaurant.id;
      return _fetch();
    }
    final my = ++_seq;
    final nowUtc = (now ?? DateTime.now()).toUtc();
    if (selection != null && (selection!.cartId != cart.id || selection!.restaurantId != restaurant.id)) { selection = null; _persist(); status = PickupStatus.notSelected; }
    if (status != PickupStatus.selected && status != PickupStatus.stale) status = PickupStatus.loadingSlots;
    error = null; notifyListeners();
    try {
      final cfg = await repo.getSettings(restaurant.id);
      final est = await repo.getEarliestPickup(restaurant.id, prepMinutes, nowUtc);
      if (my != _seq) return;
      final arrival = etaService.estimateArrival(restaurant, journey);
      final today = localDateOf(nowUtc, restaurant.timezone);
      final horizonDays = (cfg.maximumScheduleAheadMinutes / (24 * 60)).ceil() + 1;
      final dayList = [for (var i = 0; i < (horizonDays < 1 ? 1 : horizonDays); i++) addLocalDays(today, i)];
      final day = (forDate != null && dayList.contains(forDate)) ? forDate : dayList.first;
      final list = await repo.getAvailablePickupSlots(SlotQuery(restaurantId: restaurant.id, date: day, now: nowUtc, prepMinutes: prepMinutes, eta: arrival));
      if (my != _seq) return;
      settings = cfg; estimate = est; eta = arrival; days = dayList; date = day; slots = list;
      if (!cfg.modes.contains(mode)) mode = cfg.modes.first;
      if (status != PickupStatus.selected && status != PickupStatus.stale) status = (list.any((x) => x.available) || cfg.modes.contains(PickupMode.asap)) ? PickupStatus.available : PickupStatus.unavailable;
      notifyListeners();
    } catch (e) {
      if (my != _seq) return;
      error = '$e'; status = PickupStatus.error; notifyListeners();
    }
  }

  /// Backend mode: pickup times for this cart. Nothing but the cart's identity is needed — the restaurant, its
  /// schedule, the preparation time and the journey's arrival are all the backend's.
  Future<void> loadForCart(Cart cart) async {
    if (!backendMode) return;
    _cartId = cart.id; _restaurantId = cart.restaurantId;
    return _fetch();
  }

  /// Backend mode: has the backend judge the stored choice again (cart, restaurant, capacity, clock).
  Future<PickupValidation> revalidate() => _validateOnBackend();

  /// Asks again with what was asked last: after a cart change, when the app comes back, when the answer has aged.
  Future<void> refresh() async {
    if (backendMode) return _fetch();
    final l = _last; if (l != null) await load(restaurant: l.restaurant, cart: l.cart, journey: l.journey, prepMinutes: l.prepMinutes, forDate: date);
  }

  void setMode(PickupMode m) {
    if (backendMode) { mode = m; notifyListeners(); return; } // the stored choice stays until another one is made
    mode = m; selection = null; _persist(); staleReason = null; status = PickupStatus.available; notifyListeners();
  }
  Future<void> setDate(String d) async {
    if (backendMode) { date = d; slots = _slotsOf(d); notifyListeners(); return; } // the answer already holds every offered slot
    final l = _last; if (l != null) await load(restaurant: l.restaurant, cart: l.cart, journey: l.journey, prepMinutes: l.prepMinutes, forDate: d);
  }
  Future<void> setMethod(String m) async { if (!backendMode) return; _chosenMethod = m; method = m; await _fetch(); }

  void selectSlot(PickupSlot slot) {
    if (!slot.available) return;
    if (backendMode) { unawaited(_choose(PickupMode.scheduled, slotKey: slot.id)); return; }
    final l = _last; if (l == null) return;
    final ready = estimate?.earliestPickupAt ?? slot.startAt;
    selection = PickupSelection(mode: PickupMode.scheduled, slotId: slot.id, requestedAt: slot.startAt, restaurantTimezone: slot.timezone, estimatedCustomerArrival: eta, estimatedReadyTime: ready.isAfter(slot.startAt) ? ready : slot.startAt, cartId: l.cart.id, restaurantId: l.restaurant.id);
    mode = PickupMode.scheduled; staleReason = null; status = PickupStatus.selected; _persist(); notifyListeners();
  }
  void selectAsap() {
    if (backendMode) { unawaited(_choose(PickupMode.asap)); return; }
    final l = _last; final est = estimate; if (l == null || est == null) return;
    selection = PickupSelection(mode: PickupMode.asap, requestedAt: est.earliestPickupAt, restaurantTimezone: est.restaurantTimezone, estimatedCustomerArrival: eta, estimatedReadyTime: est.earliestPickupAt, cartId: l.cart.id, restaurantId: l.restaurant.id);
    mode = PickupMode.asap; staleReason = null; status = PickupStatus.selected; _persist(); notifyListeners();
  }
  /// Backend mode: the choice as a future, for callers (and tests) that wait for the backend's answer.
  Future<void> choose(PickupMode m, {String? slotKey}) => _choose(m, slotKey: slotKey);

  Future<PickupValidation> validate(GlobalRestaurant restaurant, {DateTime? now}) async {
    if (backendMode) return _validateOnBackend();
    final sel = selection; if (sel == null) return const PickupValidation.fail(PickupInvalidReason.slotMissing);
    status = PickupStatus.validating; notifyListeners();
    try {
      final res = await repo.validatePickupSelection(restaurant, sel, (now ?? DateTime.now()).toUtc());
      if (res.ok) { status = PickupStatus.selected; staleReason = null; } else { status = PickupStatus.stale; staleReason = res.reason; }
      notifyListeners(); return res;
    } catch (e) { error = '$e'; status = PickupStatus.error; notifyListeners(); rethrow; }
  }
  void clear() {
    if (backendMode) {
      selection = null; serverSelection = null; staleReason = null; _persist();
      status = server == null ? PickupStatus.notSelected : (server!.canSelect ? PickupStatus.available : PickupStatus.unavailable);
      notifyListeners();
      backend!.clear().catchError((Object e) { error = toPickupException(e).message; notifyListeners(); });
      return;
    }
    selection = null; staleReason = null; status = PickupStatus.notSelected; _persist(); notifyListeners();
  }

  /* ------------------------------------------------------------------ backend mode */

  /// The choice belongs to the cart: read it again when the cart appears, changes or goes — checkout needs it after
  /// an app restart or a sign-in, and a changed cart may have made the chosen time unreachable.
  Future<void> syncWithCart(Cart? cart) async {
    if (!backendMode) return;
    final has = cart != null && cart.items.isNotEmpty;
    final signature = has ? '${cart.id}:${cart.server?.version}' : null;
    if (_synced && signature == _syncedFor) return;
    _synced = true; _syncedFor = signature;
    if (!has) { _forget(); notifyListeners(); return; }
    _cartId = cart.id; _restaurantId = cart.restaurantId;
    try {
      final j = await backend!.selection();
      if (signature != _syncedFor) return;
      _applySelection(j);
      needsConnection = false;
      if (status != PickupStatus.loadingSlots && status != PickupStatus.validating) {
        if (j != null) { status = serverSelection!.valid ? PickupStatus.selected : PickupStatus.stale; }
        else if (status == PickupStatus.selected || status == PickupStatus.stale) { status = server == null ? PickupStatus.notSelected : (server!.canSelect ? PickupStatus.available : PickupStatus.unavailable); }
      }
    } catch (e) {
      // Offline: the last choice the backend confirmed stays on screen, marked as unconfirmed. Anything else is left
      // to the pickup screen, which reports a failed load itself, and to checkout, which validates again.
      if (toPickupException(e).code == PickupErrorCode.offline) needsConnection = true;
    }
    notifyListeners();
  }

  Future<void> _fetch() async {
    final my = ++_seq;
    if (status != PickupStatus.selected && status != PickupStatus.stale) status = PickupStatus.loadingSlots;
    error = null; notifyListeners();
    try {
      final j = await backend!.availability(method: _chosenMethod);
      if (my != _seq) return;
      _applyAnswer(toAnswer(j, cartId: _cartId, restaurantId: _restaurantId));
      notifyListeners();
    } catch (e) {
      if (my != _seq) return;
      final err = toPickupException(e);
      // No cart, or nothing in it: there is nothing to choose a time for.
      if (err.code == PickupErrorCode.noCart || err.code == PickupErrorCode.cartEmpty) { _forget(); notifyListeners(); return; }
      // The method the customer picked is no longer offered: go back to the restaurant's own default.
      if (err.code == PickupErrorCode.methodUnavailable && _chosenMethod != null) { _chosenMethod = null; return _fetch(); }
      needsConnection = err.code == PickupErrorCode.offline;
      error = err.message; status = PickupStatus.error; notifyListeners();
    }
  }

  void _applyAnswer(PickupAnswer a) {
    _answered = true;
    final s = a.server;
    server = s; method = s.method; needsConnection = false;
    selection = a.selection; serverSelection = a.selectionState; _persist();
    staleReason = a.selectionState == null || a.selectionState!.valid ? null : _reasonOf(a.selectionState!);
    final offered = [if (s.asapEnabled) PickupMode.asap, if (s.scheduledEnabled) PickupMode.scheduled];
    // The first answer decides the tab: the chosen mode, else ASAP when it is possible, else the slots. Later
    // answers leave the tab the customer is on alone. Either way never a mode the restaurant does not offer (any
    // more): with one mode there is no switch on the screen to leave it by.
    if (_first) {
      _first = false;
      mode = a.selection?.mode ?? (s.asapAvailable || !s.scheduledEnabled ? PickupMode.asap : PickupMode.scheduled);
    }
    if (!offered.contains(mode) && offered.isNotEmpty) mode = offered.first;
    final list = s.days;
    days = list.isEmpty ? [localDateOf(s.generatedAt, s.timezone)] : list;
    final chosenDay = a.selectionState?.localDate;
    date = (date != null && days.contains(date)) ? date : (chosenDay != null && days.contains(chosenDay) ? chosenDay : days.first);
    slots = _slotsOf(date!);
    settings = PickupSettings(
      restaurantId: _restaurantId, timezone: s.timezone, intervalMinutes: s.intervalMinutes ?? 15, minimumLeadMinutes: s.leadMinutes ?? 0, maximumScheduleAheadMinutes: 0, bufferMinutes: s.bufferMinutes ?? 0, acceptanceCutoffMinutes: 0,
      modes: offered, methods: s.methods, instructions: s.methods.where((m) => m.id == s.method).firstOrNull?.instructions,
    );
    final earliest = s.asapAt ?? s.slots.where((x) => x.slot.available).firstOrNull?.slot.startAt;
    estimate = s.prepMinutes == null || earliest == null ? null : PickupEstimate(prepMinutes: s.prepMinutes!, bufferMinutes: s.bufferMinutes ?? 0, earliestPickupAt: earliest, restaurantTimezone: s.timezone);
    eta = s.arrivalAt;
    status = a.selection != null ? (a.selectionState!.valid ? PickupStatus.selected : PickupStatus.stale) : (s.canSelect ? PickupStatus.available : PickupStatus.unavailable);
  }

  void _applySelection(Map<String, dynamic>? j) {
    _answered = true;
    selection = toSelection(j, cartId: _cartId, restaurantId: _restaurantId);
    serverSelection = toSelectionState(j);
    staleReason = serverSelection == null || serverSelection!.valid ? null : _reasonOf(serverSelection!);
    if (selection != null) mode = selection!.mode;
    _persist();
  }

  PickupInvalidReason? _reasonOf(ServerSelection s) => toValidation({'valid': s.valid, 'issues': [for (final i in s.issues) {'code': i.code}]}).reason;

  List<PickupSlot> _slotsOf(String day) => [for (final s in server?.slots ?? const <ServerSlot>[]) if (s.localDate == day) s.slot];

  Future<void> _choose(PickupMode m, {String? slotKey}) async {
    if (busy) return;
    busy = true; error = null; notifyListeners();
    try {
      final j = await backend!.select(mode: m, slotKey: slotKey, method: method, cartVersion: server?.cartVersion);
      _applySelection(j);
      mode = m; needsConnection = false; status = PickupStatus.selected;
      Analytics.pickupSelected(restaurant: Analytics.restaurantRef(_restaurantId), asap: m == PickupMode.asap, method: serverSelection?.method ?? method, recommended: m == PickupMode.asap ? server?.asapRecommended : server?.slots.where((s) => s.slot.id == slotKey).firstOrNull?.slot.recommended); // Module 38: the backend accepted the time
    } catch (e) {
      final err = toPickupException(e);
      if (err.code == PickupErrorCode.offline) {
        // Nothing was chosen and nothing can be confirmed: the screen says so instead of pretending.
        needsConnection = true;
      } else {
        // The cart moved on under the screen: have the app read the cart the backend has.
        if (err.code == PickupErrorCode.cartChanged || err.code == PickupErrorCode.cartInvalid || err.code == PickupErrorCode.cartExpired) onCartOutdated?.call();
        // A slot that just filled up (or passed) must be seen as such: ask again, then say what happened.
        await _fetch();
      }
      error = err.message;
    } finally {
      busy = false; notifyListeners();
    }
  }

  /// Before checkout goes on: the backend judges the stored choice again (cart, restaurant, capacity, clock).
  Future<PickupValidation> _validateOnBackend() async {
    status = PickupStatus.validating; notifyListeners();
    try {
      final j = await backend!.selection();
      _applySelection(j);
      final res = toValidation(j);
      needsConnection = false;
      status = res.ok ? PickupStatus.selected : (j != null ? PickupStatus.stale : (server?.canSelect == false ? PickupStatus.unavailable : PickupStatus.available));
      staleReason = res.reason;
      notifyListeners();
      if (!res.ok) unawaited(_fetch());
      return res;
    } catch (e) {
      // Without an answer nothing is confirmed: an old selection is never taken for a valid one.
      final err = toPickupException(e);
      needsConnection = err.code == PickupErrorCode.offline;
      error = err.message; status = PickupStatus.error; notifyListeners();
      throw err;
    }
  }

  void _forget() {
    _seq++; _first = true; _answered = true; _chosenMethod = null; method = null;
    server = null; serverSelection = null; selection = null; staleReason = null; error = null; needsConnection = false;
    slots = const []; days = const []; date = null; settings = null; estimate = null; eta = null;
    status = PickupStatus.notSelected; _persist();
  }
}
