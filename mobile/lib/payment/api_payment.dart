/// Payments on the backend (Module 30) — Android.
///
/// The backend is the authority on whether a payment happened. This file only asks and maps:
///   start    — a payment attempt for a CONFIRMED checkout, named by the price the customer saw (its fingerprint).
///              Never an amount, a currency or a status: the amount is the backend's.
///   show     — what the backend says the payment is now. The only source of "verified".
///   complete — what the provider's own screen told the app is REPORTED; the backend verifies it with the provider.
///   simulate — LOCAL DEVELOPMENT ONLY: plays the customer in the development provider's place.
///
/// No card number, CVV, UPI PIN, bank credential or provider secret passes through here; of the provider only a
/// public key id and an order reference are ever received.
library;

import '../cart/api_cart.dart';
import '../checkout/api_checkout.dart' show checkoutFromApi;
import '../core/app_config.dart';
import '../data/api_client.dart';

/// Tests switch this; the app reads the build's --dart-define=PAYMENT_MODE.
String? paymentModeOverride;

/// A payment is taken for a backend checkout, so 'api' needs the checkout on the backend too.
bool get paymentsFromApi => checkoutFromApi && (paymentModeOverride ?? AppConfig.paymentMode) == 'api';

/// What the provider's own screen needs to open. Only a PUBLIC key id — never a secret.
class ProviderSession {
  const ProviderSession({required this.provider, required this.mode, this.keyId, required this.orderReference, required this.amountMinor, required this.currency, required this.name, required this.description, required this.themeColor, this.prefillName, required this.prefillContact, this.prefillEmail, this.preferredMethod, required this.timeoutSeconds});
  factory ProviderSession.fromJson(Map<String, dynamic> j) {
    final amount = j['amount'] is Map ? Map<String, dynamic>.from(j['amount'] as Map) : const <String, dynamic>{};
    final prefill = j['prefill'] is Map ? Map<String, dynamic>.from(j['prefill'] as Map) : const <String, dynamic>{};
    return ProviderSession(
      provider: '${j['provider']}', mode: '${j['mode']}', keyId: j['key_id'] as String?, orderReference: '${j['order_reference']}',
      amountMinor: (amount['amount'] as num?)?.toInt() ?? 0, currency: '${amount['currency'] ?? ''}', name: '${j['name'] ?? ''}', description: '${j['description'] ?? ''}', themeColor: '${j['theme_color'] ?? ''}',
      prefillName: prefill['name'] as String?, prefillContact: '${prefill['contact'] ?? ''}', prefillEmail: prefill['email'] as String?,
      preferredMethod: j['preferred_method'] as String?, timeoutSeconds: (j['timeout_seconds'] as num?)?.toInt() ?? 900,
    );
  }
  final String provider, mode, orderReference, currency, name, description, themeColor, prefillContact;
  final String? keyId, prefillName, prefillEmail, preferredMethod;
  final int amountMinor, timeoutSeconds;
}

/// A payment attempt exactly as the backend holds it.
class ServerPayment {
  const ServerPayment({required this.id, required this.checkoutId, required this.attemptNumber, required this.status, required this.amountMinor, required this.currency, required this.provider, required this.providerMode, this.methodType, this.methodSummary = const {}, this.failureCategory, this.failureMessage, this.message, required this.nextAction, this.retryAfterSeconds, this.session, required this.fulfillment, this.orderNumber, this.orderId, required this.supportReference});
  factory ServerPayment.fromJson(Map<String, dynamic> j) {
    Map<String, dynamic>? map(Object? v) => v is Map ? Map<String, dynamic>.from(v) : null;
    final amount = map(j['amount']) ?? const {};
    final provider = map(j['provider']) ?? const {};
    final method = map(j['method']);
    final failure = map(j['failure']);
    final session = map(j['provider_session']);
    return ServerPayment(
      id: '${j['id']}', checkoutId: '${j['checkout_id']}', attemptNumber: (j['attempt_number'] as num?)?.toInt() ?? 1, status: '${j['status']}',
      amountMinor: (amount['amount'] as num?)?.toInt() ?? 0, currency: '${amount['currency'] ?? ''}', provider: '${provider['name'] ?? ''}', providerMode: '${provider['mode'] ?? ''}',
      methodType: method?['type'] as String?, methodSummary: map(method?['summary']) ?? const {},
      failureCategory: failure?['category'] as String?, failureMessage: failure?['message'] as String?, message: j['message'] as String?,
      nextAction: '${j['next_action'] ?? 'WAIT'}', retryAfterSeconds: (j['retry_after_seconds'] as num?)?.toInt(),
      session: session == null ? null : ProviderSession.fromJson(session),
      fulfillment: '${map(j['fulfillment'])?['status'] ?? 'NOT_APPLICABLE'}', orderNumber: map(j['fulfillment'])?['order_number'] as String?, orderId: map(j['fulfillment'])?['order_id'] as String?,
      supportReference: '${j['support_reference'] ?? ''}',
    );
  }

