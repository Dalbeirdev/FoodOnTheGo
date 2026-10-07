/// Payments on the backend (Module 30) — Android: what the payment API is asked (the price the customer saw and, at
/// most, how they mean to pay — never an amount, a currency or a status; an idempotency key with the same-key
/// retry), how its answers are kept, how a provider's report is passed on for the backend to verify, the error
/// vocabulary, the options handed to the provider's screen, and the payment state on that API: pay, verify, cancel
/// and retry, pending, restart, no connection, refusals, double taps. Payloads are in the shape of the real API
/// (OpenAPI: PaymentEnvelope).
library;

import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/checkout/api_checkout.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/payment/api_payment.dart';
import 'package:foodonthego/payment/provider_launcher.dart';
import 'package:foodonthego/pickup/api_pickup.dart';
import 'package:foodonthego/state/payment_backend_state.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:razorpay_flutter/razorpay_flutter.dart' show Razorpay;

import 'api_checkout_test.dart' show checkoutDto, ck, failure, fp, inr, json, now, utc;

const pay1 = '7d000000-0000-4000-8000-000000000001';
const pay2 = '7d000000-0000-4000-8000-000000000002';

Map<String, dynamic> sessionDto({String provider = 'fake', String mode = 'FAKE'}) => {
      'provider': provider, 'mode': mode, 'key_id': provider == 'razorpay' ? 'rzp_test_PublicKeyId01' : 'fake_public_key', 'order_reference': provider == 'razorpay' ? 'order_TestOrder0001' : 'fakeorder_0001', 'amount': inr(50000),
      'name': 'FoodOnTheGo', 'description': 'Burger Hub — pickup order', 'theme_color': '#E85D04', 'checkout_script': null,
      'prefill': {'name': 'Asha Verma', 'contact': '+919876543212', 'email': null}, 'preferred_method': 'UPI', 'methods': null, 'timeout_seconds': 900,
    };

Map<String, dynamic> payDto({String id = pay1, String status = 'PROVIDER_READY', int attempt = 1, String? next, String provider = 'fake', String mode = 'FAKE', Map<String, dynamic>? failed, String? message, Map<String, dynamic>? method, int? retryAfter, String fulfillment = 'NOT_APPLICABLE', int amount = 50000}) => {
      'id': id, 'checkout_id': ck, 'attempt_number': attempt, 'status': status, 'amount': inr(amount), 'provider': {'name': provider, 'mode': mode}, 'requested_method_type': 'UPI', 'method': method, 'failure': failed, 'message': message,
      'next_action': next ?? (status == 'PROVIDER_READY' ? 'OPEN_PROVIDER' : 'WAIT'), 'retry_after_seconds': retryAfter, 'provider_session': status == 'PROVIDER_READY' ? sessionDto(provider: provider, mode: mode) : null,
      'fulfillment': {'status': fulfillment, 'order_id': null, 'order_creation': 'NOT_IMPLEMENTED'}, 'support_reference': 'PAY-${id.replaceAll('-', '').substring(0, 10).toUpperCase()}',
      'expires_at': utc(now.add(const Duration(minutes: 15))), 'server_time': utc(now), 'created_at': utc(now), 'updated_at': utc(now), 'verified_at': status == 'VERIFIED' ? utc(now) : null,
    };
Map<String, dynamic> verifiedDto({String id = pay1}) => payDto(id: id, status: 'VERIFIED', fulfillment: 'ORDER_PENDING', message: 'Payment received. We\'re confirming your order.', method: {'type': 'UPI', 'summary': {'vpa': 'as••@upi'}});
Map<String, dynamic> cancelledDto({String id = pay1}) => payDto(id: id, status: 'CANCELLED', next: 'RETRY_PAYMENT', failed: {'category': 'CUSTOMER_CANCELLED', 'code': 'PAYMENT_CANCELLED', 'message': 'The payment was cancelled. Nothing was charged.', 'retryable': true});
Map<String, dynamic> pendingDto({int? retryAfter = 5}) => payDto(status: 'PENDING', next: 'WAIT', retryAfter: retryAfter, message: 'We\'re confirming your payment. This can take a moment.');

