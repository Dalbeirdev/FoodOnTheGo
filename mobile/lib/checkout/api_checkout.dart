/// Checkout on the backend (Module 29) — Android.
///
/// The backend is the authority on WHAT is paid: it validates the cart, the restaurant and the pickup time again,
/// prices every line from the current menu, decides whether a promotion applies, adds only the taxes and fees an
/// administrator configured, and names the total. This file only asks and maps. It never sends an amount, a
/// currency, a customer, a cart or a restaurant — a promotion code, the legal documents that were shown (type +
/// version), how the customer intends to pay (a method type or one of their saved references) and an order note are
/// all a request consists of. No card number, CVV, UPI PIN or bank credential ever passes through here.
///
/// A checkout belongs to the signed-in customer: every call goes out as the cart's owner (the session).
library;

import '../cart/api_cart.dart';
import '../core/app_config.dart';
import '../data/api_client.dart';
import '../pickup/api_pickup.dart' show pickupFromApi;
import 'checkout_models.dart';

/// Tests switch this; the app reads the build's --dart-define=CHECKOUT_MODE.
String? checkoutModeOverride;

/// A checkout is made from the backend's cart and pickup selection, so 'api' needs both on the backend too.
bool get checkoutFromApi => pickupFromApi && (checkoutModeOverride ?? AppConfig.checkoutMode) == 'api';

int _amount(Object? money) => money is Map ? (money['amount'] as num?)?.toInt() ?? 0 : 0;
DateTime? _instant(Object? iso) => iso is String ? DateTime.tryParse(iso)?.toUtc() : null;

class CheckoutIssueView {
  const CheckoutIssueView({required this.code, required this.message, required this.recover, this.details = const {}});
  factory CheckoutIssueView.fromJson(Map<String, dynamic> j) => CheckoutIssueView(code: '${j['code']}', message: '${j['message'] ?? ''}', recover: '${j['recover'] ?? 'refresh'}', details: j['details'] is Map ? Map<String, dynamic>.from(j['details'] as Map) : const {});
  final String code, message;
  /// Where the customer goes to put it right: cart · pickup · checkout · refresh · payment.
  final String recover;
  final Map<String, dynamic> details;
}

class CheckoutLine {
  const CheckoutLine({required this.id, required this.name, required this.quantity, required this.options, required this.unitPriceMinor, required this.lineSubtotalMinor, this.specialInstructions});
  final String id, name;
  final int quantity, unitPriceMinor, lineSubtotalMinor;
  final List<String> options;
  final String? specialInstructions;
}

class CheckoutTaxLine {
  const CheckoutTaxLine({required this.code, required this.label, required this.rateBasisPoints, required this.amountMinor, required this.included});
  final String code, label;
  final int rateBasisPoints, amountMinor;
  /// The prices already contain it: shown, not added to the total.
  final bool included;
}

class CheckoutFeeLine {
  const CheckoutFeeLine({required this.code, required this.label, required this.amountMinor});
  final String code, label;
  final int amountMinor;
}

class LegalDocument {
  const LegalDocument({required this.type, required this.version, required this.status, required this.slug});
  final String type, version, status, slug;
}

class SavedMethod {
  const SavedMethod({required this.id, required this.type, required this.label});
  final String id, type, label;
}

/// A checkout exactly as the backend priced it. Every amount is the server's, in integer minor units.
class ServerCheckout {
  const ServerCheckout({
    required this.id, required this.status, required this.version, required this.expiresAt, required this.serverTime, required this.receivedAt, required this.cartVersion,
    required this.restaurantName, required this.timezone, required this.asap, required this.pickupAt, required this.localPickupAt, this.estimatedPrepMinutes, this.travelerArrivalAt, required this.held, this.holdExpiresAt,
    required this.items, required this.itemCount, required this.currency, required this.subtotalMinor, required this.discountMinor, required this.taxes, required this.taxMinor, required this.taxIncludedMinor,
    required this.fees, required this.feeMinor, required this.totalMinor, required this.paymentRequired, required this.fingerprint, required this.taxConfiguration, required this.feeConfiguration,
    this.promotionCode, this.promotionName, required this.customerName, required this.customerPhone, required this.phoneVerified, this.customerEmail, this.orderNote,
    required this.legal, required this.legalFinalApproval, required this.acceptedAt, required this.providerStatus, required this.methodTypes, required this.savedMethods, this.selectedType, this.selectedSavedId,
    this.paymentStatus, this.paymentKey, this.paymentAttemptId, required this.issues, required this.changes,
  });

