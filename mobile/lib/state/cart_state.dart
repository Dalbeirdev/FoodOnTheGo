import '../market/market.dart';
import 'dart:async';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show SecureKeyValueStore;
import '../cart/cart_models.dart';
import '../data/mock_data.dart' show MenuItem;
import '../discovery/discovery_repository.dart' show globalRestaurants;
import '../i18n/format.dart' show pow10;
import '../pricing/pricing_service.dart' show configurationKey;
import 'package:intl/intl.dart';

export '../cart/cart_models.dart';
export '../cart/cart_validation.dart' show PromoState, PromoStatus;

import '../cart/cart_validation.dart' show PromoState, PromoStatus, evaluatePromo, discountMinor;

/// Legacy line shape used by the Module 01 cart / checkout / order screens (prices in MAJOR units).
class CartLine {
  CartLine({required this.item, required this.restaurantId, this.qty = 1});
  final MenuItem item;
  final String restaurantId;
  int qty;
  int get total => item.price * qty;
}

class AddItemInput {
  const AddItemInput({required this.menuItemId, required this.itemSlug, required this.itemName, required this.image, required this.basePriceMinor, required this.currency, required this.restaurantId, required this.restaurantSlug, required this.restaurantName, required this.restaurantCurrency, this.selectedVariants = const [], this.selectedModifiers = const [], this.specialInstructions = '', this.quantity = 1, required this.unitPriceMinor, this.minimumQuantity = 1, this.maximumQuantity = 99, this.lineKey});
  final String menuItemId, itemSlug, itemName, image, currency, restaurantId, restaurantSlug, restaurantName, restaurantCurrency, specialInstructions;
  final int basePriceMinor, quantity, unitPriceMinor, minimumQuantity, maximumQuantity;
  final List<SelectedOption> selectedVariants, selectedModifiers;
  /// Legacy screens pass their own key; new callers leave it null (configuration key is used).
  final String? lineKey;
}

enum AddFailure { restaurantConflict, currencyMismatch, invalidQuantity }

class AddResult {
  const AddResult.ok(this.item, {this.merged = false}) : failure = null;
  const AddResult.fail(this.failure) : item = null, merged = false;
  final CartItem? item;
  final bool merged;
  final AddFailure? failure;
  bool get ok => failure == null;
}

/// Centralized cart state (Module 08): structured items, ONE ACTIVE CART = ONE RESTAURANT,
/// identical configurations merge, different configurations stay separate, device persistence.
class CartState extends ChangeNotifier {
  CartState({CartRepository? repository}) : _repo = repository ?? LocalCartRepository(SecureKeyValueStore()) {
    _hydrate();
  }
  final CartRepository _repo;
  Cart? cart;
  CartStatus status = CartStatus.empty;
  ({AddItemInput input, Cart current})? conflict;
  String note = '';
  PromoState _promo = PromoState.none;
  final _hydrated = Completer<void>();

  Future<void> _hydrate() async {
    try {
      final c = await _repo.load();
      if (c != null && cart == null) { cart = c; status = CartStatus.active; notifyListeners(); }
    } catch (_) {}
    if (!_hydrated.isCompleted) _hydrated.complete();
  }
  /// Resolves once persistence has been read (tests await this).
  Future<void> get ready => _hydrated.future;

  int get count => cart?.itemCount ?? 0;
  int get subtotalMinor => cart?.subtotalMinor ?? 0;
  String? get currency => cart?.currency;
  String? get restaurantId => cart?.restaurantId;
  List<CartItem> get items => cart?.items ?? const [];

  void _commit(Cart? next) {
    cart = (next == null || next.items.isEmpty) ? null : next.copyWith();
    status = cart == null ? CartStatus.empty : CartStatus.active;
    notifyListeners();
    unawaited(_repo.save(cart).catchError((_) { status = CartStatus.error; notifyListeners(); }));
  }

