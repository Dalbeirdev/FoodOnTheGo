import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/i18n/format.dart';
import 'package:foodonthego/market/api_market.dart';
import 'package:foodonthego/market/market.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Module 22 — market data from the backend. The network is stubbed with the payloads the real API
/// returns (backend/openapi/openapi.json: MarketList, Coverage, Availability).
http.Response _json(int status, Object body) => http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});

const _india = {'id': 'm-in', 'slug': 'india', 'country_code': 'IN', 'name': 'India', 'status': 'ACTIVE', 'default_currency': 'INR', 'supported_currencies': ['INR'], 'default_locale': 'en-IN', 'supported_locales': ['en-IN'], 'timezone_strategy': 'single', 'default_timezone': 'Asia/Kolkata', 'distance_unit': 'metric', 'phone_country_code': '+91', 'features': {'reviews': true}, 'launched_at': null};
Map<String, Object> _square(double w, double s, double e, double n) => {'type': 'MultiPolygon', 'coordinates': [[[[w, s], [e, s], [e, n], [w, n], [w, s]]]]};
final _coverage = {
  'market_id': 'm-in', 'country_code': 'IN', 'generated_at': '2026-09-30T10:00:00+00:00',
  'regions': [{'id': 'r-up', 'code': 'IN-UP', 'name': 'Uttar Pradesh', 'type': 'STATE', 'status': 'ACTIVE'}, {'id': 'r-dl', 'code': 'IN-DL', 'name': 'Delhi', 'type': 'UNION_TERRITORY', 'status': 'PAUSED'}],
  'cities': [
    {'id': 'c-noida', 'region_id': 'r-up', 'name': 'Noida', 'slug': 'noida', 'aliases': <String>[], 'latitude': 28.5355, 'longitude': 77.391, 'timezone': 'Asia/Kolkata', 'status': 'PILOT'},
    {'id': 'c-delhi', 'region_id': 'r-dl', 'name': 'Delhi', 'slug': 'delhi', 'aliases': <String>[], 'latitude': 28.6139, 'longitude': 77.209, 'timezone': 'Asia/Kolkata', 'status': 'ACTIVE'},
  ],
  'service_areas': [
    {'id': 'a-live', 'city_id': 'c-noida', 'name': 'Noida Central', 'slug': 'noida-central', 'status': 'ACTIVE', 'geometry': _square(77.30, 28.50, 77.40, 28.60)},
    {'id': 'a-paused', 'city_id': 'c-noida', 'name': 'Noida East', 'slug': 'noida-east', 'status': 'PAUSED', 'geometry': _square(77.50, 28.50, 77.60, 28.60)},
    {'id': 'a-delhi', 'city_id': 'c-delhi', 'name': 'Delhi Central', 'slug': 'delhi-central', 'status': 'ACTIVE', 'geometry': _square(77.15, 28.55, 77.25, 28.65)},
  ],
  'route_corridors': <Object>[],
};

