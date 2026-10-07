/// Checkout on the backend (Module 29) — Android: the mapping of the checkout API onto the app model (every amount is
/// the backend's), what the gateway sends (a promotion code, the documents shown, a payment-method type, a note and
/// the fingerprint of the price on the screen — never an amount, a currency, a customer or a credential; an
/// idempotency key with the same-key retry), the error vocabulary and the checkout state on that gateway (open,
/// blocked, promotion, confirm, stale, legal version, expired, zero total, NO CONNECTION = nothing is confirmed).
/// Payloads are in the shape of the real API (OpenAPI: CheckoutEnvelope).
library;

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/checkout/api_checkout.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/pickup/api_pickup.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart' hide PromoStatus, PromoState;
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const ck = '9c000000-0000-4000-8000-000000000001';
final fp = 'a' * 64;
final now = DateTime.utc(2026, 10, 7, 6, 30);
String utc(DateTime d) => '${d.toUtc().toIso8601String().split('.').first}+00:00';
Map<String, dynamic> inr(int amount) => {'amount': amount, 'currency': 'INR'};
http.Response json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});
http.Response failure(int status, String code, [String message = 'x', Map<String, dynamic> details = const {}]) => json(status, {'error': {'code': code, 'message': message, 'details': details, 'request_id': 'req-1'}});

final legal = [
  {'type': 'TERMS', 'version': 'draft-2026-09', 'effective_date': null, 'status': 'DRAFT_PENDING_APPROVAL', 'slug': 'terms'},
  {'type': 'PRIVACY', 'version': 'draft-2026-09', 'effective_date': null, 'status': 'DRAFT_PENDING_APPROVAL', 'slug': 'privacy'},
  {'type': 'REFUND', 'version': 'draft-2026-09', 'effective_date': null, 'status': 'DRAFT_PENDING_APPROVAL', 'slug': 'refund-policy'},
];

/// 2 Large burgers of ₹250: ₹500, nothing configured, nothing applied.
Map<String, dynamic> checkoutDto({String id = ck, String status = 'DRAFT', int version = 1, Map<String, dynamic> pricing = const {}, Map<String, dynamic>? promotion, List<Map<String, dynamic>>? documents, List<Map<String, dynamic>> issues = const [], List<Map<String, dynamic>> changes = const [], int expiresInMinutes = 15, bool held = false, Map<String, dynamic>? payment, List<Map<String, dynamic>> saved = const []}) => {
      'id': id, 'status': status, 'status_reason': null, 'version': version, 'expires_at': utc(now.add(Duration(minutes: expiresInMinutes))), 'server_time': utc(now), 'created_at': utc(now), 'cart_version': 4,
      'restaurant': {'id': 'r1', 'slug': 'burger-hub', 'name': 'Burger Hub', 'timezone': 'Asia/Kolkata'},
      'pickup': {'mode': 'SCHEDULED', 'method': 'COUNTER', 'pickup_at': utc(now.add(const Duration(minutes: 75))), 'local_pickup_at': '2026-10-07T13:15:00+05:30', 'local_date': '2026-10-07', 'slot_starts_at': utc(now.add(const Duration(minutes: 75))), 'slot_ends_at': utc(now.add(const Duration(minutes: 90))), 'timezone': 'Asia/Kolkata', 'estimated_prep_minutes': 20, 'traveler_arrival_at': null, 'hold': {'held': held, 'expires_at': held ? utc(now.add(Duration(minutes: expiresInMinutes))) : null}},
      'items': [{'id': 'l1', 'item': {'id': 'i1', 'slug': 'classic-burger', 'name': 'Classic Burger', 'image': null, 'version': 3}, 'name': 'Classic Burger', 'quantity': 2, 'special_instructions': 'No onions', 'variants': [{'group_id': 'g1', 'group_name': 'Size', 'kind': 'VARIANT', 'options': [{'id': 'o1', 'name': 'Large', 'price_adjustment_minor': 5000, 'available': true}]}], 'modifiers': [], 'unit_price_minor': 25000, 'line_subtotal_minor': 50000, 'currency': 'INR'}],
      'item_count': 2,
      'pricing': {'currency': 'INR', 'items_subtotal': inr(50000), 'discounts': [], 'discount_total': inr(0), 'taxes': [], 'tax_total': inr(0), 'tax_included_total': inr(0), 'fees': [], 'fee_total': inr(0), 'total': inr(50000), 'payment_required': true, 'fingerprint': fp, 'configuration': {'tax': 'NOT_CONFIGURED', 'fees': 'NOT_CONFIGURED'}, ...pricing},
      'promotion': promotion,
      'customer': {'name': 'Asha Verma', 'phone': '+919876543212', 'phone_verified': true, 'email': null},
      'order_note': null,
      'legal': {'required': documents ?? legal, 'accepted': status == 'READY' ? [for (final d in documents ?? legal) {'type': d['type'], 'version': d['version'], 'status': d['status'], 'accepted_at': utc(now)}] : [], 'final_approval': false},
      'payment_methods': {'provider_status': 'READY', 'available': [{'type': 'UPI', 'status': 'AVAILABLE'}, {'type': 'CARD', 'status': 'AVAILABLE'}], 'saved': saved, 'selected': null, 'cash_at_pickup': 'NOT_APPROVED'},
      'payment': payment, 'issues': issues, 'changes': changes,
    };

