import 'package:flutter/foundation.dart';

import '../order/order_history.dart';
import '../order/order_models.dart';

export '../order/order_history.dart';

/// My Orders state (Module 15) — Android. Lightweight summaries, filter / search / sort, cursor paging.
class OrdersHistoryState extends ChangeNotifier {
  OrdersHistoryState({required this.orders});
  final OrderRepository orders;
  OrderGroup group = OrderGroup.all; String query = ''; OrderSort sort = OrderSort.newest;
  List<OrderSummary> items = const []; String? nextCursor; int total = 0;
  bool loading = false, loadingMore = false, error = false;
  String? _customerId; int _run = 0;

  Future<void> load({required String? customerId}) async {
    _customerId = customerId; if (customerId == null) return;
    final my = ++_run; loading = true; error = false; notifyListeners();
    try {
      final page = await orders.listSummaries(customerId, OrderListQuery(group: group, query: query, sort: sort, limit: 5));
      if (my != _run) return;
      items = page.items; nextCursor = page.nextCursor; total = page.total; loading = false; notifyListeners();
    } catch (_) { if (my == _run) { loading = false; error = true; notifyListeners(); } }
  }
  Future<void> loadMore() async {
    final c = _customerId; final cur = nextCursor; if (c == null || cur == null || loadingMore) return;
    final my = _run; loadingMore = true; notifyListeners();
    try {
      final page = await orders.listSummaries(c, OrderListQuery(group: group, query: query, sort: sort, cursor: cur, limit: 5));
      if (my != _run) return;
      items = [...items, ...page.items]; nextCursor = page.nextCursor; total = page.total;
    } catch (_) { error = true; }
    loadingMore = false; notifyListeners();
  }
  Future<void> setGroup(OrderGroup g) { group = g; return load(customerId: _customerId); }
  Future<void> setQuery(String q) { query = q; return load(customerId: _customerId); }
  Future<void> setSort(OrderSort s) { sort = s; return load(customerId: _customerId); }
  Future<void> clearFilters() { group = OrderGroup.all; query = ''; return load(customerId: _customerId); }
}
