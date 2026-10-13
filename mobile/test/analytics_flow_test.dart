/// The funnel as the app walks it (Module 38) — Android: Journey → Restaurants on the route → Restaurant → Menu → Cart,
/// on the app's own states and screen with their fake backends, and the emitter switched on with a fake endpoint.
///
///  - each step is reported ONCE, as designed — although the list is searched again, the menu is filtered and the page
///    is rebuilt;
///  - a failing analytics endpoint (no connection, or an error answer) changes NOTHING in the flow: the same journey,
///    the same list, the same page, the same cart, the same requests to the real backends.
library;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/analytics/analytics.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/core/theme.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/api_journey.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/screens/restaurant_detail_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

import 'support/analytics_fakes.dart';
import 'support/journey_api_fixtures.dart';

const _lineId = '2c000000-0000-4000-8000-000000000001';

Map<String, dynamic> _cartDto(String restaurant, int quantity, int version) => {
      'id': '5f000000-0000-4000-8000-000000000001', 'version': version, 'status': 'ACTIVE', 'owner': 'guest', 'currency': 'INR', 'restaurant': {'id': restaurant, 'slug': 'burger-hub', 'name': 'Burger Hub', 'visible': true}, 'journey': null,
      'items': [
        {
          'id': _lineId, 'item': {'id': menuItemId, 'slug': 'classic-burger', 'name': 'Classic Burger', 'image': null, 'version': 3}, 'name': 'Classic Burger', 'quantity': quantity, 'min_quantity': 1, 'max_quantity': 20, 'special_instructions': null,
          'variants': [], 'modifiers': [], 'base_price_minor': 20000, 'unit_price_minor': 20000, 'line_subtotal_minor': 20000 * quantity, 'currency': 'INR',
          'snapshot': {'unit_price_minor': 20000, 'base_price_minor': 20000, 'item_version': 3, 'taken_at': '2026-10-13T09:15:00+00:00'}, 'price_changed': false, 'priced_from': 'menu', 'included_in_subtotal': true,
          'availability': {'orderable': true, 'reason': null}, 'issues': [],
        },
      ],
      'item_count': quantity, 'line_count': 1, 'subtotal_minor': 20000 * quantity,
      'validation': {'status': 'VALID', 'can_proceed': true, 'issues': [], 'menu_changed': false, 'catalog_version': 7, 'checked_at': '2026-10-13T09:15:00+00:00'},
      'limits': {'lines': 50, 'instructions_max_length': 200}, 'expires_at': '2026-10-20T09:15:00+00:00', 'created_at': '2026-10-13T09:15:00+00:00', 'updated_at': '2026-10-13T09:15:00+00:00',
    };

/// The cart backend of the flow: it accepts an add (and says what it was asked).
class _CartBackend extends CartBackend {
  _CartBackend() : super(store: MemoryKeyValueStore(), client: ApiClient(client: MockClient((_) async => json(200, {'cart': null})), baseUrl: 'http://api.test/api/v1'));
  Map<String, dynamic>? cart;
  final log = <String>[];
  @override
  Future<Cart?> load() async { log.add('load'); return toCart(cart); }
  @override
  Future<CartAnswer> add(String restaurantPublicId, CartItemRequest item, {int? version, JourneyRef? journey}) async {
    log.add('add $restaurantPublicId ${item.itemId} x${item.quantity}${journey == null ? '' : ' j:${journey.id}'}');
    cart = _cartDto(restaurantPublicId, item.quantity, ((cart?['version'] as int?) ?? 0) + 1);
    return CartAnswer(cart: toCart(cart), lineId: _lineId);
  }
}

const _burger = AddItemInput(
  menuItemId: menuItemId, itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 20000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub',
  restaurantCurrency: 'INR', restaurantPublicId: restaurantId, quantity: 2, unitPriceMinor: 20000,
);

