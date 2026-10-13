import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/account/api_account.dart' show notificationFromApi, notificationRoute;
import 'package:foodonthego/auth/auth_repository.dart' show MemoryKeyValueStore;
import 'package:foodonthego/cart/api_cart.dart' show cartModeOverride;
import 'package:foodonthego/checkout/api_checkout.dart' show checkoutModeOverride;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/notifications/push_notice.dart';
import 'package:foodonthego/order/api_order.dart' show orderModeOverride;
import 'package:foodonthego/payment/api_payment.dart' show paymentModeOverride;
import 'package:foodonthego/pickup/api_pickup.dart' show pickupModeOverride;
import 'package:foodonthego/support/api_support.dart';
import 'package:foodonthego/support/support_drafts.dart';
import 'package:foodonthego/support/support_models.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Module 37 — support cases on the backend (Android): how the answers reach the screens, what is SENT (a category, a
/// subject, a message and the customer's own references — never a priority, a status or an owner), the two retry
/// tokens (Idempotency-Key for a new case, client_message_id for a reply), every refusal in the backend's own words,
/// the notification target, and the drafts kept on the device. The network is stubbed with payloads in the shape of
/// the real API.
http.Response _json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json'});
Map<String, dynamic> _error(String code, String message, [Map<String, List<String>>? fields]) => {'error': {'code': code, 'message': message, 'details': fields == null ? <String, dynamic>{} : {'fields': fields}, 'request_id': 'r1'}};

const caseId = '0c000000-0000-4000-8000-000000000001';
const caseNumber = 'CS-ABCD2345';
const orderNumber = 'FOTG-20261011-ABC234';
const refundReference = 'RFD-5D00000000';

Map<String, dynamic> summaryDto([Map<String, dynamic> patch = const {}]) => {
      'id': caseId, 'case_number': caseNumber, 'category': 'ORDER', 'subcategory': 'ORDER_DELAYED', 'subject': 'My order is late', 'status': 'WAITING_FOR_YOU', 'unread': true, 'message_count': 2, 'location': null,
      'created_at': '2026-10-12T08:00:00+00:00', 'last_message_at': '2026-10-12T09:00:00+00:00', 'resolved_at': null, 'closed_at': null, ...patch,
    };
const orderLink = {'id': 'l-1', 'type': 'ORDER', 'reference': orderNumber, 'record_id': 'o-1', 'order_number': orderNumber, 'status': 'COMPLETED', 'restaurant': 'Burger Hub', 'total': {'amount': 42700, 'currency': 'INR'}, 'placed_at': '2026-10-11T08:00:00+00:00', 'refund_status': 'PROCESSING'};
const refundLink = {'id': 'l-2', 'type': 'REFUND', 'reference': refundReference, 'record_id': 'r-1', 'status': 'PROCESSING', 'amount': {'amount': 42700, 'currency': 'INR'}, 'order_number': orderNumber};
const paymentLink = {'id': 'l-3', 'type': 'PAYMENT', 'reference': 'PAY-1A00000000', 'record_id': 'p-1', 'status': 'REVIEW_REQUIRED', 'amount': {'amount': 42700, 'currency': 'INR'}, 'order_created': false};
Map<String, dynamic> caseDto([Map<String, dynamic> patch = const {}]) => {
      ...summaryDto(), 'links': [orderLink, refundLink, paymentLink], 'can': {'reply': true, 'close': false, 'withdraw': true, 'reopen': false}, 'reopen_until': null, 'attachments': {'enabled': false}, ...patch,
    };
Map<String, dynamic> messageDto(String id, String author, String body, [String at = '2026-10-12T08:00:00+00:00']) => {'id': id, 'author': author, 'author_name': null, 'body': body, 'created_at': at};
Map<String, dynamic> page(List<Map<String, dynamic>> data, {int current = 1, int last = 1, int? total, Map<String, dynamic> extra = const {}}) => {'data': data, 'links': {'first': null, 'last': null, 'prev': null, 'next': null}, 'meta': {'current_page': current, 'last_page': last, 'per_page': 20, 'total': total ?? data.length}, ...extra};

