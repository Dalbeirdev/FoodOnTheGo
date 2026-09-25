import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import '../core/app_config.dart';
import '../data/mock_data.dart';

/// Backend reachability, checked on launch against GET {API_BASE_URL}/health.
enum ApiStatus { unknown, checking, ok, degraded, unreachable }

class HealthState extends ChangeNotifier {
  ApiStatus status = ApiStatus.unknown;
  String detail = '';
  Map<String, dynamic>? payload;

  Future<void> check() async {
    status = ApiStatus.checking;
    notifyListeners();
    try {
      final res = await http.get(Uri.parse('${AppConfig.apiBaseUrl}/health')).timeout(const Duration(seconds: 6));
      payload = jsonDecode(res.body) as Map<String, dynamic>;
      status = res.statusCode == 200 ? ApiStatus.ok : ApiStatus.degraded;
      detail = 'HTTP ${res.statusCode} · db ${payload?['database']} · redis ${payload?['redis']}';
    } catch (e) {
      status = ApiStatus.unreachable;
      detail = e.toString().split('\n').first;
    }
    notifyListeners();
  }
}

class CartLine {
  CartLine({required this.item, required this.restaurantId, this.qty = 1});
  final MenuItem item;
  final String restaurantId;
  int qty;
  int get total => item.price * qty;
}

class CartState extends ChangeNotifier {
  final List<CartLine> lines = [];
  String note = '';

  int get count => lines.fold(0, (a, l) => a + l.qty);
  int get subtotal => lines.fold(0, (a, l) => a + l.total);
  int get comboSaving => lines.any((l) => l.item.id.contains('combo') || l.item.id.contains('pack')) ? 50 : 0;
  int get tax => ((subtotal - comboSaving) * 0.05).round();
  int get total => subtotal - comboSaving + tax;
  String? get restaurantId => lines.isEmpty ? null : lines.first.restaurantId;

  int qtyOf(String itemId) => lines.where((l) => l.item.id == itemId).fold(0, (a, l) => a + l.qty);

  void add(MenuItem item, String restaurantId) {
    final existing = lines.where((l) => l.item.id == item.id).firstOrNull;
    if (existing != null) {
      existing.qty++;
    } else {
      lines.add(CartLine(item: item, restaurantId: restaurantId));
    }
    notifyListeners();
  }

  void remove(String itemId) {
    final existing = lines.where((l) => l.item.id == itemId).firstOrNull;
    if (existing == null) return;
    if (existing.qty > 1) {
      existing.qty--;
    } else {
      lines.remove(existing);
    }
    notifyListeners();
  }

  void removeLine(String itemId) {
    lines.removeWhere((l) => l.item.id == itemId);
    notifyListeners();
  }

  void clear() {
    lines.clear();
    note = '';
    notifyListeners();
  }
}

enum OrderStatus { placed, confirmed, preparing, ready, pickedUp, cancelled }

String statusLabel(OrderStatus s) => switch (s) {
      OrderStatus.placed => 'Order Placed',
      OrderStatus.confirmed => 'Confirmed',
      OrderStatus.preparing => 'Being Prepared',
      OrderStatus.ready => 'Ready for Pickup',
      OrderStatus.pickedUp => 'Completed',
      OrderStatus.cancelled => 'Cancelled',
    };

class Order {
  Order({required this.number, required this.restaurantId, required this.lines, required this.subtotal, required this.discount, required this.tax, required this.total, required this.placedAt, required this.status, required this.paymentMethod});
  final String number, restaurantId, paymentMethod;
  final List<CartLine> lines;
  final int subtotal, discount, tax, total;
  final DateTime placedAt;
  OrderStatus status;
  DateTime get readyFrom => placedAt.add(const Duration(minutes: 15));
  DateTime get readyTo => placedAt.add(const Duration(minutes: 20));
  int get itemCount => lines.fold(0, (a, l) => a + l.qty);
  bool get isOngoing => status != OrderStatus.pickedUp && status != OrderStatus.cancelled;
}

class OrdersState extends ChangeNotifier {
  OrdersState({bool seedSamples = true}) {
    if (seedSamples) _seed();
  }

  final List<Order> orders = [];

  /// Two completed sample orders so the history looks like the approved web design.
  void _seed() {
    final now = DateTime.now();
    orders.addAll([
      Order(
        number: 'FTG128701',
        restaurantId: 'pizza-point',
        lines: [CartLine(item: menuItemById('margherita-pizza')!, restaurantId: 'pizza-point'), CartLine(item: menuItemById('garlic-bread')!, restaurantId: 'pizza-point')],
        subtotal: 400,
        discount: 0,
        tax: 20,
        total: 420,
        placedAt: DateTime(now.year, now.month, now.day, 13, 15).subtract(const Duration(days: 4)),
        status: OrderStatus.pickedUp,
        paymentMethod: 'UPI',
      ),
      Order(
        number: 'FTG128654',
        restaurantId: 'wok-express',
        lines: [CartLine(item: menuItemById('hakka-noodles')!, restaurantId: 'wok-express'), CartLine(item: menuItemById('coke')!, restaurantId: 'wok-express')],
        subtotal: 320,
        discount: 0,
        tax: 16,
        total: 336,
        placedAt: DateTime(now.year, now.month, now.day, 19, 45).subtract(const Duration(days: 9)),
        status: OrderStatus.pickedUp,
        paymentMethod: 'Wallet',
      ),
    ]);
  }

  Order place(CartState cart, String paymentMethod) {
    final order = Order(
      number: 'FTG${100000 + DateTime.now().millisecondsSinceEpoch % 900000}',
      restaurantId: cart.restaurantId ?? restaurants.first.id,
      lines: cart.lines.map((l) => CartLine(item: l.item, restaurantId: l.restaurantId, qty: l.qty)).toList(),
      subtotal: cart.subtotal,
      discount: cart.comboSaving,
      tax: cart.tax,
      total: cart.total,
      placedAt: DateTime.now(),
      status: OrderStatus.preparing,
      paymentMethod: paymentMethod,
    );
    orders.insert(0, order);
    notifyListeners();
    return order;
  }

  /// Puts a previous order's items back into the cart.
  void reorder(Order order, CartState cart) {
    cart.clear();
    for (final l in order.lines) {
      for (var i = 0; i < l.qty; i++) {
        cart.add(l.item, l.restaurantId);
      }
    }
  }

  Order? byNumber(String number) => orders.where((o) => o.number == number).firstOrNull;
}