  final String id, checkoutId;
  final int attemptNumber;
  /// CREATED · PROVIDER_READY · PROCESSING · PENDING · AUTHORIZED · VERIFIED · FAILED · CANCELLED · EXPIRED · UNKNOWN ·
  /// REVIEW_REQUIRED — the backend's own word.
  final String status;
  /// The backend's amount, in integer minor units, with its currency.
  final int amountMinor;
  final String currency;
  final String provider;
  /// TEST · LIVE · FAKE.
  final String providerMode;
  final String? methodType;
  /// Safe to show: a card's last four digits and network, a masked UPI address. Never an instrument.
  final Map<String, dynamic> methodSummary;
  final String? failureCategory, failureMessage, message;
  /// OPEN_PROVIDER · WAIT · REFRESH_STATUS · RETRY_PAYMENT · SELECT_ANOTHER_METHOD · RETURN_TO_CHECKOUT ·
  /// CONTACT_SUPPORT · PROCEED_TO_ORDER.
  final String nextAction;
  final int? retryAfterSeconds;
  final ProviderSession? session;
  /// NOT_APPLICABLE · ORDER_PENDING · RECOVERY_REQUIRED · ORDER_CREATED. A verified payment is not an order.
  final String fulfillment;
  /// The order the payment became (Module 31) — null until the backend created it. The app waits for it after VERIFIED.
  final String? orderNumber, orderId;
  /// VERIFIED, and the backend has not named the order yet (and has not given up on it): the screen keeps asking.
  bool get awaitingOrder => verified && orderNumber == null && fulfillment != 'RECOVERY_REQUIRED';
  final String supportReference;

  bool get verified => status == 'VERIFIED';
  /// A payment that may still end in money: nothing new may be started beside it.
  bool get unsettled => const {'CREATED', 'PROCESSING', 'PENDING', 'AUTHORIZED', 'UNKNOWN'}.contains(status);
  bool get endedUnpaid => const {'FAILED', 'CANCELLED', 'EXPIRED'}.contains(status);
  bool get fake => provider == 'fake';
}

/// What the provider's screen told the app. A report for the backend to verify — never a result.
class ProviderReport {
  const ProviderReport.success({required String this.reference, required String this.signature}) : outcome = 'success', errorCode = null, errorReason = null;
  const ProviderReport.failed({this.errorCode, this.errorReason}) : outcome = 'failed', reference = null, signature = null;
  const ProviderReport.cancelled() : outcome = 'cancelled', reference = null, signature = null, errorCode = null, errorReason = null;
  final String outcome;
  final String? reference, signature, errorCode, errorReason;

  Map<String, dynamic> toBody() => {
    'outcome': outcome,
    if (outcome == 'success') ...{'provider_payment_reference': reference, 'provider_signature': signature},
    if (outcome == 'failed' && (errorCode != null || errorReason != null)) 'provider_error': {'code': errorCode, 'reason': errorReason},
  };
}

enum PaymentErrorCode {
  offline, unauthenticated, notFound, rateLimited,
  /// The checkout cannot be paid as it stands: go back and let it be priced again.
  checkoutStale, checkoutExpired, windowClosed, attemptLimit, notRequired,
  /// Nothing may be started: a payment is verified already, or money may be on its way.
  alreadyVerified, inProgress,
  /// Payments cannot be taken right now. Nothing was charged.
  providerUnavailable, initializationUnknown,
  signatureInvalid, methodUnavailable, error,
}

class PaymentApiException implements Exception {
  PaymentApiException(this.code, this.message, {this.serverCode = '', Map<String, dynamic> details = const {}})
      : paymentId = details['payment_id'] is String ? details['payment_id'] as String : null;
  final PaymentErrorCode code;
  final String message;
  /// The backend's own error code (e.g. payment_provider_unavailable).
  final String serverCode;
  /// The attempt the refusal is about — e.g. the one that is already in progress.
  final String? paymentId;
  /// The checkout has to be reviewed again before anything can be paid.
  bool get checkoutGone => const {PaymentErrorCode.checkoutStale, PaymentErrorCode.checkoutExpired, PaymentErrorCode.windowClosed, PaymentErrorCode.attemptLimit, PaymentErrorCode.notRequired}.contains(code);
  @override
  String toString() => message;
}

