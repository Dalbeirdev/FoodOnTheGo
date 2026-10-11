import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/account/api_account.dart' show notificationRoute;
import 'package:foodonthego/cart/api_cart.dart' show cartModeOverride;
import 'package:foodonthego/checkout/api_checkout.dart' show checkoutModeOverride;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/notifications/push_notice.dart';
import 'package:foodonthego/order/api_order.dart';
import 'package:foodonthego/order/order_models.dart';
import 'package:foodonthego/payment/api_payment.dart' show paymentModeOverride;
import 'package:foodonthego/pickup/api_pickup.dart' show pickupModeOverride;
import 'package:foodonthego/review/api_review.dart';
import 'package:foodonthego/review/review_models.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Module 34 — reviews on the backend (Android): what is SENT (never an owner, a restaurant, a status or "verified"),
/// how the answers reach the screens, and that a refusal is told in the backend's own words. The network is stubbed
/// with payloads in the shape of the real API.
http.Response _json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json'});
Map<String, dynamic> _error(String code, String message, [Map<String, List<String>>? fields]) => {'error': {'code': code, 'message': message, 'details': fields == null ? <String, dynamic>{} : {'fields': fields}, 'request_id': 'r1'}};

const _number = 'FOTG-20261011-ABC234';
const _reviewId = '0b000000-0000-4000-8000-000000000001';

Map<String, dynamic> reviewDto([Map<String, dynamic> patch = const {}]) => {
      'id': _reviewId, 'order': {'id': 'ord-1', 'order_number': _number}, 'restaurant': {'id': 'loc-1', 'slug': 'burger-hub', 'name': 'Burger Hub', 'branch_label': 'Murthal'},
      'overall_rating': 4, 'category_ratings': {'food_quality': 5}, 'tags': ['great_food'], 'item_feedback': [{'item_id': 'l1', 'name': 'Classic Burger', 'sentiment': 'liked'}],
      'title': null, 'comment': 'Fresh and quick.', 'status': 'PUBLISHED', 'verified_order': true, 'version': 3, 'can_edit': true, 'editable_until': '2026-10-14T08:00:00+00:00', 'edits_left': 2, 'can_remove': true,
      'response': {'text': 'Thank you!', 'restaurant_name': 'Burger Hub', 'responded_at': '2026-10-12T08:00:00+00:00', 'edited': false},
      'submitted_at': '2026-10-11T08:00:00+00:00', 'published_at': '2026-10-11T08:00:00+00:00', 'edited_at': null, ...patch,
    };
const _config = {
  'scale': {'min': 1, 'max': 5}, 'title_max_length': 120, 'comment_max_length': 500,
  'categories': [{'key': 'food_quality', 'required': false, 'group': 'food'}, {'key': 'pickup_experience', 'required': false, 'group': 'pickup'}],
  'tags': [{'key': 'great_food', 'sentiment': 'positive'}, {'key': 'long_wait', 'sentiment': 'negative'}], 'max_tags': 6, 'item_feedback': true, 'submission_window_days': 30, 'edit_window_hours': 72, 'max_edits': 3,
};
Map<String, dynamic> stateDto({bool eligible = true, String reason = 'ELIGIBLE', Map<String, dynamic>? review, bool canEdit = false}) => {
      'eligibility': {'eligible': eligible, 'reason': reason, 'window_ends_at': '2026-11-10T08:00:00+00:00', 'can_edit': canEdit}, 'review': review, 'config': _config,
      'order': {'id': 'ord-1', 'order_number': _number, 'items': [{'id': 'l1', 'name': 'Classic Burger', 'quantity': 2}]},
    };

class Net {
  final List<http.Request> calls = [];
  http.Response Function(http.Request) answer = (_) => _json(404, _error('not_found', 'No stub'));
  late final ApiClient client = ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => 'customer-session', client: MockClient((r) async { calls.add(r); return answer(r); }));
  Map<String, dynamic> body(int i) => jsonDecode(calls[i].body) as Map<String, dynamic>;
}