Widget _app(Widget screen, {required JourneyState journey, required DiscoveryState discovery, required CartState cart}) => MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => HealthState()),
        ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
        ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
        ChangeNotifierProvider.value(value: journey),
        ChangeNotifierProvider.value(value: discovery),
        ChangeNotifierProvider.value(value: cart),
        ChangeNotifierProvider(create: (_) => OrdersState()),
        ChangeNotifierProvider(create: (_) => PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore())),
      ],
      child: MaterialApp.router(theme: Brand.theme(), routerConfig: GoRouter(routes: [GoRoute(path: '/', builder: (_, _) => screen), GoRoute(path: '/:rest(.*)', builder: (_, _) => const Scaffold(body: Text('elsewhere')))])),
    );

/// Everything a customer (and the real backends) could notice of the flow.
typedef _Outcome = Map<String, Object>;

/// The flow, once: plan a journey, see the restaurants on the route (and search the list again), open a restaurant,
/// see its menu (and filter it), add an item to the cart.
Future<_Outcome> _walk(WidgetTester tester, AnalyticsRig rig) async {
  tester.view.physicalSize = const Size(390 * 3, 3600 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  Analytics.install(rig.emitter);

  final b = Backend();
  b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journeyDto()) : json(200, pageDto([rowDto('burger-hub', 'Burger Hub'), rowDto('pizza-point', 'Pizza Point', detourMin: 4)]));
  journeyApi = b.api;
  addTearDown(() => journeyApi = JourneyApi(store: MemoryKeyValueStore()));

  // 1 — the traveller submits the journey form.
  final js = JourneyState(repositories: apiJourneyRepositories(b.api))..setOrigin(place62)..setDestination(place18);
  final journey = (await js.plan())!;

  // 2 — the restaurants on the route; then the list is asked for again (another ordering, a retry).
  final ds = DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero), store: MemoryKeyValueStore(), journeys: b.api);
  await ds.bind(journey);
  await ds.retry();
  await ds.bind(journey);

  // 3 + 4 — the restaurant page and its menu; then the menu is searched (it loads again).
  final backend = _CartBackend();
  final cart = CartState(backend: backend, repository: MemoryCartRepository());
  await cart.ready;
  await tester.pumpWidget(_app(RestaurantDetailScreen(id: 'burger-hub', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)), journey: js, discovery: ds, cart: cart));
  await tester.pumpAndSettle();
  final shown = find.text('Burger Hub').evaluate().isNotEmpty;
  final menuShown = find.byType(TextField).evaluate().isNotEmpty; // the menu tab with its search field
  await tester.enterText(find.byType(TextField).first, 'burger');
  await tester.pumpAndSettle();
  await tester.enterText(find.byType(TextField).first, '');
  await tester.pumpAndSettle();

  // 5 — the backend accepts the item.
  final added = await cart.addToCart(_burger);
  await tester.pumpAndSettle();

  await Analytics.flush();
  return {
    'journey': '${js.status.name} ${js.journey?.id} ${js.errorCode?.name}',
    'list': '${ds.status.name} ${ds.total} ${[for (final i in ds.items) i.restaurant.slug].join(',')} ${ds.errorCode?.name}',
    'page': 'restaurant:$shown menu:$menuShown',
    'cart': '${added.ok} ${added.failure?.name} ${cart.count} ${cart.subtotalMinor} ${cart.status.name} ${cart.lastError}',
    'journeyRequests': [for (final c in b.calls) '${c.method} ${c.url.path}'],
    'cartRequests': backend.log,
  };
}

