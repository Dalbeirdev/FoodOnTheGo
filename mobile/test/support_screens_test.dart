import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/account/api_account.dart' show notificationFromApi;
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/checkout/checkout_models.dart' show MockConnectivityService;
import 'package:foodonthego/core/theme.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/main.dart' show protectedPrefixes;
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/notifications/push_notice.dart';
import 'package:foodonthego/order/api_order.dart' show orderFromApi;
import 'package:foodonthego/order/order_history.dart';
import 'package:foodonthego/order/refund_widgets.dart';
import 'package:foodonthego/screens/help_screen.dart';
import 'package:foodonthego/screens/order_details_screen.dart';
import 'package:foodonthego/screens/order_tracking_screen.dart';
import 'package:foodonthego/screens/support_case_screen.dart';
import 'package:foodonthego/screens/support_cases_screen.dart';
import 'package:foodonthego/screens/support_new_case_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/order_state.dart';
import 'package:foodonthego/state/tracking_state.dart';
import 'package:foodonthego/support/support_drafts.dart';
import 'package:foodonthego/support/support_models.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'api_order_test.dart' as api show number, orderDto;
import 'api_refund_test.dart' show refundBlock, refundItem, refundLine, rejected;

/// Module 37 — the support screens on backend-shaped answers (Android widget tests): Help with and without the
/// backend, My cases (unread, filter, paging, offline), a new case opened from an order or a refund (linked, one
/// Idempotency-Key per composed request), the case screen (actions exactly as `can` says, the conversation as text,
/// refresh by itself), a reply that fails and is retried under the SAME id, drafts that survive leaving the screen,
/// and the notification target. A scripted repository stands in for the API and records what was asked of it.
const caseId = '0c000000-0000-4000-8000-000000000001';
const number = 'CS-ABCD2345';
const offline = SupportFailure('support_offline', code: 'network', offline: true);

class Scripted implements SupportRepository {
  SupportCatalog cat = const SupportCatalog(categories: [SupportCategory(code: 'ORDER', subcategories: ['ORDER_DELAYED', 'WRONG_ORDER']), SupportCategory(code: 'REFUND', subcategories: ['REFUND_NOT_RECEIVED']), SupportCategory(code: 'OTHER')]);
  final Map<String, List<SupportCaseSummary>> lists = {'open': [], 'closed': [], 'all': []};
  int unread = 0, pageSize = 20;

  // The one case of the detail tests, as the "backend" has it now.
  String caseNumber = number, subject = 'My order is late', category = 'ORDER', status = 'IN_PROGRESS';
  SupportCan can = const SupportCan(reply: true, withdraw: true);
  List<SupportLink> links = [];
  DateTime? reopenUntil;
  final List<SupportMessage> thread = [];

  SupportFailure? failCatalog, failList, failOpen, failGet, failReply, failClose, failReopen;
  Completer<void>? gate;
  final listQueries = <(String, int)>[];
  final openAttempts = <(NewSupportCase, String)>[];
  final replyAttempts = <(String, String)>[];
  final gets = <String>[], reopens = <String>[];
  int closes = 0;

  SupportCase get now => SupportCase(id: caseId, caseNumber: caseNumber, category: category, subject: subject, status: status, messageCount: thread.length, createdAt: DateTime.utc(2026, 10, 12, 8), lastMessageAt: thread.isEmpty ? null : thread.last.createdAt, links: links, can: can, reopenUntil: reopenUntil);
  void say(SupportAuthor who, String body) => thread.add(SupportMessage(id: 'm-${thread.length + 1}', author: who, body: body, createdAt: DateTime.utc(2026, 10, 12, 8, thread.length + 1)));

  @override
  Future<SupportCatalog> catalog() async { final f = failCatalog; if (f != null) throw f; return cat; }
  @override
  Future<SupportCasePage> listCases({String state = 'open', int page = 1, int perPage = 20}) async {
    listQueries.add((state, page));
    final f = failList; if (f != null) throw f;
    final all = lists[state]!; final start = (page - 1) * pageSize;
    return SupportCasePage(items: all.skip(start).take(pageSize).toList(), page: page, hasMore: start + pageSize < all.length, total: all.length, unread: unread);
  }
  @override
  Future<SupportCase> openCase(NewSupportCase input, {required String idempotencyKey}) async {
    openAttempts.add((input, idempotencyKey));
    final f = failOpen; if (f != null) throw f;
    caseNumber = 'CS-NEWC2345'; subject = input.subject; category = input.category; status = 'OPEN'; can = const SupportCan(reply: true, withdraw: true);
    links = [if (input.order != null) SupportLink(id: 'l-1', type: 'ORDER', reference: input.order!, status: 'ACCEPTED', amountMinor: 50000, currency: 'INR', restaurant: 'Burger Hub'), if (input.refund != null) SupportLink(id: 'l-2', type: 'REFUND', reference: input.refund!, status: 'PROCESSING', amountMinor: 42700, currency: 'INR', orderNumber: input.order)];
    thread.clear(); say(SupportAuthor.you, input.message);
    return now;
  }
  @override
  Future<SupportCase> getCase(String reference) async { gets.add(reference); final f = failGet; if (f != null) throw f; if (reference != caseId && reference != caseNumber) throw const SupportFailure('This case does not exist.', code: 'case_not_found', presentable: true, notFound: true); return now; }
  @override
  Future<SupportMessagePage> messages(String caseId, {int page = 1, int perPage = 50}) async { final start = (page - 1) * perPage; return SupportMessagePage(items: thread.skip(start).take(perPage).toList(), page: page, hasMore: start + perPage < thread.length); }
  @override
  Future<SupportReplyResult> reply(String caseId, {required String message, required String clientMessageId}) async {
    replyAttempts.add((message, clientMessageId));
    if (gate != null) await gate!.future;
    final f = failReply; if (f != null) throw f;
    // As the backend does: the same client id writes nothing twice.
    final id = 'srv-$clientMessageId';
    var m = thread.where((x) => x.id == id).firstOrNull;
    if (m == null) { m = SupportMessage(id: id, author: SupportAuthor.you, body: message, createdAt: DateTime.utc(2026, 10, 12, 8, thread.length + 1)); thread.add(m); }
    return SupportReplyResult(message: m, supportCase: now);
  }
  @override
  Future<SupportCase> close(String caseId, {required bool confirm}) async {
    final f = failClose; if (f != null) throw f;
    closes++;
    if (status == 'RESOLVED') { status = 'CLOSED'; can = const SupportCan(reopen: true); reopenUntil = DateTime.utc(2026, 10, 19, 9); } else { status = 'CANCELLED'; can = const SupportCan(); reopenUntil = null; }
    return now;
  }
  @override
  Future<SupportCase> reopen(String caseId, String message) async {
    final f = failReopen; if (f != null) throw f;
    reopens.add(message); status = 'OPEN'; can = const SupportCan(reply: true, withdraw: true); reopenUntil = null; say(SupportAuthor.you, message);
    return now;
  }
}