  factory ServerCheckout.fromJson(Map<String, dynamic> j, {DateTime? receivedAt}) {
    final pickup = Map<String, dynamic>.from(j['pickup'] as Map);
    final pricing = Map<String, dynamic>.from(j['pricing'] as Map);
    final customer = Map<String, dynamic>.from(j['customer'] as Map);
    final legal = Map<String, dynamic>.from(j['legal'] as Map);
    final methods = Map<String, dynamic>.from(j['payment_methods'] as Map);
    final payment = j['payment'] is Map ? Map<String, dynamic>.from(j['payment'] as Map) : null;
    final promotion = j['promotion'] is Map ? Map<String, dynamic>.from(j['promotion'] as Map) : null;
    final selected = methods['selected'] is Map ? Map<String, dynamic>.from(methods['selected'] as Map) : null;
    final attempt = payment?['attempt'] is Map ? Map<String, dynamic>.from(payment!['attempt'] as Map) : null;
    final configuration = pricing['configuration'] is Map ? Map<String, dynamic>.from(pricing['configuration'] as Map) : const <String, dynamic>{};
    List<Map<String, dynamic>> list(Object? v) => v is List ? [for (final x in v) if (x is Map) Map<String, dynamic>.from(x)] : const [];
    List<String> options(Map<String, dynamic> line) => [
      for (final g in [...list(line['variants']), ...list(line['modifiers'])])
        for (final o in list(g['options'])) '${g['group_name']}: ${o['name']}',
    ];
    final accepted = list(legal['accepted']);
    return ServerCheckout(
      id: '${j['id']}', status: '${j['status']}', version: (j['version'] as num).toInt(), expiresAt: _instant(j['expires_at'])!, serverTime: _instant(j['server_time'])!, receivedAt: receivedAt ?? DateTime.now(),
      cartVersion: (j['cart_version'] as num?)?.toInt() ?? 0,
      restaurantName: '${(j['restaurant'] as Map?)?['name'] ?? ''}', timezone: '${pickup['timezone']}', asap: pickup['mode'] == 'ASAP', pickupAt: _instant(pickup['pickup_at'])!, localPickupAt: '${pickup['local_pickup_at']}',
      estimatedPrepMinutes: (pickup['estimated_prep_minutes'] as num?)?.toInt(), travelerArrivalAt: _instant(pickup['traveler_arrival_at']),
      held: (pickup['hold'] as Map?)?['held'] == true, holdExpiresAt: _instant((pickup['hold'] as Map?)?['expires_at']),
      items: [for (final l in list(j['items'])) CheckoutLine(id: '${l['id']}', name: '${l['name'] ?? (l['item'] as Map?)?['name'] ?? ''}', quantity: (l['quantity'] as num).toInt(), options: options(l), unitPriceMinor: (l['unit_price_minor'] as num).toInt(), lineSubtotalMinor: (l['line_subtotal_minor'] as num).toInt(), specialInstructions: l['special_instructions'] as String?)],
      itemCount: (j['item_count'] as num?)?.toInt() ?? 0,
      currency: '${pricing['currency']}', subtotalMinor: _amount(pricing['items_subtotal']), discountMinor: _amount(pricing['discount_total']),
      taxes: [for (final t in list(pricing['taxes'])) CheckoutTaxLine(code: '${t['code']}', label: '${t['label']}', rateBasisPoints: (t['rate_basis_points'] as num).toInt(), amountMinor: _amount(t['amount']), included: t['included_in_prices'] == true)],
      taxMinor: _amount(pricing['tax_total']), taxIncludedMinor: _amount(pricing['tax_included_total']),
      fees: [for (final f in list(pricing['fees'])) CheckoutFeeLine(code: '${f['code']}', label: '${f['label']}', amountMinor: _amount(f['amount']))],
      feeMinor: _amount(pricing['fee_total']), totalMinor: _amount(pricing['total']), paymentRequired: pricing['payment_required'] == true, fingerprint: '${pricing['fingerprint']}',
      taxConfiguration: '${configuration['tax'] ?? 'NOT_CONFIGURED'}', feeConfiguration: '${configuration['fees'] ?? 'NOT_CONFIGURED'}',
      promotionCode: promotion?['code'] as String?, promotionName: promotion?['name'] as String?,
      customerName: (customer['name'] as String?) ?? '', customerPhone: '${customer['phone'] ?? ''}', phoneVerified: customer['phone_verified'] == true, customerEmail: customer['email'] as String?,
      orderNote: j['order_note'] as String?,
      legal: [for (final d in list(legal['required'])) LegalDocument(type: '${d['type']}', version: '${d['version']}', status: '${d['status']}', slug: '${d['slug']}')],
      legalFinalApproval: legal['final_approval'] == true, acceptedAt: accepted.isEmpty ? null : _instant(accepted.first['accepted_at']),
      providerStatus: '${methods['provider_status']}', methodTypes: [for (final m in list(methods['available'])) '${m['type']}'],
      savedMethods: [for (final m in list(methods['saved'])) SavedMethod(id: '${m['id']}', type: '${m['type']}', label: '${m['display_label']}')],
      selectedType: selected?['type'] as String?, selectedSavedId: selected?['saved_method_id'] as String?,
      paymentStatus: payment?['status'] as String?, paymentKey: payment?['idempotency_key'] as String?, paymentAttemptId: attempt?['id'] as String?,
      issues: [for (final i in list(j['issues'])) CheckoutIssueView.fromJson(i)], changes: list(j['changes']),
    );
  }

