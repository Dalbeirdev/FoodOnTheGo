import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/state/journey_state.dart';

MockJourneyStore store() => MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero);
Future<JourneyLocation> place(MockLocationRepository r, String name) async => (await r.search(name)).first;

void main() {
  group('mock journey repositories', () {
    test('location search by name, alias and sub; empty for short / nowhere', () async {
      final r = MockLocationRepository(store());
      expect((await r.search('Chandigarh')).map((l) => l.name), ['Chandigarh', 'Chandigarh Railway Station', 'Chandigarh Airport']);
      expect((await r.search('bangalore')).first.name, 'Bengaluru');
      expect(await r.search('nowhere'), isEmpty);
      expect(await r.search('c'), isEmpty);
    });
    test('search failure switch', () async {
      final s = store()..failResources.add('location');
      expect(() => MockLocationRepository(s).search('Jammu'), throwsA(isA<JourneyException>()));
    });
    test('route corridor Chandigarh → Jammu with waypoints and estimate flag', () async {
      final s = store();
      final loc = MockLocationRepository(s);
      final route = await MockRouteRepository(s).getRoute(await place(loc, 'Chandigarh'), await place(loc, 'Jammu'));
      expect(route.waypoints, ['Ropar', 'Hoshiarpur', 'Pathankot']);
      expect(route.distanceKm, inInclusiveRange(250, 400));
      expect(route.isEstimate, isTrue);
      final back = await MockRouteRepository(s).getRoute(await place(loc, 'Jammu'), await place(loc, 'Chandigarh'));
      expect(back.waypoints, ['Pathankot', 'Hoshiarpur', 'Ropar']);
    });
    test('no route to the island test case', () async {
      final s = store();
      final loc = MockLocationRepository(s);
      expect(() async => MockRouteRepository(s).getRoute(await place(loc, 'Chandigarh'), await place(loc, 'Port Blair')), throwsA(predicate((e) => e is JourneyException && e.code == JourneyErrorCode.noRoute)));
    });
    test('journeys: create, recent (deduplicated), remove, get', () async {
      final s = store();
      final loc = MockLocationRepository(s);
      final repo = MockJourneyRepository(s);
      final a = await repo.create(origin: await place(loc, 'Chandigarh'), destination: await place(loc, 'Jammu'));
      await repo.create(origin: await place(loc, 'Chandigarh'), destination: await place(loc, 'Jammu'));
      final b = await repo.create(origin: await place(loc, 'Delhi'), destination: await place(loc, 'Jaipur'), departureAt: DateTime(2026, 10, 1, 9));
      expect(a.status, JourneyRecordStatus.ready);
      expect(a.originLat, 30.7333);
      expect(a.routeGeometryRef, isNull);
      final recent = await repo.recent();
      expect(recent.length, 2);
      expect(recent.first.id, b.id);
      expect(recent.first.departureAt, DateTime(2026, 10, 1, 9));
      await repo.remove(b.id);
      expect((await repo.recent()).length, 1);
      expect(await repo.get('missing'), isNull);
    });
    test('validateJourney covers missing and same locations', () async {
      final loc = MockLocationRepository(store());
      final c = await place(loc, 'Chandigarh');
      expect(validateJourney(null, null).keys, containsAll(['origin', 'destination']));
      expect(validateJourney(c, c)['destination'], contains('different'));
      expect(validateJourney(c, await place(loc, 'Jammu')), isEmpty);
    });
  });

  group('JourneyState', () {
    test('TEST 1 Chandigarh → Jammu: editing → route available', () async {
      final s = store();
      final js = JourneyState(repositories: JourneyRepositories.mock(s));
      final loc = MockLocationRepository(s);
      final seen = <JourneyStatus>[];
      js.addListener(() => seen.add(js.status));
      js.setOrigin(await place(loc, 'Chandigarh'));
      js.setDestination(await place(loc, 'Jammu'));
      final j = await js.plan();
      expect(j, isNotNull);
      expect(js.status, JourneyStatus.routeAvailable);
      expect(js.journey!.route!.waypoints, contains('Pathankot'));
      expect(seen, containsAllInOrder([JourneyStatus.editing, JourneyStatus.validating, JourneyStatus.ready, JourneyStatus.routeLoading, JourneyStatus.routeAvailable]));
      expect(js.recent.length, 1);
      expect((await js.recentLocations()).map((l) => l.name), containsAll(['Chandigarh', 'Jammu']));
    });
    test('TEST 2 swap exchanges origin and destination', () async {
      final s = store();
      final js = JourneyState(repositories: JourneyRepositories.mock(s));
      final loc = MockLocationRepository(s);
      js.setOrigin(await place(loc, 'Chandigarh'));
      js.setDestination(await place(loc, 'Jammu'));
      js.swap();
      expect(js.origin!.name, 'Jammu');
      expect(js.destination!.name, 'Chandigarh');
      await js.plan();
      expect(js.journey!.route!.waypoints, ['Pathankot', 'Hoshiarpur', 'Ropar']);
    });
    test('TEST 3 same location rejected, TEST 4 missing origin rejected', () async {
      final s = store();
      final js = JourneyState(repositories: JourneyRepositories.mock(s));
      final loc = MockLocationRepository(s);
      final c = await place(loc, 'Chandigarh');
      js.setOrigin(c); js.setDestination(c);
      expect(await js.plan(), isNull);
      expect(js.errors['destination'], contains('different'));
      expect(js.status, JourneyStatus.editing);
      js.setOrigin(null);
      js.setDestination(await place(loc, 'Jammu'));
      expect(await js.plan(), isNull);
      expect(js.errors['origin'], isNotNull);
      expect(js.journey, isNull);
    });
    test('route failure → error with retry; retry recovers', () async {
      final s = store();
      final js = JourneyState(repositories: JourneyRepositories.mock(s));
      final loc = MockLocationRepository(s);
      js.setOrigin(await place(loc, 'Mumbai'));
      js.setDestination(await place(loc, 'Pune'));
      s.failResources.add('route');
      await js.plan();
      expect(js.status, JourneyStatus.error);
      expect(js.error, contains('Route preparation failed'));
      s.failResources.clear();
      await js.retryRoute();
      expect(js.status, JourneyStatus.routeAvailable);
      expect(js.journey!.route!.waypoints, ['Panvel', 'Lonavala']);
    });
    test('load by id restores a stored journey; unknown id → null', () async {
      final s = store();
      final js = JourneyState(repositories: JourneyRepositories.mock(s));
      final loc = MockLocationRepository(s);
      js.setOrigin(await place(loc, 'Delhi'));
      js.setDestination(await place(loc, 'Jaipur'));
      final j = (await js.plan())!;
      final fresh = JourneyState(repositories: JourneyRepositories.mock(s));
      final loaded = await fresh.load(j.id);
      expect(loaded!.route!.distanceKm, j.route!.distanceKm);
      expect(fresh.status, JourneyStatus.routeAvailable);
      expect(await fresh.load('jrn-missing'), isNull);
    });
    test('useAgain + removeRecent', () async {
      final s = store();
      final js = JourneyState(repositories: JourneyRepositories.mock(s));
      final loc = MockLocationRepository(s);
      js.setOrigin(await place(loc, 'Bengaluru'));
      js.setDestination(await place(loc, 'Mysuru'));
      final j = (await js.plan())!;
      js.reset();
      expect(js.status, JourneyStatus.noJourney);
      js.useAgain(j);
      expect(js.origin!.name, 'Bengaluru');
      expect(js.status, JourneyStatus.editing);
      await js.removeRecent(j.id);
      expect(js.recent, isEmpty);
    });
  });
}
