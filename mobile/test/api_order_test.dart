/// Orders on the backend (Module 31) — Android: the order mode, the backend's order mapped onto the order model the
/// screens render (number, state, money state, lines, pricing, pickup, credential, timeline → events), the list row,
/// the repository on the real paths (read by number, not found, found through the payment, the list page), the
/// verification view and the polling tracker (only NEW timeline lines become events; a lost connection is "stale").
/// Payloads are in the shape of the real API (OpenAPI: CustomerOrderEnvelope / the recorded m31-api-samples.json).
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/checkout/api_checkout.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/order/api_order.dart';
import 'package:foodonthego/order/order_history.dart';
import 'package:foodonthego/order/order_models.dart';
import 'package:foodonthego/order/order_tracking.dart';
import 'package:foodonthego/payment/api_payment.dart';
import 'package:foodonthego/pickup/api_pickup.dart';

import 'api_checkout_test.dart' show json;
import 'api_payment_test.dart' show Net;

const oid = 'cc547104-6c95-4171-b489-9056cb48f9b6';
const number = 'FOTG-20261008-B344DN';
const customer = 'cust-asha';

Map<String, dynamic> inr(int minor) => {'amount': minor, 'currency': 'INR'};

/// The recorded customer order (Burger Hub, two lines, paid with the development provider, waiting for the restaurant).
Map<String, dynamic> orderDto({String status = 'WAITING_FOR_RESTAURANT', String refund = 'NONE', Object? credential = const {'code': 'WU9GVM', 'qr_payload': 'fotg:pickup:a3321c70ecfa570779c4cd00a53543e7', 'status': 'ACTIVE'},
    List<Map<String, dynamic>>? timeline, Map<String, dynamic>? rejection, bool required = true, int? version}) => {
      'id': oid, 'order_number': number, 'status': status, 'payment_state': required ? 'PAID' : 'NOT_REQUIRED', 'refund_status': refund, 'version': version ?? (timeline?.length ?? 1),
      'restaurant': {'id': '0a000000-0000-4000-8000-0000000000a1', 'slug': 'burger-hub', 'name': 'Burger Hub', 'branch_label': 'Sector 62 · Noida', 'formatted_address': 'Sector 62, Noida, Uttar Pradesh 201309, India', 'phone': null, 'timezone': 'Asia/Kolkata', 'pickup_instructions': 'Show your pickup code at the counter.'},
      'item_count': 3, 'total': inr(42700),
      'pickup': {'mode': 'SCHEDULED', 'method': 'COUNTER', 'pickup_at': '2026-10-08T09:00:00+00:00', 'pickup_at_local': '2026-10-08T14:30:00+05:30', 'slot': {'starts_at': '2026-10-08T09:00:00+00:00', 'ends_at': '2026-10-08T09:15:00+00:00'}, 'timezone': 'Asia/Kolkata', 'estimated_prep_minutes': 12, 'traveler_arrival_at': null},
      'placed_at': '2026-10-08T07:56:20+00:00', 'updated_at': '2026-10-08T07:56:20+00:00',
      'items': [
        {'id': '072c2495-a2f9-4489-a71e-f25cbf700e79', 'item_id': 'd24cdf13-dd63-43e6-9818-6e776884d108', 'slug': 'onion-rings', 'name': 'Onion Rings', 'quantity': 2, 'unit_price': inr(14900), 'line_subtotal': inr(29800), 'special_instructions': null, 'options': []},
        {'id': '2081bd7f-ccd4-4e54-8842-a902c5fd6de5', 'item_id': 'd907ebae-e83c-484d-b495-4712b7c78b6f', 'slug': 'masala-fries', 'name': 'Masala Fries', 'quantity': 1, 'unit_price': inr(12900), 'line_subtotal': inr(12900), 'special_instructions': 'extra crisp',
          'options': [{'kind': 'VARIANT', 'group': 'Size', 'name': 'Regular', 'price_adjustment': inr(0)}, {'kind': 'MODIFIER', 'group': 'Extras', 'name': 'Cheese dip', 'price_adjustment': inr(2500)}]},
      ],
      'pricing': {'currency': 'INR', 'subtotal': inr(42700), 'discount': inr(0), 'tax': inr(0), 'tax_included': inr(0), 'fee': inr(0), 'total': inr(42700)},
      'promotion': null, 'taxes': [], 'fees': [],
      'payment': required ? {'required': true, 'id': 'a62c8da8-0ffa-4a15-a6fd-2aa904a5387a', 'provider': 'fake', 'method': {'type': 'UPI', 'summary': {'vpa': 'su***@fake'}}, 'verified_at': '2026-10-08T07:56:19+00:00'} : {'required': false, 'id': null, 'provider': null, 'method': null, 'verified_at': null},
      'customer_note': null, 'journey': null,
      'legal': [{'type': 'TERMS', 'version': 'draft-2026-09', 'accepted_at': '2026-10-08T07:56:15+00:00'}],
      'rejection': rejection, 'cancellation': null, 'pickup_credential': credential, 'pickup_verification': 'NOT_IMPLEMENTED',
      'timeline': timeline ?? [{'at': '2026-10-08T07:56:20+00:00', 'type': 'ORDER_CREATED', 'from': 'CREATED', 'to': 'WAITING_FOR_RESTAURANT', 'actor': 'FOODONTHEGO', 'detail': {}}],
      'next_action': 'WAIT_FOR_RESTAURANT',
      'times': {'placed_at': '2026-10-08T07:56:20+00:00', 'accepted_at': null, 'rejected_at': null, 'preparing_at': null, 'ready_at': null, 'picked_up_at': null, 'completed_at': null, 'cancelled_at': null},
    };

