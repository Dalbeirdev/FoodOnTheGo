/// Plan a Journey, Restaurants and the restaurant page on the backend journey (Module 26): what the customer reads.
/// The figures are the backend's and say where they come from; every failure has its own words and nothing is estimated
/// in their place; the device's position is asked for only on a tap.
library;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/core/theme.dart';
import 'package:foodonthego/discovery/api_restaurants.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart' show DiscoveryQuery;
import 'package:foodonthego/journey/api_journey.dart';
import 'package:foodonthego/journey/device_location.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/screens/plan_journey_screen.dart';
import 'package:foodonthego/screens/restaurant_detail_screen.dart';
import 'package:foodonthego/screens/restaurants_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/state/pickup_state.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import 'support/journey_api_fixtures.dart';

class FakeLocator implements DeviceLocator {
  FakeLocator({this.failure});
  final DeviceLocationFailure? failure;
  int asked = 0;
  @override
  Future<({double lat, double lng})> current() async {
    asked++;
    if (failure != null) throw DeviceLocationException(failure!);
    return (lat: 28.6139, lng: 77.209);
  }
}

String? lastRoute;

Widget app(Widget screen, Backend b, {JourneyState? journey, DiscoveryState? discovery}) {
  final router = GoRouter(routes: [
    GoRoute(path: '/', builder: (_, _) => screen),
    GoRoute(path: '/:rest(.*)', builder: (_, s) { lastRoute = s.uri.toString(); return const Scaffold(body: Text('elsewhere')); }),
  ]);
  return MultiProvider(
    providers: [
      ChangeNotifierProvider(create: (_) => HealthState()),
      ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
      ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
      ChangeNotifierProvider.value(value: journey ?? JourneyState(repositories: apiJourneyRepositories(b.api))),
      ChangeNotifierProvider.value(value: discovery ?? DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero), store: MemoryKeyValueStore(), journeys: b.api)),
      ChangeNotifierProvider(create: (_) => CartState()),
      ChangeNotifierProvider(create: (_) => OrdersState()),
      ChangeNotifierProvider(create: (_) => PickupState(repository: MockPickupRepository(latency: Duration.zero), store: MemoryKeyValueStore())),
    ],
    child: MaterialApp.router(theme: Brand.theme(), routerConfig: router),
  );
}

/// The backend's places: search by text, and one resolved answer per place (each with its own coordinates — the same
/// point twice is refused before planning).
http.Response places(http.Request r, {bool supported = true}) {
  if (r.url.path.endsWith('/places/search')) {
    final q = (r.url.queryParameters['q'] ?? '').toLowerCase();
    return json(200, {'data': [if ('sector 62, noida'.contains(q)) suggestionDto(), if ('sector 18, noida'.contains(q)) suggestionDto('fake:noida-18', 'Sector 18, Noida')], 'country_code': 'IN', 'provider': {'name': 'fake', 'mode': 'development'}});
  }
  if (r.url.path.endsWith('/places/resolve')) {
    return json(200, r.body.contains('fake:noida-18') ? resolvedDto(supported: supported, id: 'fake:noida-18', name: 'Sector 18, Noida', lat: 28.5708, lng: 77.3261) : resolvedDto(supported: supported));
  }
  return json(404, null);
}

