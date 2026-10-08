import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../checkout/api_checkout.dart' show CheckoutBackend, ServerCheckout;
import '../checkout/checkout_models.dart' show CheckoutRequest;
import '../payment/api_payment.dart';
import '../payment/provider_launcher.dart';
import '../pickup/pickup_models.dart' show PickupMode;

/// What the payment screen needs to know about the checkout it takes a payment for — from the request confirmed on
/// this phone, or read again from the backend when the phone kept nothing.
class PaymentSubject {
  const PaymentSubject({required this.checkoutId, required this.fingerprint, required this.currency, required this.totalMinor, this.methodType, required this.pickupAt, required this.asap, required this.timezone, this.restaurantName, this.attemptId});

  static const _methodTypes = <String, String>{'upi': 'UPI', 'card': 'CARD', 'netbanking': 'NET_BANKING', 'wallet': 'WALLET'};

  /// From the checkout confirmed on this phone. Null when it is not a backend checkout.
  static PaymentSubject? fromRequest(CheckoutRequest r, {ServerCheckout? server, String? restaurantName}) {
    final id = r.checkoutId; final fingerprint = r.pricingFingerprint;
    if (id == null || fingerprint == null) return null;
    final same = server != null && server.id == id;
    return PaymentSubject(
      checkoutId: id, fingerprint: fingerprint, currency: r.currency, totalMinor: same ? server.totalMinor : r.displayedTotalMinor, methodType: _methodTypes[r.paymentMethodId],
      pickupAt: r.pickupSelection.requestedAt, asap: r.pickupSelection.mode == PickupMode.asap, timezone: r.pickupSelection.restaurantTimezone,
      restaurantName: restaurantName ?? (same ? server.restaurantName : null), attemptId: same ? server.paymentAttemptId : null,
    );
  }

  /// From the checkout as the backend holds it.
  factory PaymentSubject.fromCheckout(ServerCheckout c) => PaymentSubject(
    checkoutId: c.id, fingerprint: c.fingerprint, currency: c.currency, totalMinor: c.totalMinor, methodType: c.selectedType,
    pickupAt: c.pickupAt, asap: c.asap, timezone: c.timezone, restaurantName: c.restaurantName.isEmpty ? null : c.restaurantName, attemptId: c.paymentAttemptId,
  );

  final String checkoutId, fingerprint, currency, timezone;
  final int totalMinor;
  final String? methodType, restaurantName, attemptId;
  final DateTime pickupAt;
  final bool asap;
}

/// The screen's own phases around what the backend says.
enum PayPhase { loading, idle, preparing, openingProvider, providerOpen, verifying }

/// What is shown — the Module 12 state names, so the same texts apply.
enum PayView { preparing, ready, openingProvider, processing, verifying, pending, unknown, verified, failed, cancelled, expired, error }

/// The payment step on the backend (Module 30) — Android.
///
///   Pay            → the backend creates a payment attempt for the checkout's total and a provider order
///   provider       → the provider's OWN screen collects whatever the customer types — never this app
///   provider says  → that is reported to the backend, which verifies it with the provider
///   VERIFIED       → only the backend says so. And then there is still no order: orders are the next module.
///
/// After the app was closed, killed or offline it asks the backend what the payment is; it never pays again on its
/// own account. The phone keeps two references (checkout, payment attempt) — never an amount as a truth, never a
/// proof of payment.
class PaymentBackendState extends ChangeNotifier {
  PaymentBackendState({required this.backend, KeyValueStore? store, ProviderLauncher? Function(String provider)? launcher, this.autoPollFor = const Duration(minutes: 10), this.pollEvery})
      : _store = store ?? SecureKeyValueStore(), _launcher = launcher ?? launcherFor;

  static const attemptKey = 'fotg.payment.attempt';
  final PaymentBackend backend;
  final KeyValueStore _store;
  final ProviderLauncher? Function(String provider) _launcher;
  /// How long the screen keeps asking on its own before it leaves the asking to a button.
  final Duration autoPollFor;
  /// Tests: a fixed interval instead of the backend's hint.
  final Duration? pollEvery;

