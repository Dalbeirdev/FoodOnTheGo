/// Reviews on the backend (Module 34) — Android.
///
/// A review belongs to ONE completed order of the signed-in customer. The backend decides whether it can be written,
/// takes the restaurant from the order, screens the text and owns the rating; this file asks and maps onto the review
/// model the screens already render. It never decides eligibility, never sends an owner, a restaurant, a status or
/// "verified", and never keeps a submission for later: a review is sent when the customer taps, or not at all.
///
///   GET    /customer/orders/{order}/review     eligibility + my review + the policy the form needs
///   POST   /customer/orders/{order}/review     write it (one per order; a repeat answers the same review)
///   PATCH  /customer/reviews/{review}          change it (version required)
///   DELETE /customer/reviews/{review}          withdraw it
///   GET    /customer/reviews                   my reviews
///   GET    /restaurants/{slug}/reviews         the published reviews of a restaurant + its rating summary
///   POST   /reviews/{review}/report            report somebody else's published review
library;

import '../core/app_config.dart';
import '../data/api_client.dart';
import '../order/api_order.dart' show ordersFromApi;
import '../order/order_models.dart';
import 'review_models.dart';

/// Tests switch this; the app reads the build's --dart-define=REVIEW_MODE.
String? reviewModeOverride;

/// Reviews come from the backend only when orders do: a review is about a backend order.
bool get reviewsFromApi => ordersFromApi && (reviewModeOverride ?? AppConfig.reviewMode) == 'api';

Map<String, dynamic> _map(Object? v) => v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};
DateTime? _when(Object? v) => v is String && v.isNotEmpty ? DateTime.tryParse(v)?.toUtc() : null;

const _status = <String, ReviewStatus>{'PENDING_MODERATION': ReviewStatus.pendingModeration, 'PUBLISHED': ReviewStatus.published, 'HIDDEN': ReviewStatus.hidden, 'REJECTED': ReviewStatus.rejected, 'REMOVED': ReviewStatus.removed};
const _reason = <String, EligibilityReason>{'ELIGIBLE': EligibilityReason.ok, 'ORDER_NOT_COMPLETED': EligibilityReason.notCompleted, 'ORDER_NOT_OWNED': EligibilityReason.notOwner, 'REVIEW_ALREADY_EXISTS': EligibilityReason.alreadyReviewed, 'REVIEW_WINDOW_EXPIRED': EligibilityReason.windowExpired, 'ORDER_NOT_ELIGIBLE': EligibilityReason.blocked};
const _sentiment = <String, TagSentiment>{'positive': TagSentiment.positive, 'neutral': TagSentiment.neutral, 'negative': TagSentiment.negative};

ReviewResponse? _response(Object? v, String fallbackName) {
  if (v is! Map) return null;
  final d = v.cast<String, dynamic>();
  return ReviewResponse(text: '${d['text'] ?? ''}', restaurantName: '${d['restaurant_name'] ?? fallbackName}', respondedAt: _when(d['responded_at']), edited: d['edited'] == true);
}

Review reviewFromApi(Map<String, dynamic> d, String customerId) {
  final order = _map(d['order']), restaurant = _map(d['restaurant']);
  final submitted = _when(d['submitted_at']) ?? DateTime.now().toUtc();
  return Review(
    reviewId: '${d['id']}', orderPublicId: '${order['id'] ?? ''}', orderNumber: '${order['order_number'] ?? ''}', restaurantId: '${restaurant['id'] ?? ''}', customerId: customerId,
    overallRating: (d['overall_rating'] as num?)?.toInt() ?? 0,
    categoryRatings: {for (final e in _map(d['category_ratings']).entries) if (e.value is num) e.key: (e.value as num).toInt()},
    tags: [for (final t in d['tags'] as List? ?? const []) '$t'],
    itemFeedback: [for (final f in d['item_feedback'] as List? ?? const []) if (f is Map) ItemFeedback(lineId: '${f['item_id'] ?? ''}', itemName: '${f['name'] ?? ''}', sentiment: f['sentiment'] == 'liked' ? ItemSentiment.liked : f['sentiment'] == 'disliked' ? ItemSentiment.disliked : null)],
    text: '${d['comment'] ?? ''}', status: _status['${d['status']}'] ?? ReviewStatus.pendingModeration, version: (d['version'] as num?)?.toInt() ?? 1,
    createdAt: submitted, updatedAt: _when(d['edited_at']) ?? submitted,
    restaurantName: restaurant['name'] as String?, restaurantSlug: restaurant['slug'] as String?,
    canEdit: d['can_edit'] == true, canRemove: d['can_remove'] == true, editsLeft: (d['edits_left'] as num?)?.toInt(),
    response: _response(d['response'], '${restaurant['name'] ?? ''}'),
  );
}

