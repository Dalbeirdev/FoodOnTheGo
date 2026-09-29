import 'dart:async';

import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../checkout/checkout_models.dart';
import '../payment/payment_models.dart';

export '../payment/payment_models.dart';

/// PaymentStateController (Module 12) — Android. Single owner of the payment state machine.
///
/// idle → preparing → ready → openingProvider → processing → successClientSide → verifying → verified
///                                       ↘ failed / cancelled / pending / unknown / expired / error
/// Single initiation: `pay()` is ignored unless the status is `ready` and nothing is in flight (double tap).
/// Recovery: the current attempt id is persisted; after a restart an in-flight attempt becomes `unknown`
/// and the customer is asked to check the status instead of paying again.
class PaymentState extends ChangeNotifier {
  PaymentState({PaymentProviderResolver? resolver, PaymentRepository? repository, PaymentVerificationService? verifier, KeyValueStore? store, this.providerTimeout = const Duration(seconds: 8)})
      : resolver = resolver ?? MockPaymentProviderResolver(),
        _store = store ?? SecureKeyValueStore(),
        verifier = verifier ?? MockPaymentVerificationService() {
    repo = repository ?? MockPaymentRepository(_store);
  }
  final PaymentProviderResolver resolver;
  late final PaymentRepository repo;
  final PaymentVerificationService verifier;
  final KeyValueStore _store;
  final Duration providerTimeout;
  static const currentKey = 'fotg.payment.current';

  PaymentStatus status = PaymentStatus.idle;
  PaymentAttempt? attempt;
  PaymentProvider? provider;
  List<PaymentMethodOption> methods = const [];
  String? error;
  bool busy = false;
  bool _inFlight = false;
  Completer<void>? _cancelSignal;
  CheckoutRequest? _request;
  String? _snapshot, _countryCode, _restaurantId;

  PaymentMethodOption? get method => attempt == null ? null : methods.where((m) => m.id == attempt!.methodId).firstOrNull;
  bool get inFlight => _inFlight || inFlightStatuses.contains(status);

  Future<PaymentAttempt> _commit(String id, PaymentStatus s, {String? providerPaymentReference, String? failureReason, DateTime? expiresAt, String? methodId, String? methodType, String? note}) async {
    final a = await repo.transition(id, s, providerPaymentReference: providerPaymentReference, failureReason: failureReason, expiresAt: expiresAt, methodId: methodId, methodType: methodType, note: note);
    attempt = a; status = s; notifyListeners(); return a;
  }
  Future<void> _persistCurrent(String? id) => _store.write(currentKey, id).catchError((_) {});

  Future<PaymentAttempt> _create(PaymentProvider p, CheckoutRequest req, String snapshot, String restaurantId, String methodId) async {
    final m = methods.where((x) => x.id == methodId).firstOrNull ?? methods.where((x) => x.enabled).firstOrNull;
    if (m == null) throw StateError('no_payment_method');
    status = PaymentStatus.preparing; error = null; notifyListeners();
    final a = await repo.createAttempt(checkoutReference: req.idempotencyKey, checkoutSnapshot: snapshot, provider: p.id, currency: req.currency, amountMinor: req.displayedTotalMinor, methodId: m.id, methodType: m.type.name, customerId: req.customerId, restaurantId: restaurantId);
    await _persistCurrent(a.publicId); attempt = a; notifyListeners();
    final init = await p.initializePayment(a);
    return _commit(a.publicId, PaymentStatus.ready, providerPaymentReference: init.providerPaymentReference, expiresAt: init.expiresAt, note: 'provider payment initialised (mock)');
  }

