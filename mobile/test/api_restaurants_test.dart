import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/api_restaurants.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Module 23 — restaurants from the backend. The network is stubbed with payloads in the shape of the real API
/// (backend/openapi/openapi.json: PublicRestaurant, PublicRestaurantPage, CuisineList).
http.Response _json(int status, Object body) => http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});

Map<String, dynamic> dto({String slug = 'test-kitchen', String name = 'Test Kitchen', Map<String, dynamic>? availability, Map<String, dynamic>? images, List<dynamic>? special, String? description = 'Long text.'}) => {
      'id': '0a000000-0000-4000-8000-0000000000a1', 'slug': slug, 'name': name, 'branch_label': 'Sector 62 · Noida', 'short_description': 'Short text.', 'description': description,
      'cuisines': [{'code': 'north_indian', 'name': 'North Indian'}, {'code': 'biryani', 'name': 'Biryani'}],
      'features': [{'code': 'parking', 'name': 'Parking', 'category': 'FACILITY'}, {'code': 'pure_veg', 'name': 'Pure Veg', 'category': 'DIETARY'}],
      'price_level': 3, 'phone': '+911204567890', 'email': 'hello@test-kitchen.example', 'website': 'https://test-kitchen.example',
      'address': {'formatted': 'Sector 62, Noida, Uttar Pradesh 201309, India', 'line1': 'Sector 62', 'postal_code': '201309', 'city': 'Noida', 'city_slug': 'noida', 'region': 'Uttar Pradesh', 'region_code': 'IN-UP', 'country_code': 'IN'},
      'location': {'latitude': 28.6285, 'longitude': 77.3652}, 'timezone': 'Asia/Kolkata', 'currency': 'INR',
      'images': images ?? {'logo': null, 'cover': {'url': 'https://cdn.example/cover.jpg', 'alt_text': 'Cover'}, 'gallery': [{'url': 'https://cdn.example/cover.jpg', 'alt_text': null}, {'url': 'https://cdn.example/two.jpg', 'alt_text': null}]},
      'hours': {'timezone': 'Asia/Kolkata', 'weekly': [for (final d in [0, 1, 2, 3, 4, 5, 6]) {'day_of_week': d, 'periods': d == 1 ? <Object>[] : [{'opens_at': '08:00', 'closes_at': '23:30'}]}], 'special': special ?? [{'date': '2026-11-08', 'is_closed': true, 'periods': <Object>[], 'note': 'Diwali'}]},
      'pickup': {'methods': [{'method': 'COUNTER', 'instructions': null, 'requires_vehicle_info': false}, {'method': 'CURBSIDE', 'instructions': 'Bay 3', 'requires_vehicle_info': true}], 'asap': true, 'scheduled': true, 'default_prep_minutes': 12, 'minimum_lead_minutes': 15, 'instructions': 'Show your pickup code.'},
      'availability': availability ?? {'open_now': true, 'open_state': 'OPEN', 'accepting_orders': true, 'orderable': true, 'reason': null, 'closes_at': '2026-10-06T18:00:00+00:00', 'opens_next_at': null, 'checked_at': '2026-10-06T06:00:00+00:00'},
    };
Map<String, Object> page(List<Map<String, dynamic>> items, int current, int last) => {'data': items, 'links': <String, Object?>{}, 'meta': {'current_page': current, 'last_page': last, 'total': items.length}};
const cuisines = {'data': [{'code': 'north_indian', 'name': 'North Indian', 'slug': 'north-indian', 'restaurants': 2}, {'code': 'pizza', 'name': 'Pizza', 'slug': 'pizza', 'restaurants': 0}]};