class Net {
  final List<http.Request> calls = [];
  http.Response Function(http.Request) answer = (_) => _json(404, _error('not_found', 'No stub'));
  bool down = false;
  late final ApiClient client = ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => 'customer-session', client: MockClient((r) async { calls.add(r); if (down) throw Exception('no route to host'); return answer(r); }));
  late final repo = ApiSupportRepository(client);
  Map<String, dynamic> body(int i) => jsonDecode(calls[i].body) as Map<String, dynamic>;
}

const input = NewSupportCase(category: 'REFUND', subcategory: 'REFUND_NOT_RECEIVED', subject: '  Where is my refund?  ', message: '  It has been a week.\nPlease check.  ', order: orderNumber, refund: refundReference);

void main() {
  tearDown(() { cartModeOverride = null; pickupModeOverride = null; checkoutModeOverride = null; paymentModeOverride = null; orderModeOverride = null; });

  test('support follows the order domain: the backend only when orders come from it — there is no flag of its own', () {
    expect(supportFromApi, isFalse);
    cartModeOverride = 'api'; pickupModeOverride = 'api'; checkoutModeOverride = 'api'; paymentModeOverride = 'api';
    expect(supportFromApi, isFalse, reason: 'a case is about a backend order, payment or refund');
    orderModeOverride = 'api';
    expect(supportFromApi, isTrue);
  });

  group('mapping', () {
    test('the catalog: category and subcategory codes and the limits of what can be written', () async {
      final net = Net()..answer = (_) => _json(200, {
            'categories': [{'code': 'ORDER', 'subcategories': ['ORDER_DELAYED', 'WRONG_ORDER']}, {'code': 'OTHER', 'subcategories': <String>[]}],
            'link_types': ['ORDER', 'PAYMENT', 'REFUND'], 'statuses': ['OPEN', 'IN_PROGRESS', 'WAITING_FOR_YOU', 'RESOLVED', 'CLOSED', 'CANCELLED'], 'limits': {'subject_max': 140, 'message_max': 4000}, 'attachments': {'enabled': false},
          });
      final c = await net.repo.catalog();
      expect([net.calls.single.method, net.calls.single.url.path, net.calls.single.headers['Authorization']], ['GET', '/api/v1/customer/support/catalog', 'Bearer customer-session']);
      expect(c.categories.map((x) => x.code), ['ORDER', 'OTHER']);
      expect(c.category('ORDER')!.subcategories, ['ORDER_DELAYED', 'WRONG_ORDER']); expect(c.category('OTHER')!.subcategories, isEmpty); expect(c.category('NOPE'), isNull);
      expect([c.subjectMax, c.messageMax], [140, 4000]);
    });

    test('my cases: the filter and the page are asked of the backend; unread, the status word and "more" come from it', () async {
      final net = Net()..answer = (_) => _json(200, page([summaryDto(), summaryDto({'id': 'c-2', 'case_number': 'CS-ZZZZ9999', 'status': 'IN_PROGRESS', 'unread': false, 'subcategory': null})], current: 1, last: 3, total: 41, extra: {'unread': 2}));
      final p = await net.repo.listCases(state: 'closed', page: 1, perPage: 20);
      expect([net.calls.single.method, net.calls.single.url.path], ['GET', '/api/v1/customer/support/cases']);
      expect(net.calls.single.url.queryParameters, {'state': 'closed', 'page': '1', 'per_page': '20'});
      expect([p.items.length, p.page, p.hasMore, p.total, p.unread], [2, 1, true, 41, 2]);
      final a = p.items.first;
      expect([a.id, a.caseNumber, a.category, a.subcategory, a.subject, a.status, a.unread, a.messageCount], [caseId, caseNumber, 'ORDER', 'ORDER_DELAYED', 'My order is late', 'WAITING_FOR_YOU', true, 2]);
      expect([a.createdAt, a.lastMessageAt, a.resolvedAt, a.closedAt], [DateTime.utc(2026, 10, 12, 8), DateTime.utc(2026, 10, 12, 9), null, null]);
      expect([p.items.last.subcategory, p.items.last.unread], [null, false]);
      net.answer = (_) => _json(200, page([], current: 3, last: 3, extra: {'unread': 0}));
      final end = await net.repo.listCases(page: 3);
      expect([end.items, end.hasMore, end.unread], [isEmpty, false, 0]);
      expect(net.calls.last.url.queryParameters['state'], 'open', reason: 'open cases are the default list');
    });

    test('a case: what may be done is exactly what the backend says; the linked records carry their own state and amount', () async {
      final net = Net()..answer = (_) => _json(200, {'case': caseDto()});
      final c = await net.repo.getCase(caseNumber);
      expect([net.calls.single.method, net.calls.single.url.path], ['GET', '/api/v1/customer/support/cases/$caseNumber']);
      expect([c.id, c.caseNumber, c.status, c.subject], [caseId, caseNumber, 'WAITING_FOR_YOU', 'My order is late']);
      expect([c.can.reply, c.can.close, c.can.withdraw, c.can.reopen], [true, false, true, false]);
      expect(c.reopenUntil, isNull);
      final [order, refund, payment] = c.links;
      expect([order.type, order.reference, order.status, order.restaurant, order.amountMinor, order.currency, order.refundStatus], ['ORDER', orderNumber, 'COMPLETED', 'Burger Hub', 42700, 'INR', 'PROCESSING']);
      expect([refund.type, refund.reference, refund.status, refund.amountMinor, refund.currency, refund.orderNumber], ['REFUND', refundReference, 'PROCESSING', 42700, 'INR', orderNumber]);
      expect([payment.type, payment.reference, payment.status, payment.amountMinor], ['PAYMENT', 'PAY-1A00000000', 'REVIEW_REQUIRED', 42700]);

      // A resolved case inside its reopen window; "can" without a key, or with anything but true, offers nothing.
      final r = supportCaseFromApi(caseDto({'status': 'RESOLVED', 'can': {'reply': false, 'close': true, 'reopen': 'yes'}, 'reopen_until': '2026-10-26T09:00:00+00:00', 'links': <Object>[]}));
      expect([r.can.reply, r.can.close, r.can.withdraw, r.can.reopen], [false, true, false, false]);
      expect([r.reopenUntil, r.links], [DateTime.utc(2026, 10, 26, 9), isEmpty]);
      final bare = supportCaseFromApi(summaryDto());
      expect([bare.can.reply, bare.can.close, bare.can.withdraw, bare.can.reopen, bare.links], [false, false, false, false, isEmpty]);
    });

    test('nothing of the service desk is mapped: an answer that carried it would not reach a screen', () {
      final c = supportCaseFromApi(caseDto({'priority': 'URGENT', 'assigned': {'team': 'PAYMENTS', 'admin': {'name': 'Asha'}}, 'sla': {'status': 'BREACHED'}, 'internal_notes': [{'body': 'chargeback risk'}]}));
      // The model has no field for any of it — this is a compile-time fact; the summary is what a screen can draw.
      expect([c.caseNumber, c.status, c.subject, c.category], [caseNumber, 'WAITING_FOR_YOU', 'My order is late', 'ORDER']);
    });

    test('the conversation: oldest first as the backend sends it, who wrote as a role, the text as it was written', () async {
      final net = Net()..answer = (_) => _json(200, page([
            messageDto('m-1', 'YOU', 'Where is my order?'), messageDto('m-2', 'SUPPORT', '<script>alert(1)</script> We are checking.', '2026-10-12T09:00:00+00:00'),
            messageDto('m-3', 'SYSTEM', 'The case was reopened.'), messageDto('m-4', 'SOMEBODY_NEW', 'x'),
          ], current: 2, last: 3));
      final p = await net.repo.messages(caseId, page: 2, perPage: 50);
      expect([net.calls.single.method, net.calls.single.url.path], ['GET', '/api/v1/customer/support/cases/$caseId/messages']);
      expect(net.calls.single.url.queryParameters, {'page': '2', 'per_page': '50'});
      expect(p.items.map((m) => m.id), ['m-1', 'm-2', 'm-3', 'm-4']);
      expect(p.items.map((m) => m.author), [SupportAuthor.you, SupportAuthor.support, SupportAuthor.system, SupportAuthor.system], reason: 'an author the app does not know is never shown as the customer');
      expect(p.items[1].body, '<script>alert(1)</script> We are checking.'); expect(p.items[1].createdAt, DateTime.utc(2026, 10, 12, 9));
      expect([p.page, p.hasMore], [2, true]);
    });
  });

  group('what is sent', () {
    test('a new case: category, subject, message, the customer\'s own references, source ANDROID — under the Idempotency-Key it was given', () async {
      final net = Net()..answer = (_) => _json(201, {'case': caseDto({'status': 'OPEN', 'category': 'REFUND'})});
      final key = newSupportIdempotencyKey();
      final c = await net.repo.openCase(input, idempotencyKey: key);
      final call = net.calls.single;
      expect([call.method, call.url.path, call.headers['Idempotency-Key'], call.headers['Authorization']], ['POST', '/api/v1/customer/support/cases', key, 'Bearer customer-session']);
      expect(net.body(0), {'category': 'REFUND', 'subcategory': 'REFUND_NOT_RECEIVED', 'subject': 'Where is my refund?', 'message': 'It has been a week.\nPlease check.', 'order': orderNumber, 'refund': refundReference, 'source': 'ANDROID', 'locale': 'en'});
      for (final forbidden in ['priority', 'status', 'customer_id', 'requester', 'assignee', 'team', 'visibility', 'internal', 'links']) { expect(net.body(0).containsKey(forbidden), isFalse, reason: forbidden); }
      expect([c.caseNumber, c.status], [caseNumber, 'OPEN']);

      // The same request again (a retry after a lost answer) is byte for byte the same under the same key.
      await net.repo.openCase(input, idempotencyKey: key);
      expect(net.calls[1].body, net.calls[0].body); expect(net.calls[1].headers['Idempotency-Key'], key);

      // Nothing linked, no subcategory: neither key is sent at all.
      await net.repo.openCase(const NewSupportCase(category: 'OTHER', subject: 'Hello', message: 'A question'), idempotencyKey: newSupportIdempotencyKey());
      expect(net.body(2).keys.toSet(), {'category', 'subject', 'message', 'source', 'locale'});
    });

    test('the two retry tokens have the shape the backend accepts and are never the same twice', () {
      final ids = {for (var i = 0; i < 50; i++) newSupportMessageId()}, keys = {for (var i = 0; i < 50; i++) newSupportIdempotencyKey()};
      expect(ids.length, 50); expect(keys.length, 50);
      for (final id in ids) { expect(RegExp(r'^[A-Za-z0-9_-]{8,64}$').hasMatch(id), isTrue, reason: id); }
      for (final k in keys) { expect(RegExp(r'^[A-Za-z0-9._:-]{16,120}$').hasMatch(k), isTrue, reason: k); }
      // Two attempts are "the same request" exactly when what would be sent is the same.
      expect(input.signature, const NewSupportCase(category: 'REFUND', subcategory: 'REFUND_NOT_RECEIVED', subject: '  Where is my refund?  ', message: '  It has been a week.\nPlease check.  ', order: orderNumber, refund: refundReference).signature);
      expect(input.signature == const NewSupportCase(category: 'REFUND', subject: 'Where is my refund?', message: 'changed').signature, isFalse);
    });

    test('a reply: the message and the id it was given when written; the answer is the stored message and the case after it', () async {
      final net = Net()..answer = (_) => _json(201, {'message': messageDto('m-9', 'YOU', 'Any news?'), 'case': caseDto({'status': 'IN_PROGRESS', 'message_count': 3, 'unread': false})});
      final r = await net.repo.reply(caseId, message: '  Any news?  ', clientMessageId: 'abcdef0123456789abcdef0123456789');
      final call = net.calls.single;
      expect([call.method, call.url.path], ['POST', '/api/v1/customer/support/cases/$caseId/messages']);
      expect(net.body(0), {'message': 'Any news?', 'client_message_id': 'abcdef0123456789abcdef0123456789'});
      expect(call.headers.containsKey('Idempotency-Key'), isFalse, reason: 'a reply is made safe by its own id, in the body');
      expect([r.message.id, r.message.author, r.message.body], ['m-9', SupportAuthor.you, 'Any news?']);
      expect([r.supportCase.status, r.supportCase.messageCount, r.supportCase.unread], ['IN_PROGRESS', 3, false]);
    });

    test('closing says what was meant and nothing else; reopening sends only the message', () async {
      final net = Net()..answer = (_) => _json(200, {'case': caseDto({'status': 'CLOSED', 'can': {'reply': false, 'close': false, 'withdraw': false, 'reopen': true}, 'reopen_until': '2026-10-19T09:00:00+00:00'})});
      final closed = await net.repo.close(caseId, confirm: true);
      expect([net.calls[0].method, net.calls[0].url.path], ['POST', '/api/v1/customer/support/cases/$caseId/close']);
      expect(net.body(0), {'intent': 'CONFIRM'});
      expect([closed.status, closed.can.reopen, closed.reopenUntil], ['CLOSED', true, DateTime.utc(2026, 10, 19, 9)]);
      net.answer = (_) => _json(200, {'case': caseDto({'status': 'OPEN'})});
      final open = await net.repo.reopen(caseId, '  It happened again.  ');
      expect([net.calls[1].method, net.calls[1].url.path], ['POST', '/api/v1/customer/support/cases/$caseId/reopen']);
      expect(net.body(1), {'message': 'It happened again.'}); expect(open.status, 'OPEN');
    });

    test('confirm and withdraw say what was meant, and nothing else', () async {
      final net = Net()..answer = (_) => _json(200, {'case': caseDto({'status': 'CLOSED'})});
      await net.repo.close(caseId, confirm: true);
      await net.repo.close(caseId, confirm: false);
      expect([net.calls[0].url.path, net.calls[1].url.path], ['/api/v1/customer/support/cases/$caseId/close', '/api/v1/customer/support/cases/$caseId/close']);
      expect([net.body(0), net.body(1)], [{'intent': 'CONFIRM'}, {'intent': 'WITHDRAW'}]);
    });

    test('only a case number or a case id is ever asked for: anything else is "not found" without a request', () async {
      final net = Net();
      for (final junk in ['../../admin', 'https://evil.example', 'CS-0000OOOO', 'cs-abcd2345', '', 'FOTG-20261011-ABC234']) {
        await expectLater(net.repo.getCase(junk), throwsA(isA<SupportFailure>().having((e) => e.notFound, 'notFound', isTrue)), reason: junk);
      }
      expect(net.calls, isEmpty);
      net.answer = (_) => _json(200, {'case': caseDto()});
      await net.repo.getCase(caseId);
      expect(net.calls.single.url.path, '/api/v1/customer/support/cases/$caseId');
    });
  });

  group('refusals and failures', () {
    Future<SupportFailure> failure(Net net, Future<Object?> Function(ApiSupportRepository) call) async { try { await call(net.repo); } on SupportFailure catch (e) { return e; } fail('the call should have failed'); }

    test('every refusal of the backend is told in its own words', () async {
      final cases = <(int, String, String)>[
        (404, 'case_not_found', 'This case does not exist.'),
        (409, 'case_not_open', 'This case is resolved. Reopen it to write again, or open a new case.'),
        (409, 'case_reopen_window_over', 'This case can no longer be reopened. Please open a new case and mention CS-ABCD2345.'),
        (429, 'too_many_open_cases', 'You already have 20 cases that are being worked on. Please add to one of them instead of opening another.'),
        (404, 'order_not_found', 'This order does not exist.'),
        (404, 'payment_not_found', 'This payment does not exist.'),
        (404, 'refund_not_found', 'This refund does not exist.'),
      ];
      for (final (status, code, message) in cases) {
        final net = Net()..answer = (_) => _json(status, _error(code, message));
        final e = await failure(net, (r) => r.reply(caseId, message: 'x', clientMessageId: 'abcdef0123456789'));
        expect([e.code, e.message, e.presentable, e.offline, e.notFound], [code, message, true, false, status == 404], reason: code);
      }
    });

    test('a refused form names its fields; the first field error is the sentence shown', () async {
      final net = Net()..answer = (_) => _json(422, _error('validation_failed', 'This category cannot be used here.', {'category': ['Choose one of the categories offered.'], 'subject': ['The subject is too long.']}));
      final e = await failure(net, (r) => r.openCase(input, idempotencyKey: newSupportIdempotencyKey()));
      expect([e.code, e.presentable, e.message], ['validation_failed', true, 'Choose one of the categories offered.']);
      expect([e.field('category'), e.field('subject'), e.field('message')], ['Choose one of the categories offered.', 'The subject is too long.', null]);
    });

    test('no connection is "offline" — nothing reached the backend; a server error and a permission problem stay generic', () async {
      final down = Net()..down = true;
      for (final call in <Future<Object?> Function(ApiSupportRepository)>[(r) => r.catalog(), (r) => r.listCases(), (r) => r.getCase(caseNumber), (r) => r.messages(caseId), (r) => r.reply(caseId, message: 'x', clientMessageId: 'abcdef0123456789'), (r) => r.openCase(input, idempotencyKey: newSupportIdempotencyKey()), (r) => r.close(caseId, confirm: true), (r) => r.reopen(caseId, 'x')]) {
        final e = await failure(down, call);
        expect([e.offline, e.presentable, e.code], [true, false, 'network']);
      }
      final broken = Net()..answer = (_) => _json(500, _error('server_error', 'SQLSTATE[42P01]: relation "support_cases" does not exist'));
      final e = await failure(broken, (r) => r.listCases());
      expect([e.offline, e.presentable, e.message], [false, false, 'support_request_failed'], reason: 'an internal message is never shown');
      final denied = Net()..answer = (_) => _json(403, _error('account_suspended', 'This account is suspended.'));
      expect((await failure(denied, (r) => r.listCases())).presentable, isFalse);
      // A key the backend still holds for an unfinished first attempt is a refusal the form can explain.
      final busy = Net()..answer = (_) => _json(409, _error('idempotency_in_progress', 'The original request is still being processed.'));
      expect((await failure(busy, (r) => r.openCase(input, idempotencyKey: newSupportIdempotencyKey()))).code, 'idempotency_in_progress');
    });
  });

  group('the notification target SUPPORT_CASE', () {
    test('an inbox item opens the case by its case number; anything that is not a case number opens the list, never a URL', () {
      expect(notificationRoute('SUPPORT_CASE', caseNumber), '/support/cases/$caseNumber');
      expect(notificationRoute('SUPPORT_CASE', null), '/support');
      for (final bad in ['https://evil.example/x', '../../admin', orderNumber, 'CS-0000OOOO', 'cs-abcd2345', caseId]) { expect(notificationRoute('SUPPORT_CASE', bad), '/support', reason: bad); }
      // The order targets still take order numbers only.
      expect(notificationRoute('ORDER_DETAIL', caseNumber), '/my-orders'); expect(notificationRoute('ORDER_TRACKING', orderNumber), '/order-tracking/$orderNumber');
      final n = notificationFromApi({'id': 'n-9', 'category': 'ACCOUNT_SECURITY', 'type': 'support.reply', 'priority': 'NORMAL', 'title': 'Support replied', 'body': 'There is a new reply in case $caseNumber.', 'action': {'target': 'SUPPORT_CASE', 'reference': caseNumber}, 'subject': {'type': 'SUPPORT_CASE', 'id': caseId, 'reference': caseNumber}, 'read': false, 'created_at': '2026-10-12T10:00:00Z'});
      expect([n.route, n.target, n.reference, n.type, n.kind], ['/support/cases/$caseNumber', 'SUPPORT_CASE', caseNumber, 'support.reply', 'updates']);
    });

    test('a push with this target is kept and opens the case; a malformed or missing reference drops it', () {
      expect(PushNotice.targets.contains('SUPPORT_CASE'), isTrue);
      for (final type in ['support.case_created', 'support.reply', 'support.case_resolved']) {
        final n = PushNotice.fromData({'type': type, 'notification_id': 'n-1', 'target': 'SUPPORT_CASE', 'reference': caseNumber, 'category': 'ACCOUNT_SECURITY'}, title: 'Support replied');
        expect([n!.route, n.type, n.reference], ['/support/cases/$caseNumber', type, caseNumber]);
      }
      expect(PushNotice.fromData({'target': 'SUPPORT_CASE', 'reference': '../../admin'}), isNull);
      expect(PushNotice.fromData({'target': 'SUPPORT_CASE', 'reference': orderNumber}), isNull, reason: 'an order number is not a case');
      expect(PushNotice.fromData({'target': 'SUPPORT_CASE'}), isNull);
      expect(PushNotice.fromData({'target': 'ORDER_DETAIL', 'reference': caseNumber}), isNull, reason: 'an order target still needs an order number');
      expect(PushNotice.routeFor('SUPPORT_CASE', 'nope'), '/support');
    });
  });

  group('what is kept on the device (SupportDrafts)', () {
    test('a reply draft and its unconfirmed replies survive a restart, keep their ids, and go only when told', () async {
      final store = MemoryKeyValueStore();
      final drafts = SupportDrafts(store);
      final live = await drafts.reply('cust-1', caseId);
      expect([live.text, live.pending], ['', isEmpty]);
      drafts.setReplyText('cust-1', caseId, 'half a sentence');
      final p = PendingReply(clientMessageId: newSupportMessageId(), body: 'Any news?', at: DateTime.utc(2026, 10, 12, 9));
      drafts.addPending('cust-1', caseId, p); drafts.addPending('cust-1', caseId, p);
      expect(identical(await drafts.reply('cust-1', caseId), live), isTrue, reason: 'one live draft per case in a session');
      expect([live.text, live.pending.length], ['half a sentence', 1]);

      // A new app session reads the same thing from the store — with the SAME message id.
      final again = await SupportDrafts(store).reply('cust-1', caseId);
      expect([again.text, again.pending.single.clientMessageId, again.pending.single.body], ['half a sentence', p.clientMessageId, 'Any news?']);
      // Somebody else on this phone, or another case, sees nothing of it.
      expect((await SupportDrafts(store).reply('cust-2', caseId)).isEmpty, isTrue);
      expect((await SupportDrafts(store).reply('cust-1', 'another-case')).isEmpty, isTrue);

      drafts.removePending('cust-1', caseId, p.clientMessageId);
      drafts.setReplyText('cust-1', caseId, '');
      expect(await store.read(SupportDrafts.replyKey('cust-1', caseId)), isNull, reason: 'an empty draft leaves nothing behind');
    });

    test('the new-case form keeps its text and the key of its last attempt, per record it is about', () async {
      final store = MemoryKeyValueStore();
      final drafts = SupportDrafts(store);
      expect(await drafts.newCase('cust-1', 'new'), isNull);
      drafts.saveNewCase('cust-1', 'new.order.$orderNumber', NewCaseDraft(category: 'ORDER', subject: 'Late', message: 'Still waiting', idempotencyKey: 'sup-0123456789abcdef', sentSignature: 'sig'));
      final back = await SupportDrafts(store).newCase('cust-1', 'new.order.$orderNumber');
      expect([back!.category, back.subject, back.message, back.idempotencyKey, back.sentSignature], ['ORDER', 'Late', 'Still waiting', 'sup-0123456789abcdef', 'sig']);
      expect(await SupportDrafts(store).newCase('cust-1', 'new'), isNull, reason: 'a draft about one order is not the draft of another request');
      expect(await SupportDrafts(store).newCase('cust-2', 'new.order.$orderNumber'), isNull);
      drafts.clearNewCase('cust-1', 'new.order.$orderNumber');
      expect(await drafts.newCase('cust-1', 'new.order.$orderNumber'), isNull);
      expect(await store.read(SupportDrafts.newCaseKey('cust-1', 'new.order.$orderNumber')), isNull);
    });

    test('a stored draft that is not ours (damaged, a foreign id) is ignored, never sent', () async {
      final store = MemoryKeyValueStore();
      await store.write(SupportDrafts.replyKey('cust-1', caseId), jsonEncode({'text': 7, 'pending': [{'id': 'short', 'body': 'x'}, {'id': '../../etc', 'body': 'y'}, {'id': 'abcdef0123456789', 'body': 'kept'}, 'junk']}));
      final d = await SupportDrafts(store).reply('cust-1', caseId);
      expect([d.text, d.pending.map((p) => p.body)], ['', ['kept']]);
      await store.write(SupportDrafts.replyKey('cust-1', 'broken'), '{not json');
      expect((await SupportDrafts(store).reply('cust-1', 'broken')).isEmpty, isTrue);
    });
  });
}