  /// Prepares (or recovers / expires) the attempt for a CheckoutRequest.
  Future<void> prepare(CheckoutRequest req, {required String countryCode, required String restaurantId}) async {
    if (_inFlight) return;
    _inFlight = true; busy = true; notifyListeners();
    try {
      _request = req; _snapshot = checkoutSnapshot(req); _countryCode = countryCode; _restaurantId = restaurantId;
      final p = resolver.resolve(countryCode: countryCode, currency: req.currency);
      provider = p;
      methods = await p.getAvailablePaymentMethods(countryCode, req.currency);
      String? currentId; try { currentId = await _store.read(currentKey); } catch (_) {}
      final current = currentId == null ? null : await repo.getAttempt(currentId);
      if (current != null && current.checkoutReference == req.idempotencyKey) {
        if (current.checkoutSnapshot != _snapshot && current.status != PaymentStatus.verified) {
          await _commit(current.publicId, PaymentStatus.expired, note: 'checkout changed — attempt invalidated');
        } else if (current.status == PaymentStatus.openingProvider || current.status == PaymentStatus.processing) {
          await _commit(current.publicId, PaymentStatus.unknown, note: 'recovered after interruption — status must be checked'); return;
        } else if (current.status == PaymentStatus.verifying || current.status == PaymentStatus.successClientSide) {
          attempt = current; status = PaymentStatus.verifying; notifyListeners();
          final v = await verifier.verify(current);
          await _commit(current.publicId, v.status, failureReason: v.reason, note: 'verification (development mock)'); return;
        } else if (current.status == PaymentStatus.preparing) {
          await _commit(current.publicId, PaymentStatus.error, failureReason: 'interrupted', note: 'interrupted while preparing');
        } else { attempt = current; status = current.status; notifyListeners(); return; }
      } else if (current != null && current.checkoutReference != req.idempotencyKey && !terminalStatuses.contains(current.status)) {
        await repo.transition(current.publicId, PaymentStatus.expired, note: 'superseded by a new checkout');
      }
      await _create(p, req, _snapshot!, restaurantId, req.paymentMethodId);
    } catch (e) { error = '$e'; status = PaymentStatus.error; notifyListeners(); }
    finally { _inFlight = false; busy = false; notifyListeners(); }
  }

  Future<void> _applyResult(PaymentAttempt a, ClientPaymentResult r) async {
    final ref = r.providerPaymentReference ?? a.providerPaymentReference;
    switch (r.outcome) {
      case ClientOutcome.success:
        await _commit(a.publicId, PaymentStatus.successClientSide, providerPaymentReference: ref, note: 'provider reported success (client side — not final)');
        await _commit(a.publicId, PaymentStatus.verifying, note: 'verification requested');
        final v = await verifier.verify(a);
        await _commit(a.publicId, v.status, failureReason: v.reason, note: 'verification (development mock)');
      case ClientOutcome.failed: await _commit(a.publicId, PaymentStatus.failed, providerPaymentReference: ref, failureReason: r.reason, note: 'provider reported failure');
      case ClientOutcome.cancelled: await _commit(a.publicId, PaymentStatus.cancelled, providerPaymentReference: ref, note: 'customer cancelled');
      case ClientOutcome.pending: await _commit(a.publicId, PaymentStatus.pending, providerPaymentReference: ref, note: 'provider reported pending');
      case ClientOutcome.unknown: await _commit(a.publicId, PaymentStatus.unknown, providerPaymentReference: ref, failureReason: r.reason, note: 'status unknown (connection lost / timeout)');
    }
  }

  /// Single initiation — ignored unless `ready` and nothing in flight. [online] false → refuses to start.
  Future<void> pay({bool online = true}) async {
    final a = attempt; final p = provider;
    if (a == null || p == null || status != PaymentStatus.ready || _inFlight) return;
    if (!online) { error = 'offline'; notifyListeners(); return; }
    _inFlight = true; busy = true; error = null; notifyListeners();
    final cancel = Completer<void>(); _cancelSignal = cancel;
    try {
      await _commit(a.publicId, PaymentStatus.openingProvider, note: 'opening provider experience');
      await _commit(a.publicId, PaymentStatus.processing, note: 'waiting for the customer to pay');
      final r = await p.openPaymentExperience(a, cancelled: cancel.future).timeout(providerTimeout, onTimeout: () => const ClientPaymentResult(ClientOutcome.unknown, reason: 'timeout'));
      await _applyResult(a, r);
    } catch (e) { error = '$e'; await _commit(a.publicId, PaymentStatus.error, failureReason: 'error', note: 'unexpected error'); }
    finally { _inFlight = false; busy = false; _cancelSignal = null; notifyListeners(); }
  }