/// A backend order (support exists only with the backend, and a backend order number is what a case can be linked to).
class OneOrder implements OrderRepository {
  @override
  Future<Order?> getByOrderNumber(String orderNumber, String customerId) async => orderNumber == api.number ? orderFromApi(api.orderDto(), customerId) : null;
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
}

SupportCaseSummary row(String n, {String subject = 'My order is late', String category = 'ORDER', String status = 'IN_PROGRESS', bool unread = false}) =>
    SupportCaseSummary(id: 'id-$n', caseNumber: n, category: category, subject: subject, status: status, unread: unread, messageCount: 2, createdAt: DateTime.utc(2026, 10, 12, 8), lastMessageAt: DateTime.utc(2026, 10, 12, 9));

void main() {
  setUp(() => supportPollEvery = const Duration(seconds: 20));
  tearDown(() => supportPollEvery = const Duration(seconds: 20));

  Future<AuthState> signedIn(WidgetTester t) async { final auth = AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero)); await t.runAsync(() async { final otp = await auth.requestOtp('+919876543210'); await auth.verifyOtp(otp.devOtp!); }); return auth; }
  AuthState signedOut() => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero));
  final reorder = MockReorderService(restaurants: MockRestaurantRepository(latency: Duration.zero), menu: MockMenuRepository(latency: Duration.zero), latency: Duration.zero);

  /// The app's state with (or without) a support repository; the store is where drafts are written through to.
  (OrderState, MemoryKeyValueStore) states(Scripted? s) {
    final st = MemoryKeyValueStore(); final mock = MockOrderRepository(st, latency: Duration.zero);
    return (OrderState(orders: OneOrder(), verifications: MockPickupVerificationRepository(mock), receipts: MockReceiptRepository(), store: st, support: s), st);
  }

  late GoRouter router;
  Widget app(OrderState os, AuthState auth, String location, {Widget? home, ThemeData? theme}) {
    router = GoRouter(initialLocation: location, routes: [
      GoRoute(path: '/', builder: (_, _) => home ?? const Scaffold(body: Text('home screen'))),
      GoRoute(path: '/help', builder: (_, _) => const HelpScreen()),
      GoRoute(path: '/support', builder: (_, _) => const SupportCasesScreen()),
      GoRoute(path: '/support/new', builder: (_, s) => SupportNewCaseScreen(category: s.uri.queryParameters['category'], order: s.uri.queryParameters['order'], refund: s.uri.queryParameters['refund'], payment: s.uri.queryParameters['payment'])),
      GoRoute(path: '/support/cases/:reference', builder: (_, s) => SupportCaseScreen(key: ValueKey('support-case-${s.pathParameters['reference']}'), reference: s.pathParameters['reference']!)),
      GoRoute(path: '/order/:number', builder: (_, s) => OrderDetailsScreen(number: s.pathParameters['number']!, reorderService: reorder)),
      GoRoute(path: '/order-tracking/:number', builder: (_, _) => const Scaffold(body: Text('tracking screen'))),
      GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('login screen'))),
    ]);
    return MultiProvider(providers: [
      ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os),
      ChangeNotifierProvider(create: (_) => CartState(repository: MemoryCartRepository())),
      ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
    ], child: MaterialApp.router(theme: theme, routerConfig: router));
  }
  void tall(WidgetTester t, [double h = 3600]) { t.view.physicalSize = Size(390, h); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }
  // Scrolled into view first (and laid out there) — on a small screen an action may be below the fold.
  Future<void> tapKey(WidgetTester t, String key) async { final f = find.byKey(Key(key)).first; await t.ensureVisible(f); await t.pump(); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }
  Future<void> tapText(WidgetTester t, String text) async { final f = find.text(text).first; await t.ensureVisible(f); await t.pump(); await t.tap(f, warnIfMissed: false); await t.pumpAndSettle(); }
  Future<void> type(WidgetTester t, String key, String text) async { await t.enterText(find.byKey(Key(key)), text); await t.pumpAndSettle(); }
  Finder id(String identifier) => find.byWidgetPredicate((w) => w is Semantics && w.properties.identifier == identifier);

  testWidgets('Help — without the backend it is what it was; with it a signed-in customer contacts support and sees how many cases have a new reply; a guest is asked to sign in', (t) async {
    tall(t);
    // No backend: no support actions, and the screen still says that requests are not sent anywhere.
    final (plain, _) = states(null);
    await t.pumpWidget(app(plain, await signedIn(t), '/help')); await t.pumpAndSettle();
    expect(id('help-contact-support'), findsNothing); expect(id('help-my-cases'), findsNothing); expect(find.text('Sign in to contact support'), findsNothing);
    expect(find.textContaining('Support requests are not sent anywhere yet'), findsOneWidget); expect(find.text('Live Chat'), findsNothing); expect(find.text('Call Us'), findsNothing); expect(find.text('Popular Topics'), findsOneWidget);

    // With the backend and a session.
    final s = Scripted()..unread = 2..lists['open'] = [row(number, unread: true)];
    final (os, _) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(os, auth, '/help')); await t.pumpAndSettle();
    expect(id('help-contact-support'), findsOneWidget); expect(id('help-my-cases'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('help-unread')), matching: find.text('2')), findsOneWidget);
    expect(find.textContaining('Support requests are not sent anywhere yet'), findsNothing, reason: 'with real cases that sentence would be untrue');
    expect(find.text('Popular Topics'), findsOneWidget, reason: 'what the screen showed is kept');
    await tapKey(t, 'help-my-cases');
    expect(id('support-cases'), findsOneWidget); expect(find.textContaining(number), findsOneWidget);
    router.pop(); await t.pumpAndSettle();
    await tapKey(t, 'help-contact-support');
    expect(id('support-new'), findsOneWidget); expect(id('support-linked'), findsNothing, reason: 'nothing is linked when support is contacted from Help');

    // With the backend, signed out: a prompt, and the way back to the form after signing in.
    final guest = signedOut();
    await t.runAsync(guest.restore); // the session check is over: this is a guest
    final (os2, _) = states(Scripted());
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(os2, guest, '/help')); await t.pumpAndSettle();
    expect(find.text('Sign in to contact support'), findsOneWidget); expect(id('help-contact-support'), findsNothing); expect(id('help-my-cases'), findsNothing);
    await tapKey(t, 'help-sign-in');
    expect(find.text('login screen'), findsOneWidget); expect(guest.returnTo, '/support/new');
  });

  testWidgets('My cases — number, subject, what it is about, status and the unread mark; Open / Closed and further pages are asked of the backend', (t) async {
    tall(t);
    final s = Scripted()..unread = 1..pageSize = 2
      ..lists['open'] = [row(number, status: 'WAITING_FOR_YOU', unread: true), row('CS-BBBB2222', subject: '<b>Charged twice</b>', category: 'PAYMENT'), row('CS-CCCC3333', subject: 'Third one', category: 'BRAND_NEW_CODE', status: 'OPEN')]
      ..lists['closed'] = [row('CS-DDDD4444', subject: 'Old question', category: 'OTHER', status: 'CLOSED')];
    final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support')); await t.pumpAndSettle();
    expect(s.listQueries, [('open', 1)]);
    expect(id('support-case-row-$number'), findsOneWidget); expect(id('support-case-row-CS-BBBB2222'), findsOneWidget); expect(id('support-case-row-CS-CCCC3333'), findsNothing, reason: 'the second page was not asked for yet');
    expect(find.text('$number · An order'), findsOneWidget); expect(find.text('CS-BBBB2222 · A payment'), findsOneWidget);
    expect(find.text('Waiting for your reply'), findsOneWidget); expect(find.text('In progress'), findsOneWidget);
    expect(find.text('<b>Charged twice</b>'), findsOneWidget, reason: 'a subject is text, never markup');
    expect(find.byKey(const Key('support-case-unread-$number')), findsOneWidget); expect(find.byKey(const Key('support-case-unread-CS-BBBB2222')), findsNothing);
    expect(find.text('1 with a new reply from support'), findsOneWidget);

    await tapKey(t, 'support-more');
    expect(s.listQueries.last, ('open', 2));
    expect(id('support-case-row-CS-CCCC3333'), findsOneWidget); expect(find.text('CS-CCCC3333 · Brand new code'), findsOneWidget, reason: 'a category the app has no label for is still shown');
    expect(find.byKey(const Key('support-more')), findsNothing);

    await tapKey(t, 'support-filter-closed');
    expect(s.listQueries.last, ('closed', 1));
    expect(id('support-case-row-CS-DDDD4444'), findsOneWidget); expect(id('support-case-row-$number'), findsNothing); expect(find.text('Closed'), findsNWidgets(2), reason: 'the filter and the status');

    s.lists['closed'] = [];
    await tapText(t, 'Open'); await tapKey(t, 'support-filter-closed');
    expect(find.text('No closed cases'), findsOneWidget);

    // A row opens its case; coming back reads the list again.
    await tapKey(t, 'support-filter-open');
    final before = s.listQueries.length;
    await tapKey(t, 'support-case-row-$number');
    expect(id('support-case'), findsOneWidget); expect(s.gets.last, number);
    router.pop(); await t.pumpAndSettle();
    expect(s.listQueries.length, before + 1);
  });

  testWidgets('My cases — no connection is said plainly with a retry; a list that was loaded stays on screen; without the backend support is "not connected"', (t) async {
    tall(t);
    final s = Scripted()..failList = offline;
    final (os, _) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/support')); await t.pumpAndSettle();
    expect(id('support-offline'), findsOneWidget); expect(find.textContaining('You are offline'), findsOneWidget);
    s.failList = null; s.lists['open'] = [row(number)];
    await tapKey(t, 'support-retry');
    expect(id('support-case-row-$number'), findsOneWidget); expect(id('support-offline'), findsNothing);
    // Reading again fails: the list stays, with a note that it may be out of date.
    s.failList = offline;
    await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
    expect(id('support-case-row-$number'), findsOneWidget); expect(find.textContaining('as it was last loaded'), findsOneWidget);
    s.failList = const SupportFailure('support_request_failed', code: 'server_error');
    await tapKey(t, 'support-filter-closed');
    expect(id('support-error'), findsOneWidget); expect(find.text('support_request_failed'), findsNothing);

    final (none, _) = states(null);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(none, auth, '/support')); await t.pumpAndSettle();
    expect(find.text('Support is not connected'), findsOneWidget); expect(id('support-new-case'), findsNothing);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(none, auth, '/support/new')); await t.pumpAndSettle();
    expect(find.text('Support is not connected'), findsOneWidget); expect(find.byKey(const Key('support-submit')), findsNothing);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(none, auth, '/support/cases/$number')); await t.pumpAndSettle();
    expect(find.text('Support is not connected'), findsOneWidget);
  });

  testWidgets('New case from an order — the order is linked read-only and sent as its number; a lost connection keeps the form and the SAME Idempotency-Key; changed text is another request', (t) async {
    tall(t);
    final s = Scripted(); final (os, store) = states(s); final o = orderFromApi(api.orderDto(), 'cust-rahul'); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/order/${o.orderNumber}')); await t.pumpAndSettle();
    expect(id('order-get-help'), findsOneWidget);
    await tapKey(t, 'order-get-help');
    expect(id('support-new'), findsOneWidget);
    expect(find.descendant(of: id('support-linked'), matching: find.text('Order ${o.orderNumber}')), findsOneWidget);
    expect(find.descendant(of: id('support-linked'), matching: find.byType(TextField)), findsNothing, reason: 'the linked order cannot be edited');
    expect(find.text('An order'), findsOneWidget, reason: 'the category the entry point asked for is chosen');
    expect(id('support-subcategory'), findsOneWidget);

    // Nothing is sent while the form is incomplete.
    await tapKey(t, 'support-submit');
    expect(find.text('Add a short subject.'), findsOneWidget); expect(find.text('Describe what happened.'), findsOneWidget); expect(s.openAttempts, isEmpty);

    await type(t, 'support-subject', 'Still waiting'); await type(t, 'support-message', 'Ordered at 9, nothing yet.');
    await tapText(t, 'Not listed / not sure'); await t.tap(find.text('My order is late').last); await t.pumpAndSettle();

    // No connection: the form says "not sent", keeps every word, and navigates nowhere.
    s.failOpen = offline;
    await tapKey(t, 'support-submit');
    expect(id('support-form-error'), findsOneWidget); expect(find.textContaining('Your request was not sent'), findsOneWidget);
    expect(find.text('Still waiting'), findsOneWidget); expect(find.text('Ordered at 9, nothing yet.'), findsOneWidget); expect(id('support-new'), findsOneWidget);
    await tapKey(t, 'support-submit');
    expect(s.openAttempts.length, 2);
    final key = s.openAttempts[0].$2;
    expect(s.openAttempts[1].$2, key, reason: 'a retry of the same request travels under the same key: the backend opens one case');
    expect(RegExp(r'^[A-Za-z0-9._:-]{16,120}$').hasMatch(key), isTrue);

    // The customer changes what they wrote: that is a different request.
    await type(t, 'support-message', 'Ordered at 9, nothing yet. Please call the restaurant.');
    await tapKey(t, 'support-submit');
    final key2 = s.openAttempts[2].$2;
    expect(key2 == key, isFalse);
    expect(await store.read(SupportDrafts.newCaseKey('cust-rahul', 'new.order.${o.orderNumber}')), isNotNull, reason: 'what was written is still kept');

    // The backend refuses a field: its own sentence, under the form; still nothing lost.
    s.failOpen = const SupportFailure('The subject is too long.', code: 'validation_failed', presentable: true, fields: {'subject': ['The subject is too long.']});
    await tapKey(t, 'support-submit');
    expect(find.text('The subject is too long.'), findsNWidgets(2), reason: 'under the field and in the form error'); expect(find.text('Still waiting'), findsOneWidget);

    // Connected again: one more attempt with the key of THIS content, then the case.
    s.failOpen = null;
    await tapKey(t, 'support-submit');
    expect(s.openAttempts.length, 5); expect(s.openAttempts.last.$2, key2);
    final sent = s.openAttempts.last.$1;
    expect([sent.category, sent.subcategory, sent.subject, sent.message, sent.order, sent.refund, sent.payment], ['ORDER', 'ORDER_DELAYED', 'Still waiting', 'Ordered at 9, nothing yet. Please call the restaurant.', o.orderNumber, null, null]);
    expect(id('support-case'), findsOneWidget); expect(id('support-new'), findsNothing, reason: 'the form was replaced by the case');
    expect(find.descendant(of: id('support-case-number'), matching: find.text('CS-NEWC2345')), findsOneWidget);
    expect(find.text('Order ${o.orderNumber}'), findsOneWidget); expect(find.text('Accepted'), findsOneWidget); expect(find.text('₹500.00'), findsOneWidget);
    expect(o.orderNumber, api.number);
    expect(await store.read(SupportDrafts.newCaseKey('cust-rahul', 'new.order.${o.orderNumber}')), isNull, reason: 'the draft goes only once the backend accepted it');
  });

  testWidgets('Order tracking — with the backend "Get help with this order" opens a case linked to the order; without it the screen is what it was', (t) async {
    tall(t, 5200);
    final auth = await signedIn(t);
    Widget tracking(OrderState os) {
      final st = MemoryKeyValueStore(); final orders = OneOrder(); final mock = MockOrderRepository(st, latency: Duration.zero);
      final tr = TrackingState(orders: orders, verifications: MockPickupVerificationRepository(mock), tracking: MockOrderTrackingService(orders, st, interval: Duration.zero), connectivity: MockConnectivityService(), store: st);
      router = GoRouter(initialLocation: '/order-tracking/${api.number}', routes: [
        GoRoute(path: '/order-tracking/:number', builder: (_, p) => OrderTrackingScreen(number: p.pathParameters['number']!)),
        GoRoute(path: '/support/new', builder: (_, s) => SupportNewCaseScreen(category: s.uri.queryParameters['category'], order: s.uri.queryParameters['order'])),
        GoRoute(path: '/help', builder: (_, _) => const Scaffold(body: Text('help screen'))),
      ]);
      return MultiProvider(providers: [
        ChangeNotifierProvider.value(value: auth), ChangeNotifierProvider.value(value: os), ChangeNotifierProvider.value(value: tr),
        ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
      ], child: MaterialApp.router(routerConfig: router));
    }
    final (none, _) = states(null);
    await t.pumpWidget(tracking(none)); await t.pumpAndSettle();
    expect(find.text('Help & support'), findsOneWidget); expect(id('order-get-help'), findsNothing); expect(find.text('Get help with this order'), findsNothing);

    final s = Scripted(); final (os, _) = states(s);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(tracking(os)); await t.pumpAndSettle();
    expect(id('order-get-help'), findsOneWidget); expect(find.text('Help & support'), findsOneWidget, reason: 'the Help screen stays reachable');
    await tapKey(t, 'order-get-help');
    expect(find.descendant(of: id('support-linked'), matching: find.text('Order ${api.number}')), findsOneWidget); expect(find.text('An order'), findsOneWidget);
  });

  testWidgets('New case from a refund — the refund and its order are sent as references, category REFUND; without the backend the refund card offers nothing', (t) async {
    tall(t, 2400);
    final order = orderFromApi(rejected('PROCESSING', refundBlock('PROCESSING', [refundItem('PROCESSING')], pending: 42700), lines: [refundLine('REFUND_REQUIRED', 'REQUIRED'), refundLine('REFUND_PROCESSING', 'PROCESSING')]), 'cust-rahul');
    final card = Scaffold(body: SingleChildScrollView(child: RefundCard(order: order)));
    final auth = await signedIn(t);
    final (none, _) = states(null);
    await t.pumpWidget(app(none, auth, '/', home: card)); await t.pumpAndSettle();
    expect(find.text('Refund'), findsOneWidget); expect(id('refund-get-help'), findsNothing); expect(find.text('Need help with this refund?'), findsNothing);

    final s = Scripted(); final (os, _) = states(s);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(os, auth, '/', home: card)); await t.pumpAndSettle();
    expect(id('refund-get-help'), findsOneWidget);
    await tapText(t, 'Need help with this refund?');
    final reference = order.refunds!.items.single.reference;
    expect(find.descendant(of: id('support-linked'), matching: find.text('Refund $reference')), findsOneWidget);
    expect(find.descendant(of: id('support-linked'), matching: find.text('Order ${order.orderNumber}')), findsOneWidget);
    expect(find.text('A refund'), findsOneWidget);
    await type(t, 'support-subject', 'Refund not here'); await type(t, 'support-message', 'It says processing for days.');
    await tapKey(t, 'support-submit');
    final sent = s.openAttempts.single.$1;
    expect([sent.category, sent.refund, sent.order, sent.payment], ['REFUND', reference, order.orderNumber, null]);
    expect(id('support-case'), findsOneWidget); expect(find.text('Refund $reference'), findsOneWidget); expect(find.text('Processing'), findsOneWidget);
  });

  testWidgets('A reference in the route that is not one of ours never reaches the form or the backend', (t) async {
    tall(t);
    final s = Scripted(); final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support/new?category=NOPE&order=../../admin&refund=https://evil.example&payment=PAY-zzz')); await t.pumpAndSettle();
    expect(id('support-linked'), findsNothing); expect(find.text('Choose a topic'), findsOneWidget);
    await tapKey(t, 'support-submit');
    expect(find.text('Choose what your request is about.'), findsOneWidget); expect(s.openAttempts, isEmpty);
    await tapText(t, 'Choose a topic'); await t.tap(find.text('Something else').last); await t.pumpAndSettle();
    expect(id('support-subcategory'), findsNothing, reason: 'a category without subcategories offers none');
    await type(t, 'support-subject', 'Hello'); await type(t, 'support-message', 'A question');
    await tapKey(t, 'support-submit');
    final sent = s.openAttempts.single.$1;
    expect([sent.category, sent.subcategory, sent.order, sent.refund, sent.payment], ['OTHER', null, null, null, null]);

    // A case reference that is not a case number / id asks the backend nothing.
    final gets = s.gets.length;
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(os, await signedIn(t), '/support/cases/not-a-case')); await t.pumpAndSettle();
    expect(find.text('Case not found'), findsOneWidget); expect(s.gets.length, gets);
  });

  testWidgets('Case — the actions are exactly what the backend allows: reply and withdraw while open; confirm and reopen when resolved; nothing when it is over', (t) async {
    tall(t);
    final s = Scripted()..links = [const SupportLink(id: 'l-3', type: 'PAYMENT', reference: 'PAY-1A00000000', status: 'REVIEW_REQUIRED', amountMinor: 42700, currency: 'INR')]
      ..say(SupportAuthor.you, 'Where is my order?')..say(SupportAuthor.support, 'We are checking with the restaurant.');
    final (os, _) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/support/cases/$number')); await t.pumpAndSettle();
    expect(find.descendant(of: id('support-case-number'), matching: find.text(number)), findsOneWidget);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('In progress')), findsOneWidget);
    expect(find.text('My order is late'), findsOneWidget);
    expect(find.descendant(of: id('support-message-item-0'), matching: find.text('Where is my order?')), findsOneWidget, reason: 'oldest first');
    expect(find.descendant(of: id('support-message-item-1'), matching: find.text('We are checking with the restaurant.')), findsOneWidget);
    expect(find.textContaining('FoodOnTheGo Support'), findsOneWidget);
    expect(find.text('Payment being checked'), findsOneWidget); expect(find.textContaining('REVIEW_REQUIRED'), findsNothing); expect(find.textContaining('Review required'), findsNothing, reason: 'an internal word of the payment domain is not the customer\'s');
    expect(id('support-reply-input'), findsOneWidget); expect(id('support-withdraw'), findsOneWidget);
    expect(id('support-confirm-resolved'), findsNothing); expect(id('support-reopen'), findsNothing);
    for (final never in ['Priority', 'Assigned', 'SLA', 'Internal note']) { expect(find.textContaining(never), findsNothing, reason: never); }

    // Withdraw asks first; "Keep it open" changes nothing.
    await tapKey(t, 'support-withdraw');
    expect(find.text('Withdraw this request?'), findsOneWidget); expect(s.closes, 0);
    await tapKey(t, 'support-withdraw-keep');
    expect(s.closes, 0); expect(find.text('Withdraw this request?'), findsNothing);

    // Support resolved it: confirm or reopen, no reply box, and the date until which it can be reopened.
    s..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true)..reopenUntil = DateTime(2026, 10, 26, 12);
    await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
    expect(find.descendant(of: id('support-case-status'), matching: find.text('Resolved')), findsOneWidget);
    expect(id('support-confirm-resolved'), findsOneWidget); expect(id('support-reopen'), findsOneWidget);
    expect(id('support-reply-input'), findsNothing); expect(id('support-withdraw'), findsNothing);
    expect(find.text('You can reopen this case until Oct 26, 2026.'), findsOneWidget);

    // Reopen needs a message.
    await tapKey(t, 'support-reopen');
    expect(id('support-reopen-message'), findsOneWidget);
    await tapKey(t, 'support-reopen-submit');
    expect(find.text('Tell us what is still not settled.'), findsOneWidget); expect(s.reopens, isEmpty);
    await type(t, 'support-reopen-message', '  It happened again.  ');
    s.failReopen = offline;
    await tapKey(t, 'support-reopen-submit');
    expect(find.textContaining('nothing was changed'), findsOneWidget); expect(find.text('  It happened again.  '), findsOneWidget, reason: 'what was written is kept');
    s.failReopen = null;
    await tapKey(t, 'support-reopen-submit');
    expect(s.reopens, ['It happened again.']);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('Open')), findsOneWidget);
    expect(find.descendant(of: id('support-message-item-2'), matching: find.text('It happened again.')), findsOneWidget);
    expect(id('support-reply-input'), findsOneWidget); expect(id('support-reopen-message'), findsNothing); expect(id('support-confirm-resolved'), findsNothing);

    // Resolved again; the customer confirms: closed.
    s..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true)..reopenUntil = DateTime(2026, 10, 26, 12);
    await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
    await tapKey(t, 'support-confirm-resolved');
    expect(s.closes, 1);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('Closed')), findsOneWidget);
    expect(id('support-confirm-resolved'), findsNothing); expect(id('support-reply-input'), findsNothing); expect(id('support-reopen'), findsOneWidget, reason: 'the backend still allows a reopen');

    // Over for good: nothing is offered.
    s..can = const SupportCan()..reopenUntil = null;
    await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
    for (final none in ['support-reply-input', 'support-reply-send', 'support-confirm-resolved', 'support-withdraw', 'support-reopen']) { expect(id(none), findsNothing, reason: none); }
    expect(find.text('This case is closed.'), findsOneWidget);
  });

  testWidgets('Case — "Confirm resolved" never withdraws a case that support reopened in the meantime; a confirmed withdrawal cancels it', (t) async {
    tall(t);
    final s = Scripted()..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true)..say(SupportAuthor.you, 'Hello');
    final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support/cases/$number')); await t.pumpAndSettle();
    expect(id('support-confirm-resolved'), findsOneWidget);
    // Behind the customer's back the case is being worked on again: the one "close" endpoint would now WITHDRAW it.
    s..status = 'IN_PROGRESS'..can = const SupportCan(reply: true, withdraw: true);
    await tapKey(t, 'support-confirm-resolved');
    expect(s.closes, 0, reason: 'nothing was sent');
    expect(find.text('This case has changed in the meantime. Please check it again.'), findsOneWidget);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('In progress')), findsOneWidget); expect(id('support-withdraw'), findsOneWidget);

    await tapKey(t, 'support-withdraw'); await tapKey(t, 'support-withdraw-confirm');
    expect(s.closes, 1);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('Cancelled')), findsOneWidget);
    expect(id('support-reply-input'), findsNothing); expect(id('support-withdraw'), findsNothing);
  });

  testWidgets('Case — a reopening whose answer was lost is not reported as a failure when the retry finds the case reopened with that message', (t) async {
    tall(t);
    final s = Scripted()..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true)..reopenUntil = DateTime(2026, 10, 26, 12)..say(SupportAuthor.you, 'Hello');
    final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support/cases/$number')); await t.pumpAndSettle();
    await tapKey(t, 'support-reopen'); await type(t, 'support-reopen-message', 'It happened again.');
    // The first attempt reached the backend (the case is open again, the message is in it) but its answer never arrived;
    // the retry is refused because the case is no longer resolved.
    s..status = 'IN_PROGRESS'..can = const SupportCan(reply: true, withdraw: true)..reopenUntil = null..say(SupportAuthor.you, 'It happened again.')
      ..failReopen = const SupportFailure('This case can no longer be reopened. Please open a new case and mention CS-ABCD2345.', code: 'case_reopen_window_over', presentable: true);
    await tapKey(t, 'support-reopen-submit');
    expect(find.textContaining('can no longer be reopened'), findsNothing); expect(id('support-action-error'), findsNothing);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('In progress')), findsOneWidget);
    expect(find.descendant(of: id('support-message-item-1'), matching: find.text('It happened again.')), findsOneWidget); expect(find.text('It happened again.'), findsOneWidget, reason: 'written once');
    expect(id('support-reply-input'), findsOneWidget); expect(id('support-reopen-message'), findsNothing);

    // A refusal that is true (the window is over, nothing was reopened) is told as it is.
    final s2 = Scripted()..status = 'CLOSED'..can = const SupportCan(reopen: true)..reopenUntil = DateTime(2026, 10, 13, 12)..say(SupportAuthor.you, 'Hello')
      ..failReopen = const SupportFailure('This case can no longer be reopened. Please open a new case and mention CS-ABCD2345.', code: 'case_reopen_window_over', presentable: true);
    final (os2, _) = states(s2);
    await t.pumpWidget(const SizedBox()); await t.pumpWidget(app(os2, await signedIn(t), '/support/cases/$number')); await t.pumpAndSettle();
    await tapKey(t, 'support-reopen'); await type(t, 'support-reopen-message', 'Please look again.');
    s2.can = const SupportCan();
    await tapKey(t, 'support-reopen-submit');
    expect(find.text('This case can no longer be reopened. Please open a new case and mention CS-ABCD2345.'), findsOneWidget);
    expect(id('support-reopen'), findsNothing, reason: 'the case is shown as the backend has it now'); expect(s2.reopens, isEmpty);
  });

  testWidgets('Reply — in flight it is "Sending…", never part of the conversation; a failed one stays "Not sent" and Retry sends the SAME client_message_id exactly once more', (t) async {
    tall(t);
    final s = Scripted()..say(SupportAuthor.you, 'Where is my order?');
    final (os, store) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/support/cases/$number')); await t.pumpAndSettle();

    // Nothing to send: the button does nothing.
    await tapKey(t, 'support-reply-send');
    expect(s.replyAttempts, isEmpty);

    // In flight.
    s.gate = Completer<void>();
    await type(t, 'support-reply-input', 'Any news?');
    await t.tap(find.byKey(const Key('support-reply-send'))); await t.pump();
    expect(find.text('Sending…'), findsOneWidget); expect(id('support-sending'), findsOneWidget);
    expect(id('support-message-item-1'), findsNothing, reason: 'not part of the conversation before the backend has it');
    expect(t.widget<TextField>(find.byKey(const Key('support-reply-input'))).controller!.text, '', reason: 'the box is free for the next message');

    // The connection drops: it was not sent, and the screen says so.
    s.failReply = offline; s.gate!.complete(); s.gate = null;
    await t.pumpAndSettle();
    expect(id('support-not-sent'), findsOneWidget); expect(find.text('Not sent'), findsOneWidget); expect(find.textContaining('has not reached support'), findsOneWidget);
    expect(find.text('Sending…'), findsNothing); expect(id('support-message-item-1'), findsNothing); expect(find.text('Any news?'), findsOneWidget, reason: 'the words are kept');
    expect(s.thread.length, 1); expect(s.replyAttempts.length, 1);
    final first = s.replyAttempts.single.$2;
    expect(RegExp(r'^[A-Za-z0-9_-]{8,64}$').hasMatch(first), isTrue);

    // Retry while still offline: the same id again, still not sent.
    await tapKey(t, 'support-reply-retry');
    expect(s.replyAttempts.length, 2); expect(s.replyAttempts[1], ('Any news?', first)); expect(id('support-not-sent'), findsOneWidget);

    // Leaving the screen and coming back (even a new app session): it is still there, still not sent, still the same id.
    await t.pumpWidget(const SizedBox());
    final st2 = MemoryKeyValueStore(); final again = OrderState(orders: OneOrder(), store: st2, support: s, supportDrafts: SupportDrafts(store));
    await t.pumpWidget(app(again, auth, '/support/cases/$number')); await t.pumpAndSettle();
    expect(id('support-not-sent'), findsOneWidget); expect(find.text('Any news?'), findsOneWidget); expect(s.replyAttempts.length, 2, reason: 'nothing is sent by itself');

    // Connected again: Retry delivers it — once, under the id it was written with.
    s.failReply = null;
    await tapKey(t, 'support-reply-retry');
    expect(s.replyAttempts.length, 3); expect(s.replyAttempts[2], ('Any news?', first));
    expect(id('support-not-sent'), findsNothing); expect(id('support-reply-retry'), findsNothing);
    expect(find.descendant(of: id('support-message-item-1'), matching: find.text('Any news?')), findsOneWidget);
    expect(s.thread.where((m) => m.body == 'Any news?').length, 1, reason: 'the backend wrote it once');
    expect(find.text('Any news?'), findsOneWidget);
    expect(await store.read(SupportDrafts.replyKey('cust-rahul', caseId)), isNull, reason: 'the backend has it: nothing is kept');

    // A second message gets an id of its own.
    await type(t, 'support-reply-input', 'Thank you'); await tapKey(t, 'support-reply-send');
    expect(s.replyAttempts.length, 4); expect(s.replyAttempts[3].$1, 'Thank you'); expect(s.replyAttempts[3].$2 == first, isFalse);
    expect(find.descendant(of: id('support-message-item-2'), matching: find.text('Thank you')), findsOneWidget);
  });

  testWidgets('Reply — a refusal is told in the backend\'s words: a case that closed meanwhile keeps the message unsent and shows the case as it is; a refused text goes back into the box; a discarded one is gone', (t) async {
    tall(t);
    final s = Scripted()..say(SupportAuthor.you, 'Where is my order?');
    final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support/cases/$number')); await t.pumpAndSettle();

    s.failReply = const SupportFailure('The message may not contain HTML or scripts.', code: 'validation_failed', presentable: true, fields: {'message': ['The message may not contain HTML or scripts.']});
    await type(t, 'support-reply-input', '<b>hi</b>'); await tapKey(t, 'support-reply-send');
    expect(find.text('Your reply was not sent: The message may not contain HTML or scripts.'), findsOneWidget);
    expect(t.widget<TextField>(find.byKey(const Key('support-reply-input'))).controller!.text, '<b>hi</b>', reason: 'back in the box to be changed');
    expect(id('support-not-sent'), findsNothing); expect(s.thread.length, 1);

    // Support resolved the case while the customer was typing.
    s..failReply = const SupportFailure('This case is resolved. Reopen it to write again, or open a new case.', code: 'case_not_open', presentable: true)..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true);
    await type(t, 'support-reply-input', 'One more thing'); await tapKey(t, 'support-reply-send');
    expect(id('support-not-sent'), findsOneWidget); expect(find.text('This case is resolved. Reopen it to write again, or open a new case.'), findsOneWidget);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('Resolved')), findsOneWidget, reason: 'the case is read again');
    expect(id('support-reply-input'), findsNothing); expect(id('support-reopen'), findsOneWidget);
    final attempts = s.replyAttempts.length;
    await tapKey(t, 'support-reply-discard');
    expect(id('support-not-sent'), findsNothing); expect(find.text('One more thing'), findsNothing); expect(s.replyAttempts.length, attempts);
  });

  testWidgets('Drafts — the unsent text of a reply box and of the new-case form survive leaving the screen and are restored when it is opened again', (t) async {
    tall(t);
    final s = Scripted()..say(SupportAuthor.you, 'Where is my order?')..lists['open'] = [row(number)];
    final (os, _) = states(s); final auth = await signedIn(t);
    await t.pumpWidget(app(os, auth, '/support')); await t.pumpAndSettle();

    await tapKey(t, 'support-case-row-$number');
    await type(t, 'support-reply-input', 'I will be there at');
    router.pop(); await t.pumpAndSettle();
    expect(id('support-case'), findsNothing);
    await tapKey(t, 'support-case-row-$number');
    expect(t.widget<TextField>(find.byKey(const Key('support-reply-input'))).controller!.text, 'I will be there at');
    expect(s.replyAttempts, isEmpty, reason: 'a draft is never sent by itself');
    router.pop(); await t.pumpAndSettle();

    await tapKey(t, 'support-new-case');
    await tapText(t, 'Choose a topic'); await t.tap(find.text('A refund').last); await t.pumpAndSettle();
    await type(t, 'support-subject', 'Refund question'); await type(t, 'support-message', 'How long does');
    router.pop(); await t.pumpAndSettle();
    expect(id('support-new'), findsNothing);
    await tapKey(t, 'support-new-case');
    expect(find.text('We kept what you had written earlier.'), findsOneWidget);
    expect(t.widget<TextField>(find.byKey(const Key('support-subject'))).controller!.text, 'Refund question');
    expect(t.widget<TextField>(find.byKey(const Key('support-message'))).controller!.text, 'How long does');
    expect(find.text('A refund'), findsOneWidget, reason: 'the chosen category is kept too');
    expect(s.openAttempts, isEmpty);
  });

  testWidgets('Case — what people wrote is shown as text: markup in a message or a subject is never interpreted', (t) async {
    tall(t);
    const body = '<script>alert(document.cookie)</script><img src=x onerror=alert(1)> I <3 the fries & more';
    final s = Scripted()..subject = '<h1>Late</h1>'..say(SupportAuthor.you, body)..say(SupportAuthor.support, '<a href="https://evil.example">click</a>')..say(SupportAuthor.system, 'The case was reopened.');
    final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support/cases/$number')); await t.pumpAndSettle();
    expect(find.text(body), findsOneWidget); expect(find.text('<a href="https://evil.example">click</a>'), findsOneWidget); expect(find.text('<h1>Late</h1>'), findsOneWidget);
    expect(find.descendant(of: id('support-message-item-2'), matching: find.textContaining('Update')), findsOneWidget, reason: 'a system line is marked as one');
    // Every piece of the conversation is a plain Text widget (no rich / HTML rendering anywhere on the screen).
    for (final x in [body, '<a href="https://evil.example">click</a>']) { expect(t.widget(find.text(x)), isA<Text>()); }
    expect(find.text('click'), findsNothing); expect(find.text('Late'), findsNothing);
  });

  testWidgets('Case — it is read again by itself while the screen is shown and when the app comes back; a lost connection keeps the conversation and says so; leaving stops the reading', (t) async {
    tall(t);
    supportPollEvery = const Duration(milliseconds: 200);
    final s = Scripted()..say(SupportAuthor.you, 'Where is my order?');
    final (os, _) = states(s);
    await t.pumpWidget(app(os, await signedIn(t), '/support/cases/$number')); await t.pumpAndSettle();
    expect(id('support-message-item-1'), findsNothing);

    s..say(SupportAuthor.support, 'It is on its way.')..status = 'WAITING_FOR_YOU';
    await t.pump(const Duration(milliseconds: 250)); await t.pump();
    expect(find.descendant(of: id('support-message-item-1'), matching: find.text('It is on its way.')), findsOneWidget);
    expect(find.descendant(of: id('support-case-status'), matching: find.text('Waiting for your reply')), findsOneWidget); expect(find.text('Support is waiting for your reply.'), findsOneWidget);

    // No connection: what was loaded stays, with a note; nothing is invented.
    s.failGet = offline;
    await t.pump(const Duration(milliseconds: 250)); await t.pump();
    expect(id('support-offline'), findsOneWidget); expect(find.text('It is on its way.'), findsOneWidget);
    s.failGet = null;
    await t.pump(const Duration(milliseconds: 250)); await t.pump();
    expect(id('support-offline'), findsNothing);

    // Coming back to the app reads it at once.
    supportPollEvery = const Duration(seconds: 20);
    final gets = s.gets.length;
    for (final state in [AppLifecycleState.inactive, AppLifecycleState.hidden, AppLifecycleState.paused]) { t.binding.handleAppLifecycleStateChanged(state); }
    s.say(SupportAuthor.support, 'Delivered to the counter.');
    for (final state in [AppLifecycleState.hidden, AppLifecycleState.inactive, AppLifecycleState.resumed]) { t.binding.handleAppLifecycleStateChanged(state); }
    await t.pumpAndSettle();
    expect(s.gets.length, greaterThan(gets)); expect(find.text('Delivered to the counter.'), findsOneWidget);

    await t.pumpWidget(const SizedBox());
    final left = s.gets.length;
    await t.pump(const Duration(seconds: 2));
    expect(s.gets.length, left, reason: 'nothing is read for a screen that is gone');
  });

  // Small phone, large system text, and a tablet: nothing overflows on any support screen, in any of its states.
  for (final (name, size, scale) in [('a compact phone with 1.3× text', const Size(360, 640), 1.3), ('a tablet', const Size(1280, 800), 1.0)]) {
    testWidgets('Layout — the support screens lay out on $name', (t) async {
      final loader = FontLoader('Outfit');
      for (final w in [400, 500, 600, 700, 800]) { loader.addFont(rootBundle.load('assets/fonts/Outfit-$w.ttf')); }
      await t.runAsync(loader.load);
      t.view.physicalSize = size; t.view.devicePixelRatio = 1; t.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
      final s = Scripted()..unread = 12
        ..lists['open'] = [row(number, subject: 'A very long subject that a customer wrote without thinking about how wide a phone is', status: 'WAITING_FOR_YOU', unread: true), row('CS-BBBB2222', category: 'PAYMENT')]
        ..links = [const SupportLink(id: 'l-1', type: 'ORDER', reference: api.number, status: 'WAITING_FOR_RESTAURANT', amountMinor: 123456789, currency: 'INR', restaurant: 'Burger Hub — Murthal Highway Food Court, Unit 12'), const SupportLink(id: 'l-2', type: 'REFUND', reference: 'RFD-5D00000000', status: 'UNDER_REVIEW', amountMinor: 42700, currency: 'INR', orderNumber: api.number)]
        ..say(SupportAuthor.you, 'Where is my order? ' * 12)..say(SupportAuthor.support, 'Averyveryverylongwordwithoutanyspacesatallthatmustwrapsomewhere_${'x' * 60}')..say(SupportAuthor.system, 'The case was reopened.');
      final (os, _) = states(s); final auth = await signedIn(t);
      Future<void> at(String location) async { router.go(location); await t.pumpAndSettle(); expect(t.takeException(), isNull, reason: location); }
      await t.pumpWidget(app(os, auth, '/help', theme: Brand.theme())); await t.pumpAndSettle();
      expect(t.takeException(), isNull, reason: '/help');
      await at('/support');
      await at('/support/new?category=REFUND&refund=RFD-5D00000000&order=${api.number}');
      s.failOpen = const SupportFailure('You already have 20 cases that are being worked on. Please add to one of them instead of opening another.', code: 'too_many_open_cases', presentable: true);
      await type(t, 'support-subject', 'Late'); await type(t, 'support-message', 'Still waiting'); await tapKey(t, 'support-submit');
      expect(t.takeException(), isNull, reason: 'the form with an error'); expect(find.textContaining('You already have 20 cases'), findsOneWidget);
      await at('/support/cases/$number');
      s.failReply = offline;
      await type(t, 'support-reply-input', 'Any news? ' * 20); await tapKey(t, 'support-reply-send');
      expect(t.takeException(), isNull, reason: 'the case with an unsent reply'); expect(find.text('Not sent'), findsOneWidget);
      s..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true)..reopenUntil = DateTime(2026, 10, 26, 12);
      await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
      await tapKey(t, 'support-reopen');
      expect(t.takeException(), isNull, reason: 'the resolved case with the reopen panel');
      s.failGet = offline;
      await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
      expect(t.takeException(), isNull, reason: 'the case while offline'); expect(find.textContaining('as it was last loaded'), findsOneWidget);
    });
  }

  // What the Android preview suite reads: every identifier is a node of the SEMANTICS tree (not only a widget), the
  // fields are text fields and the actions can be tapped there.
  testWidgets('Semantics — the identifiers of the support screens are real semantics nodes: roots, rows, fields, actions, messages, the unsent reply', (t) async {
    tall(t);
    final handle = t.ensureSemantics();
    final s = Scripted()..unread = 1..lists['open'] = [row(number, unread: true)]..say(SupportAuthor.you, 'Where is my order?')..say(SupportAuthor.support, 'We are checking.');
    final (os, _) = states(s); final auth = await signedIn(t);
    void has(List<String> ids) { for (final x in ids) { expect(find.bySemanticsIdentifier(x), findsOneWidget, reason: x); } }
    SemanticsNode node(String x) => t.getSemantics(find.bySemanticsIdentifier(x));
    // An identifier names a node; what it says and does may sit on that node or on the nodes inside it.
    List<SemanticsNode> within(SemanticsNode n) { final out = [n]; n.visitChildren((c) { out.addAll(within(c)); return true; }); return out; }
    String text(String x) => within(node(x)).map((n) => [n.label, n.value].where((v) => v.isNotEmpty).join(' ')).where((v) => v.isNotEmpty).join(' | ');
    bool flag(String x, bool Function(SemanticsFlags) f) => within(node(x)).any((n) => f(n.flagsCollection));
    bool action(String x, SemanticsAction a) => within(node(x)).any((n) => n.getSemanticsData().hasAction(a));

    await t.pumpWidget(app(os, auth, '/help')); await t.pumpAndSettle();
    has(['help-contact-support', 'help-my-cases']);
    expect(text('help-my-cases'), allOf(contains('My support cases'), contains('1 with a new reply')), reason: 'the unread count is part of what is read out');

    router.go('/support'); await t.pumpAndSettle();
    has(['support-cases', 'support-case-row-$number', 'support-filter-open', 'support-filter-closed', 'support-new-case']);
    expect(action('support-case-row-$number', SemanticsAction.tap), isTrue);
    expect(text('support-case-row-$number'), allOf(contains('My order is late'), contains(number), contains('An order'), contains('In progress'), contains('New reply')));

    router.go('/support/new?category=ORDER&order=${api.number}'); await t.pumpAndSettle();
    has(['support-new', 'support-category', 'support-subcategory', 'support-subject', 'support-message', 'support-linked', 'support-submit']);
    expect([flag('support-subject', (f) => f.isTextField), action('support-subject', SemanticsAction.tap)], [true, true]);
    expect([flag('support-message', (f) => f.isTextField), flag('support-message', (f) => f.isMultiline)], [true, true]);
    expect(text('support-linked'), contains('Order ${api.number}'));
    s.failOpen = offline;
    await type(t, 'support-subject', 'Late'); await type(t, 'support-message', 'Still waiting'); await tapKey(t, 'support-submit');
    has(['support-form-error']);
    expect(flag('support-form-error', (f) => f.isLiveRegion), isTrue); expect(text('support-form-error'), contains('was not sent'));

    router.go('/support/cases/$number'); await t.pumpAndSettle();
    has(['support-case', 'support-case-number', 'support-case-status', 'support-message-item-0', 'support-message-item-1', 'support-reply-input', 'support-reply-send', 'support-withdraw']);
    expect(text('support-case-number'), number);
    expect(text('support-case-status'), 'In progress');
    expect(text('support-message-item-1'), allOf(contains('FoodOnTheGo Support'), contains('We are checking.')));
    expect(flag('support-reply-input', (f) => f.isTextField), isTrue); expect(action('support-reply-send', SemanticsAction.tap), isFalse, reason: 'nothing to send yet');
    s.failReply = offline;
    await type(t, 'support-reply-input', 'Any news?'); await tapKey(t, 'support-reply-send');
    has(['support-not-sent', 'support-reply-retry']);
    expect(flag('support-not-sent', (f) => f.isLiveRegion), isTrue); expect(text('support-not-sent'), allOf(contains('Any news?'), contains('Not sent'))); expect(action('support-reply-retry', SemanticsAction.tap), isTrue);

    s..status = 'RESOLVED'..can = const SupportCan(close: true, reopen: true)..reopenUntil = DateTime(2026, 10, 26, 12);
    await t.tap(find.byTooltip('Refresh')); await t.pumpAndSettle();
    has(['support-confirm-resolved', 'support-reopen']);
    await tapKey(t, 'support-reopen');
    has(['support-reopen-message', 'support-reopen-submit']);
    expect(flag('support-reopen-message', (f) => f.isTextField), isTrue); expect(action('support-reopen-submit', SemanticsAction.tap), isTrue);
    handle.dispose();
  });

  testWidgets('Notifications — an inbox item and a push with the target SUPPORT_CASE open that case; the route is behind sign-in', (t) async {
    tall(t);
    final s = Scripted()..say(SupportAuthor.you, 'Where is my order?')..say(SupportAuthor.support, 'It is on its way.');
    final (os, _) = states(s);
    final inbox = notificationFromApi({'id': 'n-9', 'category': 'ACCOUNT_SECURITY', 'type': 'support.reply', 'title': 'Support replied', 'body': 'There is a new reply in case $number.', 'action': {'target': 'SUPPORT_CASE', 'reference': number}, 'subject': {'type': 'SUPPORT_CASE', 'reference': number}, 'read': false, 'created_at': '2026-10-12T10:00:00Z'});
    final home = Builder(builder: (context) => Scaffold(body: TextButton(onPressed: () => context.push(inbox.route!), child: Text(inbox.title))));
    await t.pumpWidget(app(os, await signedIn(t), '/', home: home)); await t.pumpAndSettle();
    await tapText(t, 'Support replied');
    expect(id('support-case'), findsOneWidget); expect(s.gets.single, number);
    expect(find.descendant(of: id('support-case-number'), matching: find.text(number)), findsOneWidget); expect(find.text('It is on its way.'), findsOneWidget);

    // A push that was tapped: the app goes to the route of its target.
    router.go('/'); await t.pumpAndSettle();
    final push = PushNotice.fromData({'type': 'support.case_resolved', 'notification_id': 'n-10', 'target': 'SUPPORT_CASE', 'reference': number, 'category': 'ACCOUNT_SECURITY'}, title: 'Case resolved');
    router.go(push!.route!); await t.pumpAndSettle();
    expect(id('support-case'), findsOneWidget); expect(s.gets.last, number);

    // The guard of the real router: every support route needs a session; Help stays public.
    bool guarded(String path) => protectedPrefixes.any((p) => path == p || path.startsWith('$p/'));
    expect([guarded('/support'), guarded('/support/new'), guarded('/support/cases/$number'), guarded('/help')], [true, true, true, false]);
  });
}