/// The stubbed backend: answers by method and path, records every request.
class Net {
  final calls = <http.Request>[];
  /// Throw to play a lost connection.
  http.Response Function(http.Request request) answer = (_) => json(404, null);
  late final client = MockClient((request) async { calls.add(request); return answer(request); });
  Map<String, dynamic> body(http.Request r) => r.body.isEmpty ? {} : jsonDecode(r.body) as Map<String, dynamic>;
  List<http.Request> posts(String suffix) => [for (final c in calls) if (c.method == 'POST' && c.url.path.endsWith(suffix)) c];
  List<String> get paths => [for (final c in calls) '${c.method} ${c.url.path.replaceFirst('/api/v1', '')}'];

  /// Routes by path: the attempt, its status, a completion, the development simulator and the checkout.
  void route({Object Function()? start, Object Function()? show, Object Function(Map<String, dynamic> body)? complete, Object Function(Map<String, dynamic> body)? simulate, Object Function()? checkout}) {
    http.Response of(Object v, [int status = 200]) => v is http.Response ? v : v is Exception ? throw v : json(status, v);
    answer = (r) {
      final path = r.url.path;
      if (r.method == 'POST' && path.endsWith('/payment-attempts')) return of((start ?? () => {'payment': payDto()})(), 201);
      if (r.method == 'POST' && path.endsWith('/complete')) return of((complete ?? (_) => {'payment': verifiedDto()})(body(r)));
      if (r.method == 'POST' && path.endsWith('/simulate')) return of((simulate ?? (_) => {'simulation': {'client': {'outcome': 'success', 'provider_payment_reference': 'fakepay_ref0001', 'provider_signature': 'ab' * 32}}})(body(r)));
      if (r.method == 'GET' && path.contains('/payments/')) return of((show ?? () => {'payment': payDto()})());
      if (r.method == 'GET' && path.contains('/checkouts/')) return of((checkout ?? () => {'checkout': checkoutDto(status: 'PAYMENT_PENDING')})());
      return json(404, null);
    };
  }
}

final lost = Exception('connection lost');

({PaymentBackend payments, CheckoutBackend checkouts, Net net, MemoryKeyValueStore store}) gateway() {
  final net = Net();
  final store = MemoryKeyValueStore();
  store.write(ApiAuthRepository.tokenKey, 'customer-session');
  final cart = CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);
  return (payments: PaymentBackend(cart), checkouts: CheckoutBackend(cart), net: net, store: store);
}

final subject = PaymentSubject(checkoutId: ck, fingerprint: fp, currency: 'INR', totalMinor: 50000, methodType: 'UPI', pickupAt: now.add(const Duration(minutes: 75)), asap: false, timezone: 'Asia/Kolkata', restaurantName: 'Burger Hub');

/// A state on the stubbed backend. Without a [launcher] the provider has no screen here (the development sheet).
({PaymentBackendState pay, Net net, MemoryKeyValueStore store, CheckoutBackend checkouts}) state({ProviderLauncher? launcher, MemoryKeyValueStore? store, Duration? pollEvery}) {
  final g = gateway();
  final kept = store ?? MemoryKeyValueStore();
  final pay = PaymentBackendState(backend: g.payments, store: kept, launcher: (_) => launcher, pollEvery: pollEvery);
  addTearDown(pay.dispose);
  return (pay: pay, net: g.net, store: kept, checkouts: g.checkouts);
}

Future<void> settle([int ms = 30]) => Future<void>.delayed(Duration(milliseconds: ms));

