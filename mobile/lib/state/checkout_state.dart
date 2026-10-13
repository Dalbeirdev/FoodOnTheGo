import '../market/market.dart';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../analytics/analytics.dart';
import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../cart/cart_validation.dart' as cv;
import '../checkout/api_checkout.dart';
import '../checkout/checkout_models.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../menu/menu_repository.dart';
import 'auth_state.dart';
import 'cart_state.dart' hide PromoState, PromoStatus;
import 'pickup_state.dart';

export '../checkout/checkout_models.dart';

/// What the screen is told when the backend checkout was replaced because something changed.
class CheckoutNotice {
  const CheckoutNotice({this.message, this.previousTotalMinor, this.legal = false});
  /// The backend's words for what changed (null: nothing more specific is known).
  final String? message;
  /// The total the customer saw before, when it is another one now.
  final int? previousTotalMinor;
  /// The legal documents changed: they have to be accepted again.
  final bool legal;
}

/// Centralized checkout state — Android. Reads auth, cart, pickup and restaurant state; never owns them.
///
/// Two modes, one interface for the screens:
///  - the development checkout of Module 11 (no [backend]: unit tests, builds without the checkout backend), and
///  - the backend of Module 29 ([backend] given): the validation, every price, the promotion, taxes, fees, the
///    total, the expiry and the legal documents are the server's. Nothing is decided or calculated on the device;
///    a stale or expired checkout is started again and the customer sees the new total before paying.
class CheckoutState extends ChangeNotifier {
  CheckoutState({PromotionRepository? promotions, PaymentMethodRepository? payments, CheckoutRepository? checkout, ConnectivityService? connectivity, MockRestaurantRepository? restaurants, MenuRepository? menu, KeyValueStore? store, this.backend})
      : promotionsRepo = promotions ?? MockPromotionRepository(), paymentsRepo = payments ?? MockPaymentMethodRepository(), checkoutRepo = checkout ?? MockCheckoutRepository(), connectivity = connectivity ?? MockConnectivityService(), _rr = restaurants ?? MockRestaurantRepository(), _mr = menu ?? MockMenuRepository(), _store = store ?? SecureKeyValueStore() {
    _restore();
  }
  static const termsKey = 'fotg.checkout.terms';
  static const requestKey = 'fotg.checkout.request';
  static const _methodTypes = <String, PaymentMethodType>{'UPI': PaymentMethodType.upi, 'CARD': PaymentMethodType.card, 'NET_BANKING': PaymentMethodType.netbanking, 'WALLET': PaymentMethodType.wallet};
  static const _methodIds = <String, String>{'UPI': 'upi', 'CARD': 'card', 'NET_BANKING': 'netbanking', 'WALLET': 'wallet'};
  static const _methodLabels = <String, String>{'UPI': 'UPI', 'CARD': 'Credit / debit card', 'NET_BANKING': 'Net banking', 'WALLET': 'Wallet'};