/// ₹500 − ₹100 + ₹15 fee + 2 × 2.5 % test tax on ₹400 = ₹435.
Map<String, dynamic> pricedDto({String status = 'DRAFT', bool held = false, Map<String, dynamic>? payment}) => checkoutDto(status: status, held: held, payment: payment, version: status == 'READY' ? 2 : 1, expiresInMinutes: status == 'READY' ? 10 : 15, promotion: {'code': 'SAVE100', 'name': '₹100 off', 'discount': inr(10000)}, pricing: {
      'discounts': [{'code': 'SAVE100', 'label': '₹100 off', 'amount': inr(10000)}], 'discount_total': inr(10000),
      'taxes': [
        {'code': 'TEST_TAX_A', 'label': 'Test tax A', 'applies_to': 'MERCHANDISE', 'rate_basis_points': 250, 'taxable_amount': inr(40000), 'amount': inr(1000), 'included_in_prices': false},
        {'code': 'TEST_TAX_B', 'label': 'Test tax B', 'applies_to': 'MERCHANDISE', 'rate_basis_points': 250, 'taxable_amount': inr(40000), 'amount': inr(1000), 'included_in_prices': false},
      ],
      'tax_total': inr(2000), 'fees': [{'code': 'PLATFORM_FEE', 'label': 'Test platform fee', 'amount': inr(1500), 'taxable': false}], 'fee_total': inr(1500), 'total': inr(43500), 'fingerprint': 'b' * 64, 'configuration': {'tax': 'TEST', 'fees': 'TEST'},
    });
Map<String, dynamic> paymentDto(int amount, {String status = 'READY_FOR_PAYMENT'}) => {'status': status, 'checkout_id': ck, 'checkout_version': 2, 'amount': inr(amount), 'currency': 'INR', 'pricing_fingerprint': fp, 'idempotency_key': 'pay_${'c' * 40}', 'payment_method': status == 'READY_FOR_PAYMENT' ? {'type': 'UPI', 'saved_method_id': null} : null, 'expires_at': utc(now.add(const Duration(minutes: 10))), 'provider': {'status': 'READY', 'name': 'fake', 'mode': 'FAKE'}, 'attempt': null};
Map<String, dynamic> issueDto(String code, String recover, [String? message]) => {'code': code, 'message': message ?? '$code message', 'recover': recover, 'details': <String, dynamic>{}};

class Recorder {
  final calls = <http.Request>[];
  http.Response Function(http.Request request) answer = (_) => json(201, {'checkout': checkoutDto()});
  late final client = MockClient((request) async { calls.add(request); return answer(request); });
  Map<String, dynamic> body(http.Request r) => r.body.isEmpty ? {} : jsonDecode(r.body) as Map<String, dynamic>;
}

({CheckoutBackend checkout, Recorder net}) gateway() {
  final net = Recorder();
  final store = MemoryKeyValueStore();
  store.write(ApiAuthRepository.tokenKey, 'customer-session');
  final cart = CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);
  return (checkout: CheckoutBackend(cart), net: net);
}