const _codes = <String, PaymentErrorCode>{
  'checkout_stale': PaymentErrorCode.checkoutStale, 'checkout_not_ready': PaymentErrorCode.checkoutStale, 'checkout_not_found': PaymentErrorCode.checkoutStale, 'checkout_expired': PaymentErrorCode.checkoutExpired,
  'payment_window_closed': PaymentErrorCode.windowClosed, 'payment_attempt_limit_reached': PaymentErrorCode.attemptLimit, 'payment_not_required': PaymentErrorCode.notRequired,
  'payment_already_verified': PaymentErrorCode.alreadyVerified, 'payment_already_in_progress': PaymentErrorCode.inProgress, 'payment_in_progress': PaymentErrorCode.inProgress, 'payment_not_ready': PaymentErrorCode.inProgress,
  'payment_provider_unavailable': PaymentErrorCode.providerUnavailable, 'payment_initialization_failed': PaymentErrorCode.providerUnavailable, 'payment_initialization_unknown': PaymentErrorCode.initializationUnknown,
  'payment_signature_invalid': PaymentErrorCode.signatureInvalid, 'payment_method_unavailable': PaymentErrorCode.methodUnavailable, 'payment_not_found': PaymentErrorCode.notFound,
};

PaymentApiException toPaymentException(Object e) {
  if (e is PaymentApiException) return e;
  if (e is ApiException) {
    // Without a connection nothing about a payment can be concluded — least of all that it failed.
    if (e.kind == ApiErrorKind.network) return PaymentApiException(PaymentErrorCode.offline, e.message);
    if (e.kind == ApiErrorKind.unauthenticated) return PaymentApiException(PaymentErrorCode.unauthenticated, e.message, serverCode: e.code);
    final code = _codes[e.code] ?? (e.kind == ApiErrorKind.rateLimited ? PaymentErrorCode.rateLimited : e.kind == ApiErrorKind.notFound ? PaymentErrorCode.notFound : PaymentErrorCode.error);
    return PaymentApiException(code, e.message, serverCode: e.code, details: e.details);
  }
  return PaymentApiException(PaymentErrorCode.error, 'The payment could not be loaded. Please try again.');
}

/// The payment API. It goes out as the cart's owner does — the signed-in customer's session.
class PaymentBackend {
  PaymentBackend(this.cart);
  final CartBackend cart;

  Future<ServerPayment> _payment(Future<Object?> call) async {
    try {
      final answer = await call;
      return ServerPayment.fromJson(Map<String, dynamic>.from((answer as Map)['payment'] as Map));
    } catch (e) {
      throw toPaymentException(e);
    }
  }

  /// Starts paying a confirmed checkout — or gets the attempt that is already open for it. The request carries an
  /// idempotency key and is repeated once with the SAME key when only the answer was lost, so a request that did
  /// arrive never creates a second attempt.
  Future<ServerPayment> start(String checkoutId, String pricingFingerprint, {String? methodType}) => _payment(cart.request('POST', '/checkouts/$checkoutId/payment-attempts', operation: 'pay', body: {
    'pricing_fingerprint': pricingFingerprint,
    if (methodType != null) 'payment_method': {'type': methodType},
  }));

  Future<ServerPayment> show(String paymentId) => _payment(cart.request('GET', '/payments/$paymentId'));

  /// Reports how the provider's screen ended. The answer is the backend's judgement — not an echo of the report.
  Future<ServerPayment> complete(String paymentId, ProviderReport report) => _payment(cart.request('POST', '/payments/$paymentId/complete', body: report.toBody()));

  /// LOCAL DEVELOPMENT ONLY (the development provider): plays the customer in the provider's screen. Returns what
  /// that screen would hand the app — a report to send with [complete] — or null when the app hears nothing.
  Future<ProviderReport?> simulate(String paymentId, String scenario, {String webhook = 'none'}) async {
    try {
      final answer = await cart.request('POST', '/dev/payments/$paymentId/simulate', body: {'scenario': scenario, 'webhook': webhook});
      final simulation = (answer as Map)['simulation'];
      final client = simulation is Map && simulation['client'] is Map ? Map<String, dynamic>.from(simulation['client'] as Map) : null;
      if (client == null) return null;
      if (client['outcome'] == 'success') return ProviderReport.success(reference: '${client['provider_payment_reference'] ?? ''}', signature: '${client['provider_signature'] ?? ''}');
      if (client['outcome'] == 'failed') {
        final error = client['provider_error'] is Map ? Map<String, dynamic>.from(client['provider_error'] as Map) : const <String, dynamic>{};
        return ProviderReport.failed(errorCode: error['code'] as String?, errorReason: error['reason'] as String?);
      }
      return const ProviderReport.cancelled();
    } catch (e) {
      throw toPaymentException(e);
    }
  }
}
