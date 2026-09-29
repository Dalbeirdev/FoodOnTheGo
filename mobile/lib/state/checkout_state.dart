import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../cart/cart_validation.dart' as cv;
import '../checkout/checkout_models.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../menu/menu_repository.dart';
import 'auth_state.dart';
import 'cart_state.dart' hide PromoState, PromoStatus;
import 'pickup_state.dart';

export '../checkout/checkout_models.dart';

/// Centralized checkout state (Module 11) — Android. Reads auth, cart, pickup and restaurant state; never owns them.
class CheckoutState extends ChangeNotifier {
  CheckoutState({PromotionRepository? promotions, PaymentMethodRepository? payments, CheckoutRepository? checkout, ConnectivityService? connectivity, MockRestaurantRepository? restaurants, MenuRepository? menu, KeyValueStore? store})
      : promotionsRepo = promotions ?? MockPromotionRepository(), paymentsRepo = payments ?? MockPaymentMethodRepository(), checkoutRepo = checkout ?? MockCheckoutRepository(), connectivity = connectivity ?? MockConnectivityService(), _rr = restaurants ?? MockRestaurantRepository(), _mr = menu ?? MockMenuRepository(), _store = store ?? SecureKeyValueStore() {
    _restore();
  }
  static const termsKey = 'fotg.checkout.terms';
  static const requestKey = 'fotg.checkout.request';
  final PromotionRepository promotionsRepo;
  final PaymentMethodRepository paymentsRepo;
  final CheckoutRepository checkoutRepo;
  final ConnectivityService connectivity;
  final MockRestaurantRepository _rr;
  final MenuRepository _mr;
  final KeyValueStore _store;

  CheckoutStatus status = CheckoutStatus.initializing;
  GlobalRestaurant? restaurant;
  cv.CartReview? review;
  PickupValidation? pickupResult;
  CheckoutSummary? summary;
  PromoResult? promo;
  bool promoBusy = false;
  List<PaymentMethodOption> methods = const [];
  String? paymentMethodId;
  bool termsAccepted = false;
  String? error;
  CheckoutRequest? request;
  int _seq = 0;

  Future<void> _restore() async {
    try { termsAccepted = (await _store.read(termsKey)) == '1'; } catch (_) {}
    try { final raw = await _store.read(requestKey); if (raw != null && raw.isNotEmpty) request = CheckoutRequest.fromJson(jsonDecode(raw) as Map<String, dynamic>); } catch (_) {}
    notifyListeners();
  }

  List<CheckoutIssue> issues({required bool authenticated, required Cart? cart, required PickupSelection? selection}) {
    final out = <CheckoutIssue>[];
    if (!connectivity.isOnline) out.add(const CheckoutIssue(CheckoutIssueCode.offline));
    if (!authenticated) out.add(const CheckoutIssue(CheckoutIssueCode.auth));
    if (cart == null || cart.items.isEmpty) out.add(const CheckoutIssue(CheckoutIssueCode.cartEmpty));
    final rv = review;
    if (rv != null) {
      if (rv.currencyMismatch) out.add(const CheckoutIssue(CheckoutIssueCode.currency));
      if (rv.restaurantIssue == cv.RestaurantIssue.inactive) out.add(const CheckoutIssue(CheckoutIssueCode.restaurantUnavailable));
      if (rv.restaurantIssue == cv.RestaurantIssue.notAccepting) out.add(const CheckoutIssue(CheckoutIssueCode.restaurantNotAccepting));
      if (rv.lineIssues.any((i) => i.kind == cv.LineIssueKind.priceChanged)) out.add(const CheckoutIssue(CheckoutIssueCode.priceChanged));
      if (rv.lineIssues.any((i) => i.kind != cv.LineIssueKind.priceChanged)) out.add(const CheckoutIssue(CheckoutIssueCode.cartInvalid));
    }
    if (selection == null) {
      out.add(const CheckoutIssue(CheckoutIssueCode.pickupMissing));
    } else if (pickupResult != null && !pickupResult!.ok) {
      out.add(CheckoutIssue(CheckoutIssueCode.pickupInvalid, detail: pickupResult!.reason?.name));
    }
    if (promo != null && !promo!.applied && promo!.status != PromoStatus.idle && promo!.status != PromoStatus.applying) out.add(CheckoutIssue(CheckoutIssueCode.promoInvalid, blocking: false, detail: promo!.status.name));
    if (!termsAccepted) out.add(const CheckoutIssue(CheckoutIssueCode.terms));
    if (paymentMethodId == null) out.add(const CheckoutIssue(CheckoutIssueCode.paymentMethod));
    return out;
  }