Map<String, dynamic> rowDto({String id = oid, String num = number, String status = 'WAITING_FOR_RESTAURANT'}) => {
      'id': id, 'order_number': num, 'status': status, 'payment_state': 'PAID', 'refund_status': 'NONE', 'restaurant': {'id': 'r1', 'slug': 'burger-hub', 'name': 'Burger Hub'},
      'item_count': 3, 'total': inr(42700), 'pickup': {'mode': 'SCHEDULED', 'pickup_at': '2026-10-08T09:00:00+00:00', 'timezone': 'Asia/Kolkata'}, 'placed_at': '2026-10-08T07:56:20+00:00',
    };

final accepted = [
  {'at': '2026-10-08T07:56:20+00:00', 'type': 'ORDER_CREATED', 'from': 'CREATED', 'to': 'WAITING_FOR_RESTAURANT', 'actor': 'FOODONTHEGO', 'detail': {}},
  {'at': '2026-10-08T07:58:00+00:00', 'type': 'RESTAURANT_ACCEPTED', 'from': 'WAITING_FOR_RESTAURANT', 'to': 'ACCEPTED', 'actor': 'RESTAURANT', 'detail': {}},
];

({ApiOrderRepository orders, Net net}) repo() {
  final net = Net();
  final store = MemoryKeyValueStore();
  store.write(ApiAuthRepository.tokenKey, 'customer-session');
  final cart = CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);
  return (orders: ApiOrderRepository(cart), net: net);
}