void main() {
  final calls = <http.Request>[];
  var routes = <String, http.Response Function(http.Request)>{};
  ApiRestaurantRepository repo() => ApiRestaurantRepository(client: ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => 'customer-token', client: MockClient((r) async {
        calls.add(r);
        final h = routes['${r.method} ${r.url.path.replaceFirst('/api/v1', '')}'];
        return h == null ? _json(404, {'error': {'code': 'restaurant_not_found', 'message': 'Not found', 'details': <String, Object>{}}}) : h(r);
      })));

  setUp(() {
    calls.clear(); apiRestaurantData = null; restaurantModeOverride = 'api';
    routes = {'GET /restaurants': (_) => _json(200, page([dto(slug: 'a', name: 'A'), dto(slug: 'b', name: 'B')], 1, 1)), 'GET /cuisines': (_) => _json(200, cuisines)};
  });
  tearDown(() { apiRestaurantData = null; restaurantModeOverride = null; restaurantApi = ApiRestaurantRepository(); });

  group('mapping', () {
    test('keeps what the backend said and invents nothing', () {
      final r = ApiRestaurantRepository.toRestaurant(dto());
      expect([r.id, r.backendId, r.publicId, r.slug, r.name, r.description], ['test-kitchen', '0a000000-0000-4000-8000-0000000000a1', '0a000000-0000-4000-8000-0000000000a1', 'test-kitchen', 'Test Kitchen', 'Long text.']);
      expect([r.countryCode, r.timezone, r.currency, r.priceLevel, r.prepTimeMin], ['IN', 'Asia/Kolkata', 'INR', 3, 12]);
      expect(r.cuisines, ['North Indian', 'Biryani']); expect(r.features, ['Parking', 'Pure Veg']);
      expect(r.images, ['https://cdn.example/cover.jpg', 'https://cdn.example/two.jpg']);
      expect([r.address.locality, r.address.adminArea, r.address.postalCode, r.address.line1], ['Noida', 'Uttar Pradesh', '201309', 'Sector 62']);
      expect([r.phone, r.website, r.email, r.pickupInstructions], ['+911204567890', 'https://test-kitchen.example', 'hello@test-kitchen.example', 'Show your pickup code.']);
      expect(r.pickupMethods.map((m) => (m.type, m.instructions, m.requiresVehicleInfo)), [(PickupMethodCode.counter, null, false), (PickupMethodCode.curbside, 'Bay 3', true)]);
      expect(r.openingHours.periods.length, 6); expect(r.openingHours.periods.any((p) => p.day == 1), isFalse);
      expect(r.openingHours.special.single.date, '2026-11-08'); expect(r.openingHours.special.single.closed, isTrue); expect(r.openingHours.special.single.note, 'Diwali');
      // no reviews backend yet, no route in this API
      expect([r.rating, r.reviewCount, r.ratingIsSample, r.status, r.acceptingOrders, r.unavailableReason], [0, 0, false, RestaurantStatus.active, true, null]);
    });

    test('a restaurant the development data knows keeps the id its sample menu uses; its rating is a sample figure', () {
      final r = ApiRestaurantRepository.toRestaurant(dto(slug: 'burger-hub'));
      expect(r.id, 'burger-hub'); expect(r.publicId, '0a000000-0000-4000-8000-0000000000a1'); expect(r.ratingIsSample, isTrue); expect(r.reviewCount, greaterThan(0));
    });

    test('takes the reasons a customer cannot order from the backend', () {
      Map<String, dynamic> av(Map<String, dynamic> patch) => {...dto()['availability'] as Map<String, dynamic>, ...patch};
      GlobalRestaurant with_(Map<String, dynamic> patch) => ApiRestaurantRepository.toRestaurant(dto(availability: av(patch)));
      final paused = with_({'accepting_orders': false, 'orderable': false, 'reason': 'NOT_ACCEPTING_ORDERS'});
      expect([paused.status, paused.acceptingOrders, paused.unavailableReason], [RestaurantStatus.active, false, 'NOT_ACCEPTING_ORDERS']);
      final area = with_({'orderable': false, 'reason': 'AREA_UNAVAILABLE'}); // the restaurant accepts — FoodOnTheGo is not serving its area
      expect([area.status, area.acceptingOrders, area.unavailableReason], [RestaurantStatus.active, false, 'AREA_UNAVAILABLE']);
      expect(with_({'orderable': false, 'reason': 'PICKUP_UNAVAILABLE'}).acceptingOrders, isFalse);
      expect(with_({'open_now': false, 'open_state': 'TEMPORARILY_CLOSED', 'orderable': false, 'reason': 'TEMPORARILY_CLOSED'}).status, RestaurantStatus.temporarilyClosed);
      final closed = with_({'open_now': false, 'open_state': 'CLOSED', 'orderable': false, 'reason': 'CLOSED_NOW'}); // closed right now is not a reason to hide ordering later
      expect([closed.status, closed.acceptingOrders], [RestaurantStatus.active, true]);
    });

    test('web-relative image paths of local fixtures are not loaded as app images; a restaurant without photos has none', () {
      final r = ApiRestaurantRepository.toRestaurant(dto(images: {'logo': null, 'cover': {'url': '/images/food-burger.jpg', 'alt_text': null}, 'gallery': <Object>[]}));
      expect(r.images, isEmpty); expect(r.image, '');
      final known = ApiRestaurantRepository.toRestaurant(dto(slug: 'burger-hub', images: {'logo': null, 'cover': {'url': '/images/food-burger.jpg', 'alt_text': null}, 'gallery': <Object>[]}));
      expect(known.images, isNotEmpty); // the bundled asset of the fixture
      expect(ApiRestaurantRepository.toRestaurant(dto(description: null)).description, 'Short text.');
    });

    test('"open now" recomputed from the hours agrees with what the backend said at the moment it answered', () {
      final r = ApiRestaurantRepository.toRestaurant(dto());
      final open = computeAvailability(r, DateTime.parse('2026-10-06T06:00:00+00:00')); // Tuesday 11:30 IST
      expect([open.status, open.nextChangeAt], [AvailabilityStatus.open, DateTime.utc(2026, 10, 6, 18)]);
      final monday = computeAvailability(r, DateTime.utc(2026, 10, 5, 6)); // no periods on Monday
      expect([monday.status, monday.nextChangeAt], [AvailabilityStatus.closed, DateTime.utc(2026, 10, 6, 2, 30)]);
      expect(computeAvailability(r, DateTime.utc(2026, 11, 8, 6)).status, AvailabilityStatus.closed); // the special closed date
      // pickup slots follow the same dates: nothing on the special closed day, the weekly hours otherwise
      expect(serviceRangesFor(r, '2026-11-08'), isEmpty);
      expect(serviceRangesFor(r, '2026-11-07'), [(480, 1410)]);
    });
  });

  group('snapshot', () {
    test('loads every page of the public list and the cuisines, without a token; the pages read exactly this list', () async {
      routes['GET /restaurants'] = (r) => r.url.queryParameters['page[number]'] == '2' ? _json(200, page([dto(slug: 'c', name: 'C')], 2, 2)) : _json(200, page([dto(slug: 'a', name: 'A'), dto(slug: 'b', name: 'B')], 1, 2));
      expect(knownRestaurants, isEmpty); // API mode, nothing loaded: never the fixtures
      expect(await repo().hydrate(), isTrue);
      expect(calls.map((c) => '${c.method} ${c.url.path}${c.url.hasQuery ? '?${c.url.query}' : ''}').toList(), ['GET /api/v1/restaurants?page%5Bsize%5D=100&page%5Bnumber%5D=1', 'GET /api/v1/restaurants?page%5Bsize%5D=100&page%5Bnumber%5D=2', 'GET /api/v1/cuisines']);
      expect(calls.every((c) => !c.headers.containsKey('Authorization')), isTrue);
      expect(apiRestaurantData!.restaurants.map((r) => r.slug), ['a', 'b', 'c']); expect(apiRestaurantData!.cuisines, ['North Indian', 'Pizza']);
      final mock = MockRestaurantRepository(latency: Duration.zero);
      expect(mock.customerRestaurants.map((r) => r.slug), ['a', 'b', 'c']);
      expect(mock.getCuisineTaxonomy(), ['North Indian']); // the taxonomy in the backend's order, only cuisines a listed restaurant has
      final result = await mock.getRestaurants(const DiscoveryQuery());
      expect(result.items.map((x) => x.restaurant.slug), ['a', 'b', 'c']);
    });

    test('"FoodOnTheGo does not serve customers here" is an answer: the list is empty, no fixture restaurant appears', () async {
      routes['GET /restaurants'] = (_) => _json(409, {'error': {'code': 'market_unavailable', 'message': 'Not available here.', 'details': <String, Object>{}}});
      expect(await repo().hydrate(), isTrue);
      expect(apiRestaurantData!.restaurants, isEmpty); expect(MockRestaurantRepository(latency: Duration.zero).customerRestaurants, isEmpty);
    });

    test('when the backend cannot be reached nothing is invented: no snapshot → false; an earlier snapshot is kept', () async {
      routes['GET /restaurants'] = (_) => throw http.ClientException('offline');
      expect(await repo().hydrate(), isFalse); expect(apiRestaurantData, isNull); expect(knownRestaurants, isEmpty);
      routes['GET /restaurants'] = (_) => _json(200, page([dto(slug: 'a')], 1, 1));
      expect(await repo().hydrate(), isTrue);
      routes['GET /restaurants'] = (_) => throw http.ClientException('offline');
      expect(await repo().hydrate(), isTrue); expect(apiRestaurantData!.restaurants.map((r) => r.slug), ['a']);
    });

    test('one restaurant is always asked from the backend: an update replaces it, a 404 removes it from the list', () async {
      final api = repo(); restaurantApi = api; await api.hydrate();
      routes['GET /restaurants/a'] = (_) => _json(200, dto(slug: 'a', name: 'A renamed'));
      final mock = MockRestaurantRepository(latency: Duration.zero);
      expect((await mock.getRestaurantBySlug('a'))?.name, 'A renamed');
      expect(calls.last.url.path, '/api/v1/restaurants/a'); expect(calls.last.headers.containsKey('Authorization'), isFalse);
      expect(apiRestaurantData!.restaurants.firstWhere((r) => r.slug == 'a').name, 'A renamed');
      // suspended / unapproved / never existed: the same 404 — and it disappears from the list as well
      expect(await mock.getRestaurantBySlug('b'), isNull); expect(apiRestaurantData!.restaurants.map((r) => r.slug), ['a']);
      // the request itself failing is not "not found": the last known version can still be read; an unknown one is an error with a retry
      routes['GET /restaurants/a'] = (_) => throw http.ClientException('offline');
      expect((await mock.getRestaurantBySlug('a'))?.name, 'A renamed');
      routes['GET /restaurants/zzz'] = (_) => throw http.ClientException('offline');
      expect(() => mock.getRestaurantBySlug('zzz'), throwsA(isA<Exception>()));
    });

    test('in mock mode (tests, mock builds) the snapshot is not used at all', () {
      restaurantModeOverride = null;
      expect(restaurantsFromApi, isFalse); expect(knownRestaurants, same(globalRestaurants));
      expect(MockRestaurantRepository(latency: Duration.zero).customerRestaurants, isNotEmpty);
    });
  });
}