void main() {
  setUpAll(() async {
    final loader = FontLoader('Outfit');
    for (final w in [400, 500, 600, 700, 800]) {
      loader.addFont(rootBundle.load('assets/fonts/Outfit-$w.ttf'));
    }
    await loader.load();
  });
  tearDown(Analytics.uninstall);

  const expected = ['journey_search_started', 'journey_results_viewed', 'restaurant_viewed', 'menu_viewed', 'item_added_to_cart'];

  testWidgets('Journey → Restaurant → Menu → Cart: each step is reported exactly once, as designed', (tester) async {
    final rig = AnalyticsRig();
    final outcome = await _walk(tester, rig);

    // The flow itself happened.
    expect(outcome['journey'], 'routeAvailable $journeyId null');
    expect(outcome['list'], 'ready 2 burger-hub,pizza-point null');
    expect(outcome['page'], 'restaurant:true menu:true');
    expect(outcome['cart'], 'true null 2 40000 active null');
    expect((outcome['journeyRequests'] as List<String>).where((r) => r.endsWith('/restaurants')), hasLength(3)); // the list WAS asked for three times
    expect(outcome['cartRequests'], ['load', 'add $restaurantId $menuItemId x2']);

    // …and was reported: the five steps, in order, once each.
    expect(rig.net.names, expected);
    expect(rig.net.calls, hasLength(1)); // one small batch
    final body = rig.net.bodies.single;
    expect([body['platform'], body['app_version'], uuidV4.hasMatch(body['session'] as String)], ['ANDROID', '0.1.0+42', true]);
    final e = rig.net.events;
    expect(e[0].keys.toSet(), {'id', 'name', 'occurred_at'});
    expect([e[1]['refs'], e[1]['properties']], [{'journey': journeyId}, {'results': 2}]);
    expect([e[2]['refs'], e[2]['properties']], [{'restaurant': 'burger-hub', 'journey': journeyId}, {'origin': 'JOURNEY'}]);
    expect([e[3]['refs'], e[3].containsKey('properties')], [{'restaurant': 'burger-hub', 'journey': journeyId}, false]);
    expect([e[4]['refs'], e[4]['properties']], [{'restaurant': restaurantId, 'item': menuItemId}, {'quantity': 2}]);
    for (final x in e) {
      expect(x['id'], matches(uuidV4));
      expect(x['occurred_at'], matches(utcStamp));
    }
    // Nothing of the journey's places or coordinates, and no token, is in what was sent (and an amount has no field to be in).
    for (final secret in ['Noida', 'Sector', '28.6', '77.3', 'amount', 'price', 'total', guestToken]) {
      expect(rig.net.calls.single.body.contains(secret), isFalse, reason: secret);
    }
    expect(rig.timers.pending, isEmpty); // nothing queued, no timer left
  });

  testWidgets('a failing analytics endpoint changes nothing in the flow', (tester) async {
    final working = await _walk(tester, AnalyticsRig());
    await tester.pumpWidget(const SizedBox.shrink());

    final offline = AnalyticsRig()..net.offline();
    final withoutConnection = await _walk(tester, offline);
    await tester.pumpWidget(const SizedBox.shrink());

    final failing = AnalyticsRig()..net.answer = (_) => refused(500);
    final withErrors = await _walk(tester, failing);

    expect(withoutConnection, working);
    expect(withErrors, working);
    expect(working['cart'], 'true null 2 40000 active null');

    // What became of the events is the emitter's own business: kept (bounded) without a connection, dropped on an error.
    expect([offline.net.calls.length, offline.emitter.queued, offline.timers.pending.length], [1, 5, 0]);
    expect([failing.net.calls.length, failing.emitter.queued, failing.timers.pending.length], [1, 0, 0]);
    expect(offline.net.names, expected);
    expect(failing.net.names, expected);
  });

  testWidgets('with analytics off — as in every other test of the app — the same flow sends nothing', (tester) async {
    final off = AnalyticsRig(enabled: false, store: CountingStore());
    final outcome = await _walk(tester, off);
    expect(outcome['cart'], 'true null 2 40000 active null');
    expect([off.net.calls.length, off.timers.started, (off.store as CountingStore).reads, (off.store as CountingStore).writes], [0, 0, 0, 0]);
  });
}
