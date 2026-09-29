import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/checkout/checkout_models.dart' show MockConnectivityService;
import 'package:foodonthego/review/review_models.dart';
import 'package:foodonthego/screens/order_details_screen.dart';
import 'package:foodonthego/screens/review_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

/// Module 16 — review screen (Android widget tests): eligible flow, validation, tags + Unicode text, failure keeps the draft,
/// double tap, already reviewed + edit, active order, details entry point.
void main() {
  Future<AuthState> signedIn(WidgetTester t) async { final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero)); await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }); return auth; }
  (OrderState, MockOrderRepository, MockReviewRepository) states() {
    final st = MemoryKeyValueStore(); final repo = MockOrderRepository(st, latency: Duration.zero); final reviews = MockReviewRepository(st, latency: Duration.zero);
    return (OrderState(orders: repo, verifications: MockPickupVerificationRepository(repo), receipts: MockReceiptRepository(), store: st, reviews: reviews), repo, reviews);
  }
  Future<Order> completed(MockOrderRepository repo, {OrderStatus status = OrderStatus.completed}) async {
    final o = await repo.createFromPayment(CreateOrderInput(paymentAttemptId: 'pa_${DateTime.now().microsecondsSinceEpoch}', checkoutReference: 'ck', customerId: 'cust-rahul',
        restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'x', countryCode: 'IN', timezone: 'Asia/Kolkata'),
        items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000)],
        pricing: const OrderPricing(currency: 'INR', subtotalMinor: 50000, discountMinor: 0, totalMinor: 50000),
        payment: const OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_ref', paidAmountMinor: 50000, currency: 'INR'),
        pickup: PickupSnapshot(mode: 'asap', requestedAt: DateTime.utc(2026, 9, 29, 9), estimatedReadyTime: DateTime.utc(2026, 9, 29, 9), restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'), orderNote: ''));
    if (status == OrderStatus.completed) {
      final steps = [(2, OrderEventType.restaurantAccepted, OrderStatus.accepted), (3, OrderEventType.preparing, OrderStatus.preparing), (4, OrderEventType.readyForPickup, OrderStatus.readyForPickup), (5, OrderEventType.pickedUp, OrderStatus.pickedUp), (6, OrderEventType.completed, OrderStatus.completed)];
      return (await repo.applyEvents(o.orderNumber, [for (final (i, tp, st) in steps) OrderEvent(eventId: 'e$i', sequence: i, type: tp, status: st, at: DateTime.now().toUtc(), actor: 'restaurant')]))!;
    }
    return (await repo.applyEvents(o.orderNumber, [OrderEvent(eventId: 'e2', sequence: 2, type: OrderEventType.restaurantAccepted, status: OrderStatus.accepted, at: DateTime.now().toUtc(), actor: 'restaurant'), OrderEvent(eventId: 'e3', sequence: 3, type: OrderEventType.preparing, status: OrderStatus.preparing, at: DateTime.now().toUtc(), actor: 'restaurant')]))!;
  }
  Widget app(OrderState os, AuthState auth, String location, {MockConnectivityService? net}) => MultiProvider(providers: [
        ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os),
        ChangeNotifierProvider(create: (_) => CartState(repository: MemoryCartRepository())),
        ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
      ], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: location, routes: [
        GoRoute(path: '/order/:number/review', builder: (_, s) => ReviewScreen(number: s.pathParameters['number']!, connectivity: net, draftStore: MemoryKeyValueStore())),
        GoRoute(path: '/order/:number', builder: (_, s) => OrderDetailsScreen(number: s.pathParameters['number']!)),
        GoRoute(path: '/order-tracking/:number', builder: (_, s) => Scaffold(body: Text('tracking ${s.pathParameters['number']}'))),
        GoRoute(path: '/help', builder: (_, _) => const Scaffold(body: Text('help screen'))),
      ])));
  void tall(WidgetTester t, [double h = 3200]) { t.view.physicalSize = Size(390, h); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  Future<void> tapText(WidgetTester t, String text) async { final f = find.text(text).first; await t.ensureVisible(f); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }
  Future<void> tapStar(WidgetTester t, int n) async { final f = find.bySemanticsLabel(n == 1 ? '1 star of 5' : '$n stars of 5').first; await t.ensureVisible(f); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }

  testWidgets('TEST 1 / 3 / 4 / 5 / 6 — eligible order: rating required, tags + item feedback + Unicode text, single review stored, confirmation + next actions', (t) async {
    tall(t);
    final (os, repo, reviews) = states(); final o = await completed(repo); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    expect(find.text('Rate your experience'), findsWidgets); expect(find.text('Overall rating'), findsWidgets);
    await tapText(t, 'Submit review');
    expect(find.text('Please choose an overall rating.'), findsOneWidget);
    expect(await reviews.getReviewForOrder(o.publicId, 'cust-rahul'), isNull);
    await tapStar(t, 5); expect(find.text('5 of 5'), findsWidgets);
    expect(find.text('Long wait'), findsNothing); // negative tags hidden for a 5-star rating
    await tapText(t, 'Great food'); await tapText(t, 'Fast pickup'); await tapText(t, 'Liked');
    await t.enterText(find.byType(TextField), 'Très bon 😀 一風堂 <b>x</b>'); await t.pumpAndSettle();
    expect(find.textContaining('characters remaining'), findsOneWidget);
    await tapText(t, 'Submit review');
    expect(find.text('Thanks for your feedback.'), findsOneWidget); expect(find.text('What next?'), findsOneWidget); expect(find.text('Save restaurant'), findsOneWidget);
    final r = (await reviews.getReviewForOrder(o.publicId, 'cust-rahul'))!;
    expect(r.overallRating, 5); expect(r.tags, ['great_food', 'fast_pickup']); expect(r.text, 'Très bon 😀 一風堂 <b>x</b>'); expect(r.restaurantId, 'burger-hub'); expect(r.itemFeedback.single.sentiment, ItemSentiment.liked);
    expect(find.text('Très bon 😀 一風堂 <b>x</b>'), findsOneWidget); // rendered as text
    expect(find.text('Get help with this order'), findsOneWidget); expect(find.text('Plan another journey'), findsOneWidget);
  });

  testWidgets('TEST 2 — active order: not available, Track order offered', (t) async {
    tall(t);
    final (os, repo, _) = states(); final o = await completed(repo, status: OrderStatus.preparing); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    expect(find.textContaining('once your order is picked up'), findsOneWidget); expect(find.text('Submit review'), findsNothing);
    await tapText(t, 'Track order'); expect(find.text('tracking ${o.orderNumber}'), findsOneWidget);
  });

  testWidgets('TEST 7 / 8 — failure keeps the answers, Retry succeeds; rapid double tap stores one review', (t) async {
    tall(t);
    final (os, repo, reviews) = states(); final o = await completed(repo); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    await tapStar(t, 2); await tapText(t, 'Item missing'); await t.enterText(find.byType(TextField), 'Missing fries'); await t.pumpAndSettle();
    reviews.fail = true; await tapText(t, 'Submit review');
    expect(find.textContaining("couldn't submit your review"), findsOneWidget); expect(find.text('Missing fries'), findsOneWidget); expect(find.text('2 of 5'), findsWidgets);
    expect(await reviews.getReviewForOrder(o.publicId, 'cust-rahul'), isNull);
    reviews.fail = false;
    final retry = find.text('Try again').first; await t.ensureVisible(retry); await t.tap(retry, warnIfMissed: false); await t.tap(retry, warnIfMissed: false); await t.pumpAndSettle();
    expect(find.text('Thanks for your feedback.'), findsOneWidget);
    final r = (await reviews.getReviewForOrder(o.publicId, 'cust-rahul'))!; expect(r.overallRating, 2); expect(r.tags, ['item_missing']); expect(r.text, 'Missing fries'); expect(r.version, 1);
  });

  testWidgets('TEST 9 / 10 — already reviewed: no form, Edit restores the content, update keeps one record', (t) async {
    tall(t);
    final (os, repo, reviews) = states(); final o = await completed(repo); final auth = await signedIn(t);
    await reviews.submitReview(o, emptyDraft(o).copyWith(overallRating: 3, tags: ['as_expected'], text: 'OK'));
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}/review')); await t.pumpAndSettle();
    expect(find.text('You have already reviewed this order'), findsOneWidget); expect(find.text('Submit review'), findsNothing); expect(find.text('OK'), findsOneWidget);
    await tapText(t, 'Edit review');
    expect(find.text('Edit your review'), findsWidgets); expect(find.text('3 of 5'), findsWidgets); expect(find.widgetWithText(TextField, 'OK'), findsOneWidget);
    await tapStar(t, 4); await t.enterText(find.byType(TextField), 'Better than expected'); await t.pumpAndSettle();
    await tapText(t, 'Update review');
    expect(find.text('Thanks for your feedback.'), findsOneWidget);
    final r = (await reviews.getReviewForOrder(o.publicId, 'cust-rahul'))!; expect(r.version, 2); expect(r.overallRating, 4); expect(r.text, 'Better than expected');
  });

  testWidgets('TEST 1 / 9 / offline — details entry point (Rate → Edit review) and offline blocks submit', (t) async {
    tall(t, 5200);
    final (os, repo, reviews) = states(); final o = await completed(repo); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}')); await t.pumpAndSettle();
    expect(find.text('Rate your experience'), findsOneWidget);
    await reviews.submitReview(o, emptyDraft(o).copyWith(overallRating: 5));
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}')); await t.pumpAndSettle();
    expect(find.text('Edit review'), findsOneWidget); expect(find.textContaining('Reviewed · 5 of 5'), findsOneWidget);
    final o2 = await completed(repo); final net = MockConnectivityService()..online = false;
    await t.pumpWidget(app(os, auth, '/order/${o2.orderNumber}/review', net: net)); await t.pumpAndSettle();
    expect(find.textContaining('You are offline'), findsOneWidget);
    await tapStar(t, 4); await tapText(t, 'Submit review');
    expect(await reviews.getReviewForOrder(o2.publicId, 'cust-rahul'), isNull); expect(find.text('Thanks for your feedback.'), findsNothing);
  });
}
