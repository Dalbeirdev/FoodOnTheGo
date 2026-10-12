import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/checkout/checkout_models.dart' show MockConnectivityService;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/order/api_order.dart';
import 'package:foodonthego/order/order_history.dart';
import 'package:foodonthego/order/refund_widgets.dart';
import 'package:foodonthego/screens/order_details_screen.dart';
import 'package:foodonthego/screens/order_tracking_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/tracking_state.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'api_order_test.dart' show number, orderDto;
import 'api_refund_test.dart' show quote, refundBlock, refundItem, refundLine, rejected;

/// Module 35 — the refund state and the cancellation of a backend order on the screens (Android widget tests): the
/// refund card on Order Details at every step (due, processing, refunded, under review) and its re-reading while the
/// refund is open; the tracking screen's refund note; the cancel action only when the backend allows it, its dialog on
/// the backend's quote, a confirmed cancellation and a refusal. A scripted repository answers in the API's shape.
class Scripted implements OrderRepository, OrderCancellationRepository {
  Scripted(this.dto);
  Map<String, dynamic> dto;
  Map<String, dynamic> quoteDto = quote();
  Map<String, dynamic>? afterCancel;
  Object? failCancel;
  int reads = 0, quotes = 0;
  final cancels = <(String, String?, int?)>[];

  @override
  Future<Order?> getByOrderNumber(String orderNumber, String customerId) async { reads++; return orderFromApi(dto, customerId); }
  @override
  Future<Order?> findByPaymentAttempt(String paymentAttemptId) async => null;
  @override
  Future<Order> createFromPayment(CreateOrderInput input) => throw StateError('orders are made by the backend');
  @override
  Future<List<Order>> listForCustomer(String customerId) async => [];
  @override
  Future<OrderPage> listSummaries(String customerId, OrderListQuery q) async => const OrderPage(items: [], nextCursor: null, total: 0);
  @override
  Future<Order?> applyEvents(String orderNumber, List<OrderEvent> events) => getByOrderNumber(orderNumber, '');
  @override
  Future<CancellationQuote?> cancellationQuote(String orderNumber) async { quotes++; return quoteFromApi(quoteDto); }
  @override
  Future<Order?> cancel(String orderNumber, {required String reasonCode, String? note, int? expectedVersion}) async {
    final fail = failCancel;
    if (fail != null) throw fail;
    cancels.add((reasonCode, note, expectedVersion));
    dto = afterCancel!;
    return orderFromApi(dto, '');
  }
}

const off = {'enabled': false, 'can_cancel': false, 'reason': 'CUSTOMER_CANCELLATION_DISABLED'};
const on = {'enabled': true, 'can_cancel': true, 'reason': null};
const due = 'A refund is due for this order. We are arranging it.';

