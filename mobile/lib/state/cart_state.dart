import '../market/market.dart';
import 'dart:async';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show SecureKeyValueStore;
import '../cart/api_cart.dart';
import '../cart/cart_models.dart';
import '../data/mock_data.dart' show MenuItem;
import '../discovery/api_restaurants.dart' show knownRestaurants;
import '../discovery/discovery_repository.dart' show globalRestaurants;
import '../i18n/format.dart' show pow10;
import '../pricing/pricing_service.dart' show configurationKey;
import 'package:intl/intl.dart';

export '../cart/api_cart.dart' show CartBackend, CartSummary, JourneyRef, cartsFromApi;
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
  const AddItemInput({required this.menuItemId, required this.itemSlug, required this.itemName, required this.image, required this.basePriceMinor, required this.currency, required this.restaurantId, required this.restaurantSlug, required this.restaurantName, required this.restaurantCurrency, this.selectedVariants = const [], this.selectedModifiers = const [], this.specialInstructions = '', this.quantity = 1, required this.unitPriceMinor, this.minimumQuantity = 1, this.maximumQuantity = 99, this.lineKey, this.restaurantPublicId});
  final String menuItemId, itemSlug, itemName, image, currency, restaurantId, restaurantSlug, restaurantName, restaurantCurrency, specialInstructions;
  /// The backend id of the restaurant location (CART_MODE=api). [unitPriceMinor] is a preview for the device cart only:
  /// the backend never receives it and prices the item itself.
  final String? restaurantPublicId;
  final int basePriceMinor, quantity, unitPriceMinor, minimumQuantity, maximumQuantity;
  final List<SelectedOption> selectedVariants, selectedModifiers;
  /// Legacy screens pass their own key; new callers leave it null (configuration key is used).
  final String? lineKey;
}

enum AddFailure { restaurantConflict, currencyMismatch, invalidQuantity, unavailable, invalidCustomization, expired, offline, error }

class AddResult {
  const AddResult.ok(this.item, {this.merged = false}) : failure = null, message = null;
  const AddResult.fail(this.failure, {this.message}) : item = null, merged = false;
  final CartItem? item;
  final bool merged;
  final AddFailure? failure;
  /// The backend's own words for what it refused (CART_MODE=api).
  final String? message;
  bool get ok => failure == null;
}

/// Centralized cart state. Two modes behind one class:
///
///  - the device cart of Module 08 (mock mode: tests, or no [backend]): structured items, ONE ACTIVE CART = ONE
///    RESTAURANT, identical configurations merge, different configurations stay separate, device persistence;
///  - the backend cart of Module 27 ([backend] given, CART_MODE=api): the cart is the server's. Nothing counts until
///    the server confirmed it; changes run one after the other and carry the cart version the app last saw; a
///    restaurant conflict is the backend's and is answered by the explicit, confirmed switch; after sign-in the
///    guest cart is claimed with its token. The device only keeps the LAST cart the backend sent, to show —
///    never to change — while there is no connection.
class CartState extends ChangeNotifier {
  CartState({CartRepository? repository, this.backend, this.journeyRef}) : _repo = repository ?? LocalCartRepository(SecureKeyValueStore()) {
    _hydrate();
  }
  final CartRepository _repo;
  /// The backend cart gateway; null = the device cart.
  final CartBackend? backend;
  /// The journey the customer is on now (backend journeys only), asked for when an item is added.
  Future<JourneyRef?> Function()? journeyRef;
  Future<JourneyRef?> _journey() async => journeyRef == null ? null : await journeyRef!();
  bool get backendMode => backend != null;
  /// False until the cart has been read once.
  bool hydrated = false;
  /// The backend could not be reached: [cart] is the last known copy (or null) and cannot be changed.
  bool offline = false;
  /// The last change the backend refused, in the customer's words.
  String? lastError;
  /// A one-off notice (e.g. "your carts were combined").
  String? flash;
  /// Two carts of different restaurants met at sign-in: the customer chooses which one to keep.
  ({CartSummary guest, CartSummary account})? mergeConflict;
  Cart? _confirmed;
  Future<void> _queue = Future.value();
  int _waiting = 0;
  bool _signedIn = false;
  Cart? cart;
  CartStatus status = CartStatus.empty;
  ({AddItemInput input, Cart current})? conflict;
  String note = '';
  PromoState _promo = PromoState.none;
  final _hydrated = Completer<void>();

