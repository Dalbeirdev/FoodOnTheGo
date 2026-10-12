/// Refunds and cancellation on the backend (Module 35) — Android: the order's derived refund status on the screens'
/// money state (a failed refund is "under review", never "payment failed"; "refunded" only when the backend says the
/// provider confirmed it), the refund block (each refund, confirmed versus still owed), the refund lines of the
/// timeline, the backend's cancellation answer and quote, the repository on the real paths (a cancellation sends a
/// reason and never an amount), and the tracker following a finished order while its refund is open.
/// Payloads are in the shape of the real API (OpenAPI: CustomerOrderEnvelope / OrderCancellationQuote).
library;

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/checkout/checkout_models.dart' show MockConnectivityService;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/order/api_order.dart';
import 'package:foodonthego/order/order_history.dart';
import 'package:foodonthego/state/tracking_state.dart';
import 'package:foodonthego/auth/auth_repository.dart';

import 'api_checkout_test.dart' show failure, json;
import 'api_order_test.dart' show customer, inr, number, oid, orderDto, repo;

const rid = '5d000000-0000-4000-8000-000000000001';

Map<String, dynamic> refundItem(String status, {int amount = 42700, String type = 'FULL', String? completedAt, String id = rid}) => {
      'id': id, 'reference': 'RFD-5D00000000', 'type': type, 'amount': inr(amount), 'status': status,
      'reason': {'code': 'RESTAURANT_REJECTED', 'label': 'The restaurant could not accept the order'},
      'requested_at': '2026-10-08T07:59:00+00:00', 'completed_at': completedAt,
    };

Map<String, dynamic> refundBlock(String status, List<Map<String, dynamic>> items, {int refunded = 0, int pending = 0}) =>
    {'status': status, 'paid': inr(42700), 'refunded': inr(refunded), 'pending': inr(pending), 'items': items, 'timing_note': null};

final created = {'at': '2026-10-08T07:56:20+00:00', 'type': 'ORDER_CREATED', 'from': 'CREATED', 'to': 'WAITING_FOR_RESTAURANT', 'actor': 'FOODONTHEGO', 'detail': <String, dynamic>{}};
final rejectedLine = {'at': '2026-10-08T07:59:00+00:00', 'type': 'RESTAURANT_REJECTED', 'from': 'WAITING_FOR_RESTAURANT', 'to': 'REJECTED', 'actor': 'RESTAURANT', 'detail': {'reason': 'ITEM_UNAVAILABLE'}};
Map<String, dynamic> refundLine(String type, String status) => {'at': '2026-10-08T08:00:00+00:00', 'type': type, 'from': null, 'to': null, 'actor': 'FOODONTHEGO', 'detail': {'refund_status': status}};

/// A paid order the restaurant rejected, at one point of its refund.
Map<String, dynamic> rejected(String refundStatus, Map<String, dynamic>? refund, {List<Map<String, dynamic>> lines = const [], Map<String, dynamic>? mayCancel}) => {
      ...orderDto(status: 'REJECTED', refund: refundStatus, credential: null, rejection: {'reason': 'ITEM_UNAVAILABLE', 'note': null, 'at': '2026-10-08T07:59:00+00:00'}, timeline: [created, rejectedLine, ...lines], version: 2 + lines.length),
      'refund': refund,
      'customer_cancellation': mayCancel ?? {'enabled': false, 'can_cancel': false, 'reason': 'CUSTOMER_CANCELLATION_DISABLED'},
    };

Map<String, dynamic> quote({bool enabled = true, bool can = true, String? effect = 'FULL_REFUND', int? refundable = 42700, String? reason, String message = 'If you cancel now, your payment for this order will be refunded to the method you paid with.'}) => {
      'enabled': enabled, 'policy_status': enabled ? 'CONFIGURED' : 'PENDING_BUSINESS_APPROVAL', 'policy_version': '2026-10', 'can_cancel': can, 'reason': reason, 'message': message,
      'financial_effect': effect, 'refundable_amount': refundable == null ? null : inr(refundable), 'reasons': can ? ['CHANGED_MIND', 'ORDERED_BY_MISTAKE', 'PICKUP_TIME_NO_LONGER_WORKS', 'OTHER'] : <String>[],
      'order': {'id': oid, 'order_number': number, 'status': 'WAITING_FOR_RESTAURANT', 'version': 1},
    };