  final String id;
  /// DRAFT · READY · STALE · EXPIRED · PAYMENT_PENDING · CONVERTED · CANCELLED — the backend's own word.
  final String status;
  final int version, cartVersion;
  final DateTime expiresAt;
  /// The server clock when this answer was made, and this device's clock when it arrived.
  final DateTime serverTime, receivedAt;
  final String restaurantName, timezone, localPickupAt;
  final bool asap;
  final DateTime pickupAt;
  final int? estimatedPrepMinutes;
  final DateTime? travelerArrivalAt;
  /// A confirmed checkout holds its place in the pickup slot until it expires.
  final bool held;
  final DateTime? holdExpiresAt;
  final List<CheckoutLine> items;
  final int itemCount;
  final String currency;
  final int subtotalMinor, discountMinor, taxMinor, taxIncludedMinor, feeMinor, totalMinor;
  final List<CheckoutTaxLine> taxes;
  final List<CheckoutFeeLine> fees;
  final bool paymentRequired;
  final String fingerprint;
  /// NOT_CONFIGURED (no rule exists — nothing is invented) · TEST (a development configuration) · APPROVED.
  final String taxConfiguration, feeConfiguration;
  final String? promotionCode, promotionName;
  final String customerName, customerPhone;
  final bool phoneVerified;
  final String? customerEmail, orderNote;
  final List<LegalDocument> legal;
  final bool legalFinalApproval;
  final DateTime? acceptedAt;
  /// READY · UNAVAILABLE — whether payments can be taken here right now (Module 30).
  final String providerStatus;
  final List<String> methodTypes;
  final List<SavedMethod> savedMethods;
  final String? selectedType, selectedSavedId;
  /// READY_FOR_PAYMENT · PAYMENT_NOT_REQUIRED — present only once the checkout is confirmed. Nothing has been charged.
  final String? paymentStatus, paymentKey;
  /// The checkout's latest payment attempt (Module 30) — how a phone that was closed or restarted finds its payment again.
  final String? paymentAttemptId;
  final List<CheckoutIssueView> issues;
  final List<Map<String, dynamic>> changes;