  Future<void> _hydrate() async {
    if (backendMode) { await reload(); if (!_hydrated.isCompleted) _hydrated.complete(); return; }
    try {
      final c = await _repo.load();
      if (c != null && cart == null) { cart = c; status = CartStatus.active; notifyListeners(); }
    } catch (_) {}
    hydrated = true;
    if (!_hydrated.isCompleted) _hydrated.complete();
  }

  /* ---------------- backend cart (CART_MODE=api) ---------------- */
  int? get _version => _confirmed?.server?.version;
  void _accept(Cart? next) {
    _confirmed = next; cart = next; offline = false; hydrated = true;
    status = _waiting > 0 ? CartStatus.updating : (next == null || next.items.isEmpty ? CartStatus.empty : CartStatus.active);
    notifyListeners();
    unawaited(_repo.save(next).catchError((_) {}));
  }
  /// One change at a time, in the order the customer made them.
  Future<T> _run<T>(Future<T> Function() work) {
    _waiting++; status = CartStatus.updating; notifyListeners();
    final next = _queue.then((_) => work());
    _queue = next.then<void>((_) {}, onError: (_) {}).whenComplete(() {
      _waiting--;
      if (_waiting == 0) { status = offline && cart == null ? CartStatus.error : (cart == null || cart!.items.isEmpty ? CartStatus.empty : CartStatus.active); notifyListeners(); }
    });
    return next;
  }
  CartException _fail(Object e) { final err = toCartException(e); lastError = err.message; notifyListeners(); return err; }
  static AddFailure _failure(CartErrorCode code) => switch (code) {
        CartErrorCode.restaurantConflict => AddFailure.restaurantConflict, CartErrorCode.currencyMismatch => AddFailure.currencyMismatch, CartErrorCode.quantityLimit => AddFailure.invalidQuantity,
        CartErrorCode.itemUnavailable || CartErrorCode.optionUnavailable || CartErrorCode.restaurantUnavailable => AddFailure.unavailable, CartErrorCode.invalidCustomization => AddFailure.invalidCustomization,
        CartErrorCode.expired => AddFailure.expired, CartErrorCode.offline => AddFailure.offline, _ => AddFailure.error,
      };
  Future<void> _reloadNow() async {
    try {
      _accept(await backend!.load());
    } catch (e) {
      if (toCartException(e).code != CartErrorCode.offline) return;
      // No connection: show the last cart the backend sent (if any) and say so. It cannot be changed until the backend answers.
      offline = true; hydrated = true;
      if (cart == null) { try { cart = await _repo.load(); } catch (_) {} }
      status = cart == null ? CartStatus.error : CartStatus.active;
      notifyListeners();
    }
  }
  /// Reads the cart again (the backend revalidates it on every read).
  Future<void> reload() => backendMode ? _run(_reloadNow) : Future.value();
  void dismissError() { lastError = null; notifyListeners(); }
  void dismissFlash() { flash = null; notifyListeners(); }

  /// After an answer the app cannot build on (stale version, expired cart, something unavailable): show what the backend has.
  Future<void> _recover(CartException err) async {
    if (const {CartErrorCode.versionConflict, CartErrorCode.expired, CartErrorCode.notFound, CartErrorCode.itemUnavailable, CartErrorCode.optionUnavailable, CartErrorCode.restaurantUnavailable}.contains(err.code)) await _reloadNow();
  }
  CartItemRequest _request(AddItemInput i) => CartItemRequest.of(i.menuItemId, [...i.selectedVariants, ...i.selectedModifiers], i.quantity, i.specialInstructions);
  /// The backend id of the restaurant: given by the caller, otherwise looked up from the restaurants the app knows.
  String? _publicId(AddItemInput i) => i.restaurantPublicId ?? knownRestaurants.where((r) => r.id == i.restaurantId || r.slug == i.restaurantSlug).firstOrNull?.backendId;