void main() {
  tearDown(() { cartModeOverride = null; pickupModeOverride = null; checkoutModeOverride = null; paymentModeOverride = null; orderModeOverride = null; });

  group('order mode', () {
    test('orders are the development orders unless the build says otherwise — and never the backend\'s without the backend payment', () {
      expect(ordersFromApi, isFalse);
      orderModeOverride = 'api';
      expect(ordersFromApi, isFalse, reason: 'ORDER_MODE=api alone: the payment is still the development one, so there is no backend order to read');
      cartModeOverride = 'api'; pickupModeOverride = 'api'; checkoutModeOverride = 'api'; paymentModeOverride = 'api';
      expect(ordersFromApi, isTrue);
      orderModeOverride = 'mock';
      expect(ordersFromApi, isFalse);
    });
  });

  group('the backend order on the order model', () {
    test('number, state, money state, lines, pricing and pickup are the backend\'s — nothing is priced or decided here', () {
      final o = orderFromApi(orderDto(), customer);
      expect([o.orderNumber, o.publicId, o.customerId], [number, oid, customer]);
      expect([o.orderStatus, o.paymentStatus, o.payment.status], [OrderStatus.awaitingRestaurantAcceptance, OrderPaymentStatus.paid, OrderPaymentStatus.paid]);
      expect([o.restaurant.name, o.restaurant.slug], ['Burger Hub', 'burger-hub']);
      expect(o.items.map((i) => '${i.itemName} x${i.quantity} @${i.unitPriceMinor} = ${i.lineTotalMinor}'), ['Onion Rings x2 @14900 = 29800', 'Masala Fries x1 @12900 = 12900']);
      expect(o.items[1].variants.single.optionName, 'Regular');
      expect([o.items[1].modifiers.single.optionName, o.items[1].modifiers.single.priceAdjustmentMinor, o.items[1].specialInstructions], ['Cheese dip', 2500, 'extra crisp']);
      expect([o.pricing.currency, o.pricing.subtotalMinor, o.pricing.discountMinor, o.pricing.totalMinor, o.pricing.taxes, o.pricing.fees], ['INR', 42700, 0, 42700, isEmpty, isEmpty]);
      expect([o.payment.methodType, o.payment.providerDisplayName, o.payment.maskedDetails, o.payment.paidAmountMinor, o.payment.currency], ['upi', 'Development payment provider', 'su***@fake', 42700, 'INR']);
      expect([o.pickup.mode, o.pickup.restaurantTimezone, o.pickup.requestedAt.toUtc().toIso8601String()], ['scheduled', 'Asia/Kolkata', '2026-10-08T09:00:00.000Z']);
      expect(o.pickup.estimatedReadyTime.toUtc().toIso8601String(), '2026-10-08T08:08:20.000Z', reason: 'no ready_at yet: placed + the estimated preparation minutes');
      expect([o.pickupCodeReference, o.pickupVerificationStatus], ['pc-$oid', PickupVerificationStatus.ready], reason: 'the credential exists but the order is not ready: shown, not usable');
      expect([o.rejectionReasonKey, o.cancellationReasonKey, o.journey], [null, null, null]);
    });

    test('the timeline becomes the screens\' events — the verified payment first, then one event per line, numbered', () {
      final o = orderFromApi(orderDto(timeline: accepted), customer);
      expect(o.events.map((e) => '${e.sequence} ${e.type.name} ${e.status?.name} ${e.actor}'), ['1 paymentVerified null system', '2 orderCreated awaitingRestaurantAcceptance system', '3 restaurantAccepted accepted restaurant']);
      expect(o.lastEventSequence, 3);
      expect(o.events.first.at.toIso8601String(), '2026-10-08T07:56:19.000Z');
    });

    test('a rejected paid order: REJECTED with the structured reason, refund REQUIRED is "refund pending", no usable pickup code', () {
      final o = orderFromApi(orderDto(status: 'REJECTED', refund: 'REQUIRED', credential: null, rejection: {'reason': 'ITEM_UNAVAILABLE', 'note': 'Onion rings sold out', 'at': '2026-10-08T07:59:00+00:00'},
          timeline: [...accepted.take(1), {'at': '2026-10-08T07:59:00+00:00', 'type': 'RESTAURANT_REJECTED', 'from': 'WAITING_FOR_RESTAURANT', 'to': 'REJECTED', 'actor': 'RESTAURANT', 'detail': {'reason': 'ITEM_UNAVAILABLE'}}]), customer);
      expect([o.orderStatus, o.paymentStatus, o.rejectionReasonKey, o.pickupCodeReference, o.pickupVerificationStatus], [OrderStatus.rejected, OrderPaymentStatus.refundPending, 'item_unavailable', '', PickupVerificationStatus.invalid]);
      expect([o.events.last.type, o.events.last.reasonKey, o.events.last.status], [OrderEventType.restaurantRejected, 'item_unavailable', OrderStatus.rejected]);
      expect(orderFromApi(orderDto(refund: 'REFUNDED', status: 'CANCELLED', credential: null), customer).payment.refundedAmountMinor, 42700);
      expect(paymentStatusFromApi('PARTIAL'), OrderPaymentStatus.partiallyRefunded);
    });

    test('ready for pickup: the credential is usable; a zero-total order has no payment to show', () {
      final ready = orderFromApi(orderDto(status: 'READY_FOR_PICKUP'), customer);
      expect(ready.pickupVerificationStatus, PickupVerificationStatus.verificationAvailable);
      final v = verificationFromApi(orderDto(status: 'READY_FOR_PICKUP'))!;
      expect([v.code, v.qrToken, v.status, v.orderPublicId], ['WU9GVM', 'fotg:pickup:a3321c70ecfa570779c4cd00a53543e7', PickupVerificationStatus.verificationAvailable, oid]);
      expect(verificationFromApi(orderDto(credential: null)), isNull);
      final free = orderFromApi(orderDto(required: false), customer);
      expect([free.payment.methodType, free.payment.methodLabel, free.payment.paidAmountMinor, free.events.first.type], ['none', 'No payment required', 0, OrderEventType.orderCreated]);
    });

    test('a list row is a summary: the backend\'s state and total, reorder only after pickup', () {
      final s = summaryFromApi(rowDto());
      expect([s.orderNumber, s.restaurantName, s.itemCount, s.totalMinor, s.currency, s.orderStatus, s.paymentStatus, s.reorderEligible], [number, 'Burger Hub', 3, 42700, 'INR', OrderStatus.awaitingRestaurantAcceptance, OrderPaymentStatus.paid, false]);
      expect(summaryFromApi(rowDto(status: 'COMPLETED')).reorderEligible, isTrue);
    });
  });

  group('the order repository on the real paths', () {
    test('an order is read by its number; one the backend does not know is null; everything else is the backend\'s refusal', () async {
      final r = repo();
      r.net.answer = (q) => q.url.path.endsWith('/customer/orders/$number') ? json(200, {'order': orderDto()}) : json(404, {'error': {'code': 'order_not_found', 'message': 'No such order.'}});
      final o = await r.orders.getByOrderNumber(number, customer);
      expect([o!.orderNumber, o.customerId], [number, customer]);
      expect(await r.orders.getByOrderNumber('FOTG-20261008-NOPE00', customer), isNull);
      expect(r.net.paths, ['GET /customer/orders/$number', 'GET /customer/orders/FOTG-20261008-NOPE00']);
      r.net.answer = (_) => json(429, {'error': {'code': 'too_many_requests', 'message': 'Slow down.'}});
      expect(() => r.orders.getByOrderNumber(number, customer), throwsA(isA<ApiException>()));
    });

    test('found through the payment: the payment names the order it became; nothing is created by the app', () async {
      final r = repo();
      var named = false;
      r.net.answer = (q) {
        if (q.url.path.contains('/payments/')) return json(200, {'payment': {'id': 'pay-1', 'status': 'VERIFIED', 'fulfillment': {'status': named ? 'ORDER_CREATED' : 'ORDER_PENDING', 'order_id': named ? oid : null, 'order_number': named ? number : null}}});
        return json(200, {'order': orderDto()});
      };
      expect(await r.orders.findByPaymentAttempt('pay-1'), isNull, reason: 'still ORDER_PENDING: no order to read');
      named = true;
      expect((await r.orders.findByPaymentAttempt('pay-1'))!.orderNumber, number);
      expect(r.net.paths.where((p) => p.startsWith('POST')), isEmpty, reason: 'the app never posts an order');
    });

    test('the list is a page of the backend: ongoing = filter[active]=1, newest first, the next page from meta', () async {
      final r = repo();
      r.net.answer = (q) => json(200, {'data': [rowDto(), rowDto(id: 'o2', num: 'FOTG-20261007-AAAAAA', status: 'COMPLETED')], 'meta': {'current_page': 1, 'last_page': 3, 'total': 11}});
      final page = await r.orders.listSummaries(customer, const OrderListQuery(group: OrderGroup.ongoing, limit: 5));
      expect(r.net.calls.single.url.queryParameters, {'page[number]': '1', 'page[size]': '5', 'sort': '-placed_at', 'filter[active]': '1'});
      expect([page.items.length, page.nextCursor, page.total], [2, '2', 11]);
      final done = await r.orders.listSummaries(customer, const OrderListQuery(group: OrderGroup.completed, cursor: '2', sort: OrderSort.oldest));
      expect(r.net.calls.last.url.queryParameters, {'page[number]': '2', 'page[size]': '5', 'sort': 'placed_at', 'filter[active]': '0'});
      expect(done.items.map((o) => o.orderNumber), ['FOTG-20261007-AAAAAA'], reason: 'the finer group is narrowed on the page: completed, not the row still waiting');
    });

    test('the verification view comes from the order already read — no second request', () async {
      final r = repo();
      r.net.answer = (_) => json(200, {'order': orderDto(status: 'READY_FOR_PICKUP')});
      final o = (await r.orders.getByOrderNumber(number, customer))!;
      final v = await ApiPickupVerificationRepository(r.orders).getForOrder(o);
      expect([v!.code, v.status, r.net.calls.length], ['WU9GVM', PickupVerificationStatus.verificationAvailable, 1]);
    });
  });

  group('tracking by polling', () {
    test('only timeline lines newer than what the screen has become events; a lost connection is "stale", then live again', () async {
      final r = repo();
      var answer = orderDto();
      var lost = false;
      r.net.answer = (_) => lost ? throw Exception('connection lost') : json(200, {'order': answer});
      final first = orderFromApi(answer, customer);
      final events = <String>[]; final states = <TrackingConnection>[];
      final stop = ApiOrderTrackingService(r.orders, every: const Duration(milliseconds: 20)).subscribe(first, onEvent: (e) => events.add('${e.sequence} ${e.type.name}'), onConnection: states.add);
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(events, isEmpty, reason: 'nothing new on the backend: nothing delivered');
      answer = orderDto(status: 'ACCEPTED', timeline: accepted);
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(events, ['3 restaurantAccepted']);
      lost = true;
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(states.last, TrackingConnection.stale);
      lost = false;
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect([states.last, events], [TrackingConnection.live, ['3 restaurantAccepted']]);
      stop();
      final calls = r.net.calls.length;
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(r.net.calls.length, calls, reason: 'stopped: no more polling');
    });
  });
}