  bool get open => status == 'DRAFT' || status == 'READY';
  bool get testConfiguration => taxConfiguration == 'TEST' || feeConfiguration == 'TEST';
  /// The documents and versions an acceptance is given for: a new version is not covered by an old tick.
  String get legalSignature => legal.map((d) => '${d.type}@${d.version}').join('|');
  /// Time until the checkout expires — measured on the server's clock, with this device only counting the time since.
  Duration remaining([DateTime? now]) => expiresAt.difference(serverTime) - (now ?? DateTime.now()).difference(receivedAt);
}

enum CheckoutErrorCode { noCart, cartEmpty, cartInvalid, cartChanged, cartExpired, itemUnavailable, restaurantUnavailable, notAccepting, pickupRequired, pickupInvalid, pickupFull, stale, expired, notFound, paymentInProgress, promotion, legal, paymentMethod, unauthenticated, rateLimited, validation, offline, error }

class CheckoutApiException implements Exception {
  CheckoutApiException(this.code, this.message, {this.serverCode = '', this.details = const {}})
      : issues = [for (final i in (details['issues'] is List ? details['issues'] as List : const [])) if (i is Map) CheckoutIssueView.fromJson(Map<String, dynamic>.from(i))];
  final CheckoutErrorCode code;
  final String message;
  /// The backend's own error code (e.g. promotion_minimum_not_met).
  final String serverCode;
  final Map<String, dynamic> details;
  final List<CheckoutIssueView> issues;
  bool get cart => const {CheckoutErrorCode.noCart, CheckoutErrorCode.cartEmpty, CheckoutErrorCode.cartInvalid, CheckoutErrorCode.cartChanged, CheckoutErrorCode.cartExpired, CheckoutErrorCode.itemUnavailable, CheckoutErrorCode.restaurantUnavailable, CheckoutErrorCode.notAccepting}.contains(code);
  bool get pickup => const {CheckoutErrorCode.pickupRequired, CheckoutErrorCode.pickupInvalid, CheckoutErrorCode.pickupFull}.contains(code);
  bool get replaced => code == CheckoutErrorCode.stale || code == CheckoutErrorCode.notFound;
  @override
  String toString() => message;
}

const _codes = <String, CheckoutErrorCode>{
  'cart_not_found': CheckoutErrorCode.noCart, 'cart_empty': CheckoutErrorCode.cartEmpty, 'cart_invalid': CheckoutErrorCode.cartInvalid, 'cart_changed': CheckoutErrorCode.cartChanged, 'cart_version_conflict': CheckoutErrorCode.cartChanged,
  'cart_expired': CheckoutErrorCode.cartExpired, 'item_unavailable': CheckoutErrorCode.itemUnavailable, 'price_changed': CheckoutErrorCode.cartInvalid, 'currency_mismatch': CheckoutErrorCode.cartInvalid,
  'restaurant_unavailable': CheckoutErrorCode.restaurantUnavailable, 'market_unavailable': CheckoutErrorCode.restaurantUnavailable, 'restaurant_not_accepting': CheckoutErrorCode.notAccepting,
  'pickup_required': CheckoutErrorCode.pickupRequired, 'pickup_invalid': CheckoutErrorCode.pickupInvalid, 'pickup_changed': CheckoutErrorCode.pickupInvalid, 'pickup_selection_expired': CheckoutErrorCode.pickupInvalid,
  'pickup_slot_full': CheckoutErrorCode.pickupFull, 'pickup_slot_unavailable': CheckoutErrorCode.pickupFull,
  'checkout_stale': CheckoutErrorCode.stale, 'checkout_not_ready': CheckoutErrorCode.stale, 'checkout_expired': CheckoutErrorCode.expired, 'checkout_not_found': CheckoutErrorCode.notFound,
  'payment_in_progress': CheckoutErrorCode.paymentInProgress, 'cart_locked_for_payment': CheckoutErrorCode.paymentInProgress,
  'legal_acceptance_required': CheckoutErrorCode.legal, 'payment_method_required': CheckoutErrorCode.paymentMethod, 'payment_method_unavailable': CheckoutErrorCode.paymentMethod,
  'rate_limited': CheckoutErrorCode.rateLimited, 'validation_failed': CheckoutErrorCode.validation,
};