Future<void> pick(WidgetTester tester, String field, String typed, String name) async {
  await tester.tap(find.bySemanticsLabel(RegExp('^$field: ')));
  await tester.pumpAndSettle();
  await tester.enterText(find.byType(TextField), typed);
  await tester.pumpAndSettle();
  await tester.tap(find.text(name).last);
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(() async {
    final loader = FontLoader('Outfit');
    for (final w in [400, 500, 600, 700, 800]) {
      loader.addFont(rootBundle.load('assets/fonts/Outfit-$w.ttf'));
    }
    await loader.load();
  });
  setUp(() { lastRoute = null; });
  tearDown(() { deviceLocator = const GeolocatorDeviceLocator(); restaurantModeOverride = null; apiRestaurantData = null; });

  /// A phone's width; tall enough that the page's list builds everything (a lazy list does not build what is far below the fold).
  Future<void> phone(WidgetTester tester) async {
    tester.view.physicalSize = const Size(390 * 3, 3600 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
  }

  group('Plan a Journey on the backend', () {
    testWidgets('search → the backend resolves the chosen place → the provider route with its provenance; nothing is labelled mock', (tester) async {
      await phone(tester);
      final b = Backend();
      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journeyDto(flags: ['TOLLS'])) : places(r);
      await tester.pumpWidget(app(const PlanJourneyScreen(), b));
      await tester.pumpAndSettle();
      expect(find.textContaining('FoodOnTheGo keeps no journey history'), findsOneWidget);

      await pick(tester, 'Starting Point', 'sector 6', 'Sector 62, Noida');
      expect(b.body(b.last('POST', '/places/resolve')), {'place_id': 'fake:noida-62'});
      await pick(tester, 'Destination', 'sector 1', 'Sector 18, Noida');
      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();

      expect(b.body(b.last('POST', '/journeys/plan')), {'origin': {'place_id': 'fake:noida-62'}, 'destination': {'place_id': 'fake:noida-18'}});
      expect(find.text('9.6 km'), findsOneWidget);
      expect(find.text('17 min'), findsOneWidget);
      expect(find.text('development routing model'), findsNWidgets(2)); // beside the distance and beside the time
      expect(find.text('Tolls on this route'), findsOneWidget);
      expect(find.textContaining('synthetic roads, not real road data'), findsOneWidget);
      expect(find.textContaining('This search stays valid until'), findsOneWidget);
      expect(find.textContaining('mock'), findsNothing);
      expect(find.bySemanticsIdentifier('route-preview-development-5'), findsOneWidget); // the provider's five points, not a two-point line
      expect(find.bySemanticsIdentifier('pj-no-coverage'), findsNothing);

      await tester.ensureVisible(find.text('Find Restaurants Along Route'));
      await tester.tap(find.text('Find Restaurants Along Route'));
      await tester.pumpAndSettle();
      expect(lastRoute, '/restaurants?journey=$journeyId'); // the id only — no coordinate, no token
    });

    testWidgets('a place FoodOnTheGo does not serve is said so, and the route is still planned; a route with no served area says no restaurants will be listed', (tester) async {
      await phone(tester);
      final b = Backend();
      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journeyDto(along: 'NO_SERVICE_COVERAGE')) : places(r, supported: false);
      await tester.pumpWidget(app(const PlanJourneyScreen(), b));
      await tester.pumpAndSettle();
      await pick(tester, 'Starting Point', 'sector 6', 'Sector 62, Noida');
      expect(find.textContaining('FoodOnTheGo does not serve this place yet'), findsOneWidget);
      await pick(tester, 'Destination', 'sector 1', 'Sector 18, Noida');
      expect(find.textContaining('FoodOnTheGo does not serve this place yet'), findsNWidgets(2));
      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();
      expect(find.text('9.6 km'), findsOneWidget);
      expect(find.bySemanticsIdentifier('pj-no-coverage'), findsOneWidget);
      expect(find.textContaining('no restaurants will be listed for it'), findsOneWidget);
    });

    testWidgets('no road route, a provider outage and no connection each say so — and estimate nothing', (tester) async {
      await phone(tester);
      final b = Backend();
      var answer = failure(422, 'route_not_found', 'No route.');
      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? answer : places(r);
      await tester.pumpWidget(app(const PlanJourneyScreen(), b));
      await tester.pumpAndSettle();
      await pick(tester, 'Starting Point', 'sector 6', 'Sector 62, Noida');
      await pick(tester, 'Destination', 'sector 1', 'Sector 18, Noida');

      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('pj-error-noRoute'), findsOneWidget);
      expect(find.textContaining('No road route is available between these locations. FoodOnTheGo never draws a straight line in place of a road.'), findsOneWidget);
      expect(find.textContaining(' km'), findsNothing);
      expect(find.bySemanticsIdentifier(RegExp('^route-preview-')), findsNothing);

      answer = failure(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable.', {'retry_after_seconds': 15});
      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('pj-error-providerUnavailable'), findsOneWidget);
      expect(find.textContaining('Route planning is temporarily unavailable. Please try again in a moment. Nothing was estimated in the meantime'), findsOneWidget);
      expect(find.textContaining(' km'), findsNothing);

      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? throw http.ClientException('offline') : places(r);
      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('pj-error-network'), findsOneWidget);
      expect(find.textContaining('Cannot reach FoodOnTheGo right now'), findsOneWidget);

      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journeyDto()) : places(r);
      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();
      expect(find.text('9.6 km'), findsOneWidget); // the same button plans it once the backend answers
    });

    testWidgets('"Use my current location" asks the device only when tapped and sends the position in the request body', (tester) async {
      await phone(tester);
      final locator = FakeLocator();
      deviceLocator = locator;
      final b = Backend();
      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journeyDto()) : places(r);
      await tester.pumpWidget(app(const PlanJourneyScreen(), b));
      await tester.pumpAndSettle();
      expect(locator.asked, 0); // opening the screen asks for nothing

      await tester.tap(find.bySemanticsLabel(RegExp('^Starting Point: ')));
      await tester.pumpAndSettle();
      expect(find.text('Your device asks for permission first'), findsOneWidget);
      expect(locator.asked, 0); // nor does opening the picker
      await tester.tap(find.text('Use my current location'));
      await tester.pumpAndSettle();
      expect(locator.asked, 1);
      expect(find.text('Current location'), findsOneWidget);

      await pick(tester, 'Destination', 'sector 1', 'Sector 18, Noida');
      await tester.tap(find.text('Find Restaurants on Route'));
      await tester.pumpAndSettle();
      expect(b.body(b.last('POST', '/journeys/plan'))['origin'], {'lat': 28.6139, 'lng': 77.209, 'label': 'Current location'});
      expect(b.calls.every((c) => !c.url.toString().contains('28.6139')), true); // never in a URL
    });

    testWidgets('when location permission is not given the picker says so and nothing is guessed', (tester) async {
      await phone(tester);
      deviceLocator = FakeLocator(failure: DeviceLocationFailure.denied);
      final b = Backend()..reply = places;
      final js = JourneyState(repositories: apiJourneyRepositories(b.api));
      await tester.pumpWidget(app(const PlanJourneyScreen(), b, journey: js));
      await tester.pumpAndSettle();
      await tester.tap(find.bySemanticsLabel(RegExp('^Starting Point: ')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Use my current location'));
      await tester.pumpAndSettle();
      expect(find.text('Location permission was not given. Search for a place instead.'), findsOneWidget);
      expect(find.byType(TextField), findsOneWidget); // the picker stays open for a search
      expect(js.origin, isNull);
    });

    test('a saved place of the backend account travels by its id; in development mode by its coordinates', () {
      const home = SavedAddress(id: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', label: 'Home', kind: AddressKind.home, line1: 'A-203', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309', lat: 28.6271, lng: 77.3717, coverage: Coverage(status: 'unsupported', reason: 'AREA_NOT_SERVED'));
      final backend = addressToLocation(home, backend: true);
      expect([backend.savedLocationId, backend.coverage?.supported], ['df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', false]);
      expect(toEndBody(backend), {'saved_location_id': 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', 'label': 'Home'});
      final dev = addressToLocation(home);
      expect([dev.savedLocationId, dev.coverage], [isNull, isNull]);
      expect(toEndBody(dev)['lat'], 28.6271);
    });
  });

  group('Restaurants along a backend journey', () {
    Future<(Backend, JourneyState, DiscoveryState)> planned(http.Response Function(http.Request) restaurants, {Map<String, dynamic>? journey}) async {
      final b = Backend();
      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journey ?? journeyDto()) : r.url.path.endsWith('/restaurants') ? restaurants(r) : json(200, journey ?? journeyDto(withToken: false));
      final js = JourneyState(repositories: apiJourneyRepositories(b.api))..setOrigin(place62)..setDestination(place18);
      await js.plan();
      return (b, js, DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero), store: MemoryKeyValueStore(), journeys: b.api));
    }

    final rows = [
      rowDto('burger-hub', 'Burger Hub'),
      rowDto('wok-express', 'Wok Express', detourMin: 3, open: false, state: 'TEMPORARILY_CLOSED', accepting: false, reason: 'TEMPORARILY_CLOSED', cuisines: [{'code': 'chinese', 'name': 'Chinese'}], lat: 28.6158, lng: 77.3542),
      rowDto('spice-nest', 'Spice Nest', detourMin: 11, open: false, state: 'CLOSED', opensNextAt: '2026-10-05T12:30:00+00:00', cuisines: [{'code': 'north_indian', 'name': 'North Indian'}], lat: 28.5899, lng: 77.3404),
    ];

    testWidgets('cards carry the actual detour, the estimated arrival and the state ON ARRIVAL; no rating, and the source of the figures is said', (tester) async {
      await phone(tester);
      final (b, js, ds) = await planned((_) => json(200, pageDto(rows)));
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b, journey: js, discovery: ds));
      await tester.pumpAndSettle();

      expect(find.text('3 restaurants along your route'), findsOneWidget);
      expect(find.bySemanticsIdentifier('route-mode-development'), findsOneWidget);
      expect(find.text('development route model'), findsOneWidget);
      expect(find.bySemanticsIdentifier('estimate-note-development'), findsOneWidget);
      expect(find.textContaining('synthetic roads, not real road data. Calculated at'), findsOneWidget);
      expect(find.textContaining('Detours up to 15 min.'), findsOneWidget);
      expect(find.textContaining('can take your order when you arrive'), findsOneWidget);

      expect(find.bySemanticsIdentifier('rcard-burger-hub'), findsOneWidget);
      expect(find.text('Open when you arrive'), findsOneWidget);
      expect(find.text('Temporarily closed'), findsOneWidget);
      expect(find.text('Closed when you arrive'), findsOneWidget);
      expect(find.text('+2 min'), findsOneWidget);
      expect(find.text('+11 min'), findsOneWidget);
      expect(find.textContaining(RegExp(r'^Estimated arrival 4:42\sPM$')), findsNWidgets(3)); // restaurant-local time (IST)
      expect(find.byIcon(Icons.star_rounded), findsNothing); // no reviews backend: no rating at all
      expect(find.textContaining('mock'), findsNothing);
      expect(find.textContaining('Ready by'), findsNothing);
      expect(b.last('GET', '/restaurants').headers['X-Journey-Token'], guestToken);
    });

    testWidgets('only the orderings and filters the backend applies are offered', (tester) async {
      await phone(tester);
      restaurantModeOverride = 'api';
      apiRestaurantData = RestaurantSnapshot(restaurants: [ApiRestaurantRepository.toRestaurant(restaurantDto('burger-hub', 'Burger Hub'))], cuisines: const ['Burgers', 'Sushi'], cuisineCodes: const {'Burgers': 'burgers', 'Sushi': 'sushi'}, loadedAt: DateTime.now());
      final (b, js, ds) = await planned((_) => json(200, pageDto(rows)));
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b, journey: js, discovery: ds));
      await tester.pumpAndSettle();

      await tester.tap(find.textContaining('Sort by'));
      await tester.pumpAndSettle();
      for (final label in ['Lowest detour', 'Order along route', 'Nearest to route', 'Fastest pickup']) { expect(find.text(label), findsOneWidget); }
      expect(find.text('Highest rated'), findsNothing);
      await tester.tap(find.text('Order along route'));
      await tester.pumpAndSettle();
      expect(b.last('GET', '/restaurants').url.queryParameters['sort'], 'route_order');

      await tester.tap(find.textContaining('Filters'));
      await tester.pumpAndSettle();
      expect(find.text('Open when I arrive'), findsWidgets);
      expect(find.text('Max detour time'), findsOneWidget);
      expect(find.text('Burgers'), findsOneWidget);
      for (final gone in ['Price', 'Dietary options', 'Max pickup preparation', 'Distance from route', 'Open now', 'Sushi']) { expect(find.text(gone), findsNothing); }
      expect(find.text('Rating'), findsOneWidget); // Module 34: the backend filters on the real rating
      await tester.tap(find.text('Burgers'));
      await tester.pump(const Duration(milliseconds: 450));
      await tester.pumpAndSettle();
      expect(b.last('GET', '/restaurants').url.queryParameters['cuisine'], 'burgers'); // the taxonomy code, not the label
    });

    testWidgets('an expired journey: its own panel with "Plan it again", and no list', (tester) async {
      await phone(tester);
      final (b, js, ds) = await planned((_) => failure(410, 'journey_expired'));
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b, journey: js, discovery: ds));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('journey-expired'), findsOneWidget);
      expect(find.text('This journey has expired.'), findsOneWidget);
      expect(find.byType(GlobalRestaurantCard), findsNothing);
      expect(find.text('Restaurant data is unavailable right now.'), findsNothing);
      await tester.tap(find.text('Plan it again'));
      await tester.pumpAndSettle();
      expect(lastRoute, '/plan-journey');
      expect([js.journey, js.origin?.name, js.destination?.name], [isNull, 'Sector 62, Noida', 'Sector 18, Noida']);
    });

    testWidgets('a journey that is not this visitor\'s is "not found on this device" — no restaurant is requested', (tester) async {
      await phone(tester);
      final b = Backend()..reply = (_) => failure(404, 'journey_not_found');
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('journey-missing'), findsOneWidget);
      expect(find.text('Journey not found on this device.'), findsOneWidget);
      expect(find.textContaining('on the device that planned it'), findsOneWidget);
      expect(b.calls.where((c) => c.url.path.endsWith('/restaurants')), isEmpty);
      expect(find.byType(GlobalRestaurantCard), findsNothing);
    });

    testWidgets('a journey that cannot be read right now is not called "not found": it can be tried again', (tester) async {
      await phone(tester);
      final b = Backend()..reply = (_) => throw http.ClientException('offline');
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('journey-unreachable'), findsOneWidget);
      expect(find.textContaining('Cannot reach FoodOnTheGo right now'), findsOneWidget);
      expect(find.text('Journey not found on this device.'), findsNothing);
      b.reply = (r) => r.url.path.endsWith('/restaurants') ? json(200, pageDto(rows)) : json(200, journeyDto(withToken: false));
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();
      expect(find.text('3 restaurants along your route'), findsOneWidget);
    });

    testWidgets('no served area along the route is an answer, an outage is an error, filters that remove everything say so', (tester) async {
      await phone(tester);
      var answer = json(200, pageDto([], emptyReason: 'NO_SERVICE_COVERAGE', along: 'NO_SERVICE_COVERAGE', requested: 0));
      final (b, js, ds) = await planned((_) => answer, journey: journeyDto(along: 'NO_SERVICE_COVERAGE'));
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b, journey: js, discovery: ds));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('market-unavailable')), findsOneWidget);
      expect(find.text('FoodOnTheGo does not cover this route yet.'), findsOneWidget);
      expect(find.text('Restaurant data is unavailable right now.'), findsNothing);

      answer = failure(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable.');
      await ds.retry();
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('discovery-error-providerUnavailable'), findsOneWidget);
      expect(find.text('Route planning is temporarily unavailable.'), findsOneWidget);
      expect(find.byType(GlobalRestaurantCard), findsNothing); // no card is made up

      answer = json(200, pageDto([], emptyReason: 'FILTERS', requested: 0));
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('discovery-empty-filters'), findsOneWidget);
      expect(find.text('No restaurants on this route match your search.'), findsOneWidget);
      await tester.ensureVisible(find.text('Allow detours up to 30 min')); // minutes are spelled out: "30m" would read as metres
      await tester.tap(find.text('Allow detours up to 30 min'));
      await tester.pump(const Duration(milliseconds: 450));
      await tester.pumpAndSettle();
      expect(b.last('GET', '/restaurants').url.queryParameters['max_detour_minutes'], '30');

      answer = json(200, pageDto([], emptyReason: 'NO_REACHABLE_RESTAURANTS', requested: 4));
      await ds.retry();
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('discovery-empty-unreachable'), findsOneWidget);
      expect(find.text('No restaurant near this route can be reached by road from it.'), findsOneWidget);
    });

    testWidgets('the map draws the provider line and a tap on a marker selects that restaurant (the note sits under the map)', (tester) async {
      await phone(tester);
      final (b, js, ds) = await planned((_) => json(200, pageDto(rows)));
      await tester.pumpWidget(app(RestaurantsScreen(journeyId: journeyId), b, journey: js, discovery: ds));
      await tester.pumpAndSettle();
      await tester.tap(find.bySemanticsLabel('Map'));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('map-route-5'), findsOneWidget);
      expect(find.textContaining('Schematic view of the route line from the routing provider'), findsOneWidget);
      expect(find.byIcon(Icons.location_on), findsNWidgets(3));
      await tester.tap(find.byIcon(Icons.location_on).first);
      await tester.pumpAndSettle();
      expect(ds.selectedId, isNotNull);
      expect(find.byType(GlobalRestaurantCard), findsOneWidget);
    });
  });

  group('the restaurant page on a backend journey', () {
    testWidgets('shows the backend\'s figures for a restaurant the journey found — and none for one it did not', (tester) async {
      await phone(tester);
      final b = Backend();
      b.reply = (r) => r.url.path.endsWith('/journeys/plan') ? json(201, journeyDto()) : json(200, pageDto([rowDto('burger-hub', 'Burger Hub', detourMin: 2, toRoute: 63)]));
      journeyApi = b.api;
      addTearDown(() => journeyApi = JourneyApi(store: MemoryKeyValueStore()));
      final js = JourneyState(repositories: apiJourneyRepositories(b.api))..setOrigin(place62)..setDestination(place18);
      final j = (await js.plan())!;
      await b.api.discover(j, const DiscoveryQuery());

      await tester.pumpWidget(app(RestaurantDetailScreen(id: 'burger-hub', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)), b, journey: js));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('rd-route-development'), findsOneWidget);
      expect(find.text('development route model'), findsOneWidget);
      expect(find.text('2m'), findsOneWidget); // the detour the backend calculated for this journey
      expect(find.text('60 m'), findsOneWidget);
      expect(find.bySemanticsIdentifier('rd-not-on-journey'), findsNothing);

      await tester.pumpWidget(app(RestaurantDetailScreen(key: const Key('other'), id: 'pizza-point', restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero)), b, journey: js));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('rd-not-on-journey'), findsOneWidget);
      expect(find.textContaining('not among the results of your current journey'), findsOneWidget);
      expect(find.bySemanticsIdentifier(RegExp('^rd-route-')), findsNothing);
    });
  });
}