void main() {
  group('the refund status on the money state', () {
    test('every backend refund status has one meaning for the customer; a failed refund is never "payment failed"', () {
      expect({for (final s in ['NONE', 'REQUIRED', 'PROCESSING', 'PARTIALLY_REFUNDED', 'REFUNDED', 'UNDER_REVIEW', 'FAILED', 'REVIEW_REQUIRED']) s: paymentStatusFromApi(s)}, {
        'NONE': OrderPaymentStatus.paid, 'REQUIRED': OrderPaymentStatus.refundPending, 'PROCESSING': OrderPaymentStatus.refundProcessing, 'PARTIALLY_REFUNDED': OrderPaymentStatus.partiallyRefunded,
        'REFUNDED': OrderPaymentStatus.refunded, 'UNDER_REVIEW': OrderPaymentStatus.refundUnderReview, 'FAILED': OrderPaymentStatus.refundUnderReview, 'REVIEW_REQUIRED': OrderPaymentStatus.refundUnderReview,
      });
      // What a backend older than Module 35 called them.
      expect([paymentStatusFromApi('PENDING'), paymentStatusFromApi('PARTIAL')], [OrderPaymentStatus.refundProcessing, OrderPaymentStatus.partiallyRefunded]);
      expect([for (final p in OrderPaymentStatus.values) if (hasRefund(p)) p.name], ['refundPending', 'partiallyRefunded', 'refunded', 'refundProcessing', 'refundUnderReview']);
      expect([for (final p in OrderPaymentStatus.values) if (refundOpen(p)) p.name], ['refundPending', 'refundProcessing', 'refundUnderReview'], reason: 'a settled refund is not asked about again');
      expect([paymentStatusKey(OrderPaymentStatus.refundProcessing), paymentStatusKey(OrderPaymentStatus.refundUnderReview)], ['REFUND_PROCESSING', 'REFUND_UNDER_REVIEW']);
    });
  });

  group('the refund block of an order', () {
    test('a rejected paid order: the refund is DUE — rejected is not refunded, nothing is counted as refunded', () {
      final o = orderFromApi(rejected('REQUIRED', refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED')]), customer);
      expect([o.orderStatus, o.paymentStatus, o.payment.refundedAmountMinor], [OrderStatus.rejected, OrderPaymentStatus.refundPending, null]);
      final r = o.refunds!;
      expect([r.refundedMinor, r.pendingMinor, r.items.length], [0, 42700, 1]);
      final x = r.items.single;
      expect([x.id, x.reference, x.type, x.amountMinor, x.currency, x.status, x.reasonLabel, x.completedAt], [rid, 'RFD-5D00000000', 'FULL', 42700, 'INR', 'REQUIRED', 'The restaurant could not accept the order', null]);
      expect(x.requestedAt, DateTime.utc(2026, 10, 8, 7, 59));
      expect(o.events.map((e) => '${e.type.name}:${e.paymentStatus?.name}').toList(), ['paymentVerified:paid', 'orderCreated:null', 'restaurantRejected:null', 'refundUpdated:refundPending']);
    });

    test('processing is not refunded; refunded carries what the provider confirmed; a failed refund reads "under review"', () {
      final processing = orderFromApi(rejected('PROCESSING', refundBlock('PROCESSING', [refundItem('PROCESSING')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING')]), customer);
      expect([processing.paymentStatus, processing.payment.refundedAmountMinor, processing.refunds!.items.single.status], [OrderPaymentStatus.refundProcessing, null, 'PROCESSING']);
      expect(processing.events.last.paymentStatus, OrderPaymentStatus.refundProcessing);

      final done = orderFromApi(rejected('REFUNDED', refundBlock('REFUNDED', [refundItem('REFUNDED', completedAt: '2026-10-08T08:05:00+00:00')], refunded: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING'), refundLine('REFUND_COMPLETED', 'REFUNDED')]), customer);
      expect([done.orderStatus, done.paymentStatus, done.payment.refundedAmountMinor, done.refunds!.pendingMinor], [OrderStatus.rejected, OrderPaymentStatus.refunded, 42700, 0]);
      expect(done.refunds!.items.single.completedAt, DateTime.utc(2026, 10, 8, 8, 5));
      expect(done.events.where((e) => e.type == OrderEventType.refundUpdated).map((e) => e.paymentStatus!.name).toList(), ['refundPending', 'refundProcessing', 'refunded']);

      // A customer is given UNDER_REVIEW; the internal words read the same should they ever arrive.
      for (final status in ['UNDER_REVIEW', 'FAILED', 'REVIEW_REQUIRED']) {
        final o = orderFromApi(rejected(status, refundBlock(status, [refundItem('UNDER_REVIEW')], pending: 42700), lines: [refundLine('REFUND_UNDER_REVIEW', status)]), customer);
        expect([o.paymentStatus, o.payment.status, o.refunds!.items.single.status, o.events.last.paymentStatus], [OrderPaymentStatus.refundUnderReview, OrderPaymentStatus.refundUnderReview, 'UNDER_REVIEW', OrderPaymentStatus.refundUnderReview]);
        expect(o.paymentStatus, isNot(OrderPaymentStatus.failed));
      }
    });

    test('a partial refund: the confirmed part, the part still owed and each refund are told apart', () {
      final o = orderFromApi({
        ...orderDto(status: 'COMPLETED', refund: 'PARTIALLY_REFUNDED', credential: null),
        'refund': refundBlock('PARTIALLY_REFUNDED', [refundItem('REFUNDED', amount: 10000, type: 'PARTIAL', completedAt: '2026-10-08T10:00:00+00:00', id: 'r-1'), refundItem('PROCESSING', amount: 5000, type: 'PARTIAL', id: 'r-2')], refunded: 10000, pending: 5000),
        'customer_cancellation': {'enabled': false, 'can_cancel': false, 'reason': 'CUSTOMER_CANCELLATION_DISABLED'},
      }, customer);
      expect([o.orderStatus, o.paymentStatus, o.payment.paidAmountMinor, o.payment.refundedAmountMinor], [OrderStatus.completed, OrderPaymentStatus.partiallyRefunded, 42700, 10000]);
      expect([for (final x in o.refunds!.items) '${x.type} ${x.amountMinor} ${x.status}'], ['PARTIAL 10000 REFUNDED', 'PARTIAL 5000 PROCESSING']);
      expect([o.refunds!.refundedMinor, o.refunds!.pendingMinor], [10000, 5000]);
    });

    test('an order nothing was paid for, and a backend older than Module 35, have no refund block and no cancellation answer', () {
      final free = orderFromApi({...orderDto(required: false), 'refund': null, 'customer_cancellation': {'enabled': false, 'can_cancel': false, 'reason': 'CUSTOMER_CANCELLATION_DISABLED'}}, customer);
      expect([free.refunds, free.paymentStatus, free.cancellation!.canCancel], [null, OrderPaymentStatus.paid, false]);
      final older = orderFromApi(orderDto(status: 'CANCELLED', refund: 'REFUNDED', credential: null), customer);
      expect([older.refunds, older.cancellation, older.cancellationMessage, older.payment.refundedAmountMinor], [null, null, null, 42700]);
      // A refund line of an older backend carries no status: the line's own meaning is used.
      final line = orderFromApi(orderDto(status: 'REJECTED', refund: 'REQUIRED', credential: null, timeline: [created, rejectedLine, {'at': '2026-10-08T08:00:00+00:00', 'type': 'REFUND_REQUIRED', 'from': null, 'to': null, 'actor': 'FOODONTHEGO', 'detail': <String, dynamic>{}}]), customer);
      expect(line.events.last.paymentStatus, OrderPaymentStatus.refundPending);
    });

    test('whether the order may be cancelled, and who cancelled it, are the backend\'s words', () {
      final off = orderFromApi({...orderDto(), 'refund': refundBlock('NONE', []), 'customer_cancellation': {'enabled': false, 'can_cancel': false, 'reason': 'CUSTOMER_CANCELLATION_DISABLED'}}, customer);
      expect([off.cancellation!.enabled, off.cancellation!.canCancel, off.cancellation!.reason], [false, false, 'CUSTOMER_CANCELLATION_DISABLED']);
      final on = orderFromApi({...orderDto(), 'customer_cancellation': {'enabled': true, 'can_cancel': true, 'reason': null}}, customer);
      expect([on.cancellation!.enabled, on.cancellation!.canCancel, on.cancellation!.reason], [true, true, null]);
      final cancelled = orderFromApi({...orderDto(status: 'CANCELLED', refund: 'REQUIRED', credential: null), 'cancellation': {'by': 'YOU', 'message': 'You cancelled this order.'}, 'refund': refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700), 'customer_cancellation': {'enabled': true, 'can_cancel': false, 'reason': 'ORDER_ALREADY_CLOSED'}}, customer);
      expect([cancelled.orderStatus, cancelled.cancellationMessage, cancelled.cancellation!.canCancel, cancelled.paymentStatus], [OrderStatus.cancelled, 'You cancelled this order.', false, OrderPaymentStatus.refundPending]);
    });

    test('the money state of a fresh answer is taken over without touching the order\'s status or events', () {
      final shown = orderFromApi(rejected('REQUIRED', refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700)), customer);
      final fresh = orderFromApi(rejected('REFUNDED', refundBlock('REFUNDED', [refundItem('REFUNDED', completedAt: '2026-10-08T08:05:00+00:00')], refunded: 42700), lines: [refundLine('REFUND_COMPLETED', 'REFUNDED')]), customer);
      expect(shown.moneySignature == fresh.moneySignature, isFalse);
      final next = shown.withMoneyOf(fresh);
      expect([next.paymentStatus, next.payment.refundedAmountMinor, next.refunds!.items.single.status, next.version], [OrderPaymentStatus.refunded, 42700, 'REFUNDED', 3]);
      expect([next.orderStatus, next.events.length, next.lastEventSequence, next.rejectionReasonKey], [shown.orderStatus, shown.events.length, shown.lastEventSequence, 'item_unavailable']);
      expect(next.moneySignature, fresh.moneySignature);
    });
  });

  group('cancelling on the real paths', () {
    test('the quote is the backend\'s: whether, why not, and what happens to the money', () {
      final q = quoteFromApi(quote());
      expect([q.enabled, q.canCancel, q.financialEffect, q.refundableMinor, q.currency, q.reasons.length, q.version], [true, true, 'FULL_REFUND', 42700, 'INR', 4, 1]);
      final off = quoteFromApi(quote(enabled: false, can: false, effect: null, refundable: null, reason: 'CUSTOMER_CANCELLATION_DISABLED', message: 'Orders cannot be cancelled in the app yet. Please contact FoodOnTheGo support.'));
      expect([off.enabled, off.canCancel, off.reason, off.financialEffect, off.refundableMinor, off.reasons, off.message], [false, false, 'CUSTOMER_CANCELLATION_DISABLED', null, null, <String>[], 'Orders cannot be cancelled in the app yet. Please contact FoodOnTheGo support.']);
      final review = quoteFromApi(quote(effect: 'MANUAL_REVIEW', refundable: null));
      expect([review.financialEffect, review.refundableMinor], ['MANUAL_REVIEW', null], reason: 'no amount is promised when a person decides');
    });

    test('the quote is asked of the order\'s own path; an order that is not the customer\'s is "not found"', () async {
      final r = repo();
      r.net.answer = (_) => json(200, {'cancellation': quote()});
      final q = await r.orders.cancellationQuote(number);
      expect([q!.canCancel, r.net.paths], [true, ['GET /customer/orders/$number/cancellation']]);
      r.net.answer = (_) => json(404, null);
      expect(await r.orders.cancellationQuote('FOTG-20261008-NOTMINE'), isNull);
    });

    test('a cancellation sends a reason — never an amount, an effect or a status — and answers the order the backend made of it', () async {
      final r = repo();
      r.net.answer = (_) => json(200, {'order': {...orderDto(status: 'CANCELLED', refund: 'REQUIRED', credential: null), 'cancellation': {'by': 'YOU', 'message': 'You cancelled this order.'}, 'refund': refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700), 'customer_cancellation': {'enabled': true, 'can_cancel': false, 'reason': 'ORDER_ALREADY_CLOSED'}}});
      final after = await r.orders.cancel(number, reasonCode: 'CHANGED_MIND', note: 'Plans changed', expectedVersion: 1);
      expect([after!.orderStatus, after.paymentStatus, after.refunds!.pendingMinor, after.cancellationMessage], [OrderStatus.cancelled, OrderPaymentStatus.refundPending, 42700, 'You cancelled this order.']);
      final sent = r.net.calls.single;
      expect(['${sent.method} ${sent.url.path}', jsonDecode(sent.body)], ['POST /api/v1/customer/orders/$number/cancel', {'reason_code': 'CHANGED_MIND', 'note': 'Plans changed', 'expected_version': 1}]);

      // Without a note and a version nothing else is sent either.
      await r.orders.cancel(number, reasonCode: 'OTHER');
      expect(jsonDecode(r.net.calls.last.body), {'reason_code': 'OTHER'});
    });

    test('the backend\'s refusal reaches the caller with its code: no policy, or the restaurant moved the order first', () async {
      final r = repo();
      r.net.answer = (_) => failure(409, 'order_cancellation_not_allowed', 'This order cannot be cancelled in the app.');
      await expectLater(r.orders.cancel(number, reasonCode: 'CHANGED_MIND'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'order_cancellation_not_allowed')));
      r.net.answer = (_) => failure(409, 'order_version_conflict', 'The order changed.');
      await expectLater(r.orders.cancel(number, reasonCode: 'CHANGED_MIND', expectedVersion: 1), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'order_version_conflict')));
    });
  });

  group('following a refund', () {
    test('every order read again is handed over after its new events, so the refunds on the screen are the backend\'s', () async {
      final r = repo();
      var answer = rejected('REQUIRED', refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED')]);
      r.net.answer = (_) => json(200, {'order': answer});
      final first = orderFromApi(answer, customer);
      final log = <String>[];
      final service = ApiOrderTrackingService(r.orders, every: const Duration(milliseconds: 20), onOrder: (o) => log.add('order ${o.paymentStatus.name} ${o.refunds!.items.single.status}'));
      final stop = service.subscribe(first, onEvent: (e) => log.add('event ${e.sequence} ${e.paymentStatus?.name}'), onConnection: (_) {});
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(log.toSet(), {'order refundPending REQUIRED'}, reason: 'nothing new: no event, the same order');
      log.clear();
      answer = rejected('PROCESSING', refundBlock('PROCESSING', [refundItem('PROCESSING')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING')]);
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(log.take(2).toList(), ['event 5 refundProcessing', 'order refundProcessing PROCESSING'], reason: 'the new line first, then the order it came from');
      stop();
    });

    test('a finished order is followed while its refund is open — required, processing, refunded — and left alone once it is settled', () async {
      final r = repo();
      var answer = rejected('REQUIRED', refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED')]);
      r.net.answer = (_) => json(200, {'order': answer});
      final state = TrackingState(orders: r.orders, verifications: ApiPickupVerificationRepository(r.orders), tracking: ApiOrderTrackingService(r.orders, every: const Duration(milliseconds: 20)), connectivity: MockConnectivityService(), store: MemoryKeyValueStore());
      addTearDown(state.dispose);
      await state.load(number, customerId: customer);
      expect([state.order!.orderStatus, state.order!.paymentStatus, state.connection], [OrderStatus.rejected, OrderPaymentStatus.refundPending, TrackingConnection.completed]);

      answer = rejected('PROCESSING', refundBlock('PROCESSING', [refundItem('PROCESSING')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING')]);
      await Future<void>.delayed(const Duration(milliseconds: 60));
      expect([state.order!.paymentStatus, state.order!.refunds!.items.single.status, state.order!.payment.refundedAmountMinor, state.connection], [OrderPaymentStatus.refundProcessing, 'PROCESSING', null, TrackingConnection.completed]);

      answer = rejected('REFUNDED', refundBlock('REFUNDED', [refundItem('REFUNDED', completedAt: '2026-10-08T08:05:00+00:00')], refunded: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING'), refundLine('REFUND_COMPLETED', 'REFUNDED')]);
      await Future<void>.delayed(const Duration(milliseconds: 60));
      expect([state.order!.orderStatus, state.order!.paymentStatus, state.order!.refunds!.refundedMinor, state.order!.payment.refundedAmountMinor], [OrderStatus.rejected, OrderPaymentStatus.refunded, 42700, 42700]);
      expect(state.ignoredStale + state.ignoredDuplicates, 0);

      final calls = r.net.calls.length;
      await Future<void>.delayed(const Duration(milliseconds: 80));
      expect(r.net.calls.length, calls, reason: 'a settled refund is not asked about again');
    });

    test('a finished order without an open refund is not followed at all', () async {
      final r = repo();
      r.net.answer = (_) => json(200, {'order': rejected('REFUNDED', refundBlock('REFUNDED', [refundItem('REFUNDED', completedAt: '2026-10-08T08:05:00+00:00')], refunded: 42700))});
      final state = TrackingState(orders: r.orders, verifications: ApiPickupVerificationRepository(r.orders), tracking: ApiOrderTrackingService(r.orders, every: const Duration(milliseconds: 20)), connectivity: MockConnectivityService(), store: MemoryKeyValueStore());
      addTearDown(state.dispose);
      await state.load(number, customerId: customer);
      final calls = r.net.calls.length;
      await Future<void>.delayed(const Duration(milliseconds: 80));
      expect([state.connection, r.net.calls.length], [TrackingConnection.completed, calls]);
      expect(state.cancellations, isNotNull, reason: 'cancelling exists on the backend orders');
    });
  });
}
