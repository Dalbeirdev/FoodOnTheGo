import 'dart:convert';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;
import '../checkout/checkout_models.dart';

/// Payment domain (Module 12) — provider-neutral. Never models card numbers, CVV, UPI PINs or bank
/// credentials; the frontend amount is display-only; VERIFIED is simulated in development only and the
/// production verification is server-side. No provider secrets exist in this code base.
enum PaymentStatus { idle, preparing, ready, openingProvider, processing, pending, successClientSide, verifying, verified, failed, cancelled, expired, unknown, error }

enum RefundStatus { none, refundPending, partiallyRefunded, refunded, refundFailed }

const inFlightStatuses = {PaymentStatus.preparing, PaymentStatus.openingProvider, PaymentStatus.processing, PaymentStatus.verifying};
const terminalStatuses = {PaymentStatus.verified, PaymentStatus.failed, PaymentStatus.cancelled, PaymentStatus.expired};
const retryableStatuses = {PaymentStatus.failed, PaymentStatus.cancelled, PaymentStatus.expired, PaymentStatus.error};

class PaymentEvent {
  const PaymentEvent({required this.at, required this.status, this.note});
  final DateTime at;
  final PaymentStatus status;
  final String? note;
  Map<String, dynamic> toJson() => {'at': at.toIso8601String(), 'status': status.name, 'note': note};
  factory PaymentEvent.fromJson(Map<String, dynamic> j) => PaymentEvent(at: DateTime.parse(j['at'] as String), status: PaymentStatus.values.byName(j['status'] as String), note: j['note'] as String?);
}

class PaymentAttempt {
  const PaymentAttempt({required this.publicId, required this.checkoutReference, required this.checkoutSnapshot, required this.provider, this.providerPaymentReference, required this.currency, required this.amountMinor, required this.status, this.refundStatus = RefundStatus.none, required this.methodId, required this.methodType, required this.customerId, required this.restaurantId, this.failureReason, this.expiresAt, required this.createdAt, required this.updatedAt, required this.events});
  /// Public, non-sequential reference safe to show to the customer.
  final String publicId;
  /// CheckoutRequest.idempotencyKey — several attempts may exist per checkout (retry), never several charges.
  final String checkoutReference;
  /// Snapshot of the validated checkout; a changed checkout invalidates the attempt.
  final String checkoutSnapshot;
  final String provider;
  final String? providerPaymentReference;
  /// ISO 4217 — one explicit currency per attempt; no silent FX.
  final String currency;
  /// Display total the customer saw (minor units). Not authoritative.
  final int amountMinor;
  final PaymentStatus status;
  final RefundStatus refundStatus;
  final String methodId, methodType, customerId, restaurantId;
  final String? failureReason;
  final DateTime? expiresAt;
  final DateTime createdAt, updatedAt;
  /// Append-only history — never overwritten with just the latest value.
  final List<PaymentEvent> events;

  PaymentAttempt copyWith({PaymentStatus? status, String? providerPaymentReference, String? failureReason, DateTime? expiresAt, String? methodId, String? methodType, DateTime? updatedAt, List<PaymentEvent>? events, bool clearFailure = false}) => PaymentAttempt(
        publicId: publicId, checkoutReference: checkoutReference, checkoutSnapshot: checkoutSnapshot, provider: provider, providerPaymentReference: providerPaymentReference ?? this.providerPaymentReference, currency: currency, amountMinor: amountMinor, status: status ?? this.status, refundStatus: refundStatus, methodId: methodId ?? this.methodId, methodType: methodType ?? this.methodType, customerId: customerId, restaurantId: restaurantId, failureReason: clearFailure ? null : (failureReason ?? this.failureReason), expiresAt: expiresAt ?? this.expiresAt, createdAt: createdAt, updatedAt: updatedAt ?? this.updatedAt, events: events ?? this.events);