CheckoutApiException toCheckoutException(Object e) {
  if (e is CheckoutApiException) return e;
  if (e is ApiException) {
    // Without a connection nothing about a price can be confirmed — an old total must not be taken for a current one.
    if (e.kind == ApiErrorKind.network) return CheckoutApiException(CheckoutErrorCode.offline, 'Cannot reach FoodOnTheGo right now. Checkout needs a connection.');
    if (e.kind == ApiErrorKind.unauthenticated) return CheckoutApiException(CheckoutErrorCode.unauthenticated, e.message, serverCode: e.code);
    final code = e.code.startsWith('promotion_') ? CheckoutErrorCode.promotion : _codes[e.code] ?? (e.kind == ApiErrorKind.rateLimited ? CheckoutErrorCode.rateLimited : CheckoutErrorCode.error);
    return CheckoutApiException(code, e.message, serverCode: e.code, details: e.details);
  }
  return CheckoutApiException(CheckoutErrorCode.error, 'Checkout could not be loaded. Please try again.');
}

/// What a refused promotion code means for the screen. The backend's answer is deliberately coarse.
PromoStatus promoStatusOf(CheckoutApiException e) {
  if (e.code == CheckoutErrorCode.rateLimited) return PromoStatus.rateLimited;
  switch (e.serverCode) {
    case 'promotion_expired': return PromoStatus.expired;
    case 'promotion_minimum_not_met': return PromoStatus.minSpend;
    case 'promotion_not_applicable': return PromoStatus.notEligible;
    case 'promotion_limit_reached': return PromoStatus.limitReached;
    case 'promotion_customer_limit_reached': return PromoStatus.alreadyUsed;
    case 'promotion_not_found': return PromoStatus.invalid;
    default: return PromoStatus.unavailable;
  }
}

/// The checkout API. Writes that create or confirm carry an idempotency key (and are repeated once with the SAME
/// key when only the answer was lost) — a retry never makes a second checkout or a second confirmation.
class CheckoutBackend {
  CheckoutBackend(this.cart);
  final CartBackend cart;

  Future<ServerCheckout> _checkout(Future<Object?> call) async {
    try {
      final answer = await call;
      return ServerCheckout.fromJson(Map<String, dynamic>.from((answer as Map)['checkout'] as Map));
    } catch (e) {
      throw toCheckoutException(e);
    }
  }

  /// The checkout for the caller's cart: a new one, or the live one when nothing changed.
  Future<ServerCheckout> open({String? promotionCode}) => _checkout(cart.request('POST', '/checkouts', operation: 'cko', body: {if (promotionCode != null && promotionCode.trim().isNotEmpty) 'promotion_code': promotionCode.trim()}));

  /// The checkout as it stands now: the backend judges it again on every read (stale, expired).
  Future<ServerCheckout> show(String id) => _checkout(cart.request('GET', '/checkouts/$id'));

  Future<ServerCheckout> applyPromotion(String id, String code) => _checkout(cart.request('POST', '/checkouts/$id/promotion', body: {'code': code}));

  Future<ServerCheckout> removePromotion(String id) => _checkout(cart.request('DELETE', '/checkouts/$id/promotion'));

  /// Confirms the price the customer was shown, the documents they accepted and how they intend to pay. Charges nothing.
  Future<ServerCheckout> confirm(String id, {required String fingerprint, required List<LegalDocument> documents, String? methodType, String? savedMethodId, String orderNote = ''}) => _checkout(cart.request('POST', '/checkouts/$id/confirm', operation: 'ckc', body: {
    'pricing_fingerprint': fingerprint,
    'accepted_documents': [for (final d in documents) {'type': d.type, 'version': d.version}],
    if (methodType != null) 'payment_method': {'type': methodType, 'saved_method_id': ?savedMethodId},
    if (orderNote.trim().isNotEmpty) 'order_note': orderNote,
  }));

  Future<void> cancel(String id) async {
    try { await cart.request('POST', '/checkouts/$id/cancel'); } catch (e) { throw toCheckoutException(e); }
  }
}