  /// Adds an item. With a backend the result arrives once the backend has priced and validated it.
  Future<AddResult> addToCart(AddItemInput input) async {
    if (!backendMode) return addItem(input);
    final restaurant = _publicId(input);
    if (restaurant == null) return const AddResult.fail(AddFailure.error);
    final current = _confirmed;
    if (current != null && current.items.isNotEmpty && current.server?.expired != true && current.server?.restaurantPublicId != restaurant) { conflict = (input: input, current: current); notifyListeners(); return const AddResult.fail(AddFailure.restaurantConflict); }
    return _run(() async {
      try {
        final a = await backend!.add(restaurant, _request(input), version: _version, journey: await _journey());
        _accept(a.cart);
        return AddResult.ok(a.cart?.items.where((i) => i.id == a.lineId).firstOrNull, merged: a.merged);
      } catch (e) {
        final err = toCartException(e);
        // The backend's own answer to "another restaurant": ask the customer, with the cart it described.
        if (err.code == CartErrorCode.restaurantConflict) { await _reloadNow(); final c = _confirmed; if (c != null) { conflict = (input: input, current: c); notifyListeners(); } return const AddResult.fail(AddFailure.restaurantConflict); }
        _fail(err); await _recover(err);
        return AddResult.fail(_failure(err.code), message: err.message);
      }
    });
  }

  /// Saves a re-configured line. With a backend: PATCH — the backend prices it again (and merges equal configurations).
  Future<AddResult> saveItem(String id, AddItemInput input) async {
    if (!backendMode) return editItem(id, input);
    return _run(() async {
      try {
        final a = await backend!.update(id, configuration: _request(input), version: _version);
        _accept(a.cart);
        return AddResult.ok(a.cart?.items.where((i) => i.id == a.lineId).firstOrNull, merged: a.merged);
      } catch (e) { final err = _fail(e); await _recover(err); return AddResult.fail(_failure(err.code), message: err.message); }
    });
  }

  /// The confirmed switch: the cart is replaced by one of the other restaurant, with the item that raised the question.
  Future<AddResult> confirmReplaceAndWait() async {
    final pending = conflict;
    if (pending == null) return const AddResult.fail(AddFailure.error);
    conflict = null; notifyListeners();
    if (!backendMode) return _insert(null, pending.input);
    final restaurant = _publicId(pending.input);
    if (restaurant == null) return const AddResult.fail(AddFailure.error);
    return _run(() async {
      try {
        final a = await backend!.switchRestaurant(restaurant, _request(pending.input), version: _version, journey: await _journey());
        _accept(a.cart);
        return AddResult.ok(a.cart?.items.where((i) => i.id == a.lineId).firstOrNull, merged: a.merged);
      } catch (e) { final err = _fail(e); await _recover(err); return AddResult.fail(_failure(err.code), message: err.message); }
    });
  }

  /// A change the customer sees at once; the answer of the last one in the row is what stays.
  void _change(Cart? Function(Cart) optimistic, Future<CartAnswer> Function() send) {
    if (offline) { lastError = 'Cannot reach FoodOnTheGo right now. Your cart was not changed.'; notifyListeners(); return; }
    final c = cart; if (c != null) { cart = optimistic(c); notifyListeners(); }
    unawaited(_run(() async {
      try { final a = await send(); _confirmed = a.cart; if (_waiting <= 1) _accept(a.cart); } catch (e) { _fail(e); await _reloadNow(); }
    }));
  }