void main() {
  final calls = <http.Request>[];
  var routes = <String, http.Response Function(http.Request)>{};
  ApiMarketRepository repo() => ApiMarketRepository(client: ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => 'customer-token', client: MockClient((r) async {
        calls.add(r);
        final h = routes['${r.method} ${r.url.path.replaceFirst('/api/v1', '')}'];
        return h == null ? _json(404, {'error': {'code': 'not_found', 'message': 'Not found'}}) : h(r);
      })));

  setUp(() {
    calls.clear(); apiMarketData = null; marketModeOverride = null; setFixtureScope(FixtureScope.india);
    routes = {'GET /markets': (_) => _json(200, {'data': [_india]}), 'GET /markets/current/coverage': (_) => _json(200, _coverage)};
  });
  tearDown(() { apiMarketData = null; marketModeOverride = null; marketRepository = ApiMarketRepository(); setFixtureScope(null); });

  test('without the backend the bundled fixtures are used (tests, mock builds)', () {
    expect(marketFromApi, isFalse); expect(apiMarketData, isNull);
    expect(marketAvailability.serviceAreas.length, 15); expect(marketAvailability.activeMarket.defaultCurrency, 'INR');
  });

  test('hydrate reads GET /markets and GET /markets/current/coverage without a token and maps the market', () async {
    expect(await repo().hydrate(), isTrue);
    expect(calls.map((c) => '${c.method} ${c.url.path}').toSet(), {'GET /api/v1/markets', 'GET /api/v1/markets/current/coverage'});
    expect(calls.every((c) => !c.headers.containsKey('Authorization')), isTrue);

    final m = marketAvailability.activeMarket;
    expect([m.countryCode, m.displayName, m.defaultCurrency, m.defaultLocale, m.defaultTimezone, m.phoneCountryCode], ['IN', 'India', 'INR', 'en_IN', 'Asia/Kolkata', '+91']);
    expect(m.metric, isTrue); expect(m.status, MarketStatus.active);
    expect(marketAvailability.activeCountryCodes, ['IN']); expect(marketAvailability.formatLocale, 'en_IN');
    expect(formatMoney(24900, 'INR'), '₹249.00');
    expect(apiMarketData!.cities, 2); expect(marketAvailability.serviceAreas.map((a) => a.id), ['a-live', 'a-paused', 'a-delhi']);
  });

  test('coverage comes from the backend polygons and the status hierarchy', () async {
    await repo().hydrate();
    expect(marketAvailability.checkLocation(countryCode: 'IN', lat: 28.55, lng: 77.35).supported, isTrue);   // active area, pilot city, active state
    expect(marketAvailability.checkLocation(countryCode: 'IN', lat: 28.55, lng: 77.55).reason, UnavailableReason.paused);
    expect(marketAvailability.checkLocation(countryCode: 'IN', lat: 28.60, lng: 77.20).reason, UnavailableReason.paused, reason: 'an ACTIVE area under a paused state is not available');
    expect(marketAvailability.checkLocation(countryCode: 'IN', lat: 27.0, lng: 78.0).reason, UnavailableReason.area);
    expect(marketAvailability.checkLocation(countryCode: 'US', lat: 40.7, lng: -74.0).reason, UnavailableReason.market);
    expect(marketAvailability.isRestaurantAvailable(countryCode: 'IN', lat: 28.55, lng: 77.35), isTrue);
    expect(marketAvailability.isRestaurantAvailable(countryCode: 'IN', lat: 28.60, lng: 77.20), isFalse);
  });

  test('point in polygon respects several parts and holes', () {
    const area = ServiceArea.polygon('x', 'x', [
      [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]], [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]]],
      [[[20, 20], [21, 20], [21, 21], [20, 21], [20, 20]]],
    ], AreaStatus.active);
    expect(area.contains(2, 2), isTrue); expect(area.contains(5, 5), isFalse);
    expect(area.contains(20.5, 20.5), isTrue); expect(area.contains(15, 15), isFalse);
    expect(const ServiceArea.polygon('e', 'e', [], AreaStatus.active).contains(1, 1), isFalse);
    expect(const ServiceArea('c', 'circle', 28.622, 77.372, 6000, AreaStatus.active).contains(28.628, 77.3649), isTrue);
  });

  test('an unreachable backend keeps the previous snapshot and reports failure when there is none', () async {
    final offline = ApiMarketRepository(client: ApiClient(baseUrl: 'http://api.test/api/v1', client: MockClient((_) async => throw http.ClientException('offline'))));
    expect(await offline.hydrate(), isFalse); expect(apiMarketData, isNull);
    expect(await repo().hydrate(), isTrue);
    expect(await offline.hydrate(), isTrue); expect(apiMarketData!.market.countryCode, 'IN');
  });

  test('a market that no longer serves customers is held as paused and offers nothing', () async {
    await repo().hydrate(); expect(marketAvailability.isCountrySupported('IN'), isTrue);
    routes['GET /markets/current/coverage'] = (_) => _json(404, {'error': {'code': 'market_unavailable', 'message': 'FoodOnTheGo is not available in this country yet.'}});
    routes['GET /markets'] = (_) => _json(200, {'data': <Object>[]});
    expect(await repo().hydrate(), isTrue);
    expect(marketAvailability.activeMarket.status, MarketStatus.paused); expect(marketAvailability.activeMarket.defaultCurrency, 'INR');
    expect(marketAvailability.isCountrySupported('IN'), isFalse);
    expect(marketAvailability.checkLocation(countryCode: 'IN', lat: 28.55, lng: 77.35).reason, UnavailableReason.market);
  });

  test('the authoritative check posts the coordinates and maps the backend reasons', () async {
    Map<String, Object?> answer(String? reason) => {'supported': reason == null, 'reason': reason, 'market': null, 'region': null, 'city': null, 'service_area': null, 'route_corridors': <Object>[], 'checked_at': ''};
    String? next;
    routes['POST /availability/location'] = (_) => _json(200, answer(next));
    final r = repo();

    expect((await r.checkLocation(lat: 28.55, lng: 77.35, countryCode: 'in')).supported, isTrue);
    expect(jsonDecode(calls.last.body), {'lat': 28.55, 'lng': 77.35, 'country_code': 'IN'}); expect(calls.last.headers.containsKey('Authorization'), isFalse);

    for (final (reason, mapped) in [('MARKET_UNSUPPORTED', UnavailableReason.market), ('MARKET_PAUSED', UnavailableReason.market), ('REGION_UNAVAILABLE', UnavailableReason.area), ('CITY_UNAVAILABLE', UnavailableReason.area), ('OUTSIDE_SERVICE_AREA', UnavailableReason.area), ('SERVICE_AREA_PAUSED', UnavailableReason.paused)]) {
      next = reason;
      final res = await r.checkLocation(lat: 1, lng: 2);
      expect(res.supported, isFalse); expect(res.reason, mapped, reason: reason);
    }
    expect(jsonDecode(calls.last.body), {'lat': 1, 'lng': 2});
  });

  test('discovery asks the backend about the chosen location in API mode and never guesses when it cannot', () async {
    marketModeOverride = 'api';
    routes['POST /availability/location'] = (_) => _json(200, {'supported': false, 'reason': 'OUTSIDE_SERVICE_AREA', 'market': null, 'region': null, 'city': null, 'service_area': null, 'route_corridors': <Object>[], 'checked_at': ''});
    marketRepository = repo(); await marketRepository.hydrate();
    final discovery = MockRestaurantRepository(latency: Duration.zero);
    const lucknow = DiscoveryScope(countryCode: 'IN', adminArea: 'Uttar Pradesh', lat: 26.8467, lng: 80.9462, label: 'Lucknow', source: 'manual');

    final page = await discovery.getRestaurants(const DiscoveryQuery(scope: lucknow));
    expect(page.items, isEmpty); expect(page.unavailable!.reason, UnavailableReason.area);
    expect(calls.where((c) => c.url.path.endsWith('/availability/location')).length, 1);

    routes['POST /availability/location'] = (_) => _json(429, {'error': {'code': 'rate_limited', 'message': 'Too many requests. Please try again shortly.'}});
    await expectLater(discovery.getRestaurants(const DiscoveryQuery(scope: lucknow)), throwsA(predicate((e) => '$e'.contains('Too many requests'))));

    marketRepository = ApiMarketRepository(client: ApiClient(baseUrl: 'http://api.test/api/v1', client: MockClient((_) async => throw http.ClientException('offline'))));
    await expectLater(discovery.getRestaurants(const DiscoveryQuery(scope: lucknow)), throwsA(predicate((e) => '$e'.contains('Cannot reach FoodOnTheGo'))));
  });
}
