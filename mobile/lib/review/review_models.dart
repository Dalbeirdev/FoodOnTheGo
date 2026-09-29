import 'dart:convert';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;
import '../order/order_models.dart';

/// Reviews, ratings & customer feedback (Module 16) — Android domain.
/// A review is always tied to an eligible order the customer owns; the restaurant is derived from the order. Review text is
/// untrusted input (length-validated here; sanitized / moderated by the backend later). Submission state is separate from
/// moderation state. Categories / tags / scale / limits are configuration data, never code constants in the UI.
enum ReviewStatus { submitted, pendingModeration, published, hidden, rejected, flagged }
enum TagSentiment { positive, neutral, negative }
enum ItemSentiment { liked, disliked }
enum EligibilityReason { ok, notCompleted, notOwner, windowExpired, alreadyReviewed, blocked, notFound }
String reviewStatusKey(ReviewStatus s) => switch (s) { ReviewStatus.submitted => 'SUBMITTED', ReviewStatus.pendingModeration => 'PENDING_MODERATION', ReviewStatus.published => 'PUBLISHED', ReviewStatus.hidden => 'HIDDEN', ReviewStatus.rejected => 'REJECTED', ReviewStatus.flagged => 'FLAGGED' };
String eligibilityKey(EligibilityReason r) => switch (r) { EligibilityReason.ok => 'ok', EligibilityReason.notCompleted => 'not_completed', EligibilityReason.notOwner => 'not_owner', EligibilityReason.windowExpired => 'window_expired', EligibilityReason.alreadyReviewed => 'already_reviewed', EligibilityReason.blocked => 'blocked', EligibilityReason.notFound => 'not_found' };

class ReviewCategoryDef { const ReviewCategoryDef(this.key, {this.required = false}); final String key; final bool required; }
class ReviewTagDef { const ReviewTagDef(this.key, this.sentiment); final String key; final TagSentiment sentiment; }
class ReviewConfig {
  const ReviewConfig({this.scaleMin = 1, this.scaleMax = 5, this.textMaxLength = 500, required this.categories, required this.tags, this.itemFeedbackEnabled = true, this.titleEnabled = false, this.editEnabled = true, this.editWindowHours = 72, this.reviewWindowDays = 30});
  final int scaleMin, scaleMax, textMaxLength, reviewWindowDays; final int? editWindowHours;
  final List<ReviewCategoryDef> categories; final List<ReviewTagDef> tags; final bool itemFeedbackEnabled, titleEnabled, editEnabled;
  ReviewConfig copyWith({List<ReviewCategoryDef>? categories, int? textMaxLength, int? editWindowHours}) => ReviewConfig(scaleMin: scaleMin, scaleMax: scaleMax, textMaxLength: textMaxLength ?? this.textMaxLength, categories: categories ?? this.categories, tags: tags, itemFeedbackEnabled: itemFeedbackEnabled, titleEnabled: titleEnabled, editEnabled: editEnabled, editWindowHours: editWindowHours ?? this.editWindowHours, reviewWindowDays: reviewWindowDays);
}
const defaultReviewConfig = ReviewConfig(
  categories: [ReviewCategoryDef('food_quality'), ReviewCategoryDef('order_accuracy'), ReviewCategoryDef('preparation_time'), ReviewCategoryDef('pickup_experience')],
  tags: [ReviewTagDef('fast_pickup', TagSentiment.positive), ReviewTagDef('great_food', TagSentiment.positive), ReviewTagDef('accurate_order', TagSentiment.positive), ReviewTagDef('friendly_service', TagSentiment.positive), ReviewTagDef('as_expected', TagSentiment.neutral), ReviewTagDef('packaging', TagSentiment.neutral), ReviewTagDef('long_wait', TagSentiment.negative), ReviewTagDef('item_missing', TagSentiment.negative), ReviewTagDef('wrong_item', TagSentiment.negative), ReviewTagDef('cold_food', TagSentiment.negative)],
);