  PaymentSubject? subject;
  ServerPayment? payment;
  PayPhase phase = PayPhase.loading;
  PaymentApiException? error;
  /// The provider's screen could not be opened. Nothing was paid; the attempt can be opened again.
  bool providerError = false;
  /// A report or a question could not reach the backend: the status needs confirmation, nothing is assumed.
  bool unconfirmed = false;
  /// The development provider's sheet stands in for the provider's screen.
  bool sheet = false;
  bool pollPaused = false;
  /// The order exists and the screen is leaving for its confirmation: nothing more is shown here.
  bool handedOff = false;
  void markHandedOff() { handedOff = true; }
  /// The checkout the payment was to be found through could not be read.
  bool resumeFailed = false;

  bool _busy = false;
  bool _disposed = false;
  int _seq = 0;
  String? _knownId;
  Timer? _timer;
  DateTime? _pollStarted;

  bool get inFlight => phase != PayPhase.idle;
  bool get checkoutGone => error != null && error!.checkoutGone;
  bool get mustReturn => checkoutGone || payment?.nextAction == 'RETURN_TO_CHECKOUT';
  bool get canRetry => payment != null && payment!.endedUnpaid && (payment!.nextAction == 'RETRY_PAYMENT' || payment!.nextAction == 'SELECT_ANOTHER_METHOD');
  /// The backend's amount: the attempt's once there is one, the confirmed checkout's before.
  int get amountMinor => payment?.amountMinor ?? subject?.totalMinor ?? 0;
  String get currency => payment?.currency ?? subject?.currency ?? '';

  PayView get view {
    switch (phase) {
      case PayPhase.loading: case PayPhase.preparing: return PayView.preparing;
      case PayPhase.openingProvider: return PayView.openingProvider;
      case PayPhase.providerOpen: return PayView.processing;
      case PayPhase.verifying: return PayView.verifying;
      case PayPhase.idle: break;
    }
    final p = payment;
    if (p == null) return unconfirmed ? PayView.unknown : error != null ? PayView.error : PayView.ready;
    if (unconfirmed) return PayView.unknown;
    return switch (p.status) {
      'VERIFIED' => PayView.verified, 'FAILED' => PayView.failed, 'CANCELLED' => PayView.cancelled, 'EXPIRED' => PayView.expired,
      'PROVIDER_READY' => PayView.ready, 'PROCESSING' || 'CREATED' => PayView.verifying, 'PENDING' || 'AUTHORIZED' => PayView.pending,
      _ => PayView.unknown,
    };
  }

  void _notify() { if (!_disposed) notifyListeners(); }

  Future<String?> _remembered(String checkoutId) async {
    try {
      final raw = await _store.read(attemptKey);
      final v = raw == null || raw.isEmpty ? null : jsonDecode(raw);
      return v is Map && v['checkoutId'] == checkoutId && v['paymentId'] is String ? v['paymentId'] as String : null;
    } catch (_) { return null; }
  }
  // Only the two references are kept on the phone.
  Future<void> _remember(String checkoutId, String? paymentId) async {
    try { await _store.write(attemptKey, paymentId == null ? null : jsonEncode({'checkoutId': checkoutId, 'paymentId': paymentId})); } catch (_) {}
  }

  void _accept(ServerPayment p) { payment = p; _knownId = p.id; final s = subject; if (s != null) _remember(s.checkoutId, p.id); }