void main() {
  tearDown(() { cartModeOverride = null; pickupModeOverride = null; checkoutModeOverride = null; paymentModeOverride = null; });

  group('payment mode', () {
    test('is the development payment unless the build says otherwise — and never the backend without the backend checkout', () {
      expect(paymentsFromApi, isFalse);
      cartModeOverride = 'api'; pickupModeOverride = 'api'; checkoutModeOverride = 'api';
      expect(paymentsFromApi, isFalse);
      paymentModeOverride = 'api';
      expect(paymentsFromApi, isTrue);
      checkoutModeOverride = 'mock';
      expect(paymentsFromApi, isFalse);
    });
  });

  group('payment API', () {
    test('keeps what the backend sent: the amount, the state, the next action and the provider session', () {
      final p = ServerPayment.fromJson(payDto(provider: 'razorpay', mode: 'TEST'));
      expect([p.id, p.checkoutId, p.attemptNumber, p.status, p.amountMinor, p.currency, p.provider, p.providerMode, p.nextAction, p.fulfillment], [pay1, ck, 1, 'PROVIDER_READY', 50000, 'INR', 'razorpay', 'TEST', 'OPEN_PROVIDER', 'NOT_APPLICABLE']);
      expect([p.session!.keyId, p.session!.orderReference, p.session!.amountMinor, p.session!.currency, p.session!.prefillContact, p.session!.preferredMethod, p.session!.timeoutSeconds], ['rzp_test_PublicKeyId01', 'order_TestOrder0001', 50000, 'INR', '+919876543212', 'UPI', 900]);
      expect([p.verified, p.unsettled, p.endedUnpaid, p.fake], [false, false, false, false]);
      final v = ServerPayment.fromJson(verifiedDto());
      expect([v.verified, v.fulfillment, v.session, v.methodType, v.methodSummary['vpa'], v.fake], [true, 'ORDER_PENDING', null, 'UPI', 'as••@upi', true]);
      expect(ServerPayment.fromJson(pendingDto()).unsettled, isTrue);
      expect(ServerPayment.fromJson(cancelledDto()).endedUnpaid, isTrue);
    });

    test('starts a payment by naming the price the customer saw — never an amount, a currency or a status', () async {
      final g = gateway(); g.net.route();
      final p = await g.payments.start(ck, fp, methodType: 'UPI');
      final call = g.net.calls.single;
      expect('${call.method} ${call.url.path}', 'POST /api/v1/checkouts/$ck/payment-attempts');
      expect(g.net.body(call), {'pricing_fingerprint': fp, 'payment_method': {'type': 'UPI'}});
      expect(call.headers['Authorization'], 'Bearer customer-session');
      expect(call.headers['Idempotency-Key'], startsWith('pay-'));
      expect(call.body, isNot(contains('amount'))); expect(call.body, isNot(contains('currency'))); expect(call.body, isNot(contains('status')));
      expect(p.amountMinor, 50000);
      await g.payments.start(ck, fp);
      expect(g.net.body(g.net.calls.last), {'pricing_fingerprint': fp});
    });

    test('tries a lost request once more with the same key, so a request that arrived creates no second attempt', () async {
      final g = gateway(); var n = 0;
      g.net.route(start: () => ++n == 1 ? lost : {'payment': payDto()});
      final p = await g.payments.start(ck, fp);
      expect(p.id, pay1);
      expect(g.net.calls, hasLength(2));
      expect(g.net.calls[0].headers['Idempotency-Key'], g.net.calls[1].headers['Idempotency-Key']);
    });

    test('reports what the provider said as a report to verify — the references for success, nothing invented otherwise', () async {
      final g = gateway(); g.net.route();
      await g.payments.complete(pay1, const ProviderReport.success(reference: 'pay_Test0001', signature: 'sig0001'));
      await g.payments.complete(pay1, const ProviderReport.failed(errorCode: 'BAD_REQUEST_ERROR', errorReason: 'payment_failed'));
      await g.payments.complete(pay1, const ProviderReport.failed());
      await g.payments.complete(pay1, const ProviderReport.cancelled());
      expect([for (final c in g.net.calls) g.net.body(c)], [
        {'outcome': 'success', 'provider_payment_reference': 'pay_Test0001', 'provider_signature': 'sig0001'},
        {'outcome': 'failed', 'provider_error': {'code': 'BAD_REQUEST_ERROR', 'reason': 'payment_failed'}},
        {'outcome': 'failed'},
        {'outcome': 'cancelled'},
      ]);
      expect(g.net.calls.every((c) => c.url.path == '/api/v1/payments/$pay1/complete'), isTrue);
      // A report carries no amount, no status and no "verified": those are the backend's to say.
      expect(g.net.calls.map((c) => c.body).join(), isNot(matches(RegExp('amount|status|verified|VERIFIED'))));
    });

    test('the development simulator hands back what the provider\'s screen would hand the app', () async {
      final g = gateway();
      g.net.route();
      final ok = await g.payments.simulate(pay1, 'SUCCESS');
      expect([ok!.outcome, ok.reference, ok.signature], ['success', 'fakepay_ref0001', 'ab' * 32]);
      expect(g.net.body(g.net.calls.last), {'scenario': 'SUCCESS', 'webhook': 'none'});
      g.net.route(simulate: (_) => {'simulation': {'client': {'outcome': 'failed', 'provider_error': {'code': 'BAD_REQUEST_ERROR', 'reason': 'payment_failed'}}}});
      final bad = await g.payments.simulate(pay1, 'FAILED');
      expect([bad!.outcome, bad.errorCode, bad.errorReason], ['failed', 'BAD_REQUEST_ERROR', 'payment_failed']);
      g.net.route(simulate: (_) => {'simulation': {'client': {'outcome': 'cancelled'}}});
      expect((await g.payments.simulate(pay1, 'CANCELLED'))!.outcome, 'cancelled');
      g.net.route(simulate: (_) => {'simulation': {'client': null}});
      expect(await g.payments.simulate(pay1, 'SUCCESS', webhook: 'only'), isNull);
      expect(g.net.body(g.net.calls.last), {'scenario': 'SUCCESS', 'webhook': 'only'});
    });

    test('maps the backend\'s refusals onto what the screen can act on', () async {
      Future<PaymentApiException> refusal(http.Response Function() answer) async {
        final g = gateway(); g.net.route(start: answer);
        try { await g.payments.start(ck, fp); } on PaymentApiException catch (e) { return e; }
        fail('expected a refusal');
      }
      final busy = await refusal(() => failure(409, 'payment_already_in_progress', 'A payment is in progress.', {'payment_id': pay1, 'next_action': 'WAIT'}));
      expect([busy.code, busy.paymentId, busy.checkoutGone], [PaymentErrorCode.inProgress, pay1, false]);
      expect((await refusal(() => failure(409, 'payment_already_verified', 'Paid.', {'payment_id': pay1}))).code, PaymentErrorCode.alreadyVerified);
      for (final (status, code, expected) in [(409, 'checkout_stale', PaymentErrorCode.checkoutStale), (410, 'checkout_expired', PaymentErrorCode.checkoutExpired), (409, 'payment_window_closed', PaymentErrorCode.windowClosed), (409, 'payment_attempt_limit_reached', PaymentErrorCode.attemptLimit), (409, 'payment_not_required', PaymentErrorCode.notRequired)]) {
        final e = await refusal(() => failure(status, code));
        expect([e.code, e.checkoutGone], [expected, true], reason: code);
      }
      expect((await refusal(() => failure(503, 'payment_provider_unavailable'))).code, PaymentErrorCode.providerUnavailable);
      expect((await refusal(() => failure(502, 'payment_initialization_unknown'))).code, PaymentErrorCode.initializationUnknown);
      expect((await refusal(() => failure(422, 'payment_method_unavailable'))).code, PaymentErrorCode.methodUnavailable);
      expect((await refusal(() => failure(429, 'rate_limited'))).code, PaymentErrorCode.rateLimited);
      expect((await refusal(() => failure(401, 'unauthenticated'))).code, PaymentErrorCode.unauthenticated);
      // No connection is "not known" — never "failed".
      final g = gateway(); g.net.route(show: () => lost);
      await expectLater(g.payments.show(pay1), throwsA(isA<PaymentApiException>().having((e) => e.code, 'code', PaymentErrorCode.offline)));
    });
  });

  group('the provider\'s screen', () {
    test('Razorpay is opened with the public key id, the backend\'s order and amount — and no secret', () {
      final options = razorpayOptions(ServerPayment.fromJson(payDto(provider: 'razorpay', mode: 'TEST')).session!);
      expect([options['key'], options['order_id'], options['amount'], options['currency'], options['timeout']], ['rzp_test_PublicKeyId01', 'order_TestOrder0001', 50000, 'INR', 900]);
      expect(options['prefill'], {'contact': '+919876543212', 'name': 'Asha Verma', 'method': 'upi'});
      expect(options['retry'], {'enabled': false});
      expect(jsonEncode(options), isNot(matches(RegExp('secret|webhook', caseSensitive: false))));
    });

    test('a closed screen is a cancellation, anything else a failure — a report either way, never a result', () {
      expect(razorpayErrorReport(Razorpay.PAYMENT_CANCELLED, 'Payment processing cancelled by user').outcome, 'cancelled');
      final network = razorpayErrorReport(Razorpay.NETWORK_ERROR, 'No internet');
      expect([network.outcome, network.errorCode, network.errorReason], ['failed', 'NETWORK_ERROR', 'No internet']);
      expect(razorpayErrorReport(null, null).errorCode, 'UNKNOWN_ERROR');
      expect(razorpayErrorReport(Razorpay.UNKNOWN_ERROR, 'x' * 500).errorReason, hasLength(200));
    });

    test('only Razorpay has a screen of its own; the development provider has none', () {
      expect(launcherFor('razorpay'), isNotNull);
      expect(launcherFor('fake'), isNull);
      expect(() => launchRazorpay(ProviderSession.fromJson({...sessionDto(provider: 'razorpay', mode: 'TEST'), 'key_id': null})), throwsA(isA<ProviderUnavailable>()));
    });
  });

  group('payment state on the backend', () {
    test('Pay asks the backend for an attempt; the provider\'s report is verified by the backend; VERIFIED is the backend\'s word — and still no order', () async {
      final s = state(); s.net.route();
      await s.pay.open(subject);
      expect([s.pay.view, s.pay.amountMinor, s.pay.currency, s.net.calls], [PayView.ready, 50000, 'INR', isEmpty]);
      await s.pay.pay();
      // The development provider has no screen: its sheet stands in for it. Nothing is verified by opening it.
      expect([s.pay.phase, s.pay.sheet, s.pay.view, s.pay.payment!.status], [PayPhase.providerOpen, true, PayView.processing, 'PROVIDER_READY']);
      await s.pay.simulate('SUCCESS');
      expect(s.net.paths, ['POST /checkouts/$ck/payment-attempts', 'POST /dev/payments/$pay1/simulate', 'POST /payments/$pay1/complete']);
      expect(s.net.body(s.net.calls.last), {'outcome': 'success', 'provider_payment_reference': 'fakepay_ref0001', 'provider_signature': 'ab' * 32});
      expect([s.pay.view, s.pay.payment!.verified, s.pay.payment!.fulfillment, s.pay.sheet, s.pay.inFlight], [PayView.verified, true, 'ORDER_PENDING', false, false]);
      // The phone keeps two references — no amount as a truth, no proof of payment.
      expect(jsonDecode((await s.store.read(PaymentBackendState.attemptKey))!), {'checkoutId': ck, 'paymentId': pay1});
    });

    test('a success the provider\'s screen reports is a claim: the app shows only what the backend answers', () async {
      final report = Completer<ProviderReport>();
      final s = state(launcher: (_) => report.future);
      s.net.route(start: () => {'payment': payDto(provider: 'razorpay', mode: 'TEST')}, complete: (_) => failure(422, 'payment_signature_invalid', 'This payment could not be verified.'), show: () => {'payment': payDto(provider: 'razorpay', mode: 'TEST')});
      await s.pay.open(subject);
      final paying = s.pay.pay();
      await settle();
      expect([s.pay.phase, s.pay.view, s.pay.sheet], [PayPhase.providerOpen, PayView.processing, false]);
      report.complete(const ProviderReport.success(reference: 'pay_Forged', signature: 'forged'));
      await paying;
      expect(s.net.body(s.net.posts('/complete').single), {'outcome': 'success', 'provider_payment_reference': 'pay_Forged', 'provider_signature': 'forged'});
      expect([s.pay.error!.code, s.pay.payment!.status, s.pay.payment!.verified, s.pay.view], [PaymentErrorCode.signatureInvalid, 'PROVIDER_READY', false, PayView.ready]);
    });

    test('a cancelled payment is cancelled — and paying again is a NEW attempt', () async {
      final s = state(); var starts = 0;
      s.net.route(start: () => {'payment': ++starts == 1 ? payDto() : payDto(id: pay2, attempt: 2)}, simulate: (_) => {'simulation': {'client': {'outcome': 'cancelled'}}}, complete: (_) => {'payment': cancelledDto()});
      await s.pay.open(subject); await s.pay.pay(); await s.pay.simulate('CANCELLED');
      expect([s.pay.view, s.pay.canRetry, s.pay.payment!.failureMessage, s.pay.mustReturn], [PayView.cancelled, true, 'The payment was cancelled. Nothing was charged.', false]);
      await s.pay.pay();
      expect(s.net.posts('/payment-attempts'), hasLength(2));
      expect([s.pay.payment!.id, s.pay.payment!.attemptNumber, s.pay.sheet], [pay2, 2, true]);
      expect(jsonDecode((await s.store.read(PaymentBackendState.attemptKey))!)['paymentId'], pay2);
    });

    test('a pending payment is waited for by asking the backend again — never by paying again', () async {
      final s = state(pollEvery: const Duration(milliseconds: 20)); var shows = 0;
      s.net.route(simulate: (_) => {'simulation': {'client': null}}, show: () => {'payment': ++shows < 3 ? pendingDto() : verifiedDto()});
      await s.pay.open(subject); await s.pay.pay(); await s.pay.simulate('PENDING');
      expect([s.pay.view, s.pay.canRetry, s.pay.payment!.message], [PayView.pending, false, 'We\'re confirming your payment. This can take a moment.']);
      await settle(200);
      expect([s.pay.view, shows >= 3, s.net.posts('/payment-attempts').length], [PayView.verified, true, 1]);
    });

    test('after a restart the payment is found again from the backend — nothing is opened or paid by itself', () async {
      final first = state(); first.net.route(simulate: (_) => {'simulation': {'client': null}}, show: () => {'payment': pendingDto()});
      await first.pay.open(subject); await first.pay.pay(); await first.pay.simulate('PENDING');
      expect(first.pay.view, PayView.pending);

      final again = state(store: first.store); again.net.route(show: () => {'payment': verifiedDto()});
      await again.pay.open(subject);
      expect(again.net.paths, ['GET /payments/$pay1']);
      expect([again.pay.view, again.pay.sheet, again.pay.inFlight], [PayView.verified, false, false]);
    });

    test('a phone that kept nothing finds the payment through the checkout the backend names', () async {
      final s = state(); s.net.route(checkout: () => {'checkout': {...checkoutDto(status: 'PAYMENT_PENDING'), 'payment': {'status': 'READY_FOR_PAYMENT', 'attempt': {'id': pay1, 'status': 'CANCELLED', 'attempt_number': 1}}}}, show: () => {'payment': cancelledDto()}, start: () => {'payment': payDto(id: pay2, attempt: 2)});
      await s.pay.resume(ck, s.checkouts);
      expect(s.net.paths, ['GET /checkouts/$ck', 'GET /payments/$pay1']);
      expect([s.pay.subject!.checkoutId, s.pay.subject!.fingerprint, s.pay.subject!.totalMinor, s.pay.subject!.restaurantName, s.pay.view], [ck, fp, 50000, 'Burger Hub', PayView.cancelled]);
      // Paying again names the price the BACKEND holds for that checkout.
      await s.pay.pay();
      expect(s.net.body(s.net.posts('/payment-attempts').single), {'pricing_fingerprint': fp});
    });

    test('says so when that checkout cannot be read', () async {
      final s = state(); s.net.route(checkout: () => failure(404, 'checkout_not_found'));
      await s.pay.resume(ck, s.checkouts);
      expect([s.pay.resumeFailed, s.pay.subject, s.pay.payment], [true, null, null]);
    });

    test('when a report cannot reach the backend the status needs confirmation — it is not "failed"', () async {
      final s = state(pollEvery: const Duration(hours: 1)); var online = false;
      s.net.route(complete: (_) => lost, show: () => online ? {'payment': verifiedDto()} : lost);
      await s.pay.open(subject); await s.pay.pay(); await s.pay.simulate('SUCCESS');
      expect([s.pay.unconfirmed, s.pay.view, s.pay.canRetry, s.pay.payment!.status], [true, PayView.unknown, false, 'PROVIDER_READY']);
      await s.pay.refresh(manual: true);
      expect([s.pay.unconfirmed, s.pay.view], [true, PayView.unknown]);
      online = true;
      await s.pay.refresh(manual: true);
      expect([s.pay.unconfirmed, s.pay.view], [false, PayView.verified]);
      expect(s.net.posts('/payment-attempts'), hasLength(1));
    });

    test('a known payment that cannot be asked about on arrival is "needs confirmation", and is asked about again', () async {
      final kept = MemoryKeyValueStore(); await kept.write(PaymentBackendState.attemptKey, jsonEncode({'checkoutId': ck, 'paymentId': pay1}));
      final s = state(store: kept, pollEvery: const Duration(hours: 1)); var online = false;
      s.net.route(show: () => online ? {'payment': pendingDto()} : lost);
      await s.pay.open(subject);
      expect([s.pay.payment, s.pay.unconfirmed, s.pay.view], [null, true, PayView.unknown]);
      online = true; s.pay.resumed(); await settle();
      expect([s.pay.payment!.status, s.pay.unconfirmed, s.pay.view], ['PENDING', false, PayView.pending]);
      expect(s.net.posts('/payment-attempts'), isEmpty);
    });

    test('shows the payment that already exists when the backend refuses to start a second one', () async {
      final s = state(pollEvery: const Duration(hours: 1));
      s.net.route(start: () => failure(409, 'payment_already_in_progress', 'A payment is in progress.', {'payment_id': pay1}), show: () => {'payment': pendingDto()});
      await s.pay.open(subject); await s.pay.pay();
      expect([s.pay.error, s.pay.payment!.id, s.pay.view, s.pay.inFlight], [null, pay1, PayView.pending, false]);
    });

    test('sends the customer back to review the order when the checkout changed or ran out — and charges nothing', () async {
      final s = state(); s.net.route(start: () => failure(409, 'checkout_stale', 'Your order changed.'));
      await s.pay.open(subject); await s.pay.pay();
      expect([s.pay.view, s.pay.checkoutGone, s.pay.mustReturn, s.pay.payment], [PayView.error, true, true, null]);
      await s.pay.forget();
      expect([s.pay.subject, await s.store.read(PaymentBackendState.attemptKey)], [null, null]);
    });

    test('says payments are unavailable when the provider is — nothing is marked as failed, and it can be tried again', () async {
      final s = state(); var up = false;
      s.net.route(start: () => up ? {'payment': payDto()} : failure(503, 'payment_provider_unavailable'));
      await s.pay.open(subject); await s.pay.pay();
      expect([s.pay.error!.code, s.pay.view, s.pay.mustReturn, s.pay.payment], [PaymentErrorCode.providerUnavailable, PayView.error, false, null]);
      up = true; await s.pay.pay();
      expect([s.pay.error, s.pay.sheet, s.pay.payment!.status], [null, true, 'PROVIDER_READY']);
    });

    test('a second tap while the first is on its way starts nothing', () async {
      final s = state(); s.net.route();
      await s.pay.open(subject);
      await Future.wait([s.pay.pay(), s.pay.pay(), s.pay.pay()]);
      expect(s.net.posts('/payment-attempts'), hasLength(1));
    });

    test('a provider screen that cannot be opened is said so — nothing was paid and the attempt can be opened again', () async {
      final s = state(launcher: (_) => throw const ProviderUnavailable('provider_screen_failed'));
      s.net.route(start: () => {'payment': payDto(provider: 'razorpay', mode: 'TEST')});
      await s.pay.open(subject); await s.pay.pay();
      expect([s.pay.providerError, s.pay.view, s.pay.payment!.status, s.pay.inFlight, s.net.posts('/complete')], [true, PayView.ready, 'PROVIDER_READY', false, isEmpty]);
    });

    test('when the provider\'s screen is gone without a word, the app stops waiting and asks the backend', () async {
      final never = Completer<ProviderReport>();
      final s = state(launcher: (_) => never.future, pollEvery: const Duration(hours: 1));
      s.net.route(start: () => {'payment': payDto(provider: 'razorpay', mode: 'TEST')}, show: () => {'payment': pendingDto()});
      await s.pay.open(subject);
      s.pay.pay(); await settle();
      expect(s.pay.phase, PayPhase.providerOpen);
      await s.pay.leftProvider();
      expect([s.pay.phase, s.pay.view, s.net.paths.last, s.net.posts('/complete')], [PayPhase.idle, PayView.pending, 'GET /payments/$pay1', isEmpty]);
      // A report that still arrives is still sent — the backend decides what it is worth.
      never.complete(const ProviderReport.cancelled()); await settle();
      expect(s.net.posts('/complete'), hasLength(1));
    });

    test('signing out forgets the payment on this phone', () async {
      final s = state(); s.net.route();
      await s.pay.open(subject); await s.pay.pay();
      expect(await s.store.read(PaymentBackendState.attemptKey), isNotNull);
      s.pay.reset(); await settle(5);
      expect([s.pay.subject, s.pay.payment, await s.store.read(PaymentBackendState.attemptKey)], [null, null, null]);
    });
  });
}
