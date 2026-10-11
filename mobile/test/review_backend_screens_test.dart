import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/review/restaurant_reviews.dart';
import 'package:foodonthego/review/review_models.dart';
import 'package:foodonthego/screens/my_reviews_screen.dart';
import 'package:foodonthego/screens/review_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 34 — the review screens on backend-shaped answers (Android widget tests): the backend's refusal in its own
/// words, withdrawing a review, the restaurant's reply, My Reviews, and the public reviews of a restaurant (no invented
/// figures, paging, sort / filter asked of the repository, reporting). A scripted repository stands in for the API.
class Scripted implements ReviewRepository, ReviewEligibilityService, ReviewConfigProvider {
  Review? mine; bool eligible = true; EligibilityReason reason = EligibilityReason.ok;
  ReviewFailure? failSubmit, failReport;
  final List<ReviewDraft> submitted = []; final List<String> deleted = []; final List<(String, String, String?)> reports = [];
  final List<(int, PublicReviewSort, int?)> queries = [];
  List<PublicReview> public = []; RatingSummary summary = const RatingSummary(count: 0);
  List<Review> list = [];

  @override
  Future<ReviewConfig> configFor(Order order) async => defaultReviewConfig;
  @override
  Future<ReviewEligibility> check(Order order, String customerId, ReviewConfig config) async => ReviewEligibility(eligible: eligible && mine == null, reason: mine != null ? EligibilityReason.alreadyReviewed : reason, existingReview: mine, canEdit: mine?.canEdit ?? false);
  @override
  Future<Review?> getReviewForOrder(String orderPublicId, String customerId) async => mine;
  @override
  Future<Review> submitReview(Order order, ReviewDraft draft) async {
    if (failSubmit != null) throw failSubmit!;
    submitted.add(draft);
    return mine = review(order.orderNumber, rating: draft.overallRating!, text: draft.text, status: ReviewStatus.published);
  }
  @override
  Future<Review> updateReview(String reviewId, String customerId, ReviewDraft draft) async => mine!;
  @override
  Future<void> deleteReview(String reviewId, String customerId) async { deleted.add(reviewId); mine = null; eligible = false; reason = EligibilityReason.removed; }
  @override
  Future<RestaurantReviewSummary> getRestaurantReviewSummary(String restaurantId) async => RestaurantReviewSummary(restaurantId: restaurantId, reviewCount: 0, distribution: const {});
  @override
  Future<List<Review>> listMine(String customerId) async => list;
  @override
  Future<PublicReviewPage> listPublic(String restaurantSlug, {int page = 1, int pageSize = 5, PublicReviewSort sort = PublicReviewSort.newest, int? rating}) async {
    queries.add((page, sort, rating));
    final filtered = public.where((r) => rating == null || r.overallRating == rating).toList();
    final start = (page - 1) * pageSize;
    return PublicReviewPage(items: filtered.skip(start).take(pageSize).toList(), total: filtered.length, page: page, pageSize: pageSize, summary: summary);
  }
  @override
  Future<void> reportReview(String reviewId, String reason, {String? explanation}) async { if (failReport != null) throw failReport!; reports.add((reviewId, reason, explanation)); }
}

Review review(String orderNumber, {int rating = 4, String text = 'Fresh and quick.', ReviewStatus status = ReviewStatus.published, bool canEdit = true, bool canRemove = true, ReviewResponse? response, String id = 'r1'}) => Review(
    reviewId: id, orderPublicId: 'o-$id', orderNumber: orderNumber, restaurantId: 'loc-1', customerId: 'cust-rahul', overallRating: rating, categoryRatings: const {}, tags: const ['great_food'], itemFeedback: const [], text: text, status: status, version: 1,
    createdAt: DateTime.utc(2026, 10, 11, 8), updatedAt: DateTime.utc(2026, 10, 11, 8), restaurantName: 'Burger Hub', restaurantSlug: 'burger-hub', canEdit: canEdit, canRemove: canRemove, editsLeft: 3, response: response);