  /// Arriving on the screen — also after the app was closed or killed: is there a payment for this checkout already?
  Future<void> open(PaymentSubject s) async {
    final same = subject?.checkoutId == s.checkoutId;
    subject = s; resumeFailed = false;
    // Back on the screen for the same checkout: what is known stays, and an unsettled payment is asked about again.
    if (same && (payment != null || inFlight && phase != PayPhase.loading)) { _notify(); resumed(); return; }
    final my = ++_seq;
    if (!same) { payment = null; _knownId = null; error = null; providerError = false; unconfirmed = false; sheet = false; _busy = false; pollPaused = false; _pollStarted = null; }
    phase = PayPhase.loading; _notify();
    final known = await _remembered(s.checkoutId) ?? s.attemptId;
    if (my != _seq) return;
    if (known != null) {
      _knownId = known;
      try {
        final p = await backend.show(known);
        if (my != _seq) return;
        _accept(p); unconfirmed = false;
      } catch (e) {
        if (my != _seq) return;
        final failure = toPaymentException(e);
        // Not reachable: the status needs confirmation. Not this customer's, or gone: start clean.
        if (failure.code == PaymentErrorCode.offline) { unconfirmed = true; } else { _knownId = null; await _remember(s.checkoutId, null); }
      }
    }
    if (my != _seq) return;
    phase = PayPhase.idle; _notify(); _schedule();
  }

  /// The phone kept nothing, but the backend says this checkout is being paid for: read it, and go on from there.
  Future<void> resume(String checkoutId, CheckoutBackend checkouts) async {
    if (subject?.checkoutId == checkoutId) return;
    final my = ++_seq;
    resumeFailed = false; handedOff = false; phase = PayPhase.loading; _notify();
    try {
      final checkout = await checkouts.show(checkoutId);
      if (my != _seq) return;
      await open(PaymentSubject.fromCheckout(checkout));
    } catch (_) {
      if (my != _seq) return;
      resumeFailed = true; phase = PayPhase.idle; _notify();
    }
  }

  /// Asks the backend what the payment is — the backend, never the provider.
  Future<ServerPayment?> refresh({bool manual = false}) async {
    if (manual) { pollPaused = false; _pollStarted = null; }
    final id = payment?.id ?? _knownId;
    if (id == null) return null;
    try {
      final p = await backend.show(id);
      _accept(p); unconfirmed = false; error = null;
      _notify(); _schedule();
      return p;
    } catch (e) {
      final failure = toPaymentException(e);
      if (failure.code == PaymentErrorCode.offline) { unconfirmed = true; } else { error = failure; }
      _notify(); _schedule();
      return null;
    }
  }

  /// The app is in front again, or the connection is back: ask at once.
  void resumed() {
    final p = payment;
    if (phase == PayPhase.idle && (unconfirmed || (p != null && (p.unsettled || p.status == 'PROVIDER_READY')))) refresh();
  }

  /// Pay, pay again, or continue with the attempt that is already open — the backend decides which it is. A second
  /// tap while the first is on its way does nothing.
  Future<void> pay() async {
    final s = subject;
    if (s == null || _busy) return;
    _busy = true;
    error = null; providerError = false; phase = PayPhase.preparing; _notify();
    try {
      final p = await backend.start(s.checkoutId, s.fingerprint, methodType: s.methodType);
      _accept(p); unconfirmed = false;
      if (p.nextAction == 'OPEN_PROVIDER') { await _openProvider(p); return; }
      phase = PayPhase.idle; _busy = false;
    } catch (e) {
      final failure = toPaymentException(e);
      // A payment exists already (in progress, or verified): show THAT instead of starting another one.
      final existing = failure.paymentId;
      if (existing != null) { try { _accept(await backend.show(existing)); } catch (_) {/* the refusal is shown */} }
      if (failure.code != PaymentErrorCode.inProgress && failure.code != PaymentErrorCode.alreadyVerified) error = failure;
      phase = PayPhase.idle; _busy = false;
    }
    _notify(); _schedule();
  }

  Future<void> _openProvider(ServerPayment p) async {
    final session = p.session;
    if (session == null) { phase = PayPhase.idle; _busy = false; _notify(); _schedule(); return; }
    final launch = _launcher(session.provider);
    if (launch == null) {
      // No provider screen on this device: the development provider's sheet stands in for it.
      phase = PayPhase.providerOpen; sheet = true; _notify();
      return;
    }
    phase = PayPhase.openingProvider; _notify();
    try {
      final pending = launch(session);
      phase = PayPhase.providerOpen; _notify();
      await _report(p, await pending);
    } on ProviderUnavailable {
      providerError = true; phase = PayPhase.idle; _busy = false; _notify(); _schedule();
    } catch (e) {
      error = toPaymentException(e); providerError = true; phase = PayPhase.idle; _busy = false; _notify(); _schedule();
    }
  }

