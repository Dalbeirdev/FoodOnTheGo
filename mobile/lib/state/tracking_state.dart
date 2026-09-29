// ignore_for_file: prefer_initializing_formals
import 'dart:async';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../checkout/checkout_models.dart' show ConnectivityService, MockConnectivityService;
import '../order/order_models.dart';
import '../order/order_tracking.dart';

export '../order/order_models.dart';
export '../order/order_tracking.dart';

/// Centralized tracking state (Module 14) — Android. Connection state is kept apart from the order status; events pass
/// through `reduceOrder`, so duplicates and stale sequences never regress the UI. `refresh()` re-reads the order and
/// never creates events. Backgrounding / resume calls `refresh()` (TEST 15).
class TrackingState extends ChangeNotifier {
  TrackingState({required OrderRepository orders, required PickupVerificationRepository verifications, OrderTrackingService? tracking, ConnectivityService? connectivity, KeyValueStore? store})
      : _orders = orders, _verifications = verifications, connectivity = connectivity ?? MockConnectivityService() {
    final st = store ?? SecureKeyValueStore();
    service = tracking ?? MockOrderTrackingService(orders, st);
  }
  final OrderRepository _orders; final PickupVerificationRepository _verifications;
  late final OrderTrackingService service;
  final ConnectivityService connectivity;

  Order? order; PickupVerification? verification;
  TrackingConnection connection = TrackingConnection.loading;
  String? loadError; // not_found | load_failed
  DateTime? lastUpdated;
  int ignoredDuplicates = 0, ignoredStale = 0;
  String? _for; String? _customerId; void Function()? _unsub; int _run = 0;

  Future<void> load(String orderNumber, {required String? customerId}) async {
    final my = ++_run;
    _unsub?.call(); _unsub = null;
    _for = orderNumber; _customerId = customerId; connection = TrackingConnection.loading; loadError = null; order = null; verification = null; notifyListeners();
    if (customerId == null) return;
    try {
      final o = await _orders.getByOrderNumber(orderNumber, customerId);
      if (my != _run) return;
      if (o == null) { loadError = 'not_found'; connection = TrackingConnection.error; notifyListeners(); return; }
      order = o; lastUpdated = DateTime.now();
      // Subscribe before any further fetch so no live event (or dev step) can be lost in between.
      if (terminalOrderStatuses.contains(o.orderStatus)) { connection = TrackingConnection.completed; }
      else if (!connectivity.isOnline) { connection = TrackingConnection.offline; }
      else { _unsub = service.subscribe(o, onEvent: _apply, onConnection: (c) { if (connectivity.isOnline && !terminalOrderStatuses.contains(order?.orderStatus ?? OrderStatus.confirmed)) { connection = c; notifyListeners(); } }); }
      notifyListeners();
      try { verification = await _verifications.getForOrder(o); } catch (_) {}
      if (my != _run) return;
      notifyListeners();
    } catch (_) { if (my == _run) { loadError = 'load_failed'; connection = TrackingConnection.error; notifyListeners(); } }
  }

  void _apply(OrderEvent e) {
    final cur = order; if (cur == null) return;
    final r = reduceOrder(cur, e);
    if (!r.applied) { if (r.reason == 'duplicate') ignoredDuplicates++; if (r.reason == 'stale') ignoredStale++; notifyListeners(); return; }
    order = r.order; lastUpdated = DateTime.now();
    if (terminalOrderStatuses.contains(r.order.orderStatus)) connection = TrackingConnection.completed;
    notifyListeners();
  }

  /// Manual / polling / resume refresh — idempotent.
  Future<void> refresh() async {
    final n = _for; final c = _customerId; if (n == null || c == null) return;
    if (!connectivity.isOnline) { if (connection != TrackingConnection.completed) connection = TrackingConnection.offline; notifyListeners(); return; }
    if (_unsub == null && order != null && !terminalOrderStatuses.contains(order!.orderStatus)) { await load(n, customerId: c); return; } // reconnect after offline
    try {
      final o = await service.refresh(n, c);
      if (o != null) { order = o; lastUpdated = DateTime.now(); connection = terminalOrderStatuses.contains(o.orderStatus) ? TrackingConnection.completed : TrackingConnection.live; }
      notifyListeners();
    } catch (_) { connection = TrackingConnection.stale; notifyListeners(); }
  }

  /// Connectivity changed (system or development toggle): offline shows the last known status; online reconnects.
  Future<void> connectivityChanged() async { if (!connectivity.isOnline) { _unsub?.call(); _unsub = null; if (connection != TrackingConnection.completed) connection = TrackingConnection.offline; notifyListeners(); return; } final n = _for; if (n != null) await load(n, customerId: _customerId); }

  // Development helpers (mock only)
  Future<void> advance() async { final s = service; final n = _for; if (s is MockOrderTrackingService && n != null) await s.advance(n); }
  void injectDuplicate() { final s = service; final n = _for; if (s is MockOrderTrackingService && n != null) s.injectDuplicate(n); }
  void injectStale() { final s = service; final n = _for; if (s is MockOrderTrackingService && n != null) s.injectStale(n); }
  Future<void> setScenario(TrackingScenario sc) async { final s = service; final o = order; if (s is MockOrderTrackingService && o != null) await s.setScenarioFor(o, sc); }

  @override
  void dispose() { _unsub?.call(); super.dispose(); }
}
