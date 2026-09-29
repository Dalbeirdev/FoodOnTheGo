/// Checkout domain (Module 11) — Android. Review of centralized state; totals are display-only
/// (the backend recalculates before payment). No raw card / UPI / bank credentials anywhere.
library;

import '../cart/cart_models.dart';
import '../pickup/pickup_models.dart';

enum CheckoutStatus { initializing, ready, validating, requiresAuth, invalidCart, invalidPickup, paymentReady, error, offline }

/* ---------------- promotions ---------------- */
enum PromotionType { fixedAmount, percentage, restaurantPromotion, platformPromotion }
enum PromoStatus { idle, applying, applied, invalid, expired, notEligible, minSpend, restaurantNotEligible, marketNotEligible, currencyNotEligible }

class Promotion {
  const Promotion({required this.code, required this.type, required this.value, this.eligibility = 'all', this.minimumSpendMinor = 0, this.maximumDiscountMinor, this.currency, this.restaurantScope, this.marketScope, required this.validFrom, this.validTo});
  final String code, eligibility;
  final PromotionType type;
  final int value, minimumSpendMinor;
  final int? maximumDiscountMinor;
  final String? currency;
  final List<String>? restaurantScope, marketScope;
  final DateTime validFrom;
  final DateTime? validTo;
}

class PromoContext {
  const PromoContext({required this.subtotalMinor, required this.currency, required this.restaurantId, required this.countryCode, this.now, this.customerIsNew = false});
  final int subtotalMinor;
  final String currency, restaurantId, countryCode;
  final DateTime? now;
  final bool customerIsNew;
}

class PromoResult {
  const PromoResult({required this.code, required this.status, this.discountMinor = 0, this.promotion, this.minimumSpendMinor});
  final String code;
  final PromoStatus status;
  final int discountMinor;
  final Promotion? promotion;
  final int? minimumSpendMinor;
  bool get applied => status == PromoStatus.applied;
}

/* ---------------- payment methods ---------------- */
enum PaymentMethodType { upi, card, wallet, netbanking, cashAtPickup }

class PaymentMethodOption {
  const PaymentMethodOption({required this.id, required this.type, required this.label, this.description, this.provider = 'razorpay', this.enabled = true, this.reasonDisabled});
  final String id, label, provider;
  final String? description, reasonDisabled;
  final PaymentMethodType type;
  final bool enabled;
}

/* ---------------- summary + request ---------------- */
class SummaryLine {
  const SummaryLine(this.id, this.label, this.amountMinor, this.kind);
  final String id, label, kind;
  final int amountMinor;
}

class CheckoutSummary {
  const CheckoutSummary({required this.currency, required this.itemCount, required this.subtotalMinor, required this.discountMinor, this.taxes = const [], this.fees = const [], required this.totalMinor, this.expiresAt, required this.paymentEligible});
  final String currency;
  final int itemCount, subtotalMinor, discountMinor, totalMinor;
  final List<SummaryLine> taxes, fees;
  final DateTime? expiresAt;
  final bool paymentEligible;
}

/// Payment-intent preparation — no client-controlled final amount is authoritative.
class CheckoutRequest {
  const CheckoutRequest({required this.idempotencyKey, required this.customerId, required this.cartId, required this.restaurantId, required this.pickupSelection, this.promoCode, required this.currency, required this.orderNote, required this.termsAccepted, required this.termsVersion, required this.privacyVersion, required this.acceptedAt, required this.paymentMethodId, required this.displayedTotalMinor, required this.createdAt});
  final String idempotencyKey, customerId, cartId, restaurantId, currency, orderNote, termsVersion, privacyVersion, paymentMethodId;
  final String? promoCode;
  final PickupSelection pickupSelection;
  final bool termsAccepted;
  final DateTime acceptedAt, createdAt;
  final int displayedTotalMinor;
  Map<String, dynamic> toJson() => {'idempotencyKey': idempotencyKey, 'customerId': customerId, 'cartId': cartId, 'restaurantId': restaurantId, 'pickupSelection': pickupSelection.toJson(), 'promoCode': promoCode, 'currency': currency, 'orderNote': orderNote, 'termsAccepted': termsAccepted, 'termsVersion': termsVersion, 'privacyVersion': privacyVersion, 'acceptedAt': acceptedAt.toIso8601String(), 'paymentMethodId': paymentMethodId, 'displayedTotalMinor': displayedTotalMinor, 'createdAt': createdAt.toIso8601String()};
}