  /// Customer closes the (mock) provider experience / presses back while processing.
  Future<void> cancel() async {
    final a = attempt; final p = provider;
    if (a == null || p == null || (status != PaymentStatus.processing && status != PaymentStatus.openingProvider)) return;
    await p.cancelPayment(a);
    if (_cancelSignal != null && !_cancelSignal!.isCompleted) _cancelSignal!.complete();
  }

  /// Connectivity lost while processing → the status becomes unknown (never failed).
  Future<void> connectionLost() async {
    final a = attempt;
    if (a == null || status != PaymentStatus.processing) return;
    if (_cancelSignal != null && !_cancelSignal!.isCompleted) _cancelSignal!.completeError(StateError('connection_lost'));
    await _commit(a.publicId, PaymentStatus.unknown, failureReason: 'connection_lost', note: 'connection lost while processing');
  }

  /// After failed / cancelled / expired / error: a controlled new attempt for the same checkout.
  Future<void> retry() async {
    final req = _request; final p = provider; final a = attempt;
    if (req == null || p == null || a == null || _inFlight || !retryableStatuses.contains(status)) return;
    _inFlight = true; busy = true; notifyListeners();
    try { await _create(p, req, _snapshot!, _restaurantId!, a.methodId); }
    catch (e) { error = '$e'; status = PaymentStatus.error; notifyListeners(); }
    finally { _inFlight = false; busy = false; notifyListeners(); }
  }

  /// Change the method without losing the checkout.
  Future<void> changeMethod(String methodId) async {
    final req = _request; final p = provider; final a = attempt;
    final m = methods.where((x) => x.id == methodId && x.enabled).firstOrNull;
    if (req == null || p == null || a == null || m == null || _inFlight || inFlightStatuses.contains(status)) return;
    if (status == PaymentStatus.ready) { await _commit(a.publicId, PaymentStatus.ready, methodId: m.id, methodType: m.type.name, note: 'method changed'); return; }
    _inFlight = true; busy = true; notifyListeners();
    try { await _create(p, req, _snapshot!, _restaurantId!, m.id); }
    catch (e) { error = '$e'; status = PaymentStatus.error; notifyListeners(); }
    finally { _inFlight = false; busy = false; notifyListeners(); }
  }

  /// pending / unknown → status check (later: backend status endpoint). Never a second payment.
  Future<void> checkStatus() async {
    final a = attempt; final p = provider;
    if (a == null || p == null || _inFlight || (status != PaymentStatus.pending && status != PaymentStatus.unknown)) return;
    _inFlight = true; busy = true; notifyListeners();
    try {
      await _commit(a.publicId, PaymentStatus.verifying, note: 'status check requested');
      final r = await p.getClientPaymentResult(a);
      switch (r.outcome) {
        case ClientOutcome.success: final v = await verifier.verify(a); await _commit(a.publicId, v.status, failureReason: v.reason, note: 'status check → verification (development mock)');
        case ClientOutcome.failed: await _commit(a.publicId, PaymentStatus.failed, failureReason: r.reason, note: 'status check → failed');
        case ClientOutcome.pending: await _commit(a.publicId, PaymentStatus.pending, note: 'status check → still pending');
        default: await _commit(a.publicId, PaymentStatus.unknown, note: 'status check → still unknown');
      }
    } catch (e) { error = '$e'; status = PaymentStatus.error; notifyListeners(); }
    finally { _inFlight = false; busy = false; notifyListeners(); }
  }

  /// Clears the controller for a brand-new checkout.
  Future<void> reset() async { if (_cancelSignal != null && !_cancelSignal!.isCompleted) _cancelSignal!.complete(); await _persistCurrent(null); attempt = null; status = PaymentStatus.idle; error = null; _request = null; notifyListeners(); }
  String get countryCode => _countryCode ?? '';
}