  /// The checkout API (Module 29). Null: the development checkout.
  final CheckoutBackend? backend;
  bool get backendMode => backend != null;
  /// Backend mode: the checkout exactly as the backend priced it.
  ServerCheckout? server;
  /// Backend mode: the checkout the backend says is being paid for right now (null otherwise). It is how the payment
  /// screen finds a payment again when this phone kept nothing of it.
  String? paymentCheckoutId;
  CheckoutNotice? notice;
  List<CheckoutIssue> _blocked = const [];
  String? _accepted;
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
    try { final stored = await _store.read(termsKey); if (backendMode) { _accepted = stored == null || stored == '0' || stored == '1' ? null : stored; } else { termsAccepted = stored == '1'; } } catch (_) {}
    try { final raw = await _store.read(requestKey); if (raw != null && raw.isNotEmpty) request = CheckoutRequest.fromJson(jsonDecode(raw) as Map<String, dynamic>); } catch (_) {}
    notifyListeners();
  }

  List<CheckoutIssue> issues({required bool authenticated, required Cart? cart, required PickupSelection? selection}) {
    final out = <CheckoutIssue>[];
    if (!connectivity.isOnline) out.add(const CheckoutIssue(CheckoutIssueCode.offline));
    if (!authenticated) out.add(const CheckoutIssue(CheckoutIssueCode.auth));
    if (cart == null || cart.items.isEmpty) out.add(const CheckoutIssue(CheckoutIssueCode.cartEmpty));
    if (backendMode) {
      // The reasons are the backend's; what is left to the customer is the acceptance and a way to pay.
      out.addAll(_blocked);
      final s = server;
      if (s != null && _blocked.isEmpty) {
        if (!termsAccepted) out.add(const CheckoutIssue(CheckoutIssueCode.terms));
        if (s.paymentRequired && paymentMethodId == null) out.add(const CheckoutIssue(CheckoutIssueCode.paymentMethod));
      }
      if (promo != null && !promo!.applied) out.add(CheckoutIssue(CheckoutIssueCode.promoInvalid, blocking: false, detail: promo!.status.name));
      return out;
    }
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
    if (backendMode) { notice = null; _refusedPromo = null; await _open(auth: auth, cart: cart); return; }
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
    if (backendMode) return _applyOnBackend(code, cart: cart);
    promoBusy = true; notifyListeners();
    try {
      final c = cart.cart; final r = restaurant;
      final res = await promotionsRepo.evaluate(code, PromoContext(subtotalMinor: c?.subtotalMinor ?? 0, currency: c?.currency ?? marketAvailability.activeMarket.defaultCurrency, restaurantId: r?.id ?? c?.restaurantId ?? '', countryCode: r?.countryCode ?? 'ZZ', now: now));
      promo = res;
      if (res.applied) { cart.applyPromo(res.code); } else { cart.removePromo(); }
      if (c != null) summary = await checkoutRepo.buildSummary(c, res.applied ? res.discountMinor : 0, (now ?? DateTime.now()).toUtc());
      return res;
    } finally { promoBusy = false; notifyListeners(); }
  }
  Future<void> removePromo({required CartState cart, DateTime? now}) async { if (backendMode) return _removeOnBackend(cart: cart); promo = null; cart.removePromo(); final c = cart.cart; if (c != null) summary = await checkoutRepo.buildSummary(c, 0, (now ?? DateTime.now()).toUtc()); notifyListeners(); }
  /// Called once the order exists — a consumed request must never re-enter payment.
  void clearRequest() {
    request = null; status = CheckoutStatus.initializing; _store.write(requestKey, null).catchError((_) {});
    if (backendMode) { server = null; paymentCheckoutId = null; summary = null; _blocked = const []; notice = null; _refusedPromo = null; promo = null; _accepted = null; termsAccepted = false; _store.write(termsKey, null).catchError((_) {}); }
    notifyListeners();
  }
  void setPaymentMethod(String id) { paymentMethodId = id; notifyListeners(); }
  void setTermsAccepted(bool v) {
    termsAccepted = v;
    // Backend mode: the acceptance is for these documents in these versions — a new version is not covered by it.
    if (backendMode) { _accepted = v ? server?.legalSignature : null; _store.write(termsKey, _accepted).catchError((_) {}); } else { _store.write(termsKey, v ? '1' : '0').catchError((_) {}); }
    notifyListeners();
  }

  /// Final mock validation pass, then a CheckoutRequest for Module 12. The backend repeats every check.
  Future<CheckoutRequest?> continueToPayment({required AuthState auth, required CartState cart, required PickupState pickup, DateTime? now}) async {
    if (backendMode) return _confirmOnBackend(auth: auth, cart: cart, pickup: pickup);
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

  /* ───────────────────────── the backend's checkout (Module 29) ───────────────────────── */

  PromoResult? _refusedPromo;
  bool _busy = false;
  /// A confirmation is on its way: a second tap must not start another one.
  bool get busy => _busy;
  /// Time until the backend checkout expires, counted from the SERVER's clock (null: nothing to count).
  Duration? timeLeft([DateTime? now]) => server != null && (status == CheckoutStatus.ready || status == CheckoutStatus.paymentReady) ? server!.remaining(now) : null;

  CheckoutIssue _issueOf(CheckoutIssueView v) {
    const codes = <String, CheckoutIssueCode>{
      'CART_NOT_FOUND': CheckoutIssueCode.cartEmpty, 'CART_EMPTY': CheckoutIssueCode.cartEmpty, 'CART_EXPIRED': CheckoutIssueCode.cartEmpty, 'ITEM_UNAVAILABLE': CheckoutIssueCode.itemUnavailable, 'PRICE_CHANGED': CheckoutIssueCode.priceChanged,
      'RESTAURANT_UNAVAILABLE': CheckoutIssueCode.restaurantUnavailable, 'MARKET_UNAVAILABLE': CheckoutIssueCode.restaurantUnavailable, 'RESTAURANT_NOT_ACCEPTING': CheckoutIssueCode.restaurantNotAccepting,
      'PICKUP_REQUIRED': CheckoutIssueCode.pickupMissing, 'PICKUP_SLOT_FULL': CheckoutIssueCode.pickupFull, 'PICKUP_SLOT_UNAVAILABLE': CheckoutIssueCode.pickupFull, 'PICKUP_INVALID': CheckoutIssueCode.pickupInvalid,
      'PICKUP_CHANGED': CheckoutIssueCode.pickupInvalid, 'PICKUP_SELECTION_EXPIRED': CheckoutIssueCode.pickupInvalid, 'CURRENCY_MISMATCH': CheckoutIssueCode.currency, 'CHECKOUT_EXPIRED': CheckoutIssueCode.checkoutExpired,
      'PAYMENT_IN_PROGRESS': CheckoutIssueCode.paymentInProgress, 'CART_INVALID': CheckoutIssueCode.cartInvalid, 'CART_CHANGED': CheckoutIssueCode.cartInvalid,
    };
    return CheckoutIssue(codes[v.code] ?? CheckoutIssueCode.checkoutChanged, detail: v.code, message: v.message, recover: v.recover);
  }

  /// Takes a checkout the backend sent as the truth: what is shown, what can be confirmed, what can be paid.
  void _accept(ServerCheckout c, {CartState? cart}) {
    server = c; _blocked = const []; error = null; paymentCheckoutId = null;
    summary = CheckoutSummary(
      currency: c.currency, itemCount: c.itemCount, subtotalMinor: c.subtotalMinor, discountMinor: c.discountMinor, totalMinor: c.totalMinor, expiresAt: c.expiresAt, paymentEligible: c.paymentRequired, source: 'api',
      taxes: [for (final t in c.taxes) SummaryLine('tax:${t.code}', t.label, t.amountMinor, 'tax', included: t.included, rateBasisPoints: t.rateBasisPoints)],
      fees: [for (final f in c.fees) SummaryLine('fee:${f.code}', f.label, f.amountMinor, 'fee')],
    );
    promo = _refusedPromo ?? (c.promotionCode == null ? null : PromoResult(code: c.promotionCode!, status: PromoStatus.applied, discountMinor: c.discountMinor));
    methods = !c.paymentRequired ? const [] : [
      for (final m in c.savedMethods) if (c.methodTypes.contains(m.type)) PaymentMethodOption(id: 'saved:${m.id}', type: _methodTypes[m.type] ?? PaymentMethodType.card, label: m.label, description: 'Saved', provider: 'saved'),
      for (final t in c.methodTypes) PaymentMethodOption(id: _methodIds[t] ?? t.toLowerCase(), type: _methodTypes[t] ?? PaymentMethodType.card, label: _methodLabels[t] ?? t, provider: c.providerStatus),
    ];
    if (!c.paymentRequired) {
      paymentMethodId = null;
    } else if (paymentMethodId == null || !methods.any((m) => m.id == paymentMethodId)) {
      paymentMethodId = c.selectedSavedId != null ? 'saved:${c.selectedSavedId}' : c.selectedType != null ? _methodIds[c.selectedType] : methods.firstOrNull?.id;
    }
    termsAccepted = _accepted != null && _accepted == c.legalSignature;
    // A stored payment request stands only for the very checkout and price it was made from.
    final req = request;
    final still = req != null && req.checkoutId == c.id && req.pricingFingerprint == c.fingerprint && c.status == 'READY';
    if (req != null && !still) { request = null; _store.write(requestKey, null).catchError((_) {}); }
    status = still ? CheckoutStatus.paymentReady : CheckoutStatus.ready;
    // The backend may have brought the cart up to date (a changed price): show the same cart everywhere.
    final known = cart?.cart?.server?.version;
    if (cart != null && known != null && known != c.cartVersion) cart.reload();
  }

  /// A request the backend refused: nothing that was on the screen may be paid any more.
  void _refuse(CheckoutApiException e) {
    if (e.code == CheckoutErrorCode.unauthenticated) { status = CheckoutStatus.requiresAuth; return; }
    if (e.code == CheckoutErrorCode.offline) { error = e.message; status = connectivity.isOnline ? CheckoutStatus.error : CheckoutStatus.offline; return; }
    final issues = e.issues.isNotEmpty ? [for (final i in e.issues) _issueOf(i)] : [CheckoutIssue(e.code == CheckoutErrorCode.expired ? CheckoutIssueCode.checkoutExpired : e.code == CheckoutErrorCode.paymentInProgress ? CheckoutIssueCode.paymentInProgress : CheckoutIssueCode.server, message: e.message)];
    void drop() { server = null; summary = null; request = null; _store.write(requestKey, null).catchError((_) {}); }
    if (e.cart) { drop(); _blocked = issues; status = CheckoutStatus.invalidCart; return; }
    if (e.pickup) { drop(); _blocked = issues; status = CheckoutStatus.invalidPickup; return; }
    if (e.code == CheckoutErrorCode.expired) { request = null; _store.write(requestKey, null).catchError((_) {}); _blocked = issues; status = CheckoutStatus.expired; return; }
    // "A payment is in progress" names its checkout: the payment screen finds the payment through it.
    if (e.code == CheckoutErrorCode.paymentInProgress && e.details['checkout_id'] is String) paymentCheckoutId = e.details['checkout_id'] as String;
    error = e.message; _blocked = e.code == CheckoutErrorCode.paymentInProgress ? issues : const []; status = CheckoutStatus.error;
  }

  /// Asks the backend for the checkout of the caller's cart. Returns it, or null when there is none to show.
  Future<ServerCheckout?> _open({required AuthState auth, required CartState cart, String? promotionCode}) async {
    final my = ++_seq;
    error = null;
    if (status != CheckoutStatus.paymentReady) status = CheckoutStatus.validating;
    notifyListeners();
    if (!connectivity.isOnline) { status = CheckoutStatus.offline; notifyListeners(); return null; }
    if (!auth.isAuthenticated) { status = CheckoutStatus.requiresAuth; notifyListeners(); return null; }
    final c = cart.cart;
    if (c == null || c.items.isEmpty) { server = null; summary = null; status = CheckoutStatus.invalidCart; notifyListeners(); return null; }
    try {
      final checkout = await backend!.open(promotionCode: promotionCode);
      if (my != _seq) return null;
      _accept(checkout, cart: cart);
      Analytics.checkoutStarted(checkout.id, restaurant: c.server?.restaurantPublicId); // Module 38: once per checkout id
      notifyListeners();
      return checkout;
    } catch (e) {
      final failure = toCheckoutException(e);
      // A promotion code that does not apply is the customer's to hear about, by whoever asked with it.
      if (promotionCode != null && failure.code == CheckoutErrorCode.promotion) rethrow;
      if (my == _seq) { _refuse(failure); notifyListeners(); }
      return null;
    }
  }

  /// The checkout was replaced because something changed: the customer is shown the new one and told why.
  Future<ServerCheckout?> _restart({required AuthState auth, required CartState cart, String? reason, bool legal = false, String? promotionCode}) async {
    final before = server;
    if (legal) { _accepted = null; termsAccepted = false; _store.write(termsKey, null).catchError((_) {}); }
    request = null; _store.write(requestKey, null).catchError((_) {});
    final next = await _open(auth: auth, cart: cart, promotionCode: promotionCode);
    if (next != null) { notice = CheckoutNotice(message: legal ? null : reason, previousTotalMinor: before != null && before.totalMinor != next.totalMinor ? before.totalMinor : null, legal: legal); notifyListeners(); }
    return next;
  }

  /// Looks at the checkout again: the backend judges it on every read. Called when the app comes back and before
  /// paying. True only when the checkout on the screen is still exactly what the backend holds — no answer is not
  /// "still fine".
  Future<bool> revalidate({required AuthState auth, required CartState cart}) async {
    if (!backendMode) return true;
    final current = server; final stored = request;
    final id = current?.id ?? stored?.checkoutId;
    if (id == null) return false;
    if (!connectivity.isOnline) { status = CheckoutStatus.offline; notifyListeners(); return false; }
    try {
      final now = await backend!.show(id);
      if ((server?.id ?? request?.checkoutId) != id) return false;
      if (now.open) {
        _accept(now, cart: cart); notifyListeners();
        return current != null ? now.fingerprint == current.fingerprint && now.status == current.status : now.status == 'READY' && now.fingerprint == stored?.pricingFingerprint;
      }
      if (now.status == 'EXPIRED') {
        server = now; request = null; _store.write(requestKey, null).catchError((_) {}); _blocked = [for (final i in now.issues) _issueOf(i)];
        if (_blocked.isEmpty) _blocked = const [CheckoutIssue(CheckoutIssueCode.checkoutExpired, recover: 'refresh')];
        status = CheckoutStatus.expired; notifyListeners(); return false;
      }
      if (now.status == 'PAYMENT_PENDING' || now.status == 'CONVERTED') { server = now; paymentCheckoutId = now.id; _blocked = const [CheckoutIssue(CheckoutIssueCode.paymentInProgress)]; status = CheckoutStatus.error; notifyListeners(); return false; }
      final why = now.issues.firstOrNull;
      await _restart(auth: auth, cart: cart, reason: why?.message, legal: why?.code == 'LEGAL_ACCEPTANCE_REQUIRED');
      return false;
    } catch (e) {
      final failure = toCheckoutException(e);
      if (failure.code == CheckoutErrorCode.notFound) { await _restart(auth: auth, cart: cart); return false; }
      _refuse(failure); notifyListeners();
      return false;
    }
  }

  Future<PromoResult> _applyOnBackend(String code, {required CartState cart}) async {
    final typed = code.trim().toUpperCase();
    PromoResult refused(CheckoutApiException f) {
      final minimum = f.details['minimum_spend'];
      final result = PromoResult(code: typed, status: promoStatusOf(f), minimumSpendMinor: minimum is Map ? (minimum['amount'] as num?)?.toInt() : null);
      _refusedPromo = result; promo = result;
      return result;
    }
    final current = server;
    if (current == null) return refused(CheckoutApiException(CheckoutErrorCode.error, ''));
    promoBusy = true; notice = null; notifyListeners();
    try {
      final next = await backend!.applyPromotion(current.id, code.trim());
      _refusedPromo = null; _accept(next, cart: cart);
      return promo ?? PromoResult(code: typed, status: PromoStatus.unavailable);
    } catch (e) {
      final failure = toCheckoutException(e);
      if (failure.code == CheckoutErrorCode.promotion || failure.code == CheckoutErrorCode.rateLimited || failure.code == CheckoutErrorCode.validation) return refused(failure);
      _refuse(failure);
      return PromoResult(code: typed, status: PromoStatus.unavailable);
    } finally { promoBusy = false; notifyListeners(); }
  }

  Future<void> _removeOnBackend({required CartState cart}) async {
    _refusedPromo = null;
    final current = server;
    if (current == null || current.promotionCode == null) { promo = null; notifyListeners(); return; }
    promoBusy = true; notifyListeners();
    try { _accept(await backend!.removePromotion(current.id), cart: cart); } catch (e) { _refuse(toCheckoutException(e)); } finally { promoBusy = false; notifyListeners(); }
  }

  /// Confirms the price that is ON THE SCREEN: when the backend's price is another one by now, it refuses — and the
  /// customer is shown the new checkout first.
  Future<CheckoutRequest?> _confirmOnBackend({required AuthState auth, required CartState cart, required PickupState pickup}) async {
    final current = server; final user = auth.user; final c = cart.cart;
    if (_busy || current == null || user == null || c == null || !connectivity.isOnline) return null;
    if (!termsAccepted || (current.paymentRequired && paymentMethodId == null)) return null;
    final saved = paymentMethodId != null && paymentMethodId!.startsWith('saved:') ? current.savedMethods.where((m) => 'saved:${m.id}' == paymentMethodId).firstOrNull : null;
    final type = saved?.type ?? _methodIds.entries.where((e) => e.value == paymentMethodId).firstOrNull?.key;
    _busy = true; notice = null; error = null; notifyListeners();
    try {
      final ready = await backend!.confirm(current.id, fingerprint: current.fingerprint, documents: current.legal, methodType: current.paymentRequired ? type : null, savedMethodId: current.paymentRequired ? saved?.id : null, orderNote: cart.note);
      String version(String docType) => ready.legal.where((d) => d.type == docType).firstOrNull?.version ?? '';
      final sel = pickup.selection ?? PickupSelection(mode: ready.asap ? PickupMode.asap : PickupMode.scheduled, slotId: null, requestedAt: ready.pickupAt, estimatedReadyTime: ready.pickupAt, estimatedCustomerArrival: ready.travelerArrivalAt, restaurantTimezone: ready.timezone, cartId: c.id, restaurantId: c.restaurantId);
      final req = CheckoutRequest(
        idempotencyKey: ready.paymentKey ?? 'ck-${ready.id}-${ready.version}', customerId: user.id, cartId: c.id, restaurantId: c.restaurantId, pickupSelection: sel, promoCode: ready.promotionCode, currency: ready.currency,
        orderNote: ready.orderNote ?? '', termsAccepted: true, termsVersion: version('TERMS'), privacyVersion: version('PRIVACY'), acceptedAt: ready.acceptedAt ?? ready.serverTime,
        paymentMethodId: saved != null ? (_methodIds[saved.type] ?? 'card') : (paymentMethodId ?? ''),
        // The amount the payment step shows is the backend's total of the confirmed checkout — never a figure made here.
        displayedTotalMinor: ready.totalMinor, createdAt: ready.serverTime, checkoutId: ready.id, pricingFingerprint: ready.fingerprint,
      );
      request = req; _store.write(requestKey, jsonEncode(req.toJson())).catchError((_) {});
      _accept(ready, cart: cart);
      status = CheckoutStatus.paymentReady;
      return req;
    } catch (e) {
      final failure = toCheckoutException(e);
      if (failure.replaced) { await _restart(auth: auth, cart: cart, reason: failure.issues.firstOrNull?.message ?? failure.message, legal: failure.issues.firstOrNull?.code == 'LEGAL_ACCEPTANCE_REQUIRED'); return null; }
      if (failure.code == CheckoutErrorCode.legal) { await _restart(auth: auth, cart: cart, legal: true); return null; }
      if (failure.code == CheckoutErrorCode.promotion) { await _restart(auth: auth, cart: cart, reason: failure.message); return null; }
      if (failure.code == CheckoutErrorCode.paymentMethod || failure.code == CheckoutErrorCode.validation) { error = failure.message; return null; }
      _refuse(failure);
      return null;
    } finally { _busy = false; notifyListeners(); }
  }

  /// Signing out, or another customer on this phone: nothing of the earlier checkout may be shown or paid.
  void reset() {
    _seq++;
    request = null; server = null; paymentCheckoutId = null; summary = null; promo = null; _refusedPromo = null; _blocked = const []; notice = null; _accepted = null; termsAccepted = false; paymentMethodId = null; error = null; status = CheckoutStatus.initializing;
    _store.write(requestKey, null).catchError((_) {}); _store.write(termsKey, null).catchError((_) {});
    notifyListeners();
  }
}
