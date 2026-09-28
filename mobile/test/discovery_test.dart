import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:foodonthego/i18n/format.dart';
import 'package:foodonthego/i18n/markets.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/state/discovery_state.dart';

final now = DateTime.utc(2026, 9, 28, 6); // 11:30 IST · 23:00 PDT (prev day) · 07:00 BST · 15:00 JST · 10:00 GST

MockJourneyStore _store() => MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero);
Future<Journey> journeyBetween(String a, String b) async {
  final s = _store();
  final loc = MockLocationRepository(s);
  final o = (await loc.search(a)).first, d = (await loc.search(b)).first;
  final j = await MockJourneyRepository(s).create(origin: o, destination: d);
  final route = await MockRouteRepository(s).getRoute(o, d);
  return j.copyWith(route: route, status: JourneyRecordStatus.routeAvailable);
}

GlobalRestaurant byId(String id) => globalRestaurants.firstWhere((r) => r.id == id);

void main() {
  final repo = MockRestaurantRepository(latency: Duration.zero);

  group('global readiness', () {
    test('TEST 4 currency formatting adapts (ISO 4217, minor units)', () {
      expect(formatMoney(1250, 'INR', locale: 'en_IN'), '₹12.50');
      expect(formatMoney(1250, 'USD', locale: 'en_US'), '\$12.50');
      expect(formatMoney(1250, 'JPY', locale: 'ja_JP'), contains('1,250'));
      expect(formatMoney(1250, 'EUR', locale: 'fr_FR'), contains('12,50'));
      expect(priceLevelLabel(2, 'GBP', locale: 'en_GB'), '££');
    });
    test('TEST 6 / 7 metric vs imperial from the same metres', () {
      expect(formatDistance(1500, UnitSystem.metric), '1.5 km');
      expect(formatDistance(1500, UnitSystem.imperial), '0.9 mi');
      expect(formatDistance(350, UnitSystem.metric), '350 m');
      expect(resolveUnitSystem(null, 'US'), UnitSystem.imperial);
      expect(resolveUnitSystem(null, 'JP'), UnitSystem.metric);
      expect(resolveUnitSystem(UnitSystem.imperial, 'JP'), UnitSystem.imperial);
      expect(marketFor('ZZ').unitSystem, UnitSystem.metric);
    });
    test('TEST 5 opening status uses the restaurant zone (dev offset table)', () {
      expect(computeAvailability(byId('gilroy-garlic'), now).status, AvailabilityStatus.closed);   // 23:00 PDT
      expect(computeAvailability(byId('burger-hub'), now).status, AvailabilityStatus.open);       // 11:30 IST
      expect(computeAvailability(byId('kettleman-diner'), now).status, AvailabilityStatus.open);  // 24 h
      expect(computeAvailability(byId('grapevine-burgers'), DateTime.utc(2026, 9, 28, 7, 30)).status, AvailabilityStatus.closingSoon); // 00:30 PDT overnight
      final soon = computeAvailability(byId('gilroy-garlic'), DateTime.utc(2026, 9, 28, 12, 30)); // 05:30 PDT
      expect(soon.status, AvailabilityStatus.openingSoon);
      expect(formatLocalTime(soon.nextChangeAt!, 'America/Los_Angeles', locale: 'en_US').replaceAll(RegExp(r'\s'), ' '), '6:00 AM');
      expect(computeAvailability(byId('wok-express'), now).status, AvailabilityStatus.temporarilyClosed);
      expect(zoneLabel('Asia/Kolkata'), 'GMT+5:30');
    });
    test('TEST 8 Unicode names preserved and searchable', () async {
      expect(normalize('Café Élysée'), 'cafe elysee');
      Future<List<String>> names(String q) async => (await repo.getRestaurants(DiscoveryQuery(search: q, now: now, limit: 50))).items.map((x) => x.restaurant.name).toList();
      expect(await names('一風堂'), contains('一風堂 静岡店'));
      expect(await names('ippudo'), contains('一風堂 静岡店'));
      expect(await names('elysee'), contains('Café Élysée des Routes'));
      expect(await names('البيت'), contains('مطعم البيت الشامي'));
    });
  });

  group('route-aware discovery', () {
    test('TEST 1 short journey Dubai → Abu Dhabi', () async {
      final j = await journeyBetween('dubai', 'abu dhabi');
      final page = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now));
      expect(page.items.map((x) => x.restaurant.id).toSet(), {'al-bait-al-shami', 'ghantoot-karak'});
      final bait = page.items.firstWhere((x) => x.restaurant.id == 'al-bait-al-shami');
      expect(bait.distanceFromRouteM, greaterThan(0));
      expect(bait.detourDurationMin, greaterThan(0));
      expect(bait.routePosition, inExclusiveRange(0, 1));
      expect(page.corridorM, marketFor('AE').corridorM);
    });
    test('TEST 2 long journey Delhi → Mumbai stays bounded + paginated', () async {
      final j = await journeyBetween('delhi', 'mumbai');
      final p1 = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now, limit: 4));
      expect(p1.total, 6); expect(p1.items.length, 4); expect(p1.nextCursor, 'c4');
      final p2 = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now, limit: 4, cursor: p1.nextCursor));
      expect(p2.items.length, 2); expect(p2.nextCursor, isNull);
      expect([...p1.items, ...p2.items].any((x) => x.restaurant.id == 'burger-hub'), isFalse);
    });
    test('TEST 3 different country: US results only, US formatting', () async {
      final j = await journeyBetween('san francisco', 'los angeles');
      final page = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now));
      expect(page.items.length, greaterThanOrEqualTo(3));
      for (final x in page.items) {
        expect(x.restaurant.countryCode, 'US'); expect(x.restaurant.currency, 'USD'); expect(x.restaurant.timezone, 'America/Los_Angeles');
        expect(x.restaurant.address.formatted, isNot(matches(RegExp('₹|Pradesh|PIN'))));
      }
      expect(formatDistance(page.items.first.distanceFromRouteM!, resolveUnitSystem(null, 'US')), matches(RegExp(r'mi|ft')));
    });
    test('TEST 9 data-driven filters', () async {
      expect(repo.getFilterDefinitions(null).any((d) => d.id == 'distanceFromRoute'), isFalse);
      final j = await journeyBetween('chandigarh', 'jammu');
      expect(repo.getFilterDefinitions(j).any((d) => d.id == 'distanceFromRoute' && d.journeyOnly), isTrue);
      expect(repo.getFilterDefinitions(null).firstWhere((d) => d.id == 'cuisine').options.map((o) => o.value), containsAll(['Café', 'ラーメン', 'شامي']));
      final all = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now));
      expect(all.items.map((x) => x.restaurant.id).toSet(), {'dhaba-junction-ropar', 'hoshiarpur-sweets', 'pathankot-rasoi'});
      final veg = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now, filters: {'dietary': ['Vegetarian']}));
      expect(veg.items.map((x) => x.restaurant.id).toSet(), {'dhaba-junction-ropar', 'hoshiarpur-sweets'});
      final open = await repo.getRestaurants(DiscoveryQuery(now: DateTime.utc(2026, 9, 28, 22), filters: {'openNow': true}, limit: 50)); // 03:30 IST
      expect(open.items.every((x) => x.availability.isOpen), isTrue);
      expect(open.items.any((x) => x.restaurant.id == 'dhaba-junction-ropar'), isTrue);
    });
    test('TEST 10 sorting', () async {
      final byRating = (await repo.getRestaurants(DiscoveryQuery(now: now, sort: SortKey.highestRated, limit: 50))).items.map((x) => x.restaurant.rating).toList();
      expect(byRating, [...byRating]..sort((a, b) => b.compareTo(a)));
      final byPrep = (await repo.getRestaurants(DiscoveryQuery(now: now, sort: SortKey.fastestPickup, limit: 50))).items.map((x) => x.restaurant.prepTimeMin).toList();
      expect(byPrep, [...byPrep]..sort());
      final j = await journeyBetween('san francisco', 'los angeles');
      final byDetour = (await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now, sort: SortKey.lowestDetour))).items.map((x) => x.detourDurationMin!).toList();
      expect(byDetour, [...byDetour]..sort());
    });
    test('TEST 13 sparse coverage → empty page, not an error', () async {
      final j = await journeyBetween('bengaluru', 'mysuru');
      final page = await repo.getRestaurantsForJourney(j, DiscoveryQuery(now: now));
      expect(page.items, isEmpty); expect(page.total, 0);
    });
  });

  group('DiscoveryState', () {
    test('TEST 12 no journey → general results; bind journey → route-aware; widen; error + retry', () async {
      final r = MockRestaurantRepository(latency: Duration.zero);
      final ds = DiscoveryState(repository: r);
      await ds.bind(null);
      expect(ds.status, DiscoveryStatus.ready);
      expect(ds.total, globalRestaurants.length);
      expect(ds.nextCursor, isNotNull);
      final n = ds.items.length;
      await ds.loadMore();
      expect(ds.items.length, greaterThan(n));
      await ds.bind(await journeyBetween('bengaluru', 'mysuru'));
      expect(ds.items, isEmpty);
      expect(ds.corridorM, marketFor('IN').corridorM);
      ds.widenCorridor();
      await Future<void>.delayed(Duration.zero);
      expect(ds.corridorM, marketFor('IN').corridorM * 2);
      r.fail = true;
      await ds.retry();
      expect(ds.status, DiscoveryStatus.error);
      r.fail = false;
      await ds.retry();
      expect(ds.status, DiscoveryStatus.ready);
    });
    test('filters + sort + selection', () async {
      final ds = DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero));
      await ds.bind(null);
      ds.setFilter('openNow', true);
      await Future<void>.delayed(Duration.zero);
      expect(ds.items.every((x) => x.availability.isOpen), isTrue);
      ds.clearFilters();
      await Future<void>.delayed(Duration.zero);
      ds.setSort(SortKey.highestRated);
      await Future<void>.delayed(Duration.zero);
      expect(ds.items.first.restaurant.rating, 4.8);
      ds.select('ippudo-shizuoka');
      expect(ds.selectedId, 'ippudo-shizuoka');
      expect(ds.units, UnitSystem.metric);
    });
  });
}
