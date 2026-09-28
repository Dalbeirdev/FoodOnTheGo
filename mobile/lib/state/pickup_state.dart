import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../cart/cart_models.dart';
import '../discovery/restaurant_models.dart';
import '../journey/journey_repositories.dart' show Journey;
import '../pickup/pickup_models.dart';
import '../pickup/pickup_repository.dart';

export '../pickup/pickup_models.dart';

enum PickupStatus { notSelected, loadingSlots, available, selected, validating, stale, unavailable, error }

/// Centralized pickup state (Module 10) — Android. Selection is persisted on the device so it survives
/// the login / OTP round trip before checkout. Mirrors the web PickupContext state machine.
class PickupState extends ChangeNotifier {
  PickupState({PickupRepository? repository, EtaService? eta, KeyValueStore? store}) : repo = repository ?? MockPickupRepository(), etaService = eta ?? MockEtaService(), _store = store ?? SecureKeyValueStore() {
    _restore();
  }
  static const key = 'fotg.pickup.selection';
  final PickupRepository repo;
  final EtaService etaService;
  final KeyValueStore _store;

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
  int _seq = 0;
  ({GlobalRestaurant restaurant, Cart cart, Journey? journey, int prepMinutes})? _last;

  Future<void> _restore() async {
    try {
      final raw = await _store.read(key);
      if (raw != null && raw.isNotEmpty) { selection = PickupSelection.fromJson(jsonDecode(raw) as Map<String, dynamic>); mode = selection!.mode; status = PickupStatus.selected; notifyListeners(); }
    } catch (_) {}
  }
  void _persist() { _store.write(key, selection == null ? null : jsonEncode(selection!.toJson())).catchError((_) {}); }

  Future<void> load({required GlobalRestaurant restaurant, required Cart cart, Journey? journey, required int prepMinutes, String? forDate, DateTime? now}) async {
    final my = ++_seq;
    _last = (restaurant: restaurant, cart: cart, journey: journey, prepMinutes: prepMinutes);
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

  void setMode(PickupMode m) { mode = m; selection = null; _persist(); staleReason = null; status = PickupStatus.available; notifyListeners(); }
  Future<void> setDate(String d) async { final l = _last; if (l != null) await load(restaurant: l.restaurant, cart: l.cart, journey: l.journey, prepMinutes: l.prepMinutes, forDate: d); }

  void selectSlot(PickupSlot slot) {
    final l = _last; if (!slot.available || l == null) return;
    final ready = estimate?.earliestPickupAt ?? slot.startAt;
    selection = PickupSelection(mode: PickupMode.scheduled, slotId: slot.id, requestedAt: slot.startAt, restaurantTimezone: slot.timezone, estimatedCustomerArrival: eta, estimatedReadyTime: ready.isAfter(slot.startAt) ? ready : slot.startAt, cartId: l.cart.id, restaurantId: l.restaurant.id);
    mode = PickupMode.scheduled; staleReason = null; status = PickupStatus.selected; _persist(); notifyListeners();
  }
  void selectAsap() {
    final l = _last; final est = estimate; if (l == null || est == null) return;
    selection = PickupSelection(mode: PickupMode.asap, requestedAt: est.earliestPickupAt, restaurantTimezone: est.restaurantTimezone, estimatedCustomerArrival: eta, estimatedReadyTime: est.earliestPickupAt, cartId: l.cart.id, restaurantId: l.restaurant.id);
    mode = PickupMode.asap; staleReason = null; status = PickupStatus.selected; _persist(); notifyListeners();
  }
  Future<PickupValidation> validate(GlobalRestaurant restaurant, {DateTime? now}) async {
    final sel = selection; if (sel == null) return const PickupValidation.fail(PickupInvalidReason.slotMissing);
    status = PickupStatus.validating; notifyListeners();
    try {
      final res = await repo.validatePickupSelection(restaurant, sel, (now ?? DateTime.now()).toUtc());
      if (res.ok) { status = PickupStatus.selected; staleReason = null; } else { status = PickupStatus.stale; staleReason = res.reason; }
      notifyListeners(); return res;
    } catch (e) { error = '$e'; status = PickupStatus.error; notifyListeners(); rethrow; }
  }
  void clear() { selection = null; staleReason = null; status = PickupStatus.notSelected; _persist(); notifyListeners(); }
}