/// A scripted checkout backend for the state tests: every step answers from a queue or a fixed answer, and is logged.
class Scripted extends CheckoutBackend {
  Scripted() : super(CartBackend(store: MemoryKeyValueStore(), client: ApiClient(client: MockClient((_) async => json(200, {'cart': null})), baseUrl: 'http://api.test/api/v1')));
  final log = <String>[];
  Object Function(String step, Map<String, dynamic> args) on = (step, args) => checkoutDto();
  Future<ServerCheckout> _step(String step, [Map<String, dynamic> args = const {}]) async {
    log.add(step);
    final answer = on(step, args);
    if (answer is Map<String, dynamic>) return ServerCheckout.fromJson(answer);
    throw toCheckoutException(answer);
  }
  Map<String, dynamic>? lastConfirm;
  @override
  Future<ServerCheckout> open({String? promotionCode}) => _step('open', {'promotion_code': promotionCode});
  @override
  Future<ServerCheckout> show(String id) => _step('show', {'id': id});
  @override
  Future<ServerCheckout> applyPromotion(String id, String code) => _step('promotion', {'id': id, 'code': code});
  @override
  Future<ServerCheckout> removePromotion(String id) => _step('remove', {'id': id});
  @override
  Future<ServerCheckout> confirm(String id, {required String fingerprint, required List<LegalDocument> documents, String? methodType, String? savedMethodId, String orderNote = ''}) {
    lastConfirm = {'id': id, 'fingerprint': fingerprint, 'documents': [for (final d in documents) '${d.type}@${d.version}'], 'type': methodType, 'saved': savedMethodId, 'note': orderNote};
    return _step('confirm', lastConfirm!);
  }
}

ApiException refused(int status, String code, [String message = 'refused', Map<String, dynamic> details = const {}]) => ApiException(status, message, code: code, details: details);
final offline = ApiException(0, 'No connection');