class ItemFeedback {
  const ItemFeedback({required this.lineId, required this.itemName, this.sentiment});
  final String lineId, itemName; final ItemSentiment? sentiment;
  ItemFeedback withSentiment(ItemSentiment? s) => ItemFeedback(lineId: lineId, itemName: itemName, sentiment: s);
  Map<String, dynamic> toJson() => {'lineId': lineId, 'itemName': itemName, 'sentiment': sentiment?.name};
  factory ItemFeedback.fromJson(Map<String, dynamic> j) => ItemFeedback(lineId: j['lineId'] as String, itemName: j['itemName'] as String, sentiment: j['sentiment'] == null ? null : ItemSentiment.values.byName(j['sentiment'] as String));
}
class ReviewDraft {
  const ReviewDraft({this.overallRating, this.categoryRatings = const {}, this.tags = const [], this.itemFeedback = const [], this.text = '', required this.clientSubmissionId});
  final int? overallRating; final Map<String, int> categoryRatings; final List<String> tags; final List<ItemFeedback> itemFeedback; final String text;
  /// Stable per draft — the backend uses it (with the order) for idempotency; double submits collapse to one review.
  final String clientSubmissionId;
  ReviewDraft copyWith({int? overallRating, Map<String, int>? categoryRatings, List<String>? tags, List<ItemFeedback>? itemFeedback, String? text}) => ReviewDraft(overallRating: overallRating ?? this.overallRating, categoryRatings: categoryRatings ?? this.categoryRatings, tags: tags ?? this.tags, itemFeedback: itemFeedback ?? this.itemFeedback, text: text ?? this.text, clientSubmissionId: clientSubmissionId);
  Map<String, dynamic> toJson() => {'overallRating': overallRating, 'categoryRatings': categoryRatings, 'tags': tags, 'itemFeedback': itemFeedback.map((f) => f.toJson()).toList(), 'text': text, 'clientSubmissionId': clientSubmissionId};
  factory ReviewDraft.fromJson(Map<String, dynamic> j) => ReviewDraft(overallRating: j['overallRating'] as int?, categoryRatings: Map<String, int>.from(j['categoryRatings'] as Map), tags: List<String>.from(j['tags'] as List), itemFeedback: (j['itemFeedback'] as List).map((e) => ItemFeedback.fromJson(e as Map<String, dynamic>)).toList(), text: j['text'] as String, clientSubmissionId: j['clientSubmissionId'] as String);
}
class Review {
  const Review({required this.reviewId, required this.orderPublicId, required this.orderNumber, required this.restaurantId, required this.customerId, required this.overallRating, required this.categoryRatings, required this.tags, required this.itemFeedback, required this.text, required this.status, required this.version, this.moderationReason, this.moderatedAt, required this.createdAt, required this.updatedAt});
  final String reviewId, orderPublicId, orderNumber, restaurantId, customerId, text; final int overallRating, version;
  final Map<String, int> categoryRatings; final List<String> tags; final List<ItemFeedback> itemFeedback; final ReviewStatus status;
  final String? moderationReason; final DateTime? moderatedAt; final DateTime createdAt, updatedAt;
  Review copyWith({int? overallRating, Map<String, int>? categoryRatings, List<String>? tags, List<ItemFeedback>? itemFeedback, String? text, ReviewStatus? status, int? version, DateTime? updatedAt}) => Review(reviewId: reviewId, orderPublicId: orderPublicId, orderNumber: orderNumber, restaurantId: restaurantId, customerId: customerId, overallRating: overallRating ?? this.overallRating, categoryRatings: categoryRatings ?? this.categoryRatings, tags: tags ?? this.tags, itemFeedback: itemFeedback ?? this.itemFeedback, text: text ?? this.text, status: status ?? this.status, version: version ?? this.version, moderationReason: moderationReason, moderatedAt: moderatedAt, createdAt: createdAt, updatedAt: updatedAt ?? this.updatedAt);
  Map<String, dynamic> toJson() => {'reviewId': reviewId, 'orderPublicId': orderPublicId, 'orderNumber': orderNumber, 'restaurantId': restaurantId, 'customerId': customerId, 'overallRating': overallRating, 'categoryRatings': categoryRatings, 'tags': tags, 'itemFeedback': itemFeedback.map((f) => f.toJson()).toList(), 'text': text, 'status': status.name, 'version': version, 'moderationReason': moderationReason, 'moderatedAt': moderatedAt?.toIso8601String(), 'createdAt': createdAt.toIso8601String(), 'updatedAt': updatedAt.toIso8601String()};
  factory Review.fromJson(Map<String, dynamic> j) => Review(reviewId: j['reviewId'] as String, orderPublicId: j['orderPublicId'] as String, orderNumber: j['orderNumber'] as String, restaurantId: j['restaurantId'] as String, customerId: j['customerId'] as String, overallRating: j['overallRating'] as int, categoryRatings: Map<String, int>.from(j['categoryRatings'] as Map), tags: List<String>.from(j['tags'] as List), itemFeedback: (j['itemFeedback'] as List).map((e) => ItemFeedback.fromJson(e as Map<String, dynamic>)).toList(), text: j['text'] as String, status: ReviewStatus.values.byName(j['status'] as String), version: j['version'] as int, moderationReason: j['moderationReason'] as String?, moderatedAt: j['moderatedAt'] == null ? null : DateTime.parse(j['moderatedAt'] as String), createdAt: DateTime.parse(j['createdAt'] as String), updatedAt: DateTime.parse(j['updatedAt'] as String));
}
class ReviewEligibility {
  const ReviewEligibility({required this.eligible, required this.reason, this.existingReview, this.canEdit = false, this.windowEndsAt});
  final bool eligible, canEdit; final EligibilityReason reason; final Review? existingReview; final DateTime? windowEndsAt;
}
/// Aggregates are calculated server-side later; the mock aggregates only reviews stored on this device.
class RestaurantReviewSummary {
  const RestaurantReviewSummary({required this.restaurantId, this.averageRating, required this.reviewCount, required this.distribution, this.source = 'mock_local'});
  final String restaurantId; final double? averageRating; final int reviewCount; final Map<int, int> distribution; final String source;
}