  CartItem _build(AddItemInput i, int quantity) {
    final sel = <String, List<String>>{};
    for (final o in [...i.selectedVariants, ...i.selectedModifiers]) { (sel[o.groupId] ??= []).add(o.optionId); }
    return CartItem(id: i.lineKey ?? configurationKey(i.menuItemId, sel, i.specialInstructions), menuItemId: i.menuItemId, itemSlug: i.itemSlug, restaurantId: i.restaurantId, itemName: i.itemName, image: i.image, basePriceMinor: i.basePriceMinor, currency: i.currency, selectedVariants: i.selectedVariants, selectedModifiers: i.selectedModifiers, specialInstructions: i.specialInstructions, quantity: quantity, minimumQuantity: i.minimumQuantity, maximumQuantity: i.maximumQuantity, unitPriceMinor: i.unitPriceMinor, addedAt: DateTime.now());
  }

  AddResult _insert(Cart? base, AddItemInput input) {
    final q = input.quantity;
    if (q < 1) return const AddResult.fail(AddFailure.invalidQuantity);
    if (input.currency != input.restaurantCurrency) return const AddResult.fail(AddFailure.currencyMismatch);
    final fresh = _build(input, q);
    final target = (base != null && base.restaurantId == input.restaurantId) ? base : Cart(id: 'cart-${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}', restaurantId: input.restaurantId, restaurantSlug: input.restaurantSlug, restaurantName: input.restaurantName, currency: input.restaurantCurrency, items: const [], createdAt: DateTime.now(), updatedAt: DateTime.now());
    final existing = target.items.where((i) => i.id == fresh.id).firstOrNull;
    var item = fresh; var merged = false;
    if (existing != null) { final nq = existing.quantity + q; item = existing.withQuantity(nq > existing.maximumQuantity ? existing.maximumQuantity : nq); merged = true; }
    final items = existing != null ? target.items.map((i) => i.id == item.id ? item : i).toList() : [...target.items, item];
    _commit(target.copyWith(items: items));
    return AddResult.ok(item, merged: merged);
  }

  /// Validated by the caller (pricing service); raises an explicit conflict for a different restaurant.
  AddResult addItem(AddItemInput input) {
    final c = cart;
    if (c != null && c.items.isNotEmpty && c.restaurantId != input.restaurantId) { conflict = (input: input, current: c); notifyListeners(); return const AddResult.fail(AddFailure.restaurantConflict); }
    return _insert(c, input);
  }
  AddResult replaceRestaurantCart(AddItemInput input) => _insert(null, input);
  void confirmReplace() { final p = conflict; if (p != null) { conflict = null; _insert(null, p.input); } }
  void cancelReplace() { conflict = null; notifyListeners(); }
  void removeItem(String id) { final c = cart; if (c != null) _commit(c.copyWith(items: c.items.where((i) => i.id != id).toList())); }
  void updateQuantity(String id, int quantity) {
    final c = cart; if (c == null) return;
    if (quantity <= 0) { removeItem(id); return; }
    _commit(c.copyWith(items: c.items.map((i) => i.id == id ? i.withQuantity(quantity < i.minimumQuantity ? i.minimumQuantity : (quantity > i.maximumQuantity ? i.maximumQuantity : quantity)) : i).toList()));
  }
  /// Replace a line with a re-configured version (merges when the new configuration already exists).
  AddResult editItem(String id, AddItemInput input) {
    final c = cart;
    if (c == null) return const AddResult.fail(AddFailure.invalidQuantity);
    if (c.restaurantId != input.restaurantId) return const AddResult.fail(AddFailure.restaurantConflict);
    return _insert(c.copyWith(items: c.items.where((i) => i.id != id).toList()), input);
  }
  /// Customer consciously accepts a changed unit price (stale-cart review).
  void acceptPriceChange(String id, int newUnitMinor) {
    final c = cart; if (c == null) return;
    _commit(c.copyWith(items: c.items.map((i) => i.id == id ? CartItem(id: i.id, menuItemId: i.menuItemId, itemSlug: i.itemSlug, restaurantId: i.restaurantId, itemName: i.itemName, image: i.image, basePriceMinor: i.basePriceMinor, currency: i.currency, selectedVariants: i.selectedVariants, selectedModifiers: i.selectedModifiers, specialInstructions: i.specialInstructions, quantity: i.quantity, minimumQuantity: i.minimumQuantity, maximumQuantity: i.maximumQuantity, unitPriceMinor: newUnitMinor, addedAt: i.addedAt) : i).toList()));
  }
  /* -------- promotions (frontend area; backend validates for real) -------- */
  PromoState get promo => (cart != null && _promo.status == PromoStatus.applied) ? evaluatePromo(_promo.code, subtotalMinor, _digits) : _promo;
  int get discountMinorValue => cart == null ? 0 : discountMinor(subtotalMinor, promo);
  int get estimatedTotalMinor => subtotalMinor - discountMinorValue < 0 ? 0 : subtotalMinor - discountMinorValue;
  PromoState applyPromo(String code) { _promo = cart == null ? PromoState(code: code.trim().toUpperCase(), status: PromoStatus.invalid) : evaluatePromo(code, subtotalMinor, _digits); notifyListeners(); return _promo; }
  void removePromo() { _promo = PromoState.none; notifyListeners(); }
  void clearCart() { note = ''; _promo = PromoState.none; _commit(null); }