  Map<String, dynamic> toJson() => {'publicId': publicId, 'checkoutReference': checkoutReference, 'checkoutSnapshot': checkoutSnapshot, 'provider': provider, 'providerPaymentReference': providerPaymentReference, 'currency': currency, 'amountMinor': amountMinor, 'status': status.name, 'refundStatus': refundStatus.name, 'methodId': methodId, 'methodType': methodType, 'customerId': customerId, 'restaurantId': restaurantId, 'failureReason': failureReason, 'expiresAt': expiresAt?.toIso8601String(), 'createdAt': createdAt.toIso8601String(), 'updatedAt': updatedAt.toIso8601String(), 'events': events.map((e) => e.toJson()).toList()};
  factory PaymentAttempt.fromJson(Map<String, dynamic> j) => PaymentAttempt(
        publicId: j['publicId'] as String, checkoutReference: j['checkoutReference'] as String, checkoutSnapshot: j['checkoutSnapshot'] as String, provider: j['provider'] as String, providerPaymentReference: j['providerPaymentReference'] as String?, currency: j['currency'] as String, amountMinor: j['amountMinor'] as int, status: PaymentStatus.values.byName(j['status'] as String), refundStatus: RefundStatus.values.byName((j['refundStatus'] as String?) ?? 'none'), methodId: j['methodId'] as String, methodType: j['methodType'] as String, customerId: j['customerId'] as String, restaurantId: j['restaurantId'] as String, failureReason: j['failureReason'] as String?, expiresAt: j['expiresAt'] == null ? null : DateTime.parse(j['expiresAt'] as String), createdAt: DateTime.parse(j['createdAt'] as String), updatedAt: DateTime.parse(j['updatedAt'] as String), events: ((j['events'] as List?) ?? const []).map((e) => PaymentEvent.fromJson(e as Map<String, dynamic>)).toList());
}

enum ClientOutcome { success, failed, cancelled, pending, unknown }

class ClientPaymentResult {
  const ClientPaymentResult(this.outcome, {this.providerPaymentReference, this.reason});
  final ClientOutcome outcome;
  final String? providerPaymentReference, reason;
}

class PaymentInit {
  const PaymentInit({required this.providerPaymentReference, this.expiresAt});
  final String providerPaymentReference;
  final DateTime? expiresAt;
}

/// Provider-facing experience (Razorpay, another PSP, or the development mock). No secrets, no card data.
abstract class PaymentProvider {
  String get id;
  String get displayName;
  Future<List<PaymentMethodOption>> getAvailablePaymentMethods(String countryCode, String currency);
  Future<PaymentInit> initializePayment(PaymentAttempt attempt);
  /// Opens the hosted / SDK experience; completes with the client-side outcome. [cancelled] lets the
  /// controller abort a mock experience (customer closed it / back navigation).
  Future<ClientPaymentResult> openPaymentExperience(PaymentAttempt attempt, {Future<void>? cancelled});
  Future<ClientPaymentResult> getClientPaymentResult(PaymentAttempt attempt);
  Future<void> cancelPayment(PaymentAttempt attempt);
}

abstract class PaymentProviderResolver {
  PaymentProvider resolve({required String countryCode, required String currency});
}

abstract class PaymentRepository {
  Future<PaymentAttempt> createAttempt({required String checkoutReference, required String checkoutSnapshot, required String provider, required String currency, required int amountMinor, required String methodId, required String methodType, required String customerId, required String restaurantId});
  Future<PaymentAttempt?> getAttempt(String publicId);
  Future<List<PaymentAttempt>> listForCheckout(String checkoutReference);
  Future<PaymentAttempt> transition(String publicId, PaymentStatus status, {String? providerPaymentReference, String? failureReason, DateTime? expiresAt, String? methodId, String? methodType, String? note});
}

class VerificationResult {
  const VerificationResult(this.status, {this.reason});
  final PaymentStatus status; // verified | pending | failed | unknown
  final String? reason;
  final bool developmentOnly = true;
}

/// DEVELOPMENT stand-in for the server verification. The production verification is server-side.
abstract class PaymentVerificationService {
  Future<VerificationResult> verify(PaymentAttempt attempt);
}

// ---------------------------------------------------------------------------------------------------------
// Development implementations — deterministic, never random.
// ---------------------------------------------------------------------------------------------------------
enum MockOutcome { success, failure, cancelled, pending, timeout, unknown }
enum MockResolve { verified, pending, failed, unknown }

