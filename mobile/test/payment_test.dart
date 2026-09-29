import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/checkout/checkout_models.dart';
import 'package:foodonthego/pickup/pickup_models.dart';
import 'package:foodonthego/state/payment_state.dart';

/// Module 12 — payment state machine (Android unit tests, deterministic mock provider).
void main() {
  final now = DateTime.utc(2026, 9, 29, 7, 30);
  CheckoutRequest req({String key = 'ck-1', String currency = 'INR', int total = 50000, String method = 'upi', String restaurant = 'burger-hub'}) => CheckoutRequest(
      idempotencyKey: key, customerId: 'u1', cartId: 'cart-1', restaurantId: restaurant, pickupSelection: PickupSelection(mode: PickupMode.asap, requestedAt: now.add(const Duration(minutes: 20)), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: now.add(const Duration(minutes: 20)), cartId: 'cart-1', restaurantId: restaurant),
      currency: currency, orderNote: '', termsAccepted: true, termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', acceptedAt: now, paymentMethodId: method, displayedTotalMinor: total, createdAt: now);

  (PaymentState, MockPaymentProviderResolver, MockPaymentVerificationService, MemoryKeyValueStore) make({MemoryKeyValueStore? store, Duration timeout = const Duration(seconds: 8)}) {
    final st = store ?? MemoryKeyValueStore();
    final resolver = MockPaymentProviderResolver(latency: Duration.zero);
    final verifier = MockPaymentVerificationService(latency: Duration.zero);
    final pay = PaymentState(resolver: resolver, repository: MockPaymentRepository(st), verifier: verifier, store: st, providerTimeout: timeout);
    return (pay, resolver, verifier, st);
  }
  Future<List<PaymentAttempt>> attempts(PaymentState p, String ref) => p.repo.listForCheckout(ref);

  test('TEST 1 — success: ready → pay → success (client) → verifying → verified; one attempt with a full history', () async {
    final (pay, resolver, _, _) = make();
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    expect(pay.status, PaymentStatus.ready); expect(pay.provider!.id, 'mock-razorpay'); expect(pay.method!.id, 'upi');
    expect(pay.attempt!.amountMinor, 50000); expect(pay.attempt!.currency, 'INR');
    resolver.setOutcome(MockOutcome.success);
    await pay.pay();
    expect(pay.status, PaymentStatus.verified);
    final list = await attempts(pay, 'ck-1');
    expect(list, hasLength(1));
    expect(list.single.events.map((e) => e.status).toList(), [PaymentStatus.preparing, PaymentStatus.ready, PaymentStatus.openingProvider, PaymentStatus.processing, PaymentStatus.successClientSide, PaymentStatus.verifying, PaymentStatus.verified]);
    final json = list.single.toJson().toString().toLowerCase();
    expect(json.contains('cvv') || json.contains('cardnumber') || json.contains('upipin') || json.contains('secret'), isFalse);
  });

  test('TEST 6 — double tap starts exactly one payment', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.success);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await Future.wait([pay.pay(), pay.pay(), pay.pay()]);
    final list = await attempts(pay, 'ck-1');
    expect(list, hasLength(1));
    expect(list.single.events.where((e) => e.status == PaymentStatus.openingProvider), hasLength(1));
  });

  test('TEST 2 / 7 — failure keeps the checkout; retry creates a second attempt for the same checkout, then succeeds', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.failure);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await pay.pay();
    expect(pay.status, PaymentStatus.failed); expect(pay.attempt!.failureReason, 'declined');
    resolver.setOutcome(MockOutcome.success);
    await pay.retry();
    expect(pay.status, PaymentStatus.ready);
    expect(await attempts(pay, 'ck-1'), hasLength(2));
    await pay.pay();
    expect((await attempts(pay, 'ck-1')).map((a) => a.status).toList(), [PaymentStatus.failed, PaymentStatus.verified]);
  });

  test('TEST 3 — cancelled: no charge implied, retry offered, attempt recorded as cancelled', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.cancelled);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await pay.pay();
    expect(pay.status, PaymentStatus.cancelled);
    expect(retryableStatuses.contains(pay.status), isTrue);
  });

  test('TEST 4 — pending stays pending until a status check resolves it (never auto-failed / auto-succeeded)', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.pending);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await pay.pay();
    expect(pay.status, PaymentStatus.pending);
    await pay.pay(); // ignored — not ready
    expect(await attempts(pay, 'ck-1'), hasLength(1));
    (pay.provider as MockPaymentProvider).resolve = MockResolve.pending;
    await pay.checkStatus();
    expect(pay.status, PaymentStatus.pending);
    (pay.provider as MockPaymentProvider).resolve = MockResolve.verified;
    await pay.checkStatus();
    expect(pay.status, PaymentStatus.verified);
  });

  test('TEST 5 — unknown (connection lost): check status, no second attempt', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.unknown);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await pay.pay();
    expect(pay.status, PaymentStatus.unknown); expect(pay.attempt!.failureReason, 'connection_lost');
    await pay.retry(); // ignored — unknown is not retryable
    expect(await attempts(pay, 'ck-1'), hasLength(1));
    (pay.provider as MockPaymentProvider).resolve = MockResolve.failed;
    await pay.checkStatus();
    expect(pay.status, PaymentStatus.failed);
  });

  test('TEST 15 — provider timeout becomes unknown, never failed', () async {
    final (pay, resolver, _, _) = make(timeout: const Duration(milliseconds: 50));
    resolver.setOutcome(MockOutcome.timeout);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await pay.pay();
    expect(pay.status, PaymentStatus.unknown); expect(pay.attempt!.failureReason, 'timeout');
  });

  test('TEST 12 — cancel while processing (back button) ends the attempt as cancelled', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.timeout); // experience stays open until cancelled
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    final paying = pay.pay();
    await Future<void>.delayed(Duration.zero);
    expect(pay.status, PaymentStatus.processing);
    await pay.cancel();
    await paying;
    expect(pay.status, PaymentStatus.cancelled);
  });

  test('TEST 8 — change method after failure: fresh attempt with the new method', () async {
    final (pay, resolver, _, _) = make();
    resolver.setOutcome(MockOutcome.failure);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    await pay.pay();
    await pay.changeMethod('card');
    expect(pay.status, PaymentStatus.ready); expect(pay.attempt!.methodId, 'card');
    expect(await attempts(pay, 'ck-1'), hasLength(2));
  });

  test('TEST 9 / 10 — currency propagates: USD attempt for a US restaurant uses the market provider without UPI', () async {
    final (pay, _, _, _) = make();
    await pay.prepare(req(currency: 'USD', total: 1899, method: 'card', restaurant: 'kettleman-diner'), countryCode: 'US', restaurantId: 'kettleman-diner');
    expect(pay.status, PaymentStatus.ready);
    expect(pay.attempt!.currency, 'USD'); expect(pay.attempt!.amountMinor, 1899); expect(pay.provider!.id, 'mock-provider');
    expect(pay.methods.any((m) => m.type == PaymentMethodType.upi), isFalse);
    expect(pay.methods.any((m) => m.type == PaymentMethodType.cashAtPickup), isFalse);
  });

  test('TEST 13 — app restart while processing recovers as unknown (no duplicate attempt, no fake success)', () async {
    final store = MemoryKeyValueStore();
    final (first, resolver, _, _) = make(store: store);
    resolver.setOutcome(MockOutcome.timeout);
    await first.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    final paying = first.pay(); await Future<void>.delayed(Duration.zero);
    expect(first.status, PaymentStatus.processing);
    // "kill" the app: a fresh controller over the same store
    final (second, _, _, _) = make(store: store);
    await second.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    expect(second.status, PaymentStatus.unknown);
    expect(await second.repo.listForCheckout('ck-1'), hasLength(1));
    await first.cancel(); await paying;
  });

  test('checkout changed: a new request supersedes the old attempt; a verified attempt is kept on restart', () async {
    final store = MemoryKeyValueStore();
    final (pay, _, _, _) = make(store: store);
    await pay.prepare(req(), countryCode: 'IN', restaurantId: 'burger-hub');
    final oldId = pay.attempt!.publicId;
    await pay.prepare(req(key: 'ck-2', total: 75000), countryCode: 'IN', restaurantId: 'burger-hub');
    expect((await pay.repo.getAttempt(oldId))!.status, PaymentStatus.expired);
    expect(pay.attempt!.checkoutReference, 'ck-2'); expect(pay.attempt!.amountMinor, 75000);
    await pay.pay();
    expect(pay.status, PaymentStatus.verified);
    final (again, _, _, _) = make(store: store);
    await again.prepare(req(key: 'ck-2', total: 75000), countryCode: 'IN', restaurantId: 'burger-hub');
    expect(again.status, PaymentStatus.verified);
    expect(await again.repo.listForCheckout('ck-2'), hasLength(1));
  });

  test('TEST 15 — mock provider outcomes are deterministic', () async {
    final p = MockPaymentProvider(latency: Duration.zero);
    final a = PaymentAttempt(publicId: 'pay_dev_x', checkoutReference: 'c', checkoutSnapshot: 's', provider: 'mock-razorpay', providerPaymentReference: 'ref', currency: 'INR', amountMinor: 1, status: PaymentStatus.ready, methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'r', createdAt: now, updatedAt: now, events: const []);
    for (final (o, e) in [(MockOutcome.success, ClientOutcome.success), (MockOutcome.failure, ClientOutcome.failed), (MockOutcome.cancelled, ClientOutcome.cancelled), (MockOutcome.pending, ClientOutcome.pending), (MockOutcome.unknown, ClientOutcome.unknown)]) {
      p.outcome = o;
      for (var i = 0; i < 3; i++) { expect((await p.openPaymentExperience(a)).outcome, e); }
    }
  });
}