  Future<void> prepare({required AuthState auth, required CartState cart, required PickupState pickup, DateTime? now}) async {
    final my = ++_seq;
    error = null;
    if (status != CheckoutStatus.paymentReady) status = CheckoutStatus.validating;
    notifyListeners();
    try {
      if (!connectivity.isOnline) { status = CheckoutStatus.offline; notifyListeners(); return; }
      if (!auth.isAuthenticated) { status = CheckoutStatus.requiresAuth; notifyListeners(); return; }
      final c = cart.cart;
      if (c == null || c.items.isEmpty) { status = CheckoutStatus.invalidCart; review = null; summary = null; notifyListeners(); return; }
      final r = await _rr.getRestaurantBySlug(c.restaurantSlug);
      if (my != _seq) return;
      restaurant = r;
      final rv = await cv.reviewCart(c, r, _mr, now: now);
      if (my != _seq) return;
      review = rv;
      var discount = 0;
      final code = promo?.applied == true ? promo!.code : (cart.promo.status == cv.PromoStatus.applied ? cart.promo.code : null);
      if (code != null && r != null) {
        final pr = await promotionsRepo.evaluate(code, PromoContext(subtotalMinor: c.subtotalMinor, currency: c.currency, restaurantId: r.id, countryCode: r.countryCode, now: now));
        promo = pr; discount = pr.applied ? pr.discountMinor : 0;
      }
      summary = await checkoutRepo.buildSummary(c, discount, (now ?? DateTime.now()).toUtc());
      methods = r == null ? const [] : await paymentsRepo.getAvailableMethods(r.countryCode, c.currency);
      if (my != _seq) return;
      if (paymentMethodId == null || !methods.any((m) => m.id == paymentMethodId && m.enabled)) paymentMethodId = methods.where((m) => m.enabled).firstOrNull?.id;
      if (pickup.selection == null) {
        pickupResult = const PickupValidation.fail(PickupInvalidReason.slotMissing);
      } else if (r != null) {
        pickupResult = await pickup.validate(r, now: now);
      }
      if (my != _seq) return;
      if (rv.blocking) {
        status = CheckoutStatus.invalidCart;
      } else if (pickupResult == null || !pickupResult!.ok) {
        status = CheckoutStatus.invalidPickup;
      } else {
        status = CheckoutStatus.ready;
      }
      notifyListeners();
    } catch (e) {
      if (my != _seq) return;
      error = '$e'; status = CheckoutStatus.error; notifyListeners();
    }
  }

  Future<PromoResult> applyPromo(String code, {required CartState cart, DateTime? now}) async {
    promoBusy = true; notifyListeners();
    try {
      final c = cart.cart; final r = restaurant;
      final res = await promotionsRepo.evaluate(code, PromoContext(subtotalMinor: c?.subtotalMinor ?? 0, currency: c?.currency ?? 'INR', restaurantId: r?.id ?? c?.restaurantId ?? '', countryCode: r?.countryCode ?? 'ZZ', now: now));
      promo = res;
      if (res.applied) { cart.applyPromo(res.code); } else { cart.removePromo(); }
      if (c != null) summary = await checkoutRepo.buildSummary(c, res.applied ? res.discountMinor : 0, (now ?? DateTime.now()).toUtc());
      return res;
    } finally { promoBusy = false; notifyListeners(); }
  }
  Future<void> removePromo({required CartState cart, DateTime? now}) async { promo = null; cart.removePromo(); final c = cart.cart; if (c != null) summary = await checkoutRepo.buildSummary(c, 0, (now ?? DateTime.now()).toUtc()); notifyListeners(); }
  /// Called once the order exists — a consumed request must never re-enter payment.
  void clearRequest() { request = null; status = CheckoutStatus.initializing; _store.write(requestKey, null).catchError((_) {}); notifyListeners(); }
  void setPaymentMethod(String id) { paymentMethodId = id; notifyListeners(); }
  void setTermsAccepted(bool v) { termsAccepted = v; _store.write(termsKey, v ? '1' : '0').catchError((_) {}); notifyListeners(); }

  /// Final mock validation pass, then a CheckoutRequest for Module 12. The backend repeats every check.
  Future<CheckoutRequest?> continueToPayment({required AuthState auth, required CartState cart, required PickupState pickup, DateTime? now}) async {
    await prepare(auth: auth, cart: cart, pickup: pickup, now: now);
    final c = cart.cart; final sel = pickup.selection; final s = summary; final user = auth.user; final pm = paymentMethodId;
    if (status != CheckoutStatus.ready || !connectivity.isOnline || user == null || c == null || sel == null || s == null || !termsAccepted || pm == null) return null;
    final rnd = Random.secure();
    final key = List.generate(16, (_) => rnd.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    final nowUtc = (now ?? DateTime.now()).toUtc();
    request = CheckoutRequest(idempotencyKey: key, customerId: user.id, cartId: c.id, restaurantId: c.restaurantId, pickupSelection: sel, promoCode: promo?.applied == true ? promo!.code : null, currency: c.currency, orderNote: cart.note, termsAccepted: true, termsVersion: termsVersion, privacyVersion: privacyVersion, acceptedAt: nowUtc, paymentMethodId: pm, displayedTotalMinor: s.totalMinor, createdAt: nowUtc);
    _store.write(requestKey, jsonEncode(request!.toJson())).catchError((_) {});
    status = CheckoutStatus.paymentReady; notifyListeners();
    return request;
  }
}

