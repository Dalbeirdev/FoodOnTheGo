import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/order/order_models.dart';
import 'package:foodonthego/review/review_models.dart';

/// Module 16 — review domain (Android): validation, tags, eligibility, one-review-per-order, edit, failure, summary.
void main() {
  Order order(OrderStatus s, {String customer = 'u1', DateTime? doneAt, String country = 'IN'}) {
    final at = DateTime.utc(2026, 9, 20, 9); final done = doneAt ?? DateTime.now().toUtc();
    return Order(publicId: 'P-${s.name}-${done.microsecondsSinceEpoch}', orderNumber: 'FOTG-${s.name}', customerId: customer,
        restaurant: RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'x', countryCode: country, timezone: 'Asia/Kolkata'),
        items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000)],
        pricing: const OrderPricing(currency: 'INR', subtotalMinor: 50000, discountMinor: 0, totalMinor: 50000), orderStatus: s, paymentStatus: OrderPaymentStatus.paid,
        payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'ref', paidAmountMinor: 50000, currency: 'INR'),
        pickup: PickupSnapshot(mode: 'asap', requestedAt: at, estimatedReadyTime: at, restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'),
        pickupCodeReference: 'pv', paymentAttemptId: 'pa', checkoutReference: 'ck', orderNote: '',
        events: [if (s == OrderStatus.completed || s == OrderStatus.pickedUp) OrderEvent(eventId: 'e', sequence: 1, type: OrderEventType.completed, status: s, at: done, actor: 'restaurant')], lastEventSequence: 1, createdAt: at, updatedAt: done);
  }

  test('TEST 3 / 4 / 6 — validation: rating required and in range; Unicode-aware limit; tags filtered by rating; edit restores content', () {
    final o = order(OrderStatus.completed); final d = emptyDraft(o);
    expect(validateReview(d, defaultReviewConfig).rating, 'required');
    expect(validateReview(d.copyWith(overallRating: 9), defaultReviewConfig).rating, 'out_of_range');
    expect(validateReview(d.copyWith(overallRating: 4), defaultReviewConfig).isEmpty, isTrue);
    expect(textLength('😀😀😀一風堂'), 6);
    expect(validateReview(d.copyWith(overallRating: 4, text: 'x' * 501), defaultReviewConfig).textTooLong, isTrue);
    expect(tagsForRating(defaultReviewConfig, 5).every((t) => t.sentiment != TagSentiment.negative), isTrue);
    expect(tagsForRating(defaultReviewConfig, 1).every((t) => t.sentiment != TagSentiment.positive), isTrue);
    expect(tagsForRating(defaultReviewConfig, 3).length, defaultReviewConfig.tags.length);
    expect(d.itemFeedback.single.itemName, 'Classic Burger');
    final r = Review(reviewId: 'r', orderPublicId: o.publicId, orderNumber: o.orderNumber, restaurantId: 'burger-hub', customerId: 'u1', overallRating: 2, categoryRatings: const {'food_quality': 2}, tags: const ['long_wait'], itemFeedback: const [ItemFeedback(lineId: 'l1', itemName: 'Classic Burger', sentiment: ItemSentiment.disliked)], text: 'meh', status: ReviewStatus.submitted, version: 1, createdAt: DateTime.utc(2026), updatedAt: DateTime.utc(2026));
    final restored = draftFromReview(r, o);
    expect(restored.overallRating, 2); expect(restored.tags, ['long_wait']); expect(restored.itemFeedback.single.sentiment, ItemSentiment.disliked); expect(restored.clientSubmissionId, isNot(d.clientSubmissionId));
    expect(Review.fromJson(r.toJson()).itemFeedback.single.sentiment, ItemSentiment.disliked);
  });

  test('TEST 1 / 2 / 9 — eligibility: completed + owner → eligible; active → not_completed; foreign → not_owner; expired window; already reviewed (+ edit window)', () async {
    final repo = MockReviewRepository(MemoryKeyValueStore(), latency: Duration.zero); final svc = MockReviewEligibilityService(repo);
    final c = defaultReviewConfig;
    expect((await svc.check(order(OrderStatus.completed), 'u1', c)).eligible, isTrue);
    expect((await svc.check(order(OrderStatus.pickedUp), 'u1', c)).eligible, isTrue);
    expect((await svc.check(order(OrderStatus.preparing), 'u1', c)).reason, EligibilityReason.notCompleted);
    expect((await svc.check(order(OrderStatus.completed), 'someone-else', c)).reason, EligibilityReason.notOwner);
    expect((await svc.check(order(OrderStatus.completed, doneAt: DateTime.now().toUtc().subtract(const Duration(days: 40))), 'u1', c)).reason, EligibilityReason.windowExpired);
    final o = order(OrderStatus.completed);
    await repo.submitReview(o, emptyDraft(o).copyWith(overallRating: 5));
    final el = await svc.check(o, 'u1', c);
    expect(el.reason, EligibilityReason.alreadyReviewed); expect(el.existingReview, isNotNull); expect(el.canEdit, isTrue);
    final late = MockReviewEligibilityService(repo, now: () => DateTime.now().add(const Duration(hours: 100)));
    expect((await late.check(o, 'u1', c)).canEdit, isFalse);
  });

  test('TEST 5 / 8 / 10 — one review per order, restaurant derived from the order, duplicate submit returns the same review, update bumps the version', () async {
    final repo = MockReviewRepository(MemoryKeyValueStore(), latency: Duration.zero); final o = order(OrderStatus.completed);
    final d = emptyDraft(o).copyWith(overallRating: 4, tags: ['great_food', 'not_a_real_tag'], text: '  Très bon 😀  ', categoryRatings: {'food_quality': 5, 'bogus': 1});
    final r1 = await repo.submitReview(o, d);
    expect(r1.restaurantId, 'burger-hub'); expect(r1.customerId, 'u1'); expect(r1.tags, ['great_food']); expect(r1.text, 'Très bon 😀'); expect(r1.categoryRatings, {'food_quality': 5}); expect(r1.status, ReviewStatus.submitted);
    final r2 = await repo.submitReview(o, d.copyWith(overallRating: 1));
    expect(r2.reviewId, r1.reviewId); expect(r2.overallRating, 4);
    final both = await Future.wait([repo.submitReview(o, d), repo.submitReview(o, d)]);
    expect(both.map((r) => r.reviewId).toSet().length, 1);
    final r3 = await repo.updateReview(r1.reviewId, 'u1', d.copyWith(overallRating: 5, text: 'Even better'));
    expect(r3.version, 2); expect(r3.overallRating, 5); expect(r3.text, 'Even better');
    expect((await repo.getReviewForOrder(o.publicId, 'u1'))!.version, 2);
    expect(await repo.getReviewForOrder(o.publicId, 'someone-else'), isNull);
    expect(() => repo.submitReview(o, emptyDraft(order(OrderStatus.completed))), throwsStateError); // rating missing → invalid
  });

  test('TEST 7 — failure switch throws (draft kept by the caller); summary aggregates local reviews only; delete hides', () async {
    final repo = MockReviewRepository(MemoryKeyValueStore(), latency: Duration.zero); final o = order(OrderStatus.completed);
    repo.fail = true; await expectLater(repo.submitReview(o, emptyDraft(o).copyWith(overallRating: 3)), throwsStateError); repo.fail = false;
    expect((await repo.getRestaurantReviewSummary('burger-hub')).reviewCount, 0);
    final r = await repo.submitReview(o, emptyDraft(o).copyWith(overallRating: 3));
    final o2 = order(OrderStatus.pickedUp); await repo.submitReview(o2, emptyDraft(o2).copyWith(overallRating: 5));
    final s = await repo.getRestaurantReviewSummary('burger-hub');
    expect(s.reviewCount, 2); expect(s.averageRating, 4.0); expect(s.distribution, {3: 1, 5: 1}); expect(s.source, 'mock_local');
    await repo.deleteReview(r.reviewId, 'u1');
    expect((await repo.getRestaurantReviewSummary('burger-hub')).reviewCount, 1);
    expect((await repo.getReviewForOrder(o.publicId, 'u1'))!.status, ReviewStatus.hidden);
  });

  test('config is data-driven: JP override trims categories and the text limit', () async {
    final p = MockReviewConfigProvider();
    expect((await p.configFor(order(OrderStatus.completed))).categories.length, 4);
    final jp = await p.configFor(order(OrderStatus.completed, country: 'JP'));
    expect(jp.categories.map((c) => c.key), ['food_quality', 'pickup_experience']); expect(jp.textMaxLength, 300); expect(jp.scaleMax, 5);
  });
}