  /// What the provider's screen said goes to the backend. The answer — not the report — is what the screen shows.
  Future<void> _report(ServerPayment p, ProviderReport? report) async {
    sheet = false; phase = PayPhase.verifying; _notify();
    try {
      _accept(report == null ? await backend.show(p.id) : await backend.complete(p.id, report));
      unconfirmed = false;
    } catch (e) {
      final failure = toPaymentException(e);
      if (failure.code == PaymentErrorCode.offline) {
        unconfirmed = true; // paid or not is not known here: the screen keeps asking
      } else {
        error = failure;
        try { _accept(await backend.show(p.id)); } catch (_) {/* shown as it was */}
      }
    } finally {
      phase = PayPhase.idle; _busy = false; _notify(); _schedule();
    }
  }

  /// The provider's screen is gone but the app heard nothing from it (the app was restarted underneath it, or the
  /// screen never came up): stop waiting for it and ask the backend. A report that still arrives is still sent.
  Future<void> leftProvider() async {
    if (phase != PayPhase.providerOpen || sheet) return;
    phase = PayPhase.idle; _busy = false; _notify();
    await refresh(manual: true);
  }

  /// LOCAL DEVELOPMENT ONLY: what the customer does in the development provider's place.
  Future<void> simulate(String scenario, {String webhook = 'none'}) async {
    final p = payment;
    if (p == null) return;
    try {
      await _report(p, await backend.simulate(p.id, scenario, webhook: webhook));
    } catch (e) {
      error = toPaymentException(e); sheet = false; phase = PayPhase.idle; _busy = false; _notify(); _schedule();
    }
  }

  // While the backend has not settled the payment the screen asks again, at the pace the backend names.
  void _schedule() {
    _timer?.cancel(); _timer = null;
    if (_disposed) return;
    final p = payment;
    // Also while the verified payment has not become an order yet (Module 31): the order is the backend's to create.
    final waiting = phase == PayPhase.idle && (unconfirmed && (p != null || _knownId != null) || (p != null && (p.unsettled || p.awaitingOrder)));
    if (!waiting) { _pollStarted = null; return; }
    if (pollPaused) return;
    final now = DateTime.now();
    _pollStarted ??= now;
    if (now.difference(_pollStarted!) > autoPollFor) { pollPaused = true; _notify(); return; }
    final seconds = (p?.retryAfterSeconds ?? 5).clamp(2, 15);
    _timer = Timer(pollEvery ?? Duration(seconds: seconds), () { refresh(); });
  }

  /// The customer goes back to review the order: this checkout's payment is no longer looked for here.
  Future<void> forget() async {
    final s = subject;
    _seq++; _timer?.cancel(); _timer = null;
    subject = null; payment = null; _knownId = null; error = null; providerError = false; unconfirmed = false; sheet = false; _busy = false; pollPaused = false; _pollStarted = null; resumeFailed = false; handedOff = false; phase = PayPhase.loading;
    if (s != null) await _remember(s.checkoutId, null);
    _notify();
  }

  /// Signing out, or another customer on this phone: nothing of the earlier payment may be shown.
  void reset() {
    _seq++; _timer?.cancel(); _timer = null;
    subject = null; payment = null; _knownId = null; error = null; providerError = false; unconfirmed = false; sheet = false; _busy = false; pollPaused = false; _pollStarted = null; resumeFailed = false; handedOff = false; phase = PayPhase.loading;
    _store.write(attemptKey, null).catchError((_) {});
    _notify();
  }

  @override
  void dispose() { _disposed = true; _timer?.cancel(); super.dispose(); }
}