void main() {
  setUp(() { cartModeOverride = 'api'; pickupModeOverride = 'api'; checkoutModeOverride = null; });
  tearDown(() { cartModeOverride = null; pickupModeOverride = null; checkoutModeOverride = null; });

  group('checkout mode', () {
    test('the development checkout unless the build says otherwise — and never the backend without the backend cart and pickup', () {
      expect(checkoutFromApi, isFalse);
      checkoutModeOverride = 'api';
      expect(checkoutFromApi, isTrue);
      pickupModeOverride = 'mock';
      expect(checkoutFromApi, isFalse);
      pickupModeOverride = 'api'; cartModeOverride = 'mock';
      expect(checkoutFromApi, isFalse);
    });
  });

  group('checkout API mapping', () {
    test('keeps every figure the backend sent, and adds none', () {
      final c = ServerCheckout.fromJson(pricedDto(), receivedAt: now);
      expect([c.id, c.status, c.version, c.cartVersion, c.itemCount], [ck, 'DRAFT', 1, 4, 2]);
      expect([c.currency, c.subtotalMinor, c.discountMinor, c.taxMinor, c.taxIncludedMinor, c.feeMinor, c.totalMinor, c.paymentRequired], ['INR', 50000, 10000, 2000, 0, 1500, 43500, true]);
      expect([for (final t in c.taxes) '${t.code}|${t.label}|${t.rateBasisPoints}|${t.amountMinor}|${t.included}'], ['TEST_TAX_A|Test tax A|250|1000|false', 'TEST_TAX_B|Test tax B|250|1000|false']);
      expect([for (final f in c.fees) '${f.code}|${f.label}|${f.amountMinor}'], ['PLATFORM_FEE|Test platform fee|1500']);
      expect([c.promotionCode, c.promotionName, c.taxConfiguration, c.feeConfiguration, c.testConfiguration], ['SAVE100', '₹100 off', 'TEST', 'TEST', true]);
      expect([c.items.single.name, c.items.single.quantity, c.items.single.unitPriceMinor, c.items.single.lineSubtotalMinor, c.items.single.options, c.items.single.specialInstructions], ['Classic Burger', 2, 25000, 50000, ['Size: Large'], 'No onions']);
      expect([c.restaurantName, c.timezone, c.asap, c.held, c.customerPhone, c.phoneVerified], ['Burger Hub', 'Asia/Kolkata', false, false, '+919876543212', true]);
      expect(c.legalSignature, 'TERMS@draft-2026-09|PRIVACY@draft-2026-09|REFUND@draft-2026-09');
      expect([c.legalFinalApproval, c.providerStatus, c.methodTypes, c.paymentStatus], [false, 'READY', ['UPI', 'CARD'], null]);
    });

    test('a confirmed checkout carries its hold and its payment preparation; nothing of a provider or a credential', () {
      final c = ServerCheckout.fromJson(pricedDto(status: 'READY', held: true, payment: paymentDto(43500)), receivedAt: now);
      expect([c.status, c.held, c.holdExpiresAt, c.paymentStatus, c.paymentKey], ['READY', true, now.add(const Duration(minutes: 10)), 'READY_FOR_PAYMENT', 'pay_${'c' * 40}']);
      expect(c.acceptedAt, now);
      // No payment attempt yet; once there is one the checkout names it — how a restarted phone finds its payment.
      expect(c.paymentAttemptId, isNull);
      final paying = ServerCheckout.fromJson(checkoutDto(status: 'PAYMENT_PENDING', payment: {...paymentDto(50000), 'attempt': {'id': 'pay-attempt-1', 'status': 'PENDING', 'attempt_number': 1}}), receivedAt: now);
      expect([paying.status, paying.paymentAttemptId, paying.open], ['PAYMENT_PENDING', 'pay-attempt-1', false]);
    });

    test('the time left is counted on the server clock, whatever this phone thinks the time is', () {
      // The phone's clock is three days off; the answer arrived at that (wrong) time.
      final device = DateTime(2026, 10, 10, 9);
      final c = ServerCheckout.fromJson(checkoutDto(), receivedAt: device);
      expect(c.remaining(device), const Duration(minutes: 15));
      expect(c.remaining(device.add(const Duration(minutes: 1))), const Duration(minutes: 14));
      expect(c.remaining(device.add(const Duration(minutes: 16))).isNegative, isTrue);
    });
  });

  group('checkout gateway', () {
    test('opens a checkout without naming a cart, a restaurant or an amount, as the signed-in customer, with an idempotency key', () async {
      final g = gateway();
      final c = await g.checkout.open();
      expect(c.totalMinor, 50000);
      final r = g.net.calls.single;
      expect([r.method, r.url.path, g.net.body(r)], ['POST', '/api/v1/checkouts', <String, dynamic>{}]);
      expect(r.headers['Authorization'], 'Bearer customer-session');
      expect(r.headers['Idempotency-Key'], startsWith('cko-'));
      expect(r.headers.containsKey('X-Cart-Token'), isFalse);
      await g.checkout.open(promotionCode: ' save100 ');
      expect(g.net.body(g.net.calls.last), {'promotion_code': 'save100'});
    });

    test('asks once more with the SAME key when only the answer was lost', () async {
      final g = gateway();
      var n = 0;
      g.net.answer = (_) { n++; if (n == 1) throw http.ClientException('connection lost'); return json(201, {'checkout': checkoutDto()}); };
      final c = await g.checkout.open();
      expect(c.id, ck);
      expect(g.net.calls.length, 2);
      expect(g.net.calls[1].headers['Idempotency-Key'], g.net.calls[0].headers['Idempotency-Key']);
    });

    test('confirms with the fingerprint, the documents, a method type and a note — and nothing else', () async {
      final g = gateway();
      g.net.answer = (_) => json(200, {'checkout': checkoutDto(status: 'READY', version: 2, held: true, payment: paymentDto(50000))});
      final docs = ServerCheckout.fromJson(checkoutDto()).legal;
      await g.checkout.confirm(ck, fingerprint: fp, documents: docs, methodType: 'CARD', savedMethodId: 'saved-1', orderNote: 'Extra napkins');
      final r = g.net.calls.single;
      expect(r.url.path, '/api/v1/checkouts/$ck/confirm');
      expect(g.net.body(r), {
        'pricing_fingerprint': fp,
        'accepted_documents': [{'type': 'TERMS', 'version': 'draft-2026-09'}, {'type': 'PRIVACY', 'version': 'draft-2026-09'}, {'type': 'REFUND', 'version': 'draft-2026-09'}],
        'payment_method': {'type': 'CARD', 'saved_method_id': 'saved-1'}, 'order_note': 'Extra napkins',
      });
      expect(r.headers['Idempotency-Key'], startsWith('ckc-'));
      // A total of zero: no payment method, no empty note.
      await g.checkout.confirm(ck, fingerprint: fp, documents: docs, orderNote: '   ');
      expect(g.net.body(g.net.calls.last).keys, ['pricing_fingerprint', 'accepted_documents']);
      for (final c in g.net.calls) { expect(c.body, isNot(matches(RegExp('amount|total|subtotal|currency|discount|customer|cart_id|restaurant')))); }
    });

    test('applies and removes a promotion by its code only; reads and cancels by id', () async {
      final g = gateway();
      g.net.answer = (_) => json(200, {'checkout': pricedDto()});
      expect((await g.checkout.applyPromotion(ck, 'save100')).discountMinor, 10000);
      await g.checkout.removePromotion(ck); await g.checkout.show(ck); await g.checkout.cancel(ck);
      expect([for (final c in g.net.calls) '${c.method} ${c.url.path} ${c.body}'], ['POST /api/v1/checkouts/$ck/promotion {"code":"save100"}', 'DELETE /api/v1/checkouts/$ck/promotion ', 'GET /api/v1/checkouts/$ck ', 'POST /api/v1/checkouts/$ck/cancel ']);
    });

    test('tells what the backend refused, and where the customer goes to put it right', () async {
      final g = gateway();
      Future<CheckoutApiException> fails(http.Response r, Future<Object?> Function() call) async { g.net.answer = (_) => r; try { await call(); } on CheckoutApiException catch (e) { return e; } fail('expected a refusal'); }
      var e = await fails(failure(409, 'item_unavailable', 'Sold out.', {'issues': [issueDto('ITEM_UNAVAILABLE', 'cart', 'Classic Burger is sold out.')]}), g.checkout.open);
      expect([e.code, e.serverCode, e.cart, e.issues.single.recover, e.issues.single.message], [CheckoutErrorCode.itemUnavailable, 'item_unavailable', true, 'cart', 'Classic Burger is sold out.']);
      e = await fails(failure(409, 'pickup_slot_full', 'Full.', {'issues': [issueDto('PICKUP_SLOT_FULL', 'pickup')]}), g.checkout.open);
      expect([e.code, e.pickup, e.issues.single.recover], [CheckoutErrorCode.pickupFull, true, 'pickup']);
      e = await fails(failure(409, 'checkout_stale', 'Out of date.', {'reason': 'PRICE_CHANGED'}), () => g.checkout.show(ck));
      expect([e.code, e.replaced], [CheckoutErrorCode.stale, true]);
      expect((await fails(failure(410, 'checkout_expired'), () => g.checkout.show(ck))).code, CheckoutErrorCode.expired);
      expect((await fails(failure(404, 'checkout_not_found'), () => g.checkout.show(ck))).replaced, isTrue);
      expect((await fails(failure(422, 'legal_acceptance_required'), () => g.checkout.show(ck))).code, CheckoutErrorCode.legal);
      expect((await fails(failure(422, 'payment_method_unavailable'), () => g.checkout.show(ck))).code, CheckoutErrorCode.paymentMethod);
      expect((await fails(failure(409, 'payment_in_progress'), g.checkout.open)).code, CheckoutErrorCode.paymentInProgress);
      expect((await fails(failure(401, 'unauthenticated'), g.checkout.open)).code, CheckoutErrorCode.unauthenticated);
      // Without a connection nothing about a price is known — an old total is not a current one.
      g.net.answer = (_) => throw http.ClientException('offline');
      try { await g.checkout.show(ck); fail('expected a refusal'); } on CheckoutApiException catch (x) { expect(x.code, CheckoutErrorCode.offline); }
    });

    test('maps a refused promotion code onto what the screen says — nothing more specific than the backend said', () {
      PromoStatus of(String code, [int status = 422]) => promoStatusOf(toCheckoutException(refused(status, code)));
      expect([of('promotion_not_found'), of('promotion_expired'), of('promotion_not_applicable'), of('promotion_minimum_not_met'), of('promotion_limit_reached'), of('promotion_customer_limit_reached'), of('rate_limited', 429)],
          [PromoStatus.invalid, PromoStatus.expired, PromoStatus.notEligible, PromoStatus.minSpend, PromoStatus.limitReached, PromoStatus.alreadyUsed, PromoStatus.rateLimited]);
    });
  });

  group('checkout state on the backend', () {
    AddItemInput burger() => AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: 2, unitPriceMinor: 25000);
    Future<({CheckoutState co, Scripted api, AuthState auth, CartState cart, PickupState pk, MockConnectivityService net, MemoryKeyValueStore store})> boot({bool signedIn = true}) async {
      final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
      if (signedIn) { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }
      final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger());
      final pk = PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore());
      final net = MockConnectivityService(); final api = Scripted(); final store = MemoryKeyValueStore();
      final co = CheckoutState(connectivity: net, store: store, backend: api);
      await Future<void>.delayed(Duration.zero);
      return (co: co, api: api, auth: auth, cart: cart, pk: pk, net: net, store: store);
    }

    test('shows the checkout the backend made: its lines, its total, its methods — and calculates nothing', () async {
      final t = await boot();
      t.api.on = (_, _) => pricedDto();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.backendMode, t.co.status, t.api.log], [true, CheckoutStatus.ready, ['open']]);
      final s = t.co.summary!;
      expect([s.source, s.currency, s.itemCount, s.subtotalMinor, s.discountMinor, s.totalMinor, s.paymentEligible], ['api', 'INR', 2, 50000, 10000, 43500, true]);
      expect([for (final l in s.taxes) '${l.label}|${l.amountMinor}|${l.rateBasisPoints}|${l.included}'], ['Test tax A|1000|250|false', 'Test tax B|1000|250|false']);
      expect([for (final l in s.fees) '${l.label}|${l.amountMinor}'], ['Test platform fee|1500']);
      expect([t.co.promo?.code, t.co.promo?.applied, t.co.promo?.discountMinor], ['SAVE100', true, 10000]);
      expect([for (final m in t.co.methods) m.id], ['upi', 'card']);
      expect(t.co.paymentMethodId, 'upi');
      // Before the terms are accepted the checkout cannot be continued, and nothing is sent.
      expect([for (final i in t.co.issues(authenticated: true, cart: t.cart.cart, selection: null)) if (i.blocking) i.code], [CheckoutIssueCode.terms]);
      expect(await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk), isNull);
      expect(t.api.log, ['open']);
    });

    test('a guest is asked to sign in; an empty cart has no checkout', () async {
      final guest = await boot(signedIn: false);
      await guest.co.prepare(auth: guest.auth, cart: guest.cart, pickup: guest.pk);
      expect([guest.co.status, guest.api.log], [CheckoutStatus.requiresAuth, <String>[]]);
      final t = await boot();
      t.cart.clearCart();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.status, t.co.server, t.api.log], [CheckoutStatus.invalidCart, null, <String>[]]);
    });

    test('is blocked with the backend\'s reason and the way back when the cart or the pickup time is not valid', () async {
      final t = await boot();
      t.api.on = (_, _) => refused(409, 'item_unavailable', 'Sold out.', {'issues': [issueDto('ITEM_UNAVAILABLE', 'cart', 'Classic Burger is sold out.')]});
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.status, t.co.server, t.co.summary], [CheckoutStatus.invalidCart, null, null]);
      var issue = t.co.issues(authenticated: true, cart: t.cart.cart, selection: null).firstWhere((i) => i.blocking);
      expect([issue.code, issue.message, issue.recover, issue.detail], [CheckoutIssueCode.itemUnavailable, 'Classic Burger is sold out.', 'cart', 'ITEM_UNAVAILABLE']);

      t.api.on = (_, _) => refused(409, 'pickup_slot_full', 'Taken.', {'issues': [issueDto('PICKUP_SLOT_FULL', 'pickup', 'This pickup time has just been taken.')]});
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      issue = t.co.issues(authenticated: true, cart: t.cart.cart, selection: null).firstWhere((i) => i.blocking);
      expect([t.co.status, issue.code, issue.recover], [CheckoutStatus.invalidPickup, CheckoutIssueCode.pickupFull, 'pickup']);

      // No connection: nothing is confirmed, and no total stays on the screen as if it were.
      t.api.on = (_, _) => offline;
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.status, t.co.server, t.co.error != null], [CheckoutStatus.error, null, true]);
      t.net.online = false;
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect(t.co.status, CheckoutStatus.offline);
    });

    test('applies a code through the backend and says why one was refused, keeping the applied one', () async {
      final t = await boot();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.api.on = (step, _) => step == 'promotion' ? pricedDto() : checkoutDto();
      var res = await t.co.applyPromo(' save100 ', cart: t.cart);
      expect([res.code, res.applied, res.discountMinor, t.co.summary!.totalMinor], ['SAVE100', true, 10000, 43500]);

      t.api.on = (_, _) => refused(422, 'promotion_minimum_not_met', 'Spend more.', {'minimum_spend': inr(90000)});
      res = await t.co.applyPromo('big250', cart: t.cart);
      expect([res.code, res.status, res.minimumSpendMinor, res.discountMinor], ['BIG250', PromoStatus.minSpend, 90000, 0]);
      // The refused code changed nothing: the checkout still carries SAVE100 and its total.
      expect([t.co.server!.promotionCode, t.co.summary!.totalMinor, t.co.promo!.status], ['SAVE100', 43500, PromoStatus.minSpend]);
      expect(t.co.issues(authenticated: true, cart: t.cart.cart, selection: null).any((i) => i.code == CheckoutIssueCode.promoInvalid && !i.blocking), isTrue);

      t.api.on = (_, _) => refused(429, 'rate_limited', 'Too many attempts.');
      expect((await t.co.applyPromo('guess', cart: t.cart)).status, PromoStatus.rateLimited);

      t.api.on = (_, _) => checkoutDto();
      await t.co.removePromo(cart: t.cart);
      expect([t.co.promo, t.co.summary!.totalMinor, t.api.log.last], [null, 50000, 'remove']);
    });

    test('confirms the price on the screen and hands the payment step the backend\'s total', () async {
      final t = await boot();
      t.api.on = (step, _) => step == 'confirm' ? pricedDto(status: 'READY', held: true, payment: paymentDto(43500)) : pricedDto();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.co.setTermsAccepted(true); t.co.setPaymentMethod('card'); t.cart.note = 'Extra napkins';
      final req = await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk);

      expect(t.api.lastConfirm, {'id': ck, 'fingerprint': 'b' * 64, 'documents': ['TERMS@draft-2026-09', 'PRIVACY@draft-2026-09', 'REFUND@draft-2026-09'], 'type': 'CARD', 'saved': null, 'note': 'Extra napkins'});
      expect([req!.checkoutId, req.pricingFingerprint, req.displayedTotalMinor, req.currency, req.idempotencyKey, req.promoCode, req.paymentMethodId, req.termsVersion], [ck, 'b' * 64, 43500, 'INR', 'pay_${'c' * 40}', 'SAVE100', 'card', 'draft-2026-09']);
      expect([t.co.status, t.co.server!.status, t.co.server!.held], [CheckoutStatus.paymentReady, 'READY', true]);
      expect(jsonDecode((await t.store.read(CheckoutState.requestKey))!)['displayedTotalMinor'], 43500);
      // The time left is the confirmed checkout's: ten minutes on the server clock.
      expect(t.co.timeLeft(t.co.server!.receivedAt), const Duration(minutes: 10));
    });

    test('never confirms an old price: a stale checkout is started again and the customer sees the new total first', () async {
      final t = await boot();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.co.setTermsAccepted(true);
      t.api.on = (step, _) => step == 'confirm'
          ? refused(409, 'checkout_stale', 'Out of date.', {'reason': 'PRICE_CHANGED', 'issues': [issueDto('PRICE_CHANGED', 'refresh', 'A price in your cart has changed.')]})
          : checkoutDto(id: 'ck-2', pricing: {'items_subtotal': inr(60000), 'total': inr(60000), 'fingerprint': 'd' * 64}, changes: [{'code': 'PRICE_CHANGED', 'name': 'Classic Burger', 'previous_unit_price': inr(25000), 'unit_price': inr(30000)}]);
      final req = await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk);

      expect([req, t.co.request, t.co.status, t.co.summary!.totalMinor, t.api.log], [null, null, CheckoutStatus.ready, 60000, ['open', 'confirm', 'open']]);
      expect([t.co.notice!.message, t.co.notice!.previousTotalMinor, t.co.notice!.legal], ['A price in your cart has changed.', 50000, false]);
      expect(t.co.server!.changes.single['code'], 'PRICE_CHANGED');
      // The acceptance stands (same documents, same versions); the new price needs its own confirmation.
      expect(t.co.termsAccepted, isTrue);
    });

    test('asks for the terms again when their version changed', () async {
      final t = await boot();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.co.setTermsAccepted(true);
      final updated = [{...legal[0], 'version': 'v1-2026-11'}, legal[1], legal[2]];
      t.api.on = (step, _) => step == 'confirm' ? refused(422, 'legal_acceptance_required', 'Please accept the current terms.') : checkoutDto(id: 'ck-3', documents: updated);
      expect(await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk), isNull);
      expect([t.co.termsAccepted, t.co.notice!.legal, t.co.server!.legalSignature.startsWith('TERMS@v1-2026-11')], [false, true, true]);
      expect([for (final i in t.co.issues(authenticated: true, cart: t.cart.cart, selection: null)) if (i.blocking) i.code], [CheckoutIssueCode.terms]);
    });

    test('says a checkout has expired, withdraws the payment request and never shows it as payable', () async {
      final t = await boot();
      t.api.on = (step, _) => step == 'confirm' ? checkoutDto(status: 'READY', version: 2, held: true, expiresInMinutes: 10, payment: paymentDto(50000)) : checkoutDto();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.co.setTermsAccepted(true);
      expect(await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk), isNotNull);

      t.api.on = (_, _) => checkoutDto(status: 'EXPIRED', issues: [issueDto('CHECKOUT_EXPIRED', 'refresh', 'This checkout has expired. Please start it again.')]);
      expect(await t.co.revalidate(auth: t.auth, cart: t.cart), isFalse);
      expect([t.co.status, t.co.request, await t.store.read(CheckoutState.requestKey), t.co.timeLeft()], [CheckoutStatus.expired, null, null, null]);
      final issue = t.co.issues(authenticated: true, cart: t.cart.cart, selection: null).firstWhere((i) => i.blocking);
      expect([issue.code, issue.recover], [CheckoutIssueCode.checkoutExpired, 'refresh']);
      // A second confirmation of what expired is not even attempted while it is shown as expired … and starting again works.
      t.api.on = (_, _) => checkoutDto(id: 'ck-9');
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.status, t.co.server!.id], [CheckoutStatus.ready, 'ck-9']);
      expect(await t.co.revalidate(auth: t.auth, cart: t.cart), isTrue);
      // No answer is not "still fine".
      t.api.on = (_, _) => offline;
      expect(await t.co.revalidate(auth: t.auth, cart: t.cart), isFalse);
      expect(t.co.status, CheckoutStatus.error);
    });

    test('a payment in progress names its checkout — the way to the payment — and nothing is opened beside it', () async {
      final t = await boot();
      // The phone kept nothing: opening the checkout is refused, and the refusal says which checkout is being paid for.
      t.api.on = (_, _) => refused(409, 'payment_in_progress', 'A payment for this cart is in progress.', {'checkout_id': ck});
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.status, t.co.paymentCheckoutId, t.co.server], [CheckoutStatus.error, ck, null]);
      expect(t.co.issues(authenticated: true, cart: t.cart.cart, selection: null).firstWhere((i) => i.blocking).code, CheckoutIssueCode.paymentInProgress);
      // Once the payment ended unpaid the checkout is open again: nothing points to a payment any more.
      t.api.on = (_, _) => checkoutDto();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.status, t.co.paymentCheckoutId], [CheckoutStatus.ready, null]);
      // Found on resume: the confirmed checkout is in payment.
      t.api.on = (step, _) => step == 'show' ? checkoutDto(status: 'PAYMENT_PENDING') : checkoutDto();
      expect(await t.co.revalidate(auth: t.auth, cart: t.cart), isFalse);
      expect([t.co.paymentCheckoutId, t.co.status], [ck, CheckoutStatus.error]);
      t.co.reset();
      expect(t.co.paymentCheckoutId, isNull);
    });

    test('a stale checkout found on resume is replaced, and the customer is told what changed', () async {
      final t = await boot();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.api.on = (step, _) => step == 'show' ? checkoutDto(status: 'STALE', issues: [issueDto('FEE_CONFIGURATION_CHANGED', 'refresh', 'The price of this checkout has changed. Please review the new total.')]) : checkoutDto(id: 'ck-4', pricing: {'fees': [{'code': 'PLATFORM_FEE', 'label': 'Test platform fee', 'amount': inr(1500), 'taxable': false}], 'fee_total': inr(1500), 'total': inr(51500), 'fingerprint': 'e' * 64, 'configuration': {'tax': 'NOT_CONFIGURED', 'fees': 'TEST'}});
      expect(await t.co.revalidate(auth: t.auth, cart: t.cart), isFalse);
      expect([t.co.status, t.co.server!.id, t.co.summary!.totalMinor, t.co.notice!.previousTotalMinor, t.co.notice!.message], [CheckoutStatus.ready, 'ck-4', 51500, 50000, 'The price of this checkout has changed. Please review the new total.']);
    });

    test('needs no payment method when the total is zero', () async {
      final t = await boot();
      final free = {'discounts': [{'code': 'FREEMEAL', 'label': '100 % off', 'amount': inr(50000)}], 'discount_total': inr(50000), 'total': inr(0), 'payment_required': false};
      t.api.on = (step, _) => step == 'confirm' ? checkoutDto(status: 'READY', version: 2, held: true, pricing: free, promotion: {'code': 'FREEMEAL', 'name': '100 % off', 'discount': inr(50000)}, payment: paymentDto(0, status: 'PAYMENT_NOT_REQUIRED')) : checkoutDto(pricing: free, promotion: {'code': 'FREEMEAL', 'name': '100 % off', 'discount': inr(50000)});
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.co.summary!.totalMinor, t.co.summary!.paymentEligible, t.co.methods, t.co.paymentMethodId], [0, false, <PaymentMethodOption>[], null]);
      t.co.setTermsAccepted(true);
      expect(t.co.issues(authenticated: true, cart: t.cart.cart, selection: null).where((i) => i.blocking), isEmpty);
      final req = await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk);
      expect([t.api.lastConfirm!['type'], req!.displayedTotalMinor, t.co.server!.paymentStatus], [null, 0, 'PAYMENT_NOT_REQUIRED']);
    });

    test('signing out forgets the checkout, its request and the acceptance', () async {
      final t = await boot();
      t.api.on = (step, _) => step == 'confirm' ? checkoutDto(status: 'READY', version: 2, held: true, payment: paymentDto(50000)) : checkoutDto();
      await t.co.prepare(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.co.setTermsAccepted(true);
      await t.co.continueToPayment(auth: t.auth, cart: t.cart, pickup: t.pk);
      t.co.reset();
      expect([t.co.server, t.co.request, t.co.summary, t.co.termsAccepted, t.co.status, await t.store.read(CheckoutState.requestKey), await t.store.read(CheckoutState.termsKey)], [null, null, null, false, CheckoutStatus.initializing, null, null]);
    });
  });
}
