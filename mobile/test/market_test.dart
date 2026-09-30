import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:foodonthego/i18n/format.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/market/market.dart';
import 'package:foodonthego/state/discovery_state.dart';

/// Module 18A — India launch configuration on Android. The rest of the suite runs on the controlled
/// global test fixtures; these tests opt into the India scope the shipped app uses.
MockJourneyStore _store() => MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero);
Future<Journey> _journey(String a, String b) async {
  final s = _store(); final loc = MockLocationRepository(s);
  final o = (await loc.search(a)).first, d = (await loc.search(b)).first;
  final j = await MockJourneyRepository(s).create(origin: o, destination: d);
  return j.copyWith(route: await MockRouteRepository(s).getRoute(o, d), status: JourneyRecordStatus.routeAvailable);
}

void main() {
  final repo = MockRestaurantRepository(latency: Duration.zero);
  setUp(() => setFixtureScope(FixtureScope.india));
  tearDown(() => setFixtureScope(null));

  test('India is the active market: IN, INR, en_IN, Asia/Kolkata, metric, +91', () {
    final m = marketAvailability.activeMarket;
    expect([m.countryCode, m.defaultCurrency, m.defaultLocale, m.defaultTimezone, m.phoneCountryCode], ['IN', 'INR', 'en_IN', 'Asia/Kolkata', '+91']);
    expect(m.metric, isTrue); expect(m.status, MarketStatus.active);
    expect(marketAvailability.activeCountryCodes, ['IN']);
  });

  test('country support: India yes, future markets no', () {
    expect(marketAvailability.isCountrySupported('IN'), isTrue);
    for (final cc in ['US', 'GB', 'JP', 'FR', 'AE', null]) { expect(marketAvailability.isCountrySupported(cc), isFalse, reason: '$cc'); }
  });

  test('customer restaurants are India-only and inside serviceable areas (paused / planned cities hidden)', () {
    final visible = repo.customerRestaurants;
    expect(visible, isNotEmpty);
    expect(visible.every((r) => r.countryCode == 'IN' && r.currency == 'INR' && r.timezone == 'Asia/Kolkata'), isTrue);
    final ids = visible.map((r) => r.id).toSet();
    expect(ids, containsAll(['burger-hub', 'jaipur-thali', 'ahmedabad-gujarati']));
    expect(ids.contains('vapi-coffee'), isFalse, reason: 'Vapi service area is paused');
    expect(ids.contains('udaipur-lake-cafe'), isFalse, reason: 'Udaipur has no service area');
    expect(ids.contains('kettleman-diner') || ids.contains('ippudo-shizuoka'), isFalse);
    expect(repo.byId('kettleman-diner'), isNull);
    expect(repo.getCuisineTaxonomy(), isNot(contains('Diner')));
  });

  test('unsupported foreign market: no fake restaurants, market message', () async {
    final page = await repo.getRestaurants(const DiscoveryQuery(scope: DiscoveryScope(countryCode: 'US', lat: 37.77, lng: -122.41, label: 'San Francisco', source: 'manual')));
    expect(page.items, isEmpty); expect(page.unavailable?.reason, UnavailableReason.market); expect(page.unavailable?.messageKey, 'market.unavailable.market');
  });

  test('unsupported India location: India recognised, area unavailable', () async {
    final page = await repo.getRestaurants(const DiscoveryQuery(scope: DiscoveryScope(countryCode: 'IN', lat: 26.8467, lng: 80.9462, label: 'Lucknow', source: 'manual')));
    expect(page.items, isEmpty); expect(page.unavailable?.reason, UnavailableReason.area);
    final paused = await repo.getRestaurants(const DiscoveryQuery(scope: DiscoveryScope(countryCode: 'IN', lat: 20.3893, lng: 72.9106, label: 'Vapi', source: 'manual')));
    expect(paused.unavailable?.reason, UnavailableReason.paused);
  });

  test('active city shows its restaurants', () async {
    final page = await repo.getRestaurants(const DiscoveryQuery(scope: DiscoveryScope(countryCode: 'IN', adminArea: 'Uttar Pradesh', lat: 28.628, lng: 77.3649, label: 'Sector 62, Noida', source: 'manual'), limit: 20));
    expect(page.unavailable, isNull); expect(page.items.length, greaterThanOrEqualTo(6));
  });

  test('journey in another country is unsupported; India journey works; uncovered India route says so', () async {
    setFixtureScope(FixtureScope.global); final us = await _journey('San Francisco', 'Los Angeles'); final south = await _journey('Bengaluru', 'Mysuru'); final north = await _journey('Delhi', 'Chandigarh');
    setFixtureScope(FixtureScope.india);
    expect((await repo.getRestaurantsForJourney(us, const DiscoveryQuery())).unavailable?.reason, UnavailableReason.market);
    expect((await repo.getRestaurantsForJourney(south, const DiscoveryQuery())).unavailable?.reason, UnavailableReason.route);
    final ok = await repo.getRestaurantsForJourney(north, const DiscoveryQuery(corridorM: 20000, limit: 20));
    expect(ok.unavailable, isNull); expect(ok.items.every((x) => x.restaurant.countryCode == 'IN'), isTrue);
  });

  test('location search lists active-market places first', () async {
    final r = await MockLocationRepository(_store()).search('lo');
    final firstForeign = r.indexWhere((l) => l.countryCode != 'IN');
    if (firstForeign >= 0) { expect(r.sublist(firstForeign).every((l) => l.countryCode != 'IN'), isTrue); }
    expect((await MockLocationRepository(_store()).search('del')).first.countryCode, 'IN');
  });

  test('a device set to another region still resolves to the India market scope', () {
    final s = DiscoveryState.resolveScope(locale: 'en-US');
    expect(s?.countryCode, 'IN'); expect(s?.source, 'market'); expect(s?.label, 'India');
    expect(DiscoveryState.resolveScope(locale: 'en-IN')?.countryCode, 'IN');
  });

  test('INR formatting uses the market locale; metric distance', () {
    expect(formatMoney(24900, 'INR'), '₹249.00'); expect(formatMoney(124900, 'INR'), '₹1,249.00'); expect(formatMoney(1249900, 'INR'), '₹12,499.00'); expect(formatMoney(12499900, 'INR'), '₹1,24,999.00');
    expect(marketAvailability.formatLocale, 'en_IN');
  });

  test('GLOBAL REGRESSION: the controlled global fixture scope still serves another currency / zone / unit system', () async {
    setFixtureScope(FixtureScope.global);
    final diner = repo.byId('kettleman-diner');
    expect(diner?.currency, 'USD'); expect(diner?.timezone, 'America/Los_Angeles'); expect(formatMoney(1199, 'USD'), r'$11.99');
    expect(repo.customerRestaurants.any((r) => r.countryCode == 'JP'), isTrue);
  });
}