Order order() {
  final at = DateTime.utc(2026, 10, 11, 7);
  return Order(publicId: 'ord-1', orderNumber: _number, customerId: 'cust-1',
      restaurant: const RestaurantSnapshot(id: 'loc-1', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'x', countryCode: '', timezone: 'Asia/Kolkata'),
      items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000)],
      pricing: const OrderPricing(currency: 'INR', subtotalMinor: 50000, discountMinor: 0, totalMinor: 50000), orderStatus: OrderStatus.completed, paymentStatus: OrderPaymentStatus.paid,
      payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'dev', reference: 'ref', paidAmountMinor: 50000, currency: 'INR'),
      pickup: PickupSnapshot(mode: 'asap', requestedAt: at, estimatedReadyTime: at, restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'),
      pickupCodeReference: '', paymentAttemptId: 'pa', checkoutReference: '', orderNote: '', events: const [], createdAt: at, updatedAt: at);
}
ReviewDraft draft() => const ReviewDraft(overallRating: 4, categoryRatings: {'food_quality': 5}, tags: ['great_food'], itemFeedback: [ItemFeedback(lineId: 'l1', itemName: 'Classic Burger', sentiment: ItemSentiment.liked), ItemFeedback(lineId: 'l2', itemName: 'Fries')], text: '  Fresh and quick.  ', clientSubmissionId: 'sub_abc.123!xyz');