  /// The session changed: after sign-in the guest cart is claimed with its token; after sign-out the guest's cart is read.
  Future<void> onSessionChanged(bool signedIn) async {
    if (!backendMode || signedIn == _signedIn) return;
    _signedIn = signedIn;
    backend!.session = signedIn;
    await _run(() async {
      try {
        if (signedIn && await backend!.hasGuestToken()) { await _claim(null); } else { await _reloadNow(); }
      } catch (e) { _fail(e); }
    });
  }
  Future<void> _claim(String? resolution) async {
    try {
      final r = await backend!.claim(resolution: resolution);
      mergeConflict = null;
      if (r.outcome == 'merged') flash = r.adjusted.isEmpty ? 'The items you added before signing in are now in your cart.' : 'Your carts were combined. Some quantities were limited to the maximum for the item: ${r.adjusted.map((a) => '${a.$1} (${a.$2})').join(', ')}.';
      _accept(r.cart);
    } catch (e) {
      final err = toCartException(e);
      if (err.code != CartErrorCode.mergeConflict) rethrow;
      mergeConflict = (guest: CartSummary.fromJson(err.details['guest'] as Map<String, dynamic>), account: CartSummary.fromJson(err.details['account'] as Map<String, dynamic>));
      await _reloadNow();
    }
  }
  /// The customer chose which of the two carts to keep ('keep_guest' or 'keep_account').
  Future<void> resolveMerge(String resolution) => _run(() async { try { await _claim(resolution); } catch (e) { _fail(e); } });
  /// Links the cart to the journey the customer is on now — their own choice.
  void linkCurrentJourney() {
    if (!backendMode) return;
    unawaited(_run(() async { try { final j = await _journey(); if (j == null) return; _accept((await backend!.linkJourney(j, version: _version)).cart); } catch (e) { _fail(e); await _reloadNow(); } }));
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
  void confirmReplace() { if (backendMode) { unawaited(confirmReplaceAndWait()); return; } final p = conflict; if (p != null) { conflict = null; _insert(null, p.input); } }
  void cancelReplace() { conflict = null; notifyListeners(); }
  void removeItem(String id) {
    if (backendMode) { _change((c) => c.copyWith(items: c.items.where((i) => i.id != id).toList()), () => backend!.remove(id, version: _version)); return; }
    final c = cart; if (c != null) _commit(c.copyWith(items: c.items.where((i) => i.id != id).toList()));
  }
  void updateQuantity(String id, int quantity) {
    final c = cart; if (c == null) return;
    if (quantity <= 0) { removeItem(id); return; }
    if (backendMode) {
      int clamp(CartItem i) => quantity < i.minimumQuantity ? i.minimumQuantity : (quantity > i.maximumQuantity ? i.maximumQuantity : quantity);
      _change((x) => x.copyWith(items: x.items.map((i) => i.id == id ? i.withQuantity(clamp(i)) : i).toList()), () { final line = _confirmed?.items.where((i) => i.id == id).firstOrNull; return backend!.update(id, quantity: line == null ? quantity : clamp(line), version: _version); });
      return;
    }
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
    // Backend carts: "update my cart" — every line is brought to today's prices by the backend itself.
    if (backendMode) { unawaited(_run(() async { try { _accept((await backend!.refresh(version: _version)).cart); } catch (e) { _fail(e); await _reloadNow(); } })); return; }
    final c = cart; if (c == null) return;
    _commit(c.copyWith(items: c.items.map((i) => i.id == id ? CartItem(id: i.id, menuItemId: i.menuItemId, itemSlug: i.itemSlug, restaurantId: i.restaurantId, itemName: i.itemName, image: i.image, basePriceMinor: i.basePriceMinor, currency: i.currency, selectedVariants: i.selectedVariants, selectedModifiers: i.selectedModifiers, specialInstructions: i.specialInstructions, quantity: i.quantity, minimumQuantity: i.minimumQuantity, maximumQuantity: i.maximumQuantity, unitPriceMinor: newUnitMinor, addedAt: i.addedAt) : i).toList()));
  }
  /* -------- promotions (frontend area; backend validates for real) -------- */
  PromoState get promo => (cart != null && _promo.status == PromoStatus.applied) ? evaluatePromo(_promo.code, subtotalMinor, _digits) : _promo;
  int get discountMinorValue => cart == null ? 0 : discountMinor(subtotalMinor, promo);
  int get estimatedTotalMinor => subtotalMinor - discountMinorValue < 0 ? 0 : subtotalMinor - discountMinorValue;
  PromoState applyPromo(String code) { _promo = cart == null ? PromoState(code: code.trim().toUpperCase(), status: PromoStatus.invalid) : evaluatePromo(code, subtotalMinor, _digits); notifyListeners(); return _promo; }
  void removePromo() { _promo = PromoState.none; notifyListeners(); }
  void clearCart() {
    note = ''; _promo = PromoState.none;
    if (backendMode) {
      // Cleared at once for the screens; the backend follows, and if it cannot, its cart is shown again.
      _confirmed = null; cart = null; notifyListeners();
      unawaited(_run(() async { try { await backend!.clear(); unawaited(_repo.save(null).catchError((_) {})); } catch (e) { _fail(e); await _reloadNow(); } }));
      return;
    }
    _commit(null);
  }

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
    final input = AddItemInput(menuItemId: item.id, itemSlug: item.id, itemName: item.name, image: item.image, basePriceMinor: item.price * scale, currency: cur, restaurantId: restaurantId, restaurantSlug: gr?.slug ?? restaurantId, restaurantName: gr?.name ?? restaurantId, restaurantCurrency: cur, unitPriceMinor: item.price * scale, lineKey: item.id);
    // Backend cart: the backend decides whether this is an item it can add (and at what price).
    if (backendMode) { unawaited(addToCart(input)); return; }
    addItem(input);
  }
  void remove(String itemId) { final i = items.where((x) => x.id == itemId).firstOrNull; if (i != null) updateQuantity(i.id, i.quantity - 1); }
  void removeLine(String itemId) => removeItem(itemId);
  void clear() => clearCart();
}