ReviewConfig reviewConfigFromApi(Map<String, dynamic> d) {
  final scale = _map(d['scale']);
  final maxEdits = (d['max_edits'] as num?)?.toInt() ?? 0, editHours = (d['edit_window_hours'] as num?)?.toInt() ?? 0;
  return ReviewConfig(
    scaleMin: (scale['min'] as num?)?.toInt() ?? 1, scaleMax: (scale['max'] as num?)?.toInt() ?? 5, textMaxLength: (d['comment_max_length'] as num?)?.toInt() ?? 500,
    categories: [for (final c in d['categories'] as List? ?? const []) if (c is Map) ReviewCategoryDef('${c['key']}', required: c['required'] == true)],
    tags: [for (final t in d['tags'] as List? ?? const []) if (t is Map) ReviewTagDef('${t['key']}', _sentiment['${t['sentiment']}'] ?? TagSentiment.neutral)],
    itemFeedbackEnabled: d['item_feedback'] == true, titleEnabled: false, editEnabled: maxEdits > 0 && editHours > 0, editWindowHours: editHours, reviewWindowDays: (d['submission_window_days'] as num?)?.toInt() ?? 30,
  );
}

/// The rating as the backend calculated it. No reviews = no average: nothing is filled in.
RatingSummary ratingSummaryFromApi(Object? v) {
  final d = _map(v);
  return RatingSummary(
    average: (d['average'] as num?)?.toDouble(), count: (d['count'] as num?)?.toInt() ?? 0,
    distribution: {for (final e in _map(d['distribution']).entries) if (int.tryParse(e.key) != null && e.value is num) int.parse(e.key): (e.value as num).toInt()},
  );
}

PublicReview publicReviewFromApi(Map<String, dynamic> d) => PublicReview(
      reviewId: '${d['id']}', authorName: '${_map(d['author'])['display_name'] ?? ''}', overallRating: (d['overall_rating'] as num?)?.toInt() ?? 0,
      tags: [for (final t in d['tags'] as List? ?? const []) '$t'], text: '${d['comment'] ?? ''}', edited: d['edited'] == true, createdAt: _when(d['submitted_at']), response: _response(d['response'], ''),
    );

/// What the backend takes: ratings, tags, item feedback and text. Never an owner, a restaurant, a status or "verified".
Map<String, dynamic> reviewBody(ReviewDraft draft) => {
      'overall_rating': draft.overallRating,
      'category_ratings': draft.categoryRatings,
      'tags': draft.tags,
      'item_feedback': [for (final f in draft.itemFeedback) if (f.sentiment != null) {'item_id': f.lineId, 'sentiment': f.sentiment!.name}],
      'comment': draft.text.trim().isEmpty ? null : draft.text.trim(),
    };

/// The submission token as the backend accepts it (letters, digits, - and _; 8–64).
String clientToken(String id) {
  final clean = id.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '');
  final token = clean.length >= 8 ? clean : 'sub-$clean-${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}';
  return token.length > 64 ? token.substring(0, 64) : token;
}

/// A failed review call in the customer's words: the backend's own sentence for a refusal, a plain failure otherwise.
ReviewFailure reviewFailure(Object e) {
  if (e is ReviewFailure) return e;
  if (e is ApiException && (e.kind == ApiErrorKind.conflict || e.kind == ApiErrorKind.validation || e.kind == ApiErrorKind.rateLimited || e.kind == ApiErrorKind.notFound)) {
    final field = e.errors.values.where((l) => l.isNotEmpty).firstOrNull?.first;
    return ReviewFailure(field ?? e.message, code: e.code, presentable: true);
  }
  return ReviewFailure('review_request_failed', code: e is ApiException ? e.code : 'network');
}

/// One GET answers "may I review", "what is my review" and "what does the form look like". The three contracts the
/// screens use ask separately, so the answer is shared for a moment (and while in flight) instead of fetched three times.
class ReviewApi {
  ReviewApi(this.client, {DateTime Function()? now}) : _now = now ?? DateTime.now;
  final ApiClient client;
  final DateTime Function() _now;
  final Map<String, ({DateTime at, Future<Map<String, dynamic>> value})> _cache = {};
  final Map<String, int> _versions = {};

  Future<Map<String, dynamic>> orderState(String orderRef) {
    final hit = _cache[orderRef];
    if (hit != null && _now().difference(hit.at) < const Duration(seconds: 4)) return hit.value;
    final value = client.get('/customer/orders/${Uri.encodeComponent(orderRef)}/review').then((d) { final r = d['review']; if (r is Map) _versions['${r['id']}'] = (r['version'] as num?)?.toInt() ?? 1; return d; });
    _cache[orderRef] = (at: _now(), value: value);
    value.catchError((Object _) { _cache.remove(orderRef); return <String, dynamic>{}; });
    return value;
  }
  void remember(Map<String, dynamic> review) { _versions['${review['id']}'] = (review['version'] as num?)?.toInt() ?? 1; _cache.clear(); }
  void forget() => _cache.clear();
  int? version(String reviewId) => _versions[reviewId];
}

class ApiReviewConfigProvider implements ReviewConfigProvider {
  ApiReviewConfigProvider(this.api);
  final ReviewApi api;
  @override
  Future<ReviewConfig> configFor(Order order) async { try { return reviewConfigFromApi(_map((await api.orderState(order.orderNumber))['config'])); } catch (e) { throw reviewFailure(e); } }
}

