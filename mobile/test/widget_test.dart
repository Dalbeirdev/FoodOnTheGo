import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/data/mock_data.dart';
import 'package:foodonthego/state/app_state.dart';

void main() {
  group('CartState', () {
    test('adds, steps and totals items with GST and combo discount', () {
      final cart = CartState();
      final burger = menuItemById('classic-burger')!;
      final combo = menuItemById('classic-combo')!;
      cart.add(burger, 'burger-hub');
      cart.add(combo, 'burger-hub');
      expect(cart.count, 2);
      expect(cart.subtotal, 600);
      expect(cart.comboSaving, 50);
      expect(cart.tax, 28);
      expect(cart.total, 578);
      cart.remove('classic-burger');
      expect(cart.count, 1);
      cart.clear();
      expect(cart.count, 0);
    });
  });

  group('OrdersState', () {
    test('places an order from the cart with a FTG number and preparing status', () {
      final cart = CartState()..add(menuItemById('cola')!, 'burger-hub');
      final orders = OrdersState();
      final order = orders.place(cart, 'UPI');
      expect(order.number, startsWith('FTG'));
      expect(order.status, OrderStatus.preparing);
      expect(order.total, 63);
      expect(orders.byNumber(order.number), isNotNull);
    });
  });

  test('inr formats Indian grouping', () {
    expect(inr(60), '₹60');
    expect(inr(1150), '₹1,150');
    expect(inr(125000), '₹1,25,000');
  });
}