String _hex(int n) { final r = Random.secure(); return List.generate(n, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join(); }

class MockPaymentProvider implements PaymentProvider {
  MockPaymentProvider({this.id = 'mock-razorpay', this.displayName = 'Razorpay (development sandbox)', this.latency = const Duration(milliseconds: 600)});
  @override
  final String id;
  @override
  final String displayName;
  final Duration latency;
  /// Deterministic outcome for the next `openPaymentExperience` (test controls / development panel).
  MockOutcome outcome = MockOutcome.success;
  /// Outcome of a status check after pending / unknown.
  MockResolve resolve = MockResolve.verified;
  final _methods = MockPaymentMethodRepository(latency: Duration.zero);
  Future<void> _wait(Duration d) => d == Duration.zero ? Future.value() : Future<void>.delayed(d);
  @override
  Future<List<PaymentMethodOption>> getAvailablePaymentMethods(String countryCode, String currency) => _methods.getAvailableMethods(countryCode, currency);
  @override
  Future<PaymentInit> initializePayment(PaymentAttempt attempt) async { await _wait(latency ~/ 2); return PaymentInit(providerPaymentReference: '${id}_${attempt.publicId.substring(attempt.publicId.length - 8)}_${_hex(3)}'); }
  @override
  Future<ClientPaymentResult> openPaymentExperience(PaymentAttempt attempt, {Future<void>? cancelled}) async {
    if (outcome == MockOutcome.timeout) {
      // never resolves on its own — the controller times out or the customer cancels
      if (cancelled != null) { await cancelled; return const ClientPaymentResult(ClientOutcome.cancelled); }
      await Future<void>.delayed(const Duration(hours: 1)); return const ClientPaymentResult(ClientOutcome.unknown, reason: 'timeout');
    }
    final done = _wait(latency);
    if (cancelled != null) { final first = await Future.any([done.then((_) => false), cancelled.then((_) => true)]); if (first) return const ClientPaymentResult(ClientOutcome.cancelled); } else { await done; }
    return switch (outcome) {
      MockOutcome.failure => ClientPaymentResult(ClientOutcome.failed, providerPaymentReference: attempt.providerPaymentReference, reason: 'declined'),
      MockOutcome.cancelled => ClientPaymentResult(ClientOutcome.cancelled, providerPaymentReference: attempt.providerPaymentReference),
      MockOutcome.pending => ClientPaymentResult(ClientOutcome.pending, providerPaymentReference: attempt.providerPaymentReference, reason: 'awaiting_confirmation'),
      MockOutcome.unknown => ClientPaymentResult(ClientOutcome.unknown, providerPaymentReference: attempt.providerPaymentReference, reason: 'connection_lost'),
      _ => ClientPaymentResult(ClientOutcome.success, providerPaymentReference: attempt.providerPaymentReference),
    };
  }
  @override
  Future<ClientPaymentResult> getClientPaymentResult(PaymentAttempt attempt) async {
    await _wait(latency ~/ 2);
    return switch (resolve) {
      MockResolve.failed => ClientPaymentResult(ClientOutcome.failed, providerPaymentReference: attempt.providerPaymentReference, reason: 'declined'),
      MockResolve.pending => ClientPaymentResult(ClientOutcome.pending, providerPaymentReference: attempt.providerPaymentReference),
      MockResolve.unknown => ClientPaymentResult(ClientOutcome.unknown, providerPaymentReference: attempt.providerPaymentReference),
      _ => ClientPaymentResult(ClientOutcome.success, providerPaymentReference: attempt.providerPaymentReference),
    };
  }
  @override
  Future<void> cancelPayment(PaymentAttempt attempt) => _wait(latency ~/ 4);
}

/// Market → provider (configuration-driven later: market, merchant entity, currency).
class MockPaymentProviderResolver implements PaymentProviderResolver {
  MockPaymentProviderResolver({this.latency = const Duration(milliseconds: 600)});
  final Duration latency;
  final Map<String, MockPaymentProvider> _providers = {};
  @override
  MockPaymentProvider resolve({required String countryCode, required String currency}) {
    final id = countryCode == 'IN' ? 'mock-razorpay' : 'mock-provider';
    return _providers.putIfAbsent(id, () => MockPaymentProvider(id: id, displayName: id == 'mock-razorpay' ? 'Razorpay (development sandbox)' : 'Payment provider (development sandbox)', latency: latency)..outcome = _defaultOutcome);
  }
  /// Applies a development outcome to every provider (development panel / preview query).
  void setOutcome(MockOutcome o) { for (final p in _providers.values) { p.outcome = o; } _defaultOutcome = o; }
  MockOutcome _defaultOutcome = MockOutcome.success;
  MockOutcome get outcome => _defaultOutcome;
}

/// Attempts + append-only history in the key-value store (recovery after app restart).
class MockPaymentRepository implements PaymentRepository {
  MockPaymentRepository(this._store);
  final KeyValueStore _store;
  static const key = 'fotg.payment.attempts';
  Future<List<PaymentAttempt>> _load() async { try { final raw = await _store.read(key); if (raw == null || raw.isEmpty) return []; return (jsonDecode(raw) as List).map((e) => PaymentAttempt.fromJson(e as Map<String, dynamic>)).toList(); } catch (_) { return []; } }
  Future<void> _save(List<PaymentAttempt> list) => _store.write(key, jsonEncode(list.length > 20 ? list.sublist(list.length - 20) : list)).catchError((_) {});
  @override
  Future<PaymentAttempt> createAttempt({required String checkoutReference, required String checkoutSnapshot, required String provider, required String currency, required int amountMinor, required String methodId, required String methodType, required String customerId, required String restaurantId}) async {
    final now = DateTime.now().toUtc();
    final a = PaymentAttempt(publicId: 'pay_dev_${_hex(6)}', checkoutReference: checkoutReference, checkoutSnapshot: checkoutSnapshot, provider: provider, currency: currency, amountMinor: amountMinor, status: PaymentStatus.preparing, methodId: methodId, methodType: methodType, customerId: customerId, restaurantId: restaurantId, createdAt: now, updatedAt: now, events: [PaymentEvent(at: now, status: PaymentStatus.preparing, note: 'created')]);
    final list = await _load(); list.add(a); await _save(list); return a;
  }
  @override
  Future<PaymentAttempt?> getAttempt(String publicId) async => (await _load()).where((a) => a.publicId == publicId).firstOrNull;
  @override
  Future<List<PaymentAttempt>> listForCheckout(String checkoutReference) async => (await _load()).where((a) => a.checkoutReference == checkoutReference).toList();
  @override
  Future<PaymentAttempt> transition(String publicId, PaymentStatus status, {String? providerPaymentReference, String? failureReason, DateTime? expiresAt, String? methodId, String? methodType, String? note}) async {
    final list = await _load(); final i = list.indexWhere((a) => a.publicId == publicId);
    if (i < 0) throw StateError('payment attempt not found');
    final now = DateTime.now().toUtc();
    final next = list[i].copyWith(status: status, providerPaymentReference: providerPaymentReference, failureReason: failureReason, expiresAt: expiresAt, methodId: methodId, methodType: methodType, updatedAt: now, events: [...list[i].events, PaymentEvent(at: now, status: status, note: note)]);
    list[i] = next; await _save(list); return next;
  }
}

/// DEVELOPMENT ONLY — simulates the server verification (CF-143 is the real one).
class MockPaymentVerificationService implements PaymentVerificationService {
  MockPaymentVerificationService({this.latency = const Duration(milliseconds: 600)});
  final Duration latency;
  PaymentStatus result = PaymentStatus.verified; // verified | pending | failed
  @override
  Future<VerificationResult> verify(PaymentAttempt attempt) async {
    if (latency != Duration.zero) await Future<void>.delayed(latency);
    return VerificationResult(result, reason: result == PaymentStatus.failed ? 'verification_failed' : null);
  }
}

/// Stable snapshot of what the customer validated — any change expires the active attempt.
String checkoutSnapshot(CheckoutRequest r) => [r.cartId, r.restaurantId, r.pickupSelection.requestedAt.toIso8601String(), r.pickupSelection.slotId ?? '', r.promoCode ?? '', r.currency, r.displayedTotalMinor].join('|');