void main() {
  tearDown(() { cartModeOverride = null; pickupModeOverride = null; checkoutModeOverride = null; paymentModeOverride = null; orderModeOverride = null; reviewModeOverride = null; });

  test('reviews come from the backend only when orders do and the build says so', () {
    expect(reviewsFromApi, isFalse);
    reviewModeOverride = 'api';
    expect(reviewsFromApi, isFalse, reason: 'a review is about a backend order');
    cartModeOverride = 'api'; pickupModeOverride = 'api'; checkoutModeOverride = 'api'; paymentModeOverride = 'api'; orderModeOverride = 'api';
    expect(reviewsFromApi, isTrue);
    reviewModeOverride = 'mock';
    expect(reviewsFromApi, isFalse);
  });

  group('mapping', () {
    test('a review of the customer: content, state, what may be done and the restaurant\'s reply', () {
      final r = reviewFromApi(reviewDto(), 'cust-1');
      expect([r.reviewId, r.orderNumber, r.restaurantName, r.restaurantSlug, r.customerId], [_reviewId, _number, 'Burger Hub', 'burger-hub', 'cust-1']);
      expect([r.overallRating, r.categoryRatings, r.tags, r.text, r.status, r.version], [4, {'food_quality': 5}, ['great_food'], 'Fresh and quick.', ReviewStatus.published, 3]);
      expect([r.canEdit, r.canRemove, r.editsLeft], [true, true, 2]);
      expect([r.response?.text, r.response?.restaurantName, r.response?.respondedAt], ['Thank you!', 'Burger Hub', DateTime.utc(2026, 10, 12, 8)]);
      expect(r.itemFeedback.single.sentiment, ItemSentiment.liked);
      final held = reviewFromApi(reviewDto({'status': 'PENDING_MODERATION', 'response': null, 'can_edit': false, 'can_remove': true}), 'cust-1');
      expect([held.status, held.response, held.canEdit], [ReviewStatus.pendingModeration, null, false]);
      expect(reviewFromApi(reviewDto({'status': 'REMOVED'}), 'c').status, ReviewStatus.removed);
      expect(reviewFromApi(reviewDto({'status': 'SOMETHING_NEW'}), 'c').status, ReviewStatus.pendingModeration, reason: 'an unknown state is never shown as public');
    });

    test('the form policy is the backend\'s', () {
      final c = reviewConfigFromApi(_config);
      expect([c.scaleMin, c.scaleMax, c.textMaxLength, c.reviewWindowDays, c.editWindowHours, c.editEnabled, c.itemFeedbackEnabled], [1, 5, 500, 30, 72, true, true]);
      expect(c.categories.map((x) => x.key), ['food_quality', 'pickup_experience']);
      expect(c.tags.map((x) => '${x.key}:${x.sentiment.name}'), ['great_food:positive', 'long_wait:negative']);
      expect(reviewConfigFromApi({..._config, 'max_edits': 0}).editEnabled, isFalse);
    });

    test('a rating summary without reviews has no average — nothing is invented', () {
      final none = ratingSummaryFromApi({'average': null, 'count': 0, 'distribution': {'5': 0, '4': 0, '3': 0, '2': 0, '1': 0}, 'categories': {}});
      expect([none.average, none.count], [null, 0]);
      final some = ratingSummaryFromApi({'average': 4.3, 'count': 7, 'distribution': {'5': 4, '4': 2, '3': 0, '2': 0, '1': 1}});
      expect([some.average, some.count, some.distribution[5], some.distribution[1]], [4.3, 7, 4, 1]);
      expect(ratingSummaryFromApi(null).count, 0);
    });

    test('a public review carries a display name, the rating, the words and the reply — nothing about the customer or the order', () {
      final p = publicReviewFromApi({'id': 'p1', 'author': {'display_name': 'Rahul S.'}, 'overall_rating': 5, 'category_ratings': {}, 'tags': ['great_food'], 'title': null, 'comment': '<b>Great</b>', 'verified_order': true, 'edited': true, 'response': {'text': 'Thanks', 'restaurant_name': 'Burger Hub', 'responded_at': null, 'edited': true}, 'submitted_at': '2026-10-11T08:00:00+00:00'});
      expect([p.reviewId, p.authorName, p.overallRating, p.text, p.edited, p.response?.restaurantName, p.response?.edited], ['p1', 'Rahul S.', 5, '<b>Great</b>', true, 'Burger Hub', true]);
    });

    test('the order carries what may be done about its review', () {
      final eligible = OrderReviewInfo.fromApi({'state': 'ELIGIBLE', 'reason': 'ELIGIBLE', 'window_ends_at': '2026-11-10T08:00:00+00:00', 'review': null})!;
      expect([eligible.eligible, eligible.reviewed, eligible.reviewId, eligible.windowEndsAt], [true, false, null, DateTime.utc(2026, 11, 10, 8)]);
      final reviewed = OrderReviewInfo.fromApi({'state': 'REVIEWED', 'reason': 'REVIEW_ALREADY_EXISTS', 'window_ends_at': null, 'review': {'id': _reviewId, 'overall_rating': 4, 'status': 'PUBLISHED', 'can_edit': true}})!;
      expect([reviewed.eligible, reviewed.reviewed, reviewed.reviewId, reviewed.overallRating, reviewed.canEdit], [false, true, _reviewId, 4, true]);
      final removed = OrderReviewInfo.fromApi({'state': 'REMOVED', 'reason': 'REVIEW_ALREADY_EXISTS', 'review': {'id': _reviewId, 'overall_rating': 4, 'status': 'REMOVED', 'can_edit': false}})!;
      expect([removed.eligible, removed.reviewed], [false, false]);
      expect(OrderReviewInfo.fromApi(null), isNull, reason: 'a development order has no block: the status decides there');
    });

    test('what is sent is ratings, tags, item feedback and text — nothing else', () {
      final body = reviewBody(draft());
      expect(body.keys.toSet(), {'overall_rating', 'category_ratings', 'tags', 'item_feedback', 'comment'});
      expect(body['item_feedback'], [{'item_id': 'l1', 'sentiment': 'liked'}]);
      expect(body['comment'], 'Fresh and quick.');
      expect(reviewBody(const ReviewDraft(overallRating: 5, text: '   ', clientSubmissionId: 'x'))['comment'], isNull);
      expect(clientToken('sub_abc.123!xyz'), 'sub_abc123xyz');
      expect(clientToken('a').length, greaterThanOrEqualTo(8));
      expect(clientToken('x' * 90).length, 64);
    });
  });

  group('ApiReview* on a stubbed backend', () {
    test('one GET answers eligibility, my review and the form policy', () async {
      final net = Net()..answer = (r) => _json(200, stateDto());
      final api = ReviewApi(net.client);
      final config = await ApiReviewConfigProvider(api).configFor(order());
      final el = await ApiReviewEligibilityService(api).check(order(), 'cust-1', config);
      final mine = await ApiReviewRepository(api).getReviewForOrder(_number, 'cust-1');
      expect(net.calls.length, 1);
      expect([net.calls.single.method, net.calls.single.url.path, net.calls.single.headers['Authorization']], ['GET', '/api/v1/customer/orders/$_number/review', 'Bearer customer-session']);
      expect([el.eligible, el.reason, el.existingReview, el.windowEndsAt, mine], [true, EligibilityReason.ok, null, DateTime.utc(2026, 11, 10, 8), null]);
    });

    test('the backend\'s reasons reach the screen; a withdrawn review is "removed" and is not offered for editing', () async {
      Future<ReviewEligibility> check(Map<String, dynamic> dto) { final net = Net()..answer = (_) => _json(200, dto); return ApiReviewEligibilityService(ReviewApi(net.client)).check(order(), 'cust-1', defaultReviewConfig); }
      expect((await check(stateDto(eligible: false, reason: 'ORDER_NOT_COMPLETED'))).reason, EligibilityReason.notCompleted);
      expect((await check(stateDto(eligible: false, reason: 'REVIEW_WINDOW_EXPIRED'))).reason, EligibilityReason.windowExpired);
      expect((await check(stateDto(eligible: false, reason: 'ORDER_NOT_ELIGIBLE'))).reason, EligibilityReason.blocked);
      expect((await check(stateDto(eligible: false, reason: 'A_NEW_REASON'))).reason, EligibilityReason.blocked);
      final reviewed = await check(stateDto(eligible: false, reason: 'REVIEW_ALREADY_EXISTS', review: reviewDto(), canEdit: true));
      expect([reviewed.eligible, reviewed.reason, reviewed.existingReview?.reviewId, reviewed.canEdit], [false, EligibilityReason.alreadyReviewed, _reviewId, true]);
      final removed = await check(stateDto(eligible: false, reason: 'REVIEW_ALREADY_EXISTS', review: reviewDto({'status': 'REMOVED', 'can_edit': false, 'can_remove': false})));
      expect([removed.eligible, removed.reason, removed.existingReview, removed.canEdit], [false, EligibilityReason.removed, null, false]);
    });

    test('submitting posts to the order with a submission token; the customer, the restaurant, a status and "verified" are never sent', () async {
      final net = Net()..answer = (r) => _json(201, {'review': reviewDto({'status': 'PUBLISHED', 'version': 1}), 'created': true});
      final r = await ApiReviewRepository(ReviewApi(net.client)).submitReview(order(), draft());
      expect([net.calls.single.method, net.calls.single.url.path], ['POST', '/api/v1/customer/orders/$_number/review']);
      final body = net.body(0);
      expect(body.keys.toSet(), {'overall_rating', 'category_ratings', 'tags', 'item_feedback', 'comment', 'client_submission_id'});
      for (final forbidden in ['customer_id', 'order_id', 'restaurant_id', 'location_id', 'status', 'verified', 'verified_order']) { expect(body.containsKey(forbidden), isFalse); }
      expect(body['client_submission_id'], 'sub_abc123xyz');
      expect([r.status, r.customerId, r.version], [ReviewStatus.published, 'cust-1', 1]);
    });

    test('a refusal is told in the backend\'s words; a failure that is not a refusal is not', () async {
      final net = Net();
      final repo = ApiReviewRepository(ReviewApi(net.client));
      Future<ReviewFailure> refused(Future<Object?> call) async { try { await call; } on ReviewFailure catch (e) { return e; } fail('expected a refusal'); }

      net.answer = (_) => _json(409, _error('review_already_exists', 'This order has already been reviewed.'));
      final dup = await refused(repo.submitReview(order(), draft()));
      expect([dup.message, dup.code, dup.presentable], ['This order has already been reviewed.', 'review_already_exists', true]);

      net.answer = (_) => _json(422, _error('validation_failed', 'The given data was invalid.', {'comment': ['The review may not contain HTML or scripts.']}));
      final markup = await refused(repo.submitReview(order(), draft()));
      expect([markup.message, markup.presentable], ['The review may not contain HTML or scripts.', true]);

      net.answer = (_) => _json(429, _error('rate_limited', 'Too many attempts. Please wait a moment.'));
      expect((await refused(repo.submitReview(order(), draft()))).presentable, isTrue);

      net.answer = (_) => _json(500, _error('server_error', 'SQLSTATE[23505] duplicate key'));
      final broken = await refused(repo.submitReview(order(), draft()));
      expect([broken.presentable, broken.message.contains('SQLSTATE')], [false, false]);

      net.answer = (_) => throw http.ClientException('offline');
      final offline = await refused(repo.submitReview(order(), draft()));
      expect([offline.presentable, offline.code], [false, 'network']);
      // Nothing was kept to be sent later: every attempt was one request, made when asked.
      expect(net.calls.length, 5);
    });

    test('an edit sends the version that was loaded; without one it asks first', () async {
      final net = Net();
      net.answer = (r) => r.method == 'GET' && r.url.path.endsWith('/review') ? _json(200, stateDto(eligible: false, reason: 'REVIEW_ALREADY_EXISTS', review: reviewDto(), canEdit: true)) : _json(200, {'review': reviewDto({'version': 4, 'overall_rating': 2})});
      final api = ReviewApi(net.client); final repo = ApiReviewRepository(api);
      await repo.getReviewForOrder(_number, 'cust-1');
      final edited = await repo.updateReview(_reviewId, 'cust-1', draft().copyWith(overallRating: 2));
      expect([net.calls.last.method, net.calls.last.url.path], ['PATCH', '/api/v1/customer/reviews/$_reviewId']);
      expect(net.body(net.calls.length - 1)['version'], 3);
      expect([edited.version, edited.overallRating], [4, 2]);
      // The next edit uses the version the last answer carried.
      await repo.updateReview(_reviewId, 'cust-1', draft());
      expect(net.body(net.calls.length - 1)['version'], 4);

      final cold = Net()..answer = (r) => _json(200, {'review': reviewDto({'version': 7})});
      await ApiReviewRepository(ReviewApi(cold.client)).updateReview(_reviewId, 'cust-1', draft());
      expect(cold.calls.map((c) => c.method), ['GET', 'PATCH']);
      expect(cold.body(1)['version'], 7);

      net.answer = (_) => _json(409, _error('version_conflict', 'This review was changed in the meantime. Load it again.'));
      await expectLater(repo.updateReview(_reviewId, 'cust-1', draft()), throwsA(isA<ReviewFailure>().having((e) => e.message, 'message', 'This review was changed in the meantime. Load it again.')));
    });

    test('withdraw, my reviews, the public list with its query, and a report', () async {
      final net = Net();
      final repo = ApiReviewRepository(ReviewApi(net.client));
      net.answer = (_) => _json(200, {'message': 'ok'});
      await repo.deleteReview(_reviewId, 'cust-1');
      expect([net.calls.last.method, net.calls.last.url.path], ['DELETE', '/api/v1/customer/reviews/$_reviewId']);

      net.answer = (_) => _json(200, {'data': [reviewDto(), reviewDto({'id': 'r2', 'status': 'HIDDEN', 'response': null})], 'meta': {'total': 2, 'current_page': 1, 'per_page': 50, 'last_page': 1}});
      final mine = await repo.listMine('cust-1');
      expect(mine.map((r) => r.status), [ReviewStatus.published, ReviewStatus.hidden]);
      expect(net.calls.last.url.path, '/api/v1/customer/reviews');

      net.answer = (_) => _json(200, {'data': [{'id': 'p1', 'author': {'display_name': 'Asha K.'}, 'overall_rating': 2, 'tags': [], 'comment': 'Slow', 'verified_order': true, 'edited': false, 'response': null, 'submitted_at': '2026-10-10T08:00:00+00:00'}], 'meta': {'total': 9, 'current_page': 2, 'per_page': 5, 'last_page': 2}, 'summary': {'average': 3.4, 'count': 9, 'distribution': {'5': 2, '4': 2, '3': 2, '2': 2, '1': 1}}});
      final page = await repo.listPublic('burger-hub', page: 2, sort: PublicReviewSort.lowest, rating: 2);
      expect(net.calls.last.url.path, '/api/v1/restaurants/burger-hub/reviews');
      expect(net.calls.last.url.queryParameters, {'page': '2', 'per_page': '5', 'sort': 'lowest', 'rating': '2'});
      expect(net.calls.last.headers.containsKey('Authorization'), isFalse, reason: 'public reviews need no session');
      expect([page.total, page.page, page.items.single.authorName, page.summary.average, page.summary.count], [9, 2, 'Asha K.', 3.4, 9]);
      await repo.listPublic('burger-hub');
      expect(net.calls.last.url.queryParameters, {'page': '1', 'per_page': '5', 'sort': 'newest'});

      net.answer = (_) => _json(201, {'message': 'ok'});
      await repo.reportReview('p1', 'HARASSMENT', explanation: '  Names a staff member  ');
      expect([net.calls.last.method, net.calls.last.url.path, net.body(net.calls.length - 1)], ['POST', '/api/v1/reviews/p1/report', {'reason': 'HARASSMENT', 'explanation': 'Names a staff member'}]);
      await repo.reportReview('p1', 'SPAM');
      expect(net.body(net.calls.length - 1), {'reason': 'SPAM', 'explanation': null});
      net.answer = (_) => _json(409, _error('review_already_reported', 'You have already reported this review.'));
      await expectLater(repo.reportReview('p1', 'SPAM'), throwsA(isA<ReviewFailure>().having((e) => e.presentable, 'presentable', true)));
    });

    test('an order of the backend carries its review block into the order and its summary', () {
      // The smallest order payloads the mappers accept are in api_order_test; here only the block matters.
      expect(OrderReviewInfo.fromApi({'state': 'NOT_ELIGIBLE', 'reason': 'ORDER_NOT_COMPLETED', 'review': null})!.state, 'NOT_ELIGIBLE');
    });
  });

  group('the REVIEW notification target', () {
    test('opens the review of the order it names — a route of the app, never a URL from the server', () {
      expect(notificationRoute('REVIEW', _number), '/order/$_number/review');
      expect(notificationRoute('REVIEW', 'https://evil.example'), '/my-reviews');
      expect(notificationRoute('REVIEW', null), '/my-reviews');
      expect(PushNotice.fromData({'type': 'review.response', 'notification_id': 'n1', 'target': 'REVIEW', 'reference': _number, 'category': 'ORDER_UPDATES'})!.route, '/order/$_number/review');
      expect(PushNotice.fromData({'target': 'REVIEW', 'reference': 'not-an-order'}), isNull);
      expect(PushNotice.fromData({'target': 'REVIEW'}), isNull);
    });
  });
}
