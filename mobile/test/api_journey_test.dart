/// Journeys on the backend (Module 26): the mapping of the journey API onto the app model, what the repositories send
/// (one form per journey end, the guest token as a header — never a coordinate or a token in a URL), the error
/// vocabulary, the discovery query, and the two states the screens are built on.
/// Payloads are in the shape of the real API (OpenAPI: PlaceSuggestion, ResolvedPlace, Journey, JourneyRestaurantPage).
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart' show MemoryKeyValueStore;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/api_restaurants.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:foodonthego/journey/api_journey.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';

import 'support/journey_api_fixtures.dart';

void main() {
  group('mapping — the journey API onto the app model', () {
    test('a suggestion is text only; resolving gives it coordinates, a time zone and the coverage answer', () {
      final s = toSuggestion(suggestionDto());
      expect([s.name, s.lat, s.lng, s.source, s.placeId, s.countryCode], ['Sector 62, Noida', null, null, 'place', 'fake:noida-62', 'IN']);
      final r = toResolved(s, resolvedDto());
      expect([r.lat, r.lng, r.timezone, r.coverage?.supported, r.coverage?.serviceArea], [28.628, 77.3649, 'Asia/Kolkata', true, 'Noida · Sector 62 & NH24']);
      expect(toResolved(s, resolvedDto(supported: false)).coverage?.supported, false);
    });

    test('a journey carries the provider route as [lat, lng] geometry, its provenance, coverage, search bounds and expiry', () {
      final j = toJourney(journeyDto(flags: ['TOLLS']));
      expect([j.id, j.backend, j.owner, j.status], [journeyId, true, 'guest', JourneyRecordStatus.routeAvailable]);
      expect([j.route!.mode, j.route!.provider, j.route!.distanceM, j.route!.durationS, j.route!.durationMin, j.route!.isEstimate, j.route!.trafficAware], ['development', 'fake', 9624, 990, 17, true, false]);
      expect(j.route!.geometry.first, [28.628, 77.3649]); // GeoJSON is [lng, lat]; the app draws [lat, lng]
      expect(j.route!.geometry.length, 5);
      expect(j.route!.flags, ['TOLLS']);
      expect([j.coverage!.covered, j.coverage!.serviceableAreas], [true, 3]);
      expect([j.search!.corridorM, j.search!.corridorMinM, j.search!.corridorMaxM, j.search!.maxDetourMin, j.search!.maxDetourMaxMin, j.search!.candidateCap], [3000, 500, 10000, 15, 60, 40]);
      expect(j.expired, false);
      expect(j.departureAt, isNull); // "leaving now" is not a chosen departure
      expect(toJourney(journeyDto(expiresAt: DateTime.now().toUtc().subtract(const Duration(minutes: 1)))).expired, true);
      expect(toJourney(journeyDto(mode: 'live')).route!.isEstimate, false);
    });

    test('one end of a journey is sent in exactly one form — and never with an owner, a market or a route', () {
      expect(toEndBody(place62), {'place_id': 'fake:noida-62'}); // the app's copy of the coordinates is not sent
      expect(toEndBody(const JourneyLocation(id: 'addr-1', name: 'Home', sub: '', kind: LocationKind.saved, lat: 28.6, lng: 77.3, source: 'saved-address', savedLocationId: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e')), {'saved_location_id': 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', 'label': 'Home'});
      expect(toEndBody(const JourneyLocation(id: 'device-current', name: 'Current location', sub: 'From this device', kind: LocationKind.current, lat: 28.61234, lng: 77.23456, source: 'device')), {'lat': 28.61234, 'lng': 77.23456, 'label': 'Current location'});
      // A searched place that comes back from the recent places travels as its id again; one without an id as coordinates.
      expect(toEndBody(const JourneyLocation(id: 'recent-2', name: 'Sector 18, Noida', sub: '', kind: LocationKind.recent, lat: 28.5708, lng: 77.3261, source: 'recent', placeId: 'fake:noida-18')), {'place_id': 'fake:noida-18'});
      expect(toEndBody(const JourneyLocation(id: 'recent-1', name: 'Sector 18 Metro', sub: '', kind: LocationKind.recent, lat: 28.57, lng: 77.32, source: 'recent', formattedAddress: 'Sector 18 Metro Station, Noida')), {'lat': 28.57, 'lng': 77.32, 'label': 'Sector 18 Metro', 'formatted_address': 'Sector 18 Metro Station, Noida'});
    });

    test('a result describes the restaurant at the expected arrival, with the provider figures and nothing invented', () {
      final open = toRouteResult(rowDto('burger-hub', 'Burger Hub'));
      expect([open.atArrival, open.orderableAtArrival, open.detourDurationMin, open.distanceFromRouteM, open.routePosition], [true, true, 2, 63, .1]);
      expect(open.estimatedArrival, DateTime.utc(2026, 10, 5, 11, 12, 7));
      expect(open.estimatedPickupReady, isNull); // no ready-by promise: pickup slots are a later module
      expect([open.availability.status, open.availability.acceptingOrders], [AvailabilityStatus.open, true]);
      // The development fixtures know "burger-hub" with a sample rating: along a backend journey there is none.
      expect([open.restaurant.rating, open.restaurant.reviewCount, open.restaurant.ratingIsSample], [0, 0, false]);

      expect(toRouteResult(rowDto('a', 'A', closesAt: '2026-10-05T11:40:00+00:00')).availability.status, AvailabilityStatus.closingSoon); // closes within 45 minutes of arriving
      expect(toRouteResult(rowDto('a', 'A', open: false, state: 'CLOSED', opensNextAt: '2026-10-05T12:00:00+00:00')).availability.status, AvailabilityStatus.openingSoon);
      expect(toRouteResult(rowDto('a', 'A', open: false, state: 'CLOSED', opensNextAt: '2026-10-06T03:00:00+00:00')).availability.status, AvailabilityStatus.closed);
      expect(toRouteResult(rowDto('a', 'A', open: false, state: 'TEMPORARILY_CLOSED')).availability.status, AvailabilityStatus.temporarilyClosed);
      final paused = toRouteResult(rowDto('a', 'A', accepting: false, reason: 'NOT_ACCEPTING_ORDERS'));
      expect([paused.availability.isOpen, paused.availability.acceptingOrders, paused.orderableAtArrival], [true, false, false]);
      expect(toRouteResult(rowDto('a', 'A', reason: 'AREA_UNAVAILABLE')).availability.acceptingOrders, false);
    });

    test('the discovery query sends only what the API applies, inside the journey bounds', () {
      final j = toJourney(journeyDto());
      expect(toDiscoveryQuery(j, const DiscoveryQuery())..removeWhere((_, v) => v == null), {'sort': 'recommended', 'page[number]': '1', 'page[size]': '6'});
      final q = toDiscoveryQuery(j, const DiscoveryQuery(sort: SortKey.routeOrder, corridorM: 25000, search: '  burger  ', cursor: '3', limit: 99, filters: {'detourTime': 90.0, 'openNow': true, 'cuisine': ['pizza', 'burgers'], 'rating': 4.5, 'price': ['2'], 'dietary': ['Vegan']}));
      expect(q..removeWhere((_, v) => v == null), {'sort': 'route_order', 'corridor_width_meters': '10000', 'max_detour_minutes': '60', 'open_at_arrival': 'true', 'cuisine': 'pizza,burgers', 'q': 'burger', 'page[number]': '3', 'page[size]': '25'});
      expect(toDiscoveryQuery(j, const DiscoveryQuery(corridorM: 100))['corridor_width_meters'], '500');
      // "Highest rated" is not an ordering of a journey search (no reviews backend): it falls back to the default.
      expect(toDiscoveryQuery(j, const DiscoveryQuery(sort: SortKey.highestRated))['sort'], 'recommended');
      expect(journeySorts(j), [SortKey.recommended, SortKey.lowestDetour, SortKey.routeOrder, SortKey.nearestToRoute, SortKey.fastestPickup]);
    });
  });

  group('errors', () {
    test('the backend vocabulary becomes what the journey screens say', () {
      JourneyException of(int status, String code, [Map<String, List<String>> fields = const {}]) => toJourneyException(ApiException(status, 'server text', code: code, errors: fields));
      expect(of(422, 'route_not_found').code, JourneyErrorCode.noRoute);
      expect(of(422, 'route_not_found').message, 'No road route is available between these locations.');
      expect(of(422, 'market_unsupported').code, JourneyErrorCode.marketUnsupported);
      expect(of(422, 'cross_border_unsupported').code, JourneyErrorCode.crossBorder);
      expect(of(404, 'place_not_found').code, JourneyErrorCode.placeNotFound);
      expect(of(410, 'journey_expired').code, JourneyErrorCode.expired);
      expect(of(404, 'journey_not_found').code, JourneyErrorCode.notFound);
      expect(of(404, 'not_found').code, JourneyErrorCode.notFound);
      expect(of(503, 'routing_temporarily_unavailable').code, JourneyErrorCode.providerUnavailable);
      expect(of(503, 'places_temporarily_unavailable').message, contains('temporarily unavailable'));
      expect(of(503, 'provider_rate_limited').code, JourneyErrorCode.rateLimited);
      expect(of(429, 'rate_limited').code, JourneyErrorCode.rateLimited);
      expect(of(500, 'internal_error').code, JourneyErrorCode.providerUnavailable);
      final invalid = of(422, 'validation_failed', {'destination': ['The destination must differ from the origin.']});
      expect([invalid.code, invalid.message], [JourneyErrorCode.invalid, 'The destination must differ from the origin.']);
    });

    test('a network failure and an unknown error stay honest', () {
      expect(toJourneyException(ApiException(0, 'offline')).code, JourneyErrorCode.network);
      expect(toJourneyException(ApiException(0, 'offline')).message, startsWith('Cannot reach FoodOnTheGo'));
      final other = toJourneyException(StateError('boom'), 'Location search failed. Please try again.');
      expect([other.code, other.message], [JourneyErrorCode.unexpected, 'Location search failed. Please try again.']);
    });
  });

  group('repositories — what is sent, what is kept', () {
    test('search asks the backend (2+ characters) and resolve posts the place id only', () async {
      final b = Backend()..reply = (r) => r.url.path.endsWith('/places/search') ? json(200, {'data': [suggestionDto(), suggestionDto('fake:noida-18', 'Sector 18, Noida')], 'country_code': 'IN', 'provider': {'name': 'fake', 'mode': 'development'}}) : json(200, resolvedDto());
      final repo = ApiLocationRepository(b.api);
      expect(await repo.search('s'), isEmpty);
      expect(b.calls, isEmpty);
      final found = await repo.search('  sector ');
      expect(found.map((l) => l.name), ['Sector 62, Noida', 'Sector 18, Noida']);
      expect(b.last('GET', '/places/search').url.queryParameters, {'q': 'sector'});
      expect(b.last('GET', '/places/search').headers.containsKey('Authorization'), false); // a guest searches without a session

      final resolved = await repo.resolve(found.first);
      expect(b.body(b.last('POST', '/places/resolve')), {'place_id': 'fake:noida-62'});
      expect([resolved.lat, resolved.coverage?.supported], [28.628, true]);
      final before = b.calls.length;
      expect(await repo.resolve(resolved), same(resolved)); // already has coordinates: nothing is asked again
      expect(b.calls.length, before);
    });

    test('guests keep recent places for this run of the app only — and only places they searched for; customers read the backend list', () async {
      final b = Backend();
      final repo = ApiLocationRepository(b.api);
      await repo.remember(place62);
      await repo.remember(const JourneyLocation(id: 'device-current', name: 'Current location', sub: 'From this device', kind: LocationKind.current, lat: 28.61234, lng: 77.23456, source: 'device'));
      await repo.remember(const JourneyLocation(id: 'addr-1', name: 'Home', sub: '', kind: LocationKind.saved, lat: 28.6, lng: 77.3, source: 'saved-address', savedLocationId: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e'));
      expect((await repo.recent()).map((l) => [l.name, l.kind]), [['Sector 62, Noida', LocationKind.recent]]);
      expect(b.calls, isEmpty); // nothing was sent anywhere
      expect(await b.store.read(JourneyTokens.storageKey), isNull);

      b.customerToken = 'customer-token';
      b.reply = (_) => json(200, [
            {'id': 'r1', 'label': 'Sector 18 Metro', 'formatted_address': 'Sector 18 Metro Station, Noida', 'location': {'latitude': 28.5708, 'longitude': 77.3261}, 'place': null, 'country_code': 'IN', 'timezone': 'Asia/Kolkata'},
            {'id': 'r2', 'label': 'Sector 18, Noida', 'formatted_address': null, 'location': {'latitude': 28.5708, 'longitude': 77.3261}, 'place': {'provider': 'fake', 'id': 'fake:noida-18'}, 'country_code': 'IN', 'timezone': 'Asia/Kolkata'},
          ]);
      final recent = await repo.recent();
      expect(recent.map((l) => [l.name, l.source, l.placeId]), [['Sector 18 Metro', 'recent', null], ['Sector 18, Noida', 'recent', 'fake:noida-18']]);
      expect(b.last('GET', '/customer/recent-locations').headers['Authorization'], 'Bearer customer-token');
      await repo.remember(place18); // the backend records the places of a planned journey itself
      expect(b.calls.where((c) => c.method != 'GET'), isEmpty);
    });

    test('planning sends both ends and the departure; the guest token is kept in secure storage and sent as a header, never in a URL', () async {
      final b = Backend()..reply = (r) => r.method == 'POST' ? json(201, journeyDto()) : json(200, journeyDto(withToken: false));
      final repo = ApiJourneyRepository(b.api);
      final departure = DateTime.utc(2026, 10, 6, 3, 30);
      final j = await repo.create(origin: place62, destination: place18, departureAt: departure);
      expect(b.body(b.last('POST', '/journeys/plan')), {'origin': {'place_id': 'fake:noida-62'}, 'destination': {'place_id': 'fake:noida-18'}, 'departure_at': '2026-10-06T03:30:00.000Z'});
      expect([j.id, j.route!.distanceM, j.backend], [journeyId, 9624, true]);
      expect(await b.api.tokens.get(journeyId), guestToken);
      expect(await b.store.read(JourneyTokens.storageKey), contains(guestToken)); // survives the app being put in the background

      await repo.get(journeyId);
      final read = b.last('GET', '/journeys/$journeyId');
      expect(read.headers['X-Journey-Token'], guestToken);
      expect(read.url.query, isEmpty);
      expect(b.calls.every((c) => !c.url.toString().contains(guestToken) && !c.url.toString().contains('28.6')), true);
      expect((await repo.recent()).map((x) => x.id), [journeyId]); // "recent journeys" is this run of the app only
      await repo.remove(journeyId);
      expect([await repo.recent(), await b.api.tokens.get(journeyId)], [isEmpty, isNull]);
    });

    test('a customer\'s journey needs no guest token: the session is the proof', () async {
      final b = Backend()..customerToken = 'customer-token'..reply = (_) => json(201, journeyDto(owner: 'customer'));
      final j = await ApiJourneyRepository(b.api).create(origin: const JourneyLocation(id: 'addr-1', name: 'Home', sub: '', kind: LocationKind.saved, lat: 28.6, lng: 77.3, source: 'saved-address', savedLocationId: 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e'), destination: place18);
      final sent = b.last('POST', '/journeys/plan');
      expect(sent.headers['Authorization'], 'Bearer customer-token');
      expect(b.body(sent), {'origin': {'saved_location_id': 'df2d09ac-56ca-4dbe-be7f-ba5ea89df14e', 'label': 'Home'}, 'destination': {'place_id': 'fake:noida-18'}});
      expect([j.owner, await b.api.tokens.get(j.id), await b.store.read(JourneyTokens.storageKey)], ['customer', isNull, isNull]);
    });

    test('a journey that is not this visitor\'s is "not found"; an expired one says so — and keeps saying so (its token stays)', () async {
      final b = Backend()..reply = (_) => json(201, journeyDto());
      final repo = ApiJourneyRepository(b.api);
      await repo.create(origin: place62, destination: place18);
      b.reply = (_) => failure(404, 'journey_not_found');
      expect(await repo.get(journeyId), isNull);
      expect(await b.api.tokens.get(journeyId), guestToken); // "not found" may be a wrong link: the token stays
      b.reply = (_) => failure(410, 'journey_expired');
      await expectLater(repo.get(journeyId), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.expired)));
      // The backend says "expired" only to the owner: the token is sent again the next time the link is opened.
      expect(await b.api.tokens.get(journeyId), guestToken);
      await expectLater(repo.get(journeyId), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.expired)));
      expect(b.calls.last.headers['X-Journey-Token'], guestToken);
      b.reply = (_) => failure(503, 'routing_temporarily_unavailable');
      await expectLater(repo.get(journeyId), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.providerUnavailable)));
    });

    test('only the newest five guest tokens are kept; one is dropped an hour after its journey expired', () async {
      final tokens = JourneyTokens(MemoryKeyValueStore());
      final later = DateTime.now().add(const Duration(minutes: 30));
      for (var i = 1; i <= 6; i++) { await tokens.set('j$i', 't$i', later); }
      expect([await tokens.get('j1'), await tokens.get('j2'), await tokens.get('j6')], [isNull, 't2', 't6']);
      await tokens.set('justExpired', 'kept', DateTime.now().subtract(const Duration(minutes: 5)));
      expect(await tokens.headers('justExpired'), {'X-Journey-Token': 'kept'}); // the backend still answers "expired" to it
      await tokens.set('old', 'gone', DateTime.now().subtract(const Duration(minutes: 61)));
      expect([await tokens.get('old'), await tokens.headers('old')], [isNull, isEmpty]);
      expect(await tokens.headers('j6'), {'X-Journey-Token': 't6'});
    });
  });

  group('discovery along a backend journey', () {
    test('asks the backend with the token, maps the page and remembers the route context of what it found', () async {
      final b = Backend()..reply = (r) => r.method == 'POST' ? json(201, journeyDto()) : json(200, pageDto([rowDto('burger-hub', 'Burger Hub'), rowDto('pizza-point', 'Pizza Point', detourMin: 1, toRoute: 0, progress: 1)], last: 3, total: 6));
      final j = await ApiJourneyRepository(b.api).create(origin: place62, destination: place18);
      final page = await b.api.discover(j, const DiscoveryQuery(sort: SortKey.lowestDetour));
      final sent = b.last('GET', '/journeys/$journeyId/restaurants');
      expect(sent.headers['X-Journey-Token'], guestToken);
      expect(sent.url.queryParameters, {'sort': 'lowest_detour', 'page[number]': '1', 'page[size]': '6'});
      expect(page.items.map((x) => [x.restaurant.slug, x.detourDurationMin]), [['burger-hub', 2], ['pizza-point', 1]]);
      expect([page.total, page.nextCursor, page.corridorM, page.emptyReason, page.unavailable], [6, '2', 3000, isNull, isNull]);
      expect([page.searchMeta!.providerMode, page.searchMeta!.cached, page.searchMeta!.maxDetourMin, page.searchMeta!.detourRequested], ['development', false, 15, 6]);

      expect(b.api.storedContext(journeyId, 'burger-hub')?.detourDurationMin, 2);
      expect(b.api.storedContext(journeyId, 'spice-nest'), isNull); // not found by this journey: no figures, nothing estimated
      expect(b.api.storedContext('another-journey', 'burger-hub'), isNull);
    });

    test('tells the reasons for an empty list apart', () async {
      final b = Backend();
      final j = toJourney(journeyDto(owner: 'customer'));
      Future<ResultPage> empty(String reason, {String along = 'COVERED'}) { b.reply = (_) => json(200, pageDto([], emptyReason: reason, along: along, requested: 0)); return b.api.discover(j, const DiscoveryQuery()); }
      final coverage = await empty('NO_SERVICE_COVERAGE', along: 'NO_SERVICE_COVERAGE');
      expect([coverage.emptyReason, coverage.unavailable?.messageKey], [EmptyReason.coverage, 'market.unavailable.route']); // an answer, not an error
      expect((await empty('NO_RESTAURANTS_IN_CORRIDOR')).emptyReason, EmptyReason.corridor);
      expect((await empty('NO_REACHABLE_RESTAURANTS')).emptyReason, EmptyReason.unreachable);
      final filters = await empty('FILTERS');
      expect([filters.emptyReason, filters.unavailable], [EmptyReason.filters, isNull]);
    });

    test('an outage, a rate limit and an expired journey are errors with their own codes', () async {
      final b = Backend()..reply = (_) => json(201, journeyDto());
      final j = await ApiJourneyRepository(b.api).create(origin: place62, destination: place18);
      b.reply = (_) => failure(503, 'routing_temporarily_unavailable', 'Route planning is temporarily unavailable.', {'retry_after_seconds': 15});
      await expectLater(b.api.discover(j, const DiscoveryQuery()), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.providerUnavailable)));
      b.reply = (_) => failure(429, 'rate_limited', 'Too many requests.', {'retry_after_seconds': 30});
      await expectLater(b.api.discover(j, const DiscoveryQuery()), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.rateLimited)));
      b.reply = (_) => failure(410, 'journey_expired');
      await expectLater(b.api.discover(j, const DiscoveryQuery()), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.expired)));
      expect(await b.api.tokens.get(journeyId), guestToken);
    });
  });

  group('JourneyState on the backend repositories', () {
    test('plans in one step: the journey arrives with its route and no separate route lookup happens', () async {
      final b = Backend()..reply = (_) => json(201, journeyDto());
      final js = JourneyState(repositories: apiJourneyRepositories(b.api));
      expect(js.backend, true);
      js.setOrigin(place62); js.setDestination(place18);
      final seen = <JourneyStatus>[];
      js.addListener(() => seen.add(js.status));
      final j = await js.plan();
      expect([j?.id, js.status, js.journey?.route?.mode], [journeyId, JourneyStatus.routeAvailable, 'development']);
      expect(seen.contains(JourneyStatus.routeLoading), false);
      expect(b.calls.where((c) => c.method == 'POST').length, 1);
      expect(js.recent.map((x) => x.id), [journeyId]);
    });

    test('says why a journey cannot be planned, keeps no journey and plans again on retry', () async {
      final b = Backend()..reply = (_) => failure(422, 'route_not_found', 'No route.');
      final js = JourneyState(repositories: apiJourneyRepositories(b.api))..setOrigin(place62)..setDestination(place18);
      expect(await js.plan(), isNull);
      expect([js.status, js.errorCode, js.journey, js.error], [JourneyStatus.error, JourneyErrorCode.noRoute, isNull, 'No road route is available between these locations.']);

      b.reply = (_) => failure(503, 'routing_temporarily_unavailable');
      await js.plan();
      expect([js.errorCode, js.journey], [JourneyErrorCode.providerUnavailable, isNull]);
      b.reply = (_) => json(201, journeyDto());
      await js.retryRoute(); // a backend journey has no separate route step: "try again" plans it again
      expect([js.status, js.errorCode, b.calls.where((c) => c.url.path.endsWith('/journeys/plan')).length], [JourneyStatus.routeAvailable, isNull, 3]);
    });

    test('the same place twice is refused before anything is sent', () async {
      final b = Backend();
      final js = JourneyState(repositories: apiJourneyRepositories(b.api))..setOrigin(place62)..setDestination(place62);
      expect(await js.plan(), isNull);
      expect([js.errors['destination'], b.calls], ['Destination must be different from the starting point', isEmpty]);
    });

    test('opening a journey by id: unknown is null, an expired one throws its own code — also when the app already knows it is over', () async {
      final b = Backend()..reply = (_) => failure(404, 'journey_not_found');
      final js = JourneyState(repositories: apiJourneyRepositories(b.api));
      expect(await js.load(journeyId), isNull);
      b.reply = (_) => failure(410, 'journey_expired');
      await expectLater(js.load(journeyId), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.expired)));

      b.reply = (_) => json(201, journeyDto(expiresAt: DateTime.now().toUtc().subtract(const Duration(seconds: 5))));
      js..setOrigin(place62)..setDestination(place18);
      await js.plan();
      final before = b.calls.length;
      await expectLater(js.load(journeyId), throwsA(isA<JourneyException>().having((e) => e.code, 'code', JourneyErrorCode.expired)));
      expect(b.calls.length, before); // known to be over: the backend is not even asked
      js.planAgain();
      expect([js.status, js.journey, js.origin?.name, js.destination?.name], [JourneyStatus.editing, isNull, 'Sector 62, Noida', 'Sector 18, Noida']);
    });
  });

  group('DiscoveryState along a backend journey', () {
    setUp(() {
      restaurantModeOverride = 'api';
      apiRestaurantData = RestaurantSnapshot(
        restaurants: [ApiRestaurantRepository.toRestaurant(restaurantDto('burger-hub', 'Burger Hub')), ApiRestaurantRepository.toRestaurant(restaurantDto('pizza-point', 'Pizza Point', cuisines: [{'code': 'pizza', 'name': 'Pizza'}]))],
        cuisines: const ['Pizza', 'Burgers', 'Sushi'], cuisineCodes: const {'Pizza': 'pizza', 'Burgers': 'burgers', 'Sushi': 'sushi'}, loadedAt: DateTime.now(),
      );
    });
    tearDown(() { restaurantModeOverride = null; apiRestaurantData = null; });

    Future<(Backend, DiscoveryState, Journey)> bound() async {
      final b = Backend()..reply = (r) => r.method == 'POST' ? json(201, journeyDto()) : json(200, pageDto([rowDto('burger-hub', 'Burger Hub'), rowDto('pizza-point', 'Pizza Point', detourMin: 1, cuisines: [{'code': 'pizza', 'name': 'Pizza'}])]));
      final j = await ApiJourneyRepository(b.api).create(origin: place62, destination: place18);
      final ds = DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero), store: MemoryKeyValueStore(), journeys: b.api);
      await ds.bind(j, deviceLocale: 'en-IN');
      return (b, ds, j);
    }

    test('lists what the backend found and offers only the orderings and filters the backend applies', () async {
      final (b, ds, _) = await bound();
      expect([ds.backend, ds.status, ds.items.map((x) => x.restaurant.slug).toList(), ds.searchMeta?.providerMode], [true, DiscoveryStatus.ready, ['burger-hub', 'pizza-point'], 'development']);
      expect(ds.sorts, [SortKey.recommended, SortKey.lowestDetour, SortKey.routeOrder, SortKey.nearestToRoute, SortKey.fastestPickup]);
      // Cuisine options are taxonomy CODES of cuisines a listed restaurant has (no "Sushi"); no rating, price or dietary control.
      expect(ds.definitions.map((d) => [d.id, d.labelKey, d.options.map((o) => '${o.value}=${o.label}').toList()]), [
        ['cuisine', 'filter.cuisine', ['pizza=Pizza', 'burgers=Burgers']],
        ['openNow', 'filter.openAtArrival', <String>[]],
        ['detourTime', 'filter.detourTime', <String>[]],
      ]);
      expect([ds.corridorM, ds.corridorBounds], [3000, (500, 10000, 500)]);
      // The corridor is the journey's own until the customer moves it.
      expect(b.last('GET', '/restaurants').url.queryParameters.containsKey('corridor_width_meters'), false);
    });

    test('a slider that is dragged sends ONE request once it settles; the value on screen follows at once', () async {
      final (b, ds, _) = await bound();
      final before = b.calls.length;
      for (final m in [2500, 2000, 1500, 1000]) { ds.setCorridor(m); }
      expect([ds.corridorM, b.calls.length], [1000, before]);
      await Future<void>.delayed(const Duration(milliseconds: 450));
      expect(b.calls.length, before + 1);
      expect(b.last('GET', '/restaurants').url.queryParameters['corridor_width_meters'], '1000');

      ds.toggleOption('cuisine', 'pizza');
      ds.setFilter('openNow', true);
      await Future<void>.delayed(const Duration(milliseconds: 450));
      expect(b.calls.length, before + 2);
      expect(b.last('GET', '/restaurants').url.queryParameters, containsPair('cuisine', 'pizza'));
      expect(b.last('GET', '/restaurants').url.queryParameters, containsPair('open_at_arrival', 'true'));
    });

    test('an expired journey leaves no stale list; an outage keeps the list question open with its own code', () async {
      final (b, ds, _) = await bound();
      b.reply = (_) => failure(503, 'routing_temporarily_unavailable');
      ds.setSort(SortKey.routeOrder);
      await Future<void>.delayed(Duration.zero);
      await Future<void>.delayed(Duration.zero);
      expect([ds.status, ds.errorCode, ds.journeyGone], [DiscoveryStatus.error, JourneyErrorCode.providerUnavailable, isNull]);
      expect(ds.items, isNotEmpty); // the outage is said; what was listed is not thrown away

      b.reply = (_) => failure(410, 'journey_expired');
      await ds.retry();
      expect([ds.status, ds.errorCode, ds.journeyGone, ds.items, ds.total, ds.searchMeta], [DiscoveryStatus.error, JourneyErrorCode.expired, 'expired', isEmpty, 0, isNull]);
      ds.clearJourney();
      expect([ds.journey, ds.journeyGone, ds.status], [isNull, isNull, DiscoveryStatus.ready]);
    });

    test('pages are the backend\'s pages', () async {
      final (b, ds, _) = await bound();
      b.reply = (_) => json(200, pageDto([rowDto('burger-hub', 'Burger Hub')], last: 2, total: 2));
      await ds.retry();
      expect([ds.items.length, ds.nextCursor, ds.total], [1, '2', 2]);
      b.reply = (_) => json(200, pageDto([rowDto('pizza-point', 'Pizza Point')], page: 2, last: 2, total: 2, cached: true));
      await ds.loadMore();
      expect([ds.items.map((x) => x.restaurant.slug).toList(), ds.nextCursor, ds.searchMeta?.cached], [['burger-hub', 'pizza-point'], isNull, true]);
      expect(b.last('GET', '/restaurants').url.queryParameters['page[number]'], '2');
    });

    test('a development (in-app) journey still uses the in-app search', () async {
      final b = Backend();
      final ds = DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero), store: MemoryKeyValueStore(), journeys: b.api);
      final sketch = Journey(id: 'jrn-1', origin: place62, destination: place18, createdAt: DateTime.utc(2026, 10, 5), route: const RouteSummary(distanceKm: 10, durationMin: 17, geometry: [[28.628, 77.3649], [28.5708, 77.3261]], waypoints: []));
      await ds.bind(sketch, deviceLocale: 'en-IN');
      expect([ds.backend, b.calls], [false, isEmpty]);
      expect(ds.sorts.contains(SortKey.highestRated), true);
      expect(ds.sorts.contains(SortKey.routeOrder), false);
    });
  });
}