abstract class ReviewRepository {
  Future<Review?> getReviewForOrder(String orderPublicId, String customerId);
  /// Creates the single review for the order (idempotent per order); the restaurant comes from the order.
  Future<Review> submitReview(Order order, ReviewDraft draft);
  Future<Review> updateReview(String reviewId, String customerId, ReviewDraft draft);
  /// Customer deletion = remove from public view; the record is retained (audit / legal) — the backend decides.
  Future<void> deleteReview(String reviewId, String customerId);
  Future<RestaurantReviewSummary> getRestaurantReviewSummary(String restaurantId);
}
abstract class ReviewEligibilityService { Future<ReviewEligibility> check(Order order, String customerId, ReviewConfig config); }
abstract class ReviewConfigProvider { Future<ReviewConfig> configFor(Order order); }

// ---------------------------------------------------------------------------------------------------------
// Pure form helpers
// ---------------------------------------------------------------------------------------------------------
/// Unicode-aware length (code points / runes) — emoji and CJK count as characters.
int textLength(String s) => s.runes.length;
int remainingChars(String s, ReviewConfig c) => c.textMaxLength - textLength(s);
class ReviewErrors { const ReviewErrors({this.rating, this.categories = const [], this.textTooLong = false}); final String? rating; final List<String> categories; final bool textTooLong; bool get isEmpty => rating == null && categories.isEmpty && !textTooLong; }
ReviewErrors validateReview(ReviewDraft d, ReviewConfig c) {
  String? rating; final r = d.overallRating;
  if (r == null) { rating = 'required'; } else if (r < c.scaleMin || r > c.scaleMax) { rating = 'out_of_range'; }
  final missing = [for (final cat in c.categories) if (cat.required && d.categoryRatings[cat.key] == null) cat.key];
  return ReviewErrors(rating: rating, categories: missing, textTooLong: textLength(d.text) > c.textMaxLength);
}
/// High ratings show positive + neutral tags, low ratings negative + neutral, the middle shows all (relevance only — never steering).
List<ReviewTagDef> tagsForRating(ReviewConfig c, int? rating) {
  if (rating == null) return c.tags;
  final span = c.scaleMax - c.scaleMin;
  if (rating >= c.scaleMin + span * 0.75) return c.tags.where((t) => t.sentiment != TagSentiment.negative).toList();
  if (rating <= c.scaleMin + span * 0.25) return c.tags.where((t) => t.sentiment != TagSentiment.positive).toList();
  return c.tags;
}
final _rnd = Random.secure();
String newSubmissionId() => 'sub_${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}${List.generate(6, (_) => _rnd.nextInt(36).toRadixString(36)).join()}';
ReviewDraft emptyDraft(Order o) => ReviewDraft(itemFeedback: [for (final i in o.items) ItemFeedback(lineId: i.lineId, itemName: i.itemName)], clientSubmissionId: newSubmissionId());
/// Editing restores the existing content (TEST 10) with a fresh submission id.
ReviewDraft draftFromReview(Review r, Order o) { final base = emptyDraft(o); return base.copyWith(overallRating: r.overallRating, categoryRatings: Map.of(r.categoryRatings), tags: List.of(r.tags), text: r.text, itemFeedback: [for (final f in base.itemFeedback) f.withSentiment(r.itemFeedback.where((x) => x.lineId == f.lineId).firstOrNull?.sentiment)]); }
ReviewDraft normalizeDraft(ReviewDraft d, ReviewConfig c) { final keys = c.tags.map((t) => t.key).toSet(); final cats = c.categories.map((x) => x.key).toSet(); return d.copyWith(text: d.text.trim(), tags: d.tags.where(keys.contains).toList(), categoryRatings: {for (final e in d.categoryRatings.entries) if (cats.contains(e.key)) e.key: e.value}, itemFeedback: c.itemFeedbackEnabled ? d.itemFeedback : const []); }