  /* ---------------- legacy API (Module 01 screens, replaced by the Cart module) ---------------- */
  int get _digits => cart == null ? 2 : (NumberFormat.simpleCurrency(locale: 'en_US', name: cart!.currency).decimalDigits ?? 2);
  int _major(int minor) => minor ~/ pow10(_digits);
  List<CartLine> get lines => [for (final i in items) CartLine(item: MenuItem(id: i.id, name: i.itemName, desc: i.detail, price: _major(i.unitPriceMinor), image: i.image, veg: i.selectedModifiers.isEmpty && false), restaurantId: i.restaurantId, qty: i.quantity)];
  int get subtotal => _major(subtotalMinor);
  int get comboSaving => items.any((l) => l.menuItemId.contains('combo') || l.menuItemId.contains('pack')) ? 50 : 0;
  /// No tax rate lives in the app: taxes are backend / configuration driven (legacy getter kept for Module 01 callers).
  int get tax => 0;
  int get total => subtotal - comboSaving + tax;
  int qtyOf(String itemId) => items.where((i) => i.menuItemId == itemId || i.id == itemId).fold(0, (a, i) => a + i.quantity);
  /// Legacy add: a plain line without options (reorder, quick add). Prices arrive in major units of the cart currency.
  void add(MenuItem item, String restaurantId) {
    final existing = items.where((i) => i.id == item.id).firstOrNull;
    if (existing != null) { updateQuantity(existing.id, existing.quantity + 1); return; }
    final gr = globalRestaurants.where((r) => r.id == restaurantId).firstOrNull;
    final cur = cart != null && cart!.restaurantId == restaurantId ? cart!.currency : (gr?.currency ?? marketAvailability.activeMarket.defaultCurrency);
    final scale = pow10(NumberFormat.simpleCurrency(locale: 'en_US', name: cur).decimalDigits ?? 2);
    addItem(AddItemInput(menuItemId: item.id, itemSlug: item.id, itemName: item.name, image: item.image, basePriceMinor: item.price * scale, currency: cur, restaurantId: restaurantId, restaurantSlug: gr?.slug ?? restaurantId, restaurantName: gr?.name ?? restaurantId, restaurantCurrency: cur, unitPriceMinor: item.price * scale, lineKey: item.id));
  }
  void remove(String itemId) { final i = items.where((x) => x.id == itemId).firstOrNull; if (i != null) updateQuantity(i.id, i.quantity - 1); }
  void removeLine(String itemId) => removeItem(itemId);
  void clear() => clearCart();
}
