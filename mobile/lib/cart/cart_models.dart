/// Cart domain (Module 08) — structured selections, integer minor units, one restaurant per cart.
/// SERVER CART = NOT STARTED; persistence is the device key-value store only.
library;

import 'dart:convert';

import '../auth/auth_repository.dart' show KeyValueStore;

class SelectedOption {
  const SelectedOption({required this.groupId, required this.groupName, required this.optionId, required this.optionName, required this.priceAdjustmentMinor});
  final String groupId, groupName, optionId, optionName;
  final int priceAdjustmentMinor;
  Map<String, dynamic> toJson() => {'groupId': groupId, 'groupName': groupName, 'optionId': optionId, 'optionName': optionName, 'priceAdjustmentMinor': priceAdjustmentMinor};
  factory SelectedOption.fromJson(Map<String, dynamic> j) => SelectedOption(groupId: j['groupId'] as String, groupName: j['groupName'] as String, optionId: j['optionId'] as String, optionName: j['optionName'] as String, priceAdjustmentMinor: j['priceAdjustmentMinor'] as int);
}

class CartItem {
  const CartItem({required this.id, required this.menuItemId, required this.itemSlug, required this.restaurantId, required this.itemName, required this.image, required this.basePriceMinor, required this.currency, required this.selectedVariants, required this.selectedModifiers, required this.specialInstructions, required this.quantity, this.minimumQuantity = 1, this.maximumQuantity = 99, required this.unitPriceMinor, required this.addedAt});
  final String id, menuItemId, itemSlug, restaurantId, itemName, image, currency, specialInstructions;
  final int basePriceMinor, quantity, minimumQuantity, maximumQuantity, unitPriceMinor;
  final List<SelectedOption> selectedVariants, selectedModifiers;
  final DateTime addedAt;
  int get lineTotalMinor => unitPriceMinor * quantity;
  List<SelectedOption> get allOptions => [...selectedVariants, ...selectedModifiers];
  String get detail => [...allOptions.map((o) => o.optionName), if (specialInstructions.isNotEmpty) '"$specialInstructions"'].join(', ');
  CartItem withQuantity(int q) => CartItem(id: id, menuItemId: menuItemId, itemSlug: itemSlug, restaurantId: restaurantId, itemName: itemName, image: image, basePriceMinor: basePriceMinor, currency: currency, selectedVariants: selectedVariants, selectedModifiers: selectedModifiers, specialInstructions: specialInstructions, quantity: q, minimumQuantity: minimumQuantity, maximumQuantity: maximumQuantity, unitPriceMinor: unitPriceMinor, addedAt: addedAt);
  Map<String, dynamic> toJson() => {'id': id, 'menuItemId': menuItemId, 'itemSlug': itemSlug, 'restaurantId': restaurantId, 'itemName': itemName, 'image': image, 'basePriceMinor': basePriceMinor, 'currency': currency, 'selectedVariants': selectedVariants.map((o) => o.toJson()).toList(), 'selectedModifiers': selectedModifiers.map((o) => o.toJson()).toList(), 'specialInstructions': specialInstructions, 'quantity': quantity, 'minimumQuantity': minimumQuantity, 'maximumQuantity': maximumQuantity, 'unitPriceMinor': unitPriceMinor, 'addedAt': addedAt.toIso8601String()};
  factory CartItem.fromJson(Map<String, dynamic> j) => CartItem(
        id: j['id'] as String, menuItemId: j['menuItemId'] as String, itemSlug: j['itemSlug'] as String, restaurantId: j['restaurantId'] as String, itemName: j['itemName'] as String, image: j['image'] as String, basePriceMinor: j['basePriceMinor'] as int, currency: j['currency'] as String,
        selectedVariants: [for (final o in j['selectedVariants'] as List) SelectedOption.fromJson(o as Map<String, dynamic>)], selectedModifiers: [for (final o in j['selectedModifiers'] as List) SelectedOption.fromJson(o as Map<String, dynamic>)],
        specialInstructions: j['specialInstructions'] as String, quantity: j['quantity'] as int, minimumQuantity: (j['minimumQuantity'] as int?) ?? 1, maximumQuantity: (j['maximumQuantity'] as int?) ?? 99, unitPriceMinor: j['unitPriceMinor'] as int, addedAt: DateTime.tryParse(j['addedAt'] as String? ?? '') ?? DateTime.now());
}

class Cart {
  const Cart({required this.id, required this.restaurantId, required this.restaurantSlug, required this.restaurantName, required this.currency, required this.items, required this.createdAt, required this.updatedAt});
  final String id, restaurantId, restaurantSlug, restaurantName, currency;
  final List<CartItem> items;
  final DateTime createdAt, updatedAt;
  int get itemCount => items.fold(0, (a, i) => a + i.quantity);
  int get subtotalMinor => items.fold(0, (a, i) => a + i.lineTotalMinor);
  Cart copyWith({List<CartItem>? items}) => Cart(id: id, restaurantId: restaurantId, restaurantSlug: restaurantSlug, restaurantName: restaurantName, currency: currency, items: items ?? this.items, createdAt: createdAt, updatedAt: DateTime.now());
  Map<String, dynamic> toJson() => {'id': id, 'restaurantId': restaurantId, 'restaurantSlug': restaurantSlug, 'restaurantName': restaurantName, 'currency': currency, 'items': items.map((i) => i.toJson()).toList(), 'createdAt': createdAt.toIso8601String(), 'updatedAt': updatedAt.toIso8601String()};
  factory Cart.fromJson(Map<String, dynamic> j) => Cart(id: j['id'] as String, restaurantId: j['restaurantId'] as String, restaurantSlug: j['restaurantSlug'] as String, restaurantName: j['restaurantName'] as String, currency: j['currency'] as String, items: [for (final i in j['items'] as List) CartItem.fromJson(i as Map<String, dynamic>)], createdAt: DateTime.tryParse(j['createdAt'] as String? ?? '') ?? DateTime.now(), updatedAt: DateTime.tryParse(j['updatedAt'] as String? ?? '') ?? DateTime.now());
}

enum CartStatus { empty, active, updating, error }

abstract class CartRepository {
  Future<Cart?> load();
  Future<void> save(Cart? cart);
}

/// Device-local persistence (development phase). Never a server cart.
class LocalCartRepository implements CartRepository {
  LocalCartRepository(this._store);
  final KeyValueStore _store;
  static const key = 'fotg.cart.v1';
  @override
  Future<Cart?> load() async {
    try {
      final raw = await _store.read(key);
      if (raw == null || raw.isEmpty) return null;
      final c = Cart.fromJson(jsonDecode(raw) as Map<String, dynamic>);
      return c.items.isEmpty ? null : c;
    } catch (_) {
      return null;
    }
  }
  @override
  Future<void> save(Cart? cart) => _store.write(key, cart == null || cart.items.isEmpty ? null : jsonEncode(cart.toJson()));
}

class MemoryCartRepository implements CartRepository {
  Cart? _cart;
  @override
  Future<Cart?> load() async => _cart;
  @override
  Future<void> save(Cart? cart) async => _cart = (cart == null || cart.items.isEmpty) ? null : cart;
}