// ---------------------------------------------------------------------------------------------------------
// Development implementations
// ---------------------------------------------------------------------------------------------------------
/// Per-market overrides prove the configuration is data-driven (example values only — not business rules).
class MockReviewConfigProvider implements ReviewConfigProvider {
  @override
  Future<ReviewConfig> configFor(Order o) async => switch (o.restaurant.countryCode) {
        'JP' => defaultReviewConfig.copyWith(categories: const [ReviewCategoryDef('food_quality'), ReviewCategoryDef('pickup_experience')], textMaxLength: 300),
        'FR' => defaultReviewConfig.copyWith(editWindowHours: 24),
        _ => defaultReviewConfig,
      };
}
const _completed = {OrderStatus.pickedUp, OrderStatus.completed};
class MockReviewEligibilityService implements ReviewEligibilityService {
  MockReviewEligibilityService(this._reviews, {DateTime Function()? now}) : _now = now ?? DateTime.now;
  final ReviewRepository _reviews; final DateTime Function() _now;
  @override
  Future<ReviewEligibility> check(Order o, String customerId, ReviewConfig c) async {
    if (o.customerId != customerId) return const ReviewEligibility(eligible: false, reason: EligibilityReason.notOwner);
    if (!_completed.contains(o.orderStatus)) return const ReviewEligibility(eligible: false, reason: EligibilityReason.notCompleted);
    final doneAt = o.events.where((e) => e.type == OrderEventType.completed || e.type == OrderEventType.pickedUp).map((e) => e.at).lastOrNull ?? o.updatedAt;
    final windowEndsAt = doneAt.add(Duration(days: c.reviewWindowDays));
    final existing = await _reviews.getReviewForOrder(o.publicId, customerId);
    if (existing != null) {
      final moderated = existing.status != ReviewStatus.submitted && existing.status != ReviewStatus.pendingModeration;
      final inWindow = c.editWindowHours == null || _now().isBefore(existing.createdAt.add(Duration(hours: c.editWindowHours!)));
      return ReviewEligibility(eligible: false, reason: EligibilityReason.alreadyReviewed, existingReview: existing, canEdit: c.editEnabled && !moderated && inWindow);
    }
    if (_now().isAfter(windowEndsAt)) return ReviewEligibility(eligible: false, reason: EligibilityReason.windowExpired, windowEndsAt: windowEndsAt);
    return ReviewEligibility(eligible: true, reason: EligibilityReason.ok, windowEndsAt: windowEndsAt);
  }
}
class MockReviewRepository implements ReviewRepository {
  MockReviewRepository(this._store, {this.latency = const Duration(milliseconds: 300)});
  final KeyValueStore _store; final Duration latency;
  static const key = 'fotg.reviews.v1';
  /// Development switch: submit / update fails (the draft is kept).
  bool fail = false;
  Future<void> _wait() => latency == Duration.zero ? Future.value() : Future<void>.delayed(latency);
  Future<List<Review>> _all() async { try { final raw = await _store.read(key); if (raw == null || raw.isEmpty) return []; return (jsonDecode(raw) as List).map((e) => Review.fromJson(e as Map<String, dynamic>)).toList(); } catch (_) { return []; } }
  Future<void> _save(List<Review> l) => _store.write(key, jsonEncode(l.map((r) => r.toJson()).toList())).catchError((_) {});
  @override
  Future<Review?> getReviewForOrder(String orderPublicId, String customerId) async { await _wait(); return (await _all()).where((r) => r.orderPublicId == orderPublicId && r.customerId == customerId).firstOrNull; }
  @override
  Future<Review> submitReview(Order o, ReviewDraft draft) async {
    await _wait();
    if (fail) throw StateError('review_submit_failed');
    final c = await MockReviewConfigProvider().configFor(o); final d = normalizeDraft(draft, c);
    if (!validateReview(d, c).isEmpty) throw StateError('review_invalid');
    final list = await _all();
    // One review per order: a duplicate submit (double tap, retry, replay) returns the existing review.
    final existing = list.where((r) => r.orderPublicId == o.publicId && r.customerId == o.customerId).firstOrNull;
    if (existing != null) return existing;
    final now = DateTime.now().toUtc();
    final r = Review(reviewId: 'rv_${now.millisecondsSinceEpoch.toRadixString(36)}${_rnd.nextInt(1 << 20).toRadixString(36)}', orderPublicId: o.publicId, orderNumber: o.orderNumber, restaurantId: o.restaurant.id, customerId: o.customerId, overallRating: d.overallRating!, categoryRatings: d.categoryRatings, tags: d.tags, itemFeedback: d.itemFeedback, text: d.text, status: ReviewStatus.submitted, version: 1, createdAt: now, updatedAt: now);
    list.add(r); await _save(list); return r;
  }
  @override
  Future<Review> updateReview(String reviewId, String customerId, ReviewDraft draft) async {
    await _wait();
    if (fail) throw StateError('review_update_failed');
    final list = await _all(); final i = list.indexWhere((r) => r.reviewId == reviewId && r.customerId == customerId);
    if (i < 0) throw StateError('review_not_found');
    final cur = list[i];
    list[i] = cur.copyWith(overallRating: draft.overallRating ?? cur.overallRating, categoryRatings: draft.categoryRatings, tags: draft.tags, itemFeedback: draft.itemFeedback, text: draft.text.trim(), version: cur.version + 1, updatedAt: DateTime.now().toUtc());
    await _save(list); return list[i];
  }
  @override
  Future<void> deleteReview(String reviewId, String customerId) async {
    await _wait();
    final list = await _all(); final i = list.indexWhere((r) => r.reviewId == reviewId && r.customerId == customerId);
    if (i >= 0) { list[i] = list[i].copyWith(status: ReviewStatus.hidden, updatedAt: DateTime.now().toUtc()); await _save(list); }
  }
  @override
  Future<RestaurantReviewSummary> getRestaurantReviewSummary(String restaurantId) async {
    await _wait();
    final mine = (await _all()).where((r) => r.restaurantId == restaurantId && r.status != ReviewStatus.hidden && r.status != ReviewStatus.rejected).toList();
    final dist = <int, int>{}; for (final r in mine) { dist[r.overallRating] = (dist[r.overallRating] ?? 0) + 1; }
    return RestaurantReviewSummary(restaurantId: restaurantId, averageRating: mine.isEmpty ? null : mine.fold<int>(0, (a, r) => a + r.overallRating) / mine.length, reviewCount: mine.length, distribution: dist);
  }
}