enum CheckoutIssueCode { offline, auth, cartEmpty, cartInvalid, priceChanged, pickupMissing, pickupInvalid, restaurantUnavailable, restaurantNotAccepting, promoInvalid, terms, paymentMethod, currency }

class CheckoutIssue {
  const CheckoutIssue(this.code, {this.blocking = true, this.detail});
  final CheckoutIssueCode code;
  final bool blocking;
  final String? detail;
}

class CheckoutException implements Exception {
  const CheckoutException(this.message);
  final String message;
  @override
  String toString() => message;
}

/* ---------------- abstractions ---------------- */
abstract class PromotionRepository { Future<PromoResult> evaluate(String code, PromoContext ctx); }
abstract class PaymentMethodRepository { Future<List<PaymentMethodOption>> getAvailableMethods(String countryCode, String currency); }
abstract class CheckoutRepository { Future<CheckoutSummary> buildSummary(Cart cart, int discountMinor, DateTime now); }
abstract class ConnectivityService { bool get isOnline; }

/* ---------------- development implementations ---------------- */
final promotions = <Promotion>[
  Promotion(code: 'WELCOME10', type: PromotionType.platformPromotion, value: 10, validFrom: DateTime.utc(2026, 1, 1)),
  Promotion(code: 'TRAVEL5', type: PromotionType.percentage, value: 5, minimumSpendMinor: 2000, validFrom: DateTime.utc(2026, 1, 1)),
  Promotion(code: 'EXPIRED', type: PromotionType.percentage, value: 20, validFrom: DateTime.utc(2025, 1, 1), validTo: DateTime.utc(2025, 12, 31, 23, 59, 59)),
  Promotion(code: 'BURGER20', type: PromotionType.restaurantPromotion, value: 20, maximumDiscountMinor: 10000, currency: 'INR', restaurantScope: const ['burger-hub'], marketScope: const ['IN'], validFrom: DateTime.utc(2026, 1, 1)),
  Promotion(code: 'INDIA50', type: PromotionType.fixedAmount, value: 5000, minimumSpendMinor: 30000, currency: 'INR', marketScope: const ['IN'], validFrom: DateTime.utc(2026, 1, 1)),
  Promotion(code: 'NEWBIE', type: PromotionType.percentage, value: 15, eligibility: 'new_customers', validFrom: DateTime.utc(2026, 1, 1)),
];

PromoResult evaluatePromotion(String code, PromoContext ctx) {
  final key = code.trim().toUpperCase();
  final p = promotions.where((x) => x.code == key).firstOrNull;
  PromoResult none(PromoStatus s, {int? minSpend}) => PromoResult(code: key, status: s, promotion: p, minimumSpendMinor: minSpend);
  if (key.isEmpty || p == null) return none(PromoStatus.invalid);
  final now = (ctx.now ?? DateTime.now()).toUtc();
  if (now.isBefore(p.validFrom)) return none(PromoStatus.notEligible);
  if (p.validTo != null && now.isAfter(p.validTo!)) return none(PromoStatus.expired);
  if (p.currency != null && p.currency != ctx.currency) return none(PromoStatus.currencyNotEligible);
  if (p.marketScope != null && !p.marketScope!.contains(ctx.countryCode)) return none(PromoStatus.marketNotEligible);
  if (p.restaurantScope != null && !p.restaurantScope!.contains(ctx.restaurantId)) return none(PromoStatus.restaurantNotEligible);
  if (p.eligibility == 'new_customers' && !ctx.customerIsNew) return none(PromoStatus.notEligible);
  if (ctx.subtotalMinor < p.minimumSpendMinor) return none(PromoStatus.minSpend, minSpend: p.minimumSpendMinor);
  var discount = p.type == PromotionType.fixedAmount ? p.value : (ctx.subtotalMinor * p.value / 100).round();
  if (p.maximumDiscountMinor != null && discount > p.maximumDiscountMinor!) discount = p.maximumDiscountMinor!;
  if (discount > ctx.subtotalMinor) discount = ctx.subtotalMinor;
  if (discount < 0) discount = 0;
  return PromoResult(code: key, status: PromoStatus.applied, discountMinor: discount, promotion: p);
}