void main() {
  setUp(() => refundRereadEvery = const Duration(milliseconds: 200));
  tearDown(() => refundRereadEvery = const Duration(seconds: 8));

  Future<AuthState> signedIn(WidgetTester t) async { final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero)); await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }); return auth; }
  void tall(WidgetTester t, [double h = 5200]) { t.view.physicalSize = Size(390, h); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  final reorder = MockReorderService(restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), latency: Duration.zero);

  Widget details(Scripted s, AuthState auth) {
    final st = MemoryKeyValueStore(); final mock = MockOrderRepository(st, latency: Duration.zero);
    final os = OrderState(orders: s, verifications: MockPickupVerificationRepository(mock), receipts: MockReceiptRepository(), store: st);
    return MultiProvider(providers: [
      ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os),
      ChangeNotifierProvider(create: (_) => CartState(repository: MemoryCartRepository())),
      ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
    ], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/order/$number', routes: [
      GoRoute(path: '/order/:number', builder: (_, p) => OrderDetailsScreen(number: p.pathParameters['number']!, reorderService: reorder)),
      GoRoute(path: '/order-tracking/:number', builder: (_, _) => const Scaffold(body: Text('tracking screen'))),
    ])));
  }

  (Widget, TrackingState) tracking(Scripted s, AuthState auth) {
    final st = MemoryKeyValueStore(); final mock = MockOrderRepository(st, latency: Duration.zero);
    final tr = TrackingState(orders: s, verifications: MockPickupVerificationRepository(mock), tracking: MockOrderTrackingService(s, st, interval: Duration.zero), connectivity: MockConnectivityService(), store: st);
    return (MultiProvider(providers: [
      ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: tr),
      ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
    ], child: MaterialApp.router(routerConfig: GoRouter(initialLocation: '/order-tracking/$number', routes: [
      GoRoute(path: '/order-tracking/:number', builder: (_, p) => OrderTrackingScreen(number: p.pathParameters['number']!)),
      GoRoute(path: '/order/:number', builder: (_, _) => const Scaffold(body: Text('details screen'))),
      GoRoute(path: '/help', builder: (_, _) => const Scaffold(body: Text('help screen'))),
    ]))), tr);
  }

  testWidgets('Order Details — a rejected paid order: refund due, then processing, then refunded, read again by itself and never ahead of the backend', (t) async {
    tall(t);
    final s = Scripted(rejected('REQUIRED', refundBlock('REQUIRED', [refundItem('REQUIRED')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED')]));
    final auth = await signedIn(t);
    await t.pumpWidget(details(s, auth)); await t.pumpAndSettle();
    expect(find.text('Order status: Rejected'), findsOneWidget); expect(find.text('Payment status: Refund pending'), findsOneWidget);
    expect(find.text('Refund'), findsOneWidget); expect(find.text('Refund due'), findsOneWidget); expect(find.text(due), findsOneWidget);
    expect(find.text('Refunded so far'), findsOneWidget); expect(find.text('₹0.00'), findsOneWidget);
    expect(find.text('Still to be refunded'), findsOneWidget); expect(find.text('₹427.00'), findsWidgets);
    expect(find.textContaining('Reference RFD-5D00000000'), findsOneWidget); expect(find.text('The restaurant could not accept the order'), findsOneWidget);
    // The development block ("Refunded / Remaining paid / Amount pending confirmation") is not shown next to the backend's refunds.
    expect(find.text('Remaining paid'), findsNothing); expect(find.text('Amount pending confirmation'), findsNothing);
    expect(find.text('Refunded'), findsNothing, reason: 'rejected is not refunded');

    s.dto = rejected('PROCESSING', refundBlock('PROCESSING', [refundItem('PROCESSING')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING')]);
    await t.pump(const Duration(milliseconds: 250)); await t.pump();
    expect(find.text('Payment status: Refund processing'), findsOneWidget); expect(find.text('Processing'), findsOneWidget);
    expect(find.textContaining('It is not completed yet'), findsOneWidget); expect(find.text('Refunded'), findsNothing, reason: 'processing is not refunded');

    s.dto = rejected('REFUNDED', refundBlock('REFUNDED', [refundItem('REFUNDED', completedAt: '2026-10-08T08:05:00+00:00')], refunded: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING'), refundLine('REFUND_COMPLETED', 'REFUNDED')]);
    await t.pump(const Duration(milliseconds: 250)); await t.pump();
    expect(find.text('Payment status: Refunded'), findsOneWidget); expect(find.text('Refunded'), findsNWidgets(2), reason: 'the payment section and the refund itself'); expect(find.text('Completed by the payment provider.'), findsOneWidget);
    expect(find.text('Still to be refunded'), findsNothing); expect(find.textContaining('completed Thu, Oct 8'), findsOneWidget);
    expect(find.text('Order status: Rejected'), findsOneWidget, reason: 'the order stays what it was');
    expect(find.textContaining('depends on your bank'), findsWidgets); expect(find.textContaining(RegExp(r'\d+\s*(business )?days')), findsNothing, reason: 'no number of days is promised');

    final reads = s.reads;
    await t.pump(const Duration(seconds: 2)); await t.pump();
    expect(s.reads, reads, reason: 'a settled refund is not asked about again');
  });

  testWidgets('Order Details — a refund that failed or needs a person reads "under review": never "failed", never "refunded", never a second charge', (t) async {
    tall(t);
    final s = Scripted(rejected('UNDER_REVIEW', refundBlock('UNDER_REVIEW', [refundItem('UNDER_REVIEW')], pending: 42700), lines: [refundLine('REFUND_UNDER_REVIEW', 'UNDER_REVIEW')]));
    final auth = await signedIn(t);
    await t.pumpWidget(details(s, auth)); await t.pumpAndSettle();
    expect(find.text('Payment status: Refund under review'), findsOneWidget); expect(find.text('Under review'), findsOneWidget);
    expect(find.textContaining('you do not need to do anything, and you will not be charged again'), findsOneWidget);
    expect(find.textContaining('Failed'), findsNothing); expect(find.textContaining('failed'), findsNothing); expect(find.text('Refunded'), findsNothing);
    // It is asked about again: a person may settle it.
    final reads = s.reads;
    await t.pump(const Duration(milliseconds: 450)); await t.pump();
    expect(s.reads, greaterThan(reads));
    await t.pumpWidget(const SizedBox()); // leaving the screen stops the re-reading
    final left = s.reads;
    await t.pump(const Duration(seconds: 1));
    expect(s.reads, left);
  });

  testWidgets('Order Details — a partial refund on a completed order: the confirmed part and the part on its way, each with its own status', (t) async {
    tall(t);
    final s = Scripted({
      ...orderDto(status: 'COMPLETED', refund: 'PARTIALLY_REFUNDED', credential: null),
      'refund': refundBlock('PARTIALLY_REFUNDED', [refundItem('REFUNDED', amount: 10000, type: 'PARTIAL', completedAt: '2026-10-08T10:00:00+00:00', id: 'r-1'), refundItem('REFUNDED', amount: 5000, type: 'PARTIAL', completedAt: '2026-10-08T11:00:00+00:00', id: 'r-2')], refunded: 15000),
      'customer_cancellation': off,
    });
    final auth = await signedIn(t);
    await t.pumpWidget(details(s, auth)); await t.pumpAndSettle();
    expect(find.text('Payment status: Partially refunded'), findsOneWidget); expect(find.text('₹150.00'), findsOneWidget);
    expect(find.text('₹100.00'), findsOneWidget); expect(find.text('₹50.00'), findsOneWidget); expect(find.textContaining('part of the payment'), findsNWidgets(2));
    expect(find.text('Refunded'), findsNWidgets(2)); expect(find.text('Still to be refunded'), findsNothing);
    final reads = s.reads;
    await t.pump(const Duration(seconds: 1));
    expect(s.reads, reads, reason: 'nothing is open: nothing is re-read');
  });

  testWidgets('Tracking — cancellation is not offered while the backend has no approved policy; an older answer keeps the development note', (t) async {
    tall(t);
    final s = Scripted({...orderDto(), 'refund': refundBlock('NONE', []), 'customer_cancellation': off});
    final auth = await signedIn(t);
    final (app, _) = tracking(s, auth);
    await t.pumpWidget(app); await t.pumpAndSettle();
    expect(find.byKey(const Key('trk-cancel')), findsNothing); expect(find.text('Cancel order'), findsNothing);
    expect(find.text('Orders cannot be cancelled in the app yet. If you need to cancel, please contact support.'), findsOneWidget);
    expect(find.textContaining('POLICY / BACKEND PENDING'), findsNothing);
    expect(find.text('Refund'), findsNothing, reason: 'no refund: no refund card');

    // The policy is on, but the restaurant already accepted: not now.
    final s2 = Scripted({...orderDto(status: 'ACCEPTED'), 'customer_cancellation': {'enabled': true, 'can_cancel': false, 'reason': 'ORDER_STATE_NOT_CANCELLABLE'}});
    final (app2, _) = tracking(s2, auth);
    await t.pumpWidget(app2); await t.pumpAndSettle();
    expect(find.byKey(const Key('trk-cancel')), findsNothing);
    expect(find.text('This order can no longer be cancelled in the app. If you need help, please contact support.'), findsOneWidget);

    // An answer without the block (a backend older than Module 35, the development orders): the old disabled action and its note.
    final s3 = Scripted(orderDto());
    final (app3, _) = tracking(s3, auth);
    await t.pumpWidget(app3); await t.pumpAndSettle();
    expect(find.text('Cancel order'), findsOneWidget); expect(find.byKey(const Key('trk-cancel')), findsNothing);
    expect(find.textContaining('POLICY / BACKEND PENDING'), findsOneWidget);
  });

  testWidgets('Tracking — when the backend allows it: the quote is shown before confirming, only a reason is sent, and the screen shows what the backend made of it', (t) async {
    tall(t);
    final s = Scripted({...orderDto(), 'refund': refundBlock('NONE', []), 'customer_cancellation': on});
    s.afterCancel = {...orderDto(status: 'CANCELLED', refund: 'REQUIRED', credential: null), 'cancellation': {'by': 'YOU', 'message': 'You cancelled this order.'}, 'refund': refundBlock('REQUIRED', [{...refundItem('REQUIRED'), 'reason': {'code': 'CUSTOMER_CANCELLATION', 'label': 'You cancelled the order'}}], pending: 42700), 'customer_cancellation': {'enabled': true, 'can_cancel': false, 'reason': 'ORDER_ALREADY_CLOSED'}};
    final auth = await signedIn(t);
    final (app, tr) = tracking(s, auth);
    await t.pumpWidget(app); await t.pumpAndSettle();
    expect(find.byKey(const Key('trk-cancel')), findsOneWidget); expect(find.byKey(const Key('trk-cancel-unavailable')), findsNothing);
    await t.tap(find.byKey(const Key('trk-cancel'))); await t.pumpAndSettle();
    expect(find.text('Cancel order $number?'), findsOneWidget);
    expect(find.text('If you cancel now, your payment for this order will be refunded to the method you paid with.'), findsOneWidget);
    expect(find.text('A refund of your payment is due'), findsOneWidget); expect(find.text('Refund amount'), findsOneWidget); expect(find.text('I changed my mind'), findsOneWidget);
    expect(s.cancels, isEmpty, reason: 'opening the dialog cancels nothing');
    await t.enterText(find.byKey(const Key('cancel-note')), '  Plans changed  '); await t.pump();
    await t.tap(find.byKey(const Key('cancel-confirm'))); await t.pumpAndSettle();
    expect(s.cancels, [('CHANGED_MIND', 'Plans changed', 1)], reason: 'a reason, a note and the version that was read — no amount');
    expect(find.text('Cancel order $number?'), findsNothing);
    expect(tr.order!.orderStatus, OrderStatus.cancelled);
    expect(find.textContaining('You cancelled this order.'), findsOneWidget);
    expect(find.text(due), findsNWidgets(2), reason: 'the note under the status and the refund card');
    expect(find.text('Refund due'), findsOneWidget); expect(find.text('You cancelled the order'), findsOneWidget); expect(find.text('Refunded'), findsNothing, reason: 'cancelled is not refunded');
    expect(find.byKey(const Key('trk-cancel')), findsNothing);
  });

  testWidgets('Tracking — the restaurant moved the order first: the backend refuses, the dialog says so and nothing is cancelled', (t) async {
    tall(t);
    final s = Scripted({...orderDto(), 'customer_cancellation': on})..quoteDto = quote(effect: 'MANUAL_REVIEW', refundable: null, message: 'If you cancel now, our team reviews the payment for this order and tells you the outcome.');
    final auth = await signedIn(t);
    final (app, tr) = tracking(s, auth);
    await t.pumpWidget(app); await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('trk-cancel'))); await t.pumpAndSettle();
    expect(find.text('Our team reviews your payment and tells you the outcome'), findsOneWidget); expect(find.text('Refund amount'), findsNothing, reason: 'no amount is promised when a person decides');
    s.failCancel = ApiException(409, 'This order cannot be cancelled in the app.', code: 'order_cancellation_not_allowed');
    s.quoteDto = quote(can: false, effect: null, refundable: null, reason: 'ORDER_STATE_NOT_CANCELLABLE', message: 'This order can no longer be cancelled in the app. Please contact FoodOnTheGo support.');
    await t.tap(find.byKey(const Key('cancel-confirm'))); await t.pumpAndSettle();
    expect(find.text('This order has moved on and can no longer be cancelled in the app.'), findsOneWidget);
    expect(find.text('This order can no longer be cancelled in the app. Please contact FoodOnTheGo support.'), findsOneWidget, reason: 'the quote was asked again');
    expect(find.byKey(const Key('cancel-confirm')), findsNothing); expect(s.cancels, isEmpty);
    await t.tap(find.byKey(const Key('cancel-keep'))); await t.pumpAndSettle();
    expect(find.text('Cancel order $number?'), findsNothing); expect(tr.order!.orderStatus, OrderStatus.awaitingRestaurantAcceptance);

    // A failure that is not a refusal stays generic and the order is kept.
    s.quoteDto = quote(); s.failCancel = ApiException(0, 'network');
    await t.tap(find.byKey(const Key('trk-cancel'))); await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('cancel-confirm'))); await t.pumpAndSettle();
    expect(find.text('The order could not be cancelled. Please try again.'), findsOneWidget); expect(s.cancels, isEmpty);
    await t.tap(find.byKey(const Key('cancel-keep'))); await t.pumpAndSettle();
  });

  testWidgets('Tracking — a rejected paid order says what happens to the money in the backend\'s state, and shows the refund', (t) async {
    tall(t);
    final s = Scripted(rejected('PROCESSING', refundBlock('PROCESSING', [refundItem('PROCESSING')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING')]));
    final auth = await signedIn(t);
    final (app, _) = tracking(s, auth);
    await t.pumpWidget(app); await t.pumpAndSettle();
    expect(find.text('Order could not be accepted'), findsOneWidget);
    expect(find.text('Your refund is on its way to the method you paid with. It is not completed yet.'), findsOneWidget);
    expect(find.text('Processing'), findsOneWidget); expect(find.text('Refunded so far'), findsOneWidget); expect(find.text('Refunded'), findsNothing);
    expect(find.textContaining('Payment status: {status}'), findsNothing);
    // A finished order: no cancel action and no line about cancelling.
    expect(find.byKey(const Key('trk-cancel')), findsNothing); expect(find.byKey(const Key('trk-cancel-unavailable')), findsNothing);
  });
}