PublicReview publicReview(int n, {int? rating, String? text, ReviewResponse? response}) => PublicReview(reviewId: 'p$n', authorName: 'Guest $n.', overallRating: rating ?? ((n - 1) % 5) + 1, text: text ?? 'Review number $n', edited: n == 2, createdAt: DateTime.utc(2026, 10, 10 - (n % 9)), response: response);

void main() {
  Future<AuthState> signedIn(WidgetTester t) async { final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero)); await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }); return auth; }
  AuthState signedOut() => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
  (OrderState, MockOrderRepository) states(Scripted s) {
    final st = MemoryKeyValueStore(); final repo = MockOrderRepository(st, latency: Duration.zero);
    return (OrderState(orders: repo, verifications: MockPickupVerificationRepository(repo), receipts: MockReceiptRepository(), store: st, reviews: s, reviewEligibility: s, reviewConfig: s), repo);
  }
  Future<Order> completed(MockOrderRepository repo) async {
    final o = await repo.createFromPayment(CreateOrderInput(paymentAttemptId: 'pa_${DateTime.now().microsecondsSinceEpoch}', checkoutReference: 'ck', customerId: 'cust-rahul',
        restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'x', countryCode: 'IN', timezone: 'Asia/Kolkata'),
        items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000)],
        pricing: const OrderPricing(currency: 'INR', subtotalMinor: 50000, discountMinor: 0, totalMinor: 50000),
        payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_ref', paidAmountMinor: 50000, currency: 'INR'),
        pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 29, 9), estimatedReadyTime: DateTime.utc(2026, 9, 29, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'), orderNote: ''));
    final steps = [(2, OrderEventType.restaurantAccepted, OrderStatus.accepted), (3, OrderEventType.preparing, OrderStatus.preparing), (4, OrderEventType.readyForPickup, OrderStatus.readyForPickup), (5, OrderEventType.pickedUp, OrderStatus.pickedUp), (6, OrderEventType.completed, OrderStatus.completed)];
    return (await repo.applyEvents(o.orderNumber, [for (final (i, tp, st) in steps) OrderEvent(eventId: 'e$i', sequence: i, type: tp, status: st, at: DateTime.now().toUtc(), actor: 'restaurant')]))!;
  }
  Widget app(OrderState os, AuthState auth, String location, {Widget? home}) => MultiProvider(providers: [
        ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os),
        ChangeNotifierProvider(create: (_) => CartState(repository: MemoryCartRepository())),
        ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
      ], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: location, routes: [
        GoRoute(path: '/', builder: (_, _) => home ?? const SizedBox()),
        GoRoute(path: '/order/:number/review', builder: (_, s) => ReviewScreen(number: s.pathParameters['number']!, draftStore: MemoryKeyValueStore())),
        GoRoute(path: '/order/:number', builder: (_, s) => Scaffold(body: Text('details ${s.pathParameters['number']}'))),
        GoRoute(path: '/my-reviews', builder: (_, _) => const MyReviewsScreen()),
        GoRoute(path: '/my-orders', builder: (_, _) => const Scaffold(body: Text('orders screen'))),
        GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('login screen'))),
      ])));
  void tall(WidgetTester t, [double h = 3600]) { t.view.physicalSize = Size(390, h); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  Future<void> tapText(WidgetTester t, String text) async { final f = find.text(text).first; await t.ensureVisible(f); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }
  Future<void> tapStar(WidgetTester t, int n) async { final f = find.bySemanticsLabel(n == 1 ? '1 star of 5' : '$n stars of 5').first; await t.ensureVisible(f); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }

  testWidgets('a refusal by the backend is shown in its own words and the answers are kept; a failure that is not a refusal stays generic', (t) async {
    tall(t);
    final s = Scripted(); final (os, repo) = states(s); final o = await completed(repo); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    await tapStar(t, 2);
    await t.enterText(find.byType(TextField), 'Call me on 9876543210'); await t.pumpAndSettle();
    s.failSubmit = const ReviewFailure('The review may not contain HTML or scripts.', code: 'validation_failed', presentable: true);
    await tapText(t, 'Submit review');
    expect(find.text('The review may not contain HTML or scripts.'), findsOneWidget);
    expect(find.text('Call me on 9876543210'), findsOneWidget); expect(find.text('2 of 5'), findsWidgets);
    s.failSubmit = const ReviewFailure('review_request_failed', code: 'network');
    await tapText(t, 'Submit review');
    expect(find.textContaining("couldn't submit your review"), findsOneWidget); expect(find.text('review_request_failed'), findsNothing);
    expect(s.submitted, isEmpty, reason: 'nothing is queued for later: a failed attempt is only a failed attempt');
    s.failSubmit = null;
    await tapText(t, 'Submit review');
    expect(find.text('Thanks for your feedback.'), findsOneWidget);
    expect(s.submitted.single.overallRating, 2);
    expect(find.textContaining('public and counts'), findsWidgets);
  });

  // What the Android preview suite could not show by scrolling (runs 4 and 5 of m34_flutter.js lost the text once the
  // harness had scrolled the form in the browser preview): in the app itself the text is the state's, not the field's.
  testWidgets('what the customer wrote survives the text field scrolling off the screen and a failed submit: nothing is lost, nothing is sent twice', (t) async {
    t.view.physicalSize = const Size(390, 720); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio);
    final s = Scripted(); final (os, repo) = states(s); final o = await completed(repo); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    await tapStar(t, 4);
    final form = find.byType(Scrollable).first;
    await t.scrollUntilVisible(find.byType(TextField), 200, scrollable: form); await t.pumpAndSettle();
    await t.enterText(find.byType(TextField), 'Hot and quick, thank you. 👍'); await t.pumpAndSettle();
    // Back to the top of the form: on this small screen the text field is far outside the viewport and is disposed.
    await t.drag(find.byType(ListView).first, const Offset(0, 4000)); await t.pumpAndSettle();
    expect(find.byType(TextField), findsNothing, reason: 'the test must really take the field off the screen');
    s.failSubmit = const ReviewFailure('review_request_failed', code: 'network');
    await t.tap(find.text('Submit review')); await t.pumpAndSettle();
    expect(find.text('Try again'), findsOneWidget); expect(s.submitted, isEmpty);
    await t.scrollUntilVisible(find.byType(TextField), 200, scrollable: form); await t.pumpAndSettle();
    expect(find.text('Hot and quick, thank you. 👍'), findsOneWidget, reason: 'the field shows what was written');
    await t.drag(find.byType(ListView).first, const Offset(0, 4000)); await t.pumpAndSettle();
    s.failSubmit = null;
    await t.tap(find.text('Submit review')); await t.pumpAndSettle();
    expect(s.submitted.length, 1);
    expect([s.submitted.single.overallRating, s.submitted.single.text], [4, 'Hot and quick, thank you. 👍']);
  });

  testWidgets('my review shows the restaurant\'s reply; it can be withdrawn after a confirmation, and the order cannot be reviewed again', (t) async {
    tall(t);
    final s = Scripted(); final (os, repo) = states(s); final o = await completed(repo); final auth = await signedIn(t);
    s.mine = review(o.orderNumber, response: const ReviewResponse(text: 'Thank you for stopping by.', restaurantName: 'Burger Hub'));
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    expect(find.text('You have already reviewed this order'), findsOneWidget);
    expect(find.text('Reply from Burger Hub'), findsOneWidget); expect(find.text('Thank you for stopping by.'), findsOneWidget);
    expect(find.text('Edit review'), findsOneWidget);
    await tapText(t, 'Remove review');
    expect(find.textContaining('this order cannot be reviewed again'), findsOneWidget);
    await tapText(t, 'Cancel');
    expect(s.deleted, isEmpty); expect(find.text('Reply from Burger Hub'), findsOneWidget);
    await tapText(t, 'Remove review'); await tapText(t, 'Yes, remove it');
    expect(s.deleted, ['r1']);
    expect(find.text('You removed your review'), findsOneWidget);
    expect(find.textContaining('a new review cannot be written'), findsOneWidget);
    expect(find.text('Submit review'), findsNothing); expect(find.text('Remove review'), findsNothing); expect(find.text('Edit review'), findsNothing);
  });

  testWidgets('a review the backend no longer lets the customer change offers neither edit nor remove', (t) async {
    tall(t);
    final s = Scripted(); final (os, repo) = states(s); final o = await completed(repo); final auth = await signedIn(t);
    s.mine = review(o.orderNumber, status: ReviewStatus.hidden, canEdit: false, canRemove: false);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    expect(find.text('Edit review'), findsNothing); expect(find.text('Remove review'), findsNothing);
    expect(find.textContaining('does not meet the review guidelines'), findsOneWidget);
  });

  testWidgets('My Reviews lists each review with its state, the reply and the way back; an empty list says how to start', (t) async {
    tall(t);
    final s = Scripted(); final (os, _) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/my-reviews')); await t.pumpAndSettle();
    expect(find.text('You have not reviewed an order yet.'), findsOneWidget);

    s.list = [
      review('FOTG-20261011-ABC234', response: const ReviewResponse(text: 'Thank you!', restaurantName: 'Burger Hub')),
      review('FOTG-20261010-XYZ987', id: 'r2', status: ReviewStatus.pendingModeration, canEdit: false, text: 'See my site'),
      review('FOTG-20261009-QRS555', id: 'r3', status: ReviewStatus.rejected, canEdit: false),
    ];
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(os, auth, '/my-reviews')); await t.pumpAndSettle();
    expect(find.text('Published'), findsOneWidget); expect(find.text('Being checked'), findsOneWidget); expect(find.text('Not published'), findsOneWidget);
    expect(find.text('Reply from Burger Hub'), findsOneWidget);
    expect(find.textContaining('being checked before it is shown publicly'), findsOneWidget);
    expect(find.textContaining('does not meet the review guidelines'), findsOneWidget);
    expect(find.text('Edit review'), findsOneWidget); expect(find.text('View review'), findsNWidgets(2));
    expect(find.text('FOTG-20261010-XYZ987 · Oct 11, 2026'), findsOneWidget);
  });

  testWidgets('a restaurant without reviews says so — no stars, no average, no count is invented', (t) async {
    tall(t, 1400);
    final s = Scripted(); final (os, _) = states(s);
    await t.pumpWidget(app(os, signedOut(), '/', home: const Scaffold(body: SingleChildScrollView(child: RestaurantReviews(slug: 'burger-hub'))))); await t.pumpAndSettle();
    expect(find.text('New'), findsOneWidget); expect(find.text('No reviews yet'), findsOneWidget);
    expect(find.byIcon(Icons.star_rounded), findsNothing);
    expect(find.textContaining('5.0'), findsNothing); expect(find.textContaining('verified order reviews'), findsNothing);
  });

  testWidgets('one review is "1 verified order review", not "reviews"', (t) async {
    tall(t, 1400);
    final s = Scripted()..public = [publicReview(1, rating: 4)]..summary = const RatingSummary(average: 4, count: 1, distribution: {4: 1});
    final (os, _) = states(s);
    await t.pumpWidget(app(os, signedOut(), '/', home: const Scaffold(body: SingleChildScrollView(child: RestaurantReviews(slug: 'burger-hub'))))); await t.pumpAndSettle();
    expect(find.text('from 1 verified order review'), findsOneWidget); expect(find.text('4.0'), findsOneWidget);
  });

  testWidgets('the public reviews: the backend\'s average and distribution, pages, sort and star filter asked of the backend, text shown as text', (t) async {
    tall(t);
    final s = Scripted()
      ..public = [publicReview(1, text: '<img src=x onerror=alert(1)> I <3 the fries', response: const ReviewResponse(text: 'Thank you.', restaurantName: 'Burger Hub', edited: true)), for (var n = 2; n <= 7; n++) publicReview(n)]
      ..summary = const RatingSummary(average: 3.4, count: 7, distribution: {5: 1, 4: 1, 3: 1, 2: 2, 1: 2});
    final (os, _) = states(s);
    await t.pumpWidget(app(os, signedOut(), '/', home: const Scaffold(body: SingleChildScrollView(child: RestaurantReviews(slug: 'burger-hub'))))); await t.pumpAndSettle();
    expect(find.text('3.4'), findsOneWidget); expect(find.text('from 7 verified order reviews'), findsOneWidget);
    expect(find.text('<img src=x onerror=alert(1)> I <3 the fries'), findsOneWidget);
    expect(find.text('Reply from Burger Hub · edited'), findsOneWidget);
    expect(find.text('Guest 5.'), findsOneWidget); expect(find.text('Guest 6.'), findsNothing);
    expect(find.textContaining('Verified order'), findsNWidgets(5));
    expect(find.text('Sign in to report a review'), findsNWidgets(5)); expect(find.text('Report this review'), findsNothing);

    await tapText(t, 'Show more reviews (5 of 7)');
    expect(find.text('Guest 7.'), findsOneWidget); expect(find.textContaining('Show more reviews'), findsNothing);
    expect(s.queries.last, (2, PublicReviewSort.newest, null));

    await tapText(t, 'Newest first'); await t.tap(find.text('Lowest rating first').last); await t.pumpAndSettle();
    expect(s.queries.last, (1, PublicReviewSort.lowest, null));
    await tapText(t, 'All ratings'); await t.tap(find.text('2 stars of 5').last); await t.pumpAndSettle();
    expect(s.queries.last, (1, PublicReviewSort.lowest, 2));
    expect(find.text('Guest 2.'), findsOneWidget); expect(find.text('Guest 1.'), findsNothing);
    expect(find.textContaining('not removed for being unfavourable'), findsOneWidget);
  });

  testWidgets('a signed-in customer reports a review with a reason; the backend\'s refusal is shown; the review stays', (t) async {
    tall(t);
    final s = Scripted()..public = [publicReview(1), publicReview(2)]..summary = const RatingSummary(average: 1.5, count: 2, distribution: {2: 1, 1: 1});
    final (os, _) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/', home: const Scaffold(body: SingleChildScrollView(child: RestaurantReviews(slug: 'burger-hub'))))); await t.pumpAndSettle();
    expect(find.text('Report this review'), findsNWidgets(2));
    expect(find.text('from 2 verified order reviews'), findsOneWidget);
    await tapText(t, 'Report this review');
    expect(find.textContaining('stays public until they decide'), findsOneWidget);
    await tapText(t, 'Spam or advertising'); await t.tap(find.text('I think it is not genuine').last); await t.pumpAndSettle();
    s.failReport = const ReviewFailure('You have already reported this review.', code: 'review_already_reported', presentable: true);
    await tapText(t, 'Send report');
    expect(find.text('You have already reported this review.'), findsOneWidget); expect(s.reports, isEmpty);
    s.failReport = null;
    await tapText(t, 'Send report');
    expect(s.reports, [('p1', 'FAKE_REVIEW_CONCERN', '')]);
    expect(find.textContaining('our team will look at this review'), findsOneWidget);
    expect(find.text('Guest 1.'), findsOneWidget); expect(find.text('Report this review'), findsOneWidget);
  });
}
