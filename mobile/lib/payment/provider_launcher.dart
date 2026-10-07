/// Opens the PROVIDER'S OWN payment screen (Module 30) and resolves with what it tells the app.
///
/// Razorpay's Android checkout, through the `razorpay_flutter` plugin Razorpay publishes (1.4.7, reviewed
/// 2026-10-07): `Razorpay().open(options)` with the PUBLIC key id and the order the backend created; the plugin
/// then raises EVENT_PAYMENT_SUCCESS (payment id, order id, signature) or EVENT_PAYMENT_ERROR (a code — 2 is "the
/// customer closed it" — and a message).
///
/// What comes back is a REPORT for the backend to verify: this file never decides that a payment succeeded, and
/// nothing the customer types into the provider's screen passes through FoodOnTheGo.
library;

import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:razorpay_flutter/razorpay_flutter.dart';

import 'api_payment.dart';

/// Opens the provider's screen for one attempt and completes with the provider's report.
typedef ProviderLauncher = Future<ProviderReport> Function(ProviderSession session);

/// Thrown when the provider's screen could not be opened at all. Nothing was paid; the attempt can be opened again.
class ProviderUnavailable implements Exception {
  const ProviderUnavailable(this.reason);
  final String reason;
  @override
  String toString() => reason;
}

const _methods = <String, String>{'UPI': 'upi', 'CARD': 'card', 'NET_BANKING': 'netbanking', 'WALLET': 'wallet'};

/// The options handed to Razorpay's checkout: the public key id, the backend's order and amount — and no secret.
Map<String, dynamic> razorpayOptions(ProviderSession s) => {
  'key': s.keyId,
  'order_id': s.orderReference,
  'amount': s.amountMinor,
  'currency': s.currency,
  'name': s.name,
  'description': s.description,
  'timeout': s.timeoutSeconds,
  'prefill': {
    'contact': s.prefillContact,
    if (s.prefillName != null) 'name': s.prefillName,
    if (s.prefillEmail != null) 'email': s.prefillEmail,
    if (_methods[s.preferredMethod] != null) 'method': _methods[s.preferredMethod],
  },
  if (s.themeColor.isNotEmpty) 'theme': {'color': s.themeColor},
  // One try per attempt: a failed try comes back to the app, and the backend decides what a retry is.
  'retry': {'enabled': false},
};

/// What Razorpay's error event means for the report. A closed screen is a cancellation; anything else a failure —
/// either way the backend asks Razorpay what really happened before anything is concluded.
ProviderReport razorpayErrorReport(int? code, String? message) => code == Razorpay.PAYMENT_CANCELLED
    ? const ProviderReport.cancelled()
    : ProviderReport.failed(errorCode: switch (code) { Razorpay.NETWORK_ERROR => 'NETWORK_ERROR', Razorpay.INVALID_OPTIONS => 'INVALID_OPTIONS', Razorpay.TLS_ERROR => 'TLS_ERROR', Razorpay.INCOMPATIBLE_PLUGIN => 'INCOMPATIBLE_PLUGIN', _ => 'UNKNOWN_ERROR' }, errorReason: message == null || message.length <= 200 ? message : message.substring(0, 200));

Future<ProviderReport> launchRazorpay(ProviderSession session) {
  // The plugin exists for Android (and iOS). In a browser preview there is no provider screen to open.
  if (kIsWeb) throw const ProviderUnavailable('provider_screen_not_available_here');
  if (session.keyId == null || session.keyId!.isEmpty) throw const ProviderUnavailable('provider_not_configured');
  final done = Completer<ProviderReport>();
  final razorpay = Razorpay();
  void finish(ProviderReport report) { if (!done.isCompleted) { done.complete(report); razorpay.clear(); } }
  razorpay.on(Razorpay.EVENT_PAYMENT_SUCCESS, (PaymentSuccessResponse r) {
    // The provider says it worked. That is a claim with a proof attached — the backend checks both.
    final id = r.paymentId; final signature = r.signature;
    finish(id != null && id.isNotEmpty && signature != null && signature.isNotEmpty ? ProviderReport.success(reference: id, signature: signature) : const ProviderReport.failed(errorReason: 'incomplete_provider_response'));
  });
  razorpay.on(Razorpay.EVENT_PAYMENT_ERROR, (PaymentFailureResponse r) => finish(razorpayErrorReport(r.code, r.message)));
  // An external wallet takes the customer out of the provider's screen: nothing is known here, the backend asks.
  razorpay.on(Razorpay.EVENT_EXTERNAL_WALLET, (ExternalWalletResponse _) => finish(const ProviderReport.failed(errorCode: 'EXTERNAL_WALLET', errorReason: 'external_wallet_selected')));
  try {
    razorpay.open(razorpayOptions(session));
  } catch (_) {
    razorpay.clear();
    throw const ProviderUnavailable('provider_screen_failed');
  }
  return done.future;
}

/// The provider's screen for a provider, or null when it has none on this device — the development provider, whose
/// place is taken by the development sheet.
ProviderLauncher? launcherFor(String provider) => provider == 'razorpay' ? launchRazorpay : null;
