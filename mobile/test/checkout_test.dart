import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart' hide PromoStatus, PromoState;
import 'package:foodonthego/state/checkout_state.dart';
import 'package:foodonthego/state/pickup_state.dart';

/// Module 11 — checkout preparation (Android unit tests).
void main() {
  final now = DateTime.utc(2026, 9, 29, 7, 30); // 13:00 IST — Burger Hub open
  AddItemInput burger({int qty = 2}) => AddItemInput(menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', restaurantCurrency: 'INR', quantity: qty, unitPriceMinor: 25000);
  PromoContext ctx({int subtotal = 50000, String currency = 'INR', String restaurant = 'burger-hub', String country = 'IN', bool isNew = false}) => PromoContext(subtotalMinor: subtotal, currency: currency, restaurantId: restaurant, countryCode: country, now: now, customerIsNew: isNew);

  group('promotions (fixtures, integer money)', () {
    final promos = MockPromotionRepository(latency: Duration.zero);
    test('percentage applies and rounds in minor units', () async {
      final r = await promos.evaluate('welcome10', ctx(subtotal: 33333));
      expect(r.status, PromoStatus.applied); expect(r.code, 'WELCOME10'); expect(r.discountMinor, 3333);
    });
    test('minimum spend, expired, unknown', () async {
      expect((await promos.evaluate('TRAVEL5', ctx(subtotal: 1500))).status, PromoStatus.minSpend);
      expect((await promos.evaluate('EXPIRED', ctx())).status, PromoStatus.expired);
      expect((await promos.evaluate('NOPE', ctx())).status, PromoStatus.invalid);
      expect((await promos.evaluate('   ', ctx())).status, PromoStatus.invalid);
    });
    test('restaurant / market / currency scope and discount cap', () async {
      expect((await promos.evaluate('BURGER20', ctx(restaurant: 'spice-route'))).status, PromoStatus.restaurantNotEligible);
      final capped = await promos.evaluate('BURGER20', ctx(subtotal: 100000));
      expect(capped.status, PromoStatus.applied); expect(capped.discountMinor, 10000); // 20% = 20000 capped at 10000
      expect((await promos.evaluate('INDIA50', ctx(subtotal: 40000, currency: 'USD', country: 'US', restaurant: 'x'))).status, anyOf(PromoStatus.marketNotEligible, PromoStatus.currencyNotEligible));
      expect((await promos.evaluate('INDIA50', ctx(subtotal: 40000))).discountMinor, 5000);
      expect((await promos.evaluate('NEWBIE', ctx())).status, PromoStatus.notEligible);
      expect((await promos.evaluate('NEWBIE', ctx(isNew: true))).status, PromoStatus.applied);
    });
    test('discount never exceeds the subtotal', () async {
      final r = await promos.evaluate('INDIA50', ctx(subtotal: 30000));
      expect(r.discountMinor, lessThanOrEqualTo(30000));
    });
  });

  group('payment methods come from the repository per market', () {
    final pay = MockPaymentMethodRepository(latency: Duration.zero);
    test('IN market: UPI, card, wallet, net banking — never cash at pickup', () async {
      final m = await pay.getAvailableMethods('IN', 'INR');
      expect(m.map((x) => x.type), containsAll([PaymentMethodType.upi, PaymentMethodType.card]));
      expect(m.any((x) => x.type == PaymentMethodType.cashAtPickup), isFalse);
    });
    test('other markets: card enabled, no cash at pickup', () async {
      final m = await pay.getAvailableMethods('US', 'USD');
      expect(m.where((x) => x.enabled).map((x) => x.type), contains(PaymentMethodType.card));
      expect(m.any((x) => x.type == PaymentMethodType.cashAtPickup), isFalse);
    });
  });

  group('CheckoutState', () {
    Future<(AuthState, CartState, PickupState, CheckoutState, MockConnectivityService)> setup({bool signedIn = true, bool withPickup = true, int qty = 2}) async {
      final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
      if (signedIn) { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }
      final cart = CartState(repository: MemoryCartRepository()); await cart.ready; cart.addItem(burger(qty: qty));
      final pk = PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore());
      if (withPickup) pk.selection = PickupSelection(mode: PickupMode.asap, requestedAt: now.add(const Duration(minutes: 20)), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: now.add(const Duration(minutes: 20)), cartId: cart.cart!.id, restaurantId: 'burger-hub');
      final net = MockConnectivityService();
      final co = CheckoutState(promotions: MockPromotionRepository(latency: Duration.zero), payments: MockPaymentMethodRepository(latency: Duration.zero), checkout: MockCheckoutRepository(latency: Duration.zero), connectivity: net, restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), store: MemoryKeyValueStore());
      return (auth, cart, pk, co, net);
    }

    test('TEST 1 — valid cart + pickup + signed in → READY with a mock summary and market methods', () async {
      final (auth, cart, pk, co, _) = await setup();
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(co.status, CheckoutStatus.ready);
      expect(co.summary!.subtotalMinor, 50000); expect(co.summary!.totalMinor, 50000); expect(co.summary!.taxes, isEmpty); expect(co.summary!.fees, isEmpty);
      expect(co.methods, isNotEmpty); expect(co.paymentMethodId, isNotNull);
      final issues = co.issues(authenticated: true, cart: cart.cart, selection: pk.selection);
      expect(issues.map((i) => i.code), [CheckoutIssueCode.terms]);
    });
    test('TEST 2 — guest → REQUIRES_AUTH, nothing prepared', () async {
      final (auth, cart, pk, co, _) = await setup(signedIn: false);
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(co.status, CheckoutStatus.requiresAuth); expect(co.summary, isNull);
      expect(await co.continueToPayment(auth: auth, cart: cart, pickup: pk, now: now), isNull);
    });
    test('TEST 3 — missing pickup → INVALID_PICKUP and a blocking issue', () async {
      final (auth, cart, pk, co, _) = await setup(withPickup: false);
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(co.status, CheckoutStatus.invalidPickup);
      expect(co.issues(authenticated: true, cart: cart.cart, selection: null).map((i) => i.code), contains(CheckoutIssueCode.pickupMissing));
    });
    test('TEST 9 — pickup outside the schedule is invalid (revalidated on the client, authoritative on the server)', () async {
      final (auth, cart, pk, co, _) = await setup();
      final late = DateTime.utc(2026, 9, 29, 20); // 01:30 IST — closed
      pk.selection = PickupSelection(mode: PickupMode.asap, requestedAt: late.add(const Duration(minutes: 20)), restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: late.add(const Duration(minutes: 20)), cartId: cart.cart!.id, restaurantId: 'burger-hub');
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: late);
      expect(co.status, CheckoutStatus.invalidPickup); expect(co.pickupResult!.reason, PickupInvalidReason.outsideSchedule);
    });
    test('TEST 5/6/7 — promo apply, invalid, remove; totals recalculate', () async {
      final (auth, cart, pk, co, _) = await setup();
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      final r = await co.applyPromo('welcome10', cart: cart, now: now);
      expect(r.status, PromoStatus.applied); expect(co.summary!.discountMinor, 5000); expect(co.summary!.totalMinor, 45000);
      final bad = await co.applyPromo('EXPIRED', cart: cart, now: now);
      expect(bad.status, PromoStatus.expired); expect(co.summary!.totalMinor, 50000);
      expect(co.issues(authenticated: true, cart: cart.cart, selection: pk.selection).where((i) => i.code == CheckoutIssueCode.promoInvalid).single.blocking, isFalse);
      await co.removePromo(cart: cart, now: now);
      expect(co.promo, isNull); expect(co.summary!.totalMinor, 50000);
    });
    test('TEST 11 — Continue blocked until terms accepted; request carries no client amount as authority', () async {
      final (auth, cart, pk, co, _) = await setup();
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(await co.continueToPayment(auth: auth, cart: cart, pickup: pk, now: now), isNull);
      co.setTermsAccepted(true);
      final req = await co.continueToPayment(auth: auth, cart: cart, pickup: pk, now: now);
      expect(req, isNotNull); expect(co.status, CheckoutStatus.paymentReady);
      expect(req!.idempotencyKey.length, 32); expect(req.termsVersion, termsVersion); expect(req.paymentMethodId, co.paymentMethodId);
      expect(req.displayedTotalMinor, co.summary!.totalMinor); // display-only echo, server recomputes
      final json = req.toJson();
      expect(json.containsKey('amount'), isFalse); expect(json.containsKey('totalMinor'), isFalse); expect(json['displayedTotalMinor'], 50000);
      expect(json.keys.any((k) => k.toLowerCase().contains('card') || k.toLowerCase().contains('cvv')), isFalse);
    });
    test('TEST 14 — offline blocks checkout and no request is created', () async {
      final (auth, cart, pk, co, net) = await setup();
      net.online = false; co.setTermsAccepted(true);
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(co.status, CheckoutStatus.offline);
      expect(await co.continueToPayment(auth: auth, cart: cart, pickup: pk, now: now), isNull);
      net.online = true;
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(co.status, CheckoutStatus.ready);
    });
    test('empty cart → INVALID_CART', () async {
      final (auth, cart, pk, co, _) = await setup();
      cart.clear();
      await co.prepare(auth: auth, cart: cart, pickup: pk, now: now);
      expect(co.status, CheckoutStatus.invalidCart);
    });
  });
}