class MockPromotionRepository implements PromotionRepository {
  MockPromotionRepository({this.latency = const Duration(milliseconds: 150)});
  final Duration latency;
  bool fail = false;
  @override
  Future<PromoResult> evaluate(String code, PromoContext ctx) async {
    if (latency != Duration.zero) await Future<void>.delayed(latency);
    if (fail) throw const CheckoutException('The promotion could not be checked. Please try again.');
    return evaluatePromotion(code, ctx);
  }
}

const _inMethods = [
  PaymentMethodOption(id: 'upi', type: PaymentMethodType.upi, label: 'UPI', description: 'Google Pay, PhonePe, BHIM and other UPI apps'),
  PaymentMethodOption(id: 'card', type: PaymentMethodType.card, label: 'Credit / debit card', description: 'Entered securely on the payment provider’s page'),
  PaymentMethodOption(id: 'wallet', type: PaymentMethodType.wallet, label: 'Wallet'),
  PaymentMethodOption(id: 'netbanking', type: PaymentMethodType.netbanking, label: 'Net banking'),
];
const _defaultMethods = [
  PaymentMethodOption(id: 'card', type: PaymentMethodType.card, label: 'Credit / debit card', description: 'Entered securely on the payment provider’s page'),
  PaymentMethodOption(id: 'wallet', type: PaymentMethodType.wallet, label: 'Wallet', enabled: false, reasonDisabled: 'Not available in this market yet'),
];
class MockPaymentMethodRepository implements PaymentMethodRepository {
  MockPaymentMethodRepository({this.latency = const Duration(milliseconds: 80)});
  final Duration latency;
  bool fail = false;
  @override
  Future<List<PaymentMethodOption>> getAvailableMethods(String countryCode, String currency) async {
    if (latency != Duration.zero) await Future<void>.delayed(latency);
    if (fail) throw const CheckoutException('Payment options could not be loaded. Please try again.');
    return countryCode == 'IN' ? _inMethods : _defaultMethods;
  }
}

class MockCheckoutRepository implements CheckoutRepository {
  MockCheckoutRepository({this.latency = const Duration(milliseconds: 80)});
  final Duration latency;
  bool fail = false;
  @override
  Future<CheckoutSummary> buildSummary(Cart cart, int discountMinor, DateTime now) async {
    if (latency != Duration.zero) await Future<void>.delayed(latency);
    if (fail) throw const CheckoutException('Checkout could not be prepared. Please try again.');
    final subtotal = cart.subtotalMinor;
    final discount = discountMinor.clamp(0, subtotal);
    // No taxes or fees configured for any development market — none are invented.
    return CheckoutSummary(currency: cart.currency, itemCount: cart.itemCount, subtotalMinor: subtotal, discountMinor: discount, totalMinor: subtotal - discount, expiresAt: now.add(const Duration(minutes: 15)), paymentEligible: subtotal - discount > 0);
  }
}

/// Development connectivity: always online unless toggled (real connectivity plugin is carry-forward CF-141).
class MockConnectivityService implements ConnectivityService {
  bool online = true;
  @override
  bool get isOnline => online;
}

const termsVersion = 'draft-2026-09';
const privacyVersion = 'draft-2026-09';