class ApiReviewEligibilityService implements ReviewEligibilityService {
  ApiReviewEligibilityService(this.api);
  final ReviewApi api;
  @override
  Future<ReviewEligibility> check(Order order, String customerId, ReviewConfig config) async {
    try {
      final d = await api.orderState(order.orderNumber);
      final e = _map(d['eligibility']);
      final raw = d['review'] is Map ? reviewFromApi(_map(d['review']), customerId) : null;
      final removed = raw?.status == ReviewStatus.removed;
      return ReviewEligibility(
        eligible: e['eligible'] == true, reason: removed ? EligibilityReason.removed : _reason['${e['reason']}'] ?? EligibilityReason.blocked,
        existingReview: removed ? null : raw, canEdit: e['can_edit'] == true, windowEndsAt: _when(e['window_ends_at']),
      );
    } catch (e) { throw reviewFailure(e); }
  }
}

class ApiReviewRepository implements ReviewRepository {
  ApiReviewRepository(this.api);
  final ReviewApi api;
  ApiClient get _c => api.client;

  @override
  Future<Review?> getReviewForOrder(String orderRef, String customerId) async {
    try {
      final d = await api.orderState(orderRef);
      if (d['review'] is! Map) return null;
      final r = reviewFromApi(_map(d['review']), customerId);
      return r.status == ReviewStatus.removed ? null : r;
    } on ApiException catch (e) { if (e.kind == ApiErrorKind.notFound) return null; throw reviewFailure(e); }
  }

  @override
  Future<Review> submitReview(Order order, ReviewDraft draft) async {
    try {
      final a = await _c.post('/customer/orders/${Uri.encodeComponent(order.orderNumber)}/review', body: {...reviewBody(draft), 'client_submission_id': clientToken(draft.clientSubmissionId)});
      final review = _map(a['review']);
      api.remember(review);
      return reviewFromApi(review, order.customerId);
    } catch (e) { api.forget(); throw reviewFailure(e); }
  }

  @override
  Future<Review> updateReview(String reviewId, String customerId, ReviewDraft draft) async {
    try {
      final version = api.version(reviewId) ?? (_map((await _c.get('/customer/reviews/$reviewId'))['review'])['version'] as num?)?.toInt() ?? 1;
      final a = await _c.patch('/customer/reviews/$reviewId', body: {...reviewBody(draft), 'version': version});
      final review = _map(a['review']);
      api.remember(review);
      return reviewFromApi(review, customerId);
    } catch (e) { api.forget(); throw reviewFailure(e); }
  }

  @override
  Future<void> deleteReview(String reviewId, String customerId) async {
    try { await _c.delete('/customer/reviews/$reviewId'); api.forget(); } catch (e) { api.forget(); throw reviewFailure(e); }
  }

  @override
  Future<List<Review>> listMine(String customerId) async {
    try { final a = await _c.get('/customer/reviews', query: {'per_page': '50'}); return [for (final d in a['data'] as List? ?? const []) if (d is Map) reviewFromApi(d.cast<String, dynamic>(), customerId)]; } catch (e) { throw reviewFailure(e); }
  }

  @override
  Future<PublicReviewPage> listPublic(String restaurantSlug, {int page = 1, int pageSize = 5, PublicReviewSort sort = PublicReviewSort.newest, int? rating}) async {
    try {
      final a = await _c.get('/restaurants/${Uri.encodeComponent(restaurantSlug)}/reviews', auth: false, query: {'page': '$page', 'per_page': '$pageSize', 'sort': sort.name, 'rating': rating == null ? null : '$rating'});
      final meta = _map(a['meta']);
      return PublicReviewPage(
        items: [for (final d in a['data'] as List? ?? const []) if (d is Map) publicReviewFromApi(d.cast<String, dynamic>())],
        total: (meta['total'] as num?)?.toInt() ?? 0, page: (meta['current_page'] as num?)?.toInt() ?? page, pageSize: (meta['per_page'] as num?)?.toInt() ?? pageSize, summary: ratingSummaryFromApi(a['summary']),
      );
    } catch (e) { throw reviewFailure(e); }
  }

  @override
  Future<void> reportReview(String reviewId, String reason, {String? explanation}) async {
    try { final text = explanation?.trim() ?? ''; await _c.post('/reviews/$reviewId/report', body: {'reason': reason, 'explanation': text.isEmpty ? null : text}); } catch (e) { throw reviewFailure(e); }
  }

  @override
  Future<RestaurantReviewSummary> getRestaurantReviewSummary(String restaurantSlug) async {
    try {
      final s = ratingSummaryFromApi((await _c.get('/restaurants/${Uri.encodeComponent(restaurantSlug)}/reviews/summary', auth: false))['rating']);
      return RestaurantReviewSummary(restaurantId: restaurantSlug, averageRating: s.average, reviewCount: s.count, distribution: s.distribution, source: 'server');
    } catch (e) { throw reviewFailure(e); }
  }
}
