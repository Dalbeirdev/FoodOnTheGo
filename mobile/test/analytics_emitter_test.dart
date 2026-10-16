/// The behavioural-event emitter (Module 38) — Android: the session the app makes up and when it ends, "once per
/// session", the batches and the body exactly as the endpoint takes it, the customer's token when there is one, the
/// registry that lets nothing else out, the local opt-out, the bounded buffer for a missing connection, and the
/// disabled mode that does nothing at all. The emitter is switched on explicitly here, with a fake HTTP layer, a fake
/// clock and fake timers: no test of the app has it on by default.
library;

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/analytics/analytics.dart';
import 'package:foodonthego/analytics/analytics_registry.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/core/app_config.dart';

import 'support/analytics_fakes.dart';

void main() {
  tearDown(Analytics.uninstall);

  group('the session', () {
    test('is a random UUID v4 made up on the first event and kept while the customer is active', () async {
      final a = AnalyticsRig(), b = AnalyticsRig();
      a.emitter.track('journey_search_started');
      b.emitter.track('journey_search_started');
      await a.emitter.ready;
      await b.emitter.ready;
      final first = a.emitter.sessionId!;
      expect(first, matches(uuidV4));
      expect(b.emitter.sessionId, isNot(first)); // made up, not derived from the device: two emitters never agree

      a.clock.advance(const Duration(minutes: 30)); // idle for exactly the limit is not "more than"
      a.emitter.track('journey_search_started');
      expect(a.emitter.sessionId, first);
      await a.emitter.flush();
      expect(a.net.bodies.single['session'], first);
      expect(a.net.events, hasLength(2));
    });

    test('is replaced after more than 30 minutes without an event', () async {
      final r = AnalyticsRig();
      r.emitter.track('journey_search_started');
      await r.emitter.ready;
      final first = r.emitter.sessionId!;
      r.clock.advance(const Duration(minutes: 30, seconds: 1));
      r.emitter.track('journey_search_started');
      final second = r.emitter.sessionId!;
      expect(second, isNot(first));
      expect(second, matches(uuidV4));
      await r.emitter.flush();
      // Each batch carries the events of ONE session, under that session.
      expect([for (final b in r.net.bodies) b['session']], [first, second]);
      expect([for (final b in r.net.bodies) (b['events'] as List).length], [1, 1]);
    });

    test('is replaced when it is older than 12 hours, however active it was', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      final seen = <String>[];
      for (var step = 0; step <= 37; step++) {
        r.emitter.track('journey_search_started');
        seen.add(r.emitter.sessionId!);
        r.clock.advance(const Duration(minutes: 20)); // never idle
        if (step % 10 == 9) await r.emitter.flush();
      }
      expect(seen.take(37).toSet(), hasLength(1)); // 0 … 12 h: the same session
      expect(seen[37], isNot(seen[0])); // 12 h 20 min: a new one
    });

    test('a quick restart of the app stays in the session — and still ends by the same rules', () async {
      final store = MemoryKeyValueStore();
      final first = AnalyticsRig(store: store);
      first.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, onceKey: 'restaurant_viewed:$restaurantId');
      await first.emitter.flush();
      final session = first.emitter.sessionId!;

      final restarted = AnalyticsRig(store: store, clock: TestClock(first.clock.now.add(const Duration(minutes: 5))));
      restarted.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, onceKey: 'restaurant_viewed:$restaurantId'); // already sent in this session
      restarted.emitter.track('menu_viewed', refs: {'restaurant': restaurantId}, onceKey: 'menu_viewed:$restaurantId');
      await restarted.emitter.flush();
      expect(restarted.net.bodies.single['session'], session);
      expect(restarted.net.names, ['menu_viewed']);

      final later = AnalyticsRig(store: store, clock: TestClock(first.clock.now.add(const Duration(hours: 2))));
      later.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, onceKey: 'restaurant_viewed:$restaurantId');
      await later.emitter.flush();
      expect(later.net.bodies.single['session'], isNot(session)); // idle for too long: a new session, and "once" starts again
      expect(later.net.names, ['restaurant_viewed']);
    });
  });

  group('once per session', () {
    test('an event with a once-key is recorded the first time only; without one, every time', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      for (var i = 0; i < 3; i++) {
        r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, onceKey: 'restaurant_viewed:$restaurantId');
        r.emitter.track('item_added_to_cart', refs: {'restaurant': restaurantId}, properties: {'quantity': 1});
      }
      r.emitter.track('restaurant_viewed', refs: {'restaurant': 'pizza-point'}, onceKey: 'restaurant_viewed:pizza-point');
      await r.emitter.flush();
      expect(r.net.names.where((n) => n == 'restaurant_viewed'), hasLength(2)); // one per restaurant
      expect(r.net.names.where((n) => n == 'item_added_to_cart'), hasLength(3)); // one per accepted add
    });

    test('the keys are forgotten when the session is replaced', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      r.emitter.track('menu_viewed', refs: {'restaurant': restaurantId}, onceKey: 'menu_viewed:$restaurantId');
      r.emitter.track('menu_viewed', refs: {'restaurant': restaurantId}, onceKey: 'menu_viewed:$restaurantId');
      expect(r.emitter.queued, 1);
      r.clock.advance(const Duration(minutes: 31));
      r.emitter.track('menu_viewed', refs: {'restaurant': restaurantId}, onceKey: 'menu_viewed:$restaurantId');
      expect(r.emitter.queued, 2);
    });

    test('the ten events of the app: seven are once per session, three are one per accepted action', () async {
      final r = AnalyticsRig();
      Analytics.install(r.emitter);
      await r.emitter.ready;
      for (var i = 0; i < 2; i++) {
        Analytics.journeySearchStarted();
        Analytics.journeyResultsViewed(journeyUuid, 3);
        Analytics.restaurantViewed(restaurantId, journeyId: journeyUuid);
        Analytics.menuViewed(restaurantId, journeyId: journeyUuid);
        Analytics.itemViewed(restaurantId, menuItemId);
        Analytics.itemAddedToCart(restaurantId, itemId: menuItemId, quantity: 2);
        Analytics.pickupViewed(restaurant: restaurantId);
        Analytics.pickupSelected(restaurant: restaurantId, asap: false, method: 'CURBSIDE', recommended: true);
        Analytics.checkoutStarted(checkoutId, restaurant: restaurantId);
        Analytics.paymentStarted(checkoutId, method: 'UPI');
      }
      await Analytics.flush();
      final counts = <String, int>{};
      for (final n in r.net.names) { counts[n] = (counts[n] ?? 0) + 1; }
      expect(counts, {
        'journey_search_started': 2, 'journey_results_viewed': 1, 'restaurant_viewed': 1, 'menu_viewed': 1, 'item_viewed': 1,
        'item_added_to_cart': 2, 'pickup_viewed': 1, 'pickup_selected': 2, 'checkout_started': 1, 'payment_started': 1,
      });
      expect(counts.keys.toSet(), analyticsEvents.keys.toSet()); // exactly the registry — nothing else exists
      Map<String, dynamic> first(String name) => r.net.events.firstWhere((e) => e['name'] == name);
      expect(first('journey_search_started').containsKey('refs'), isFalse);
      expect(first('journey_results_viewed'), containsPair('refs', {'journey': journeyUuid}));
      expect(first('journey_results_viewed'), containsPair('properties', {'results': 3}));
      expect(first('restaurant_viewed'), containsPair('refs', {'restaurant': restaurantId, 'journey': journeyUuid}));
      expect(first('restaurant_viewed'), containsPair('properties', {'origin': 'JOURNEY'}));
      expect(first('menu_viewed'), containsPair('refs', {'restaurant': restaurantId, 'journey': journeyUuid}));
      expect(first('item_viewed'), containsPair('refs', {'restaurant': restaurantId, 'item': menuItemId}));
      expect(first('item_added_to_cart'), containsPair('properties', {'quantity': 2}));
      expect(first('pickup_viewed'), containsPair('refs', {'restaurant': restaurantId}));
      expect(first('pickup_selected'), containsPair('properties', {'mode': 'SCHEDULED', 'method': 'CURBSIDE', 'recommended': true}));
      expect(first('checkout_started'), containsPair('refs', {'checkout': checkoutId, 'restaurant': restaurantId}));
      expect(first('payment_started'), containsPair('refs', {'checkout': checkoutId}));
      expect(first('payment_started'), containsPair('properties', {'method': 'UPI'}));
    });

    test('where the restaurant page was opened from, and a payment method the registry does not name', () async {
      final r = AnalyticsRig();
      Analytics.install(r.emitter);
      await r.emitter.ready;
      Analytics.restaurantViewed('burger-hub', listed: true);
      Analytics.restaurantViewed('pizza-point');
      Analytics.restaurantViewed('dhaba-junction-ropar', journeyId: 'j-local-1'); // a development journey: the context counts, its id is not sent
      Analytics.paymentStarted(checkoutId, method: 'PAY_LATER');
      Analytics.pickupSelected(asap: true); // nothing more is known: only the mode
      await Analytics.flush();
      final e = r.net.events;
      expect([for (final x in e.take(3)) (x['properties'] as Map)['origin']], ['BROWSE', 'DIRECT', 'JOURNEY']);
      expect(e[2]['refs'], {'restaurant': 'dhaba-junction-ropar'});
      expect(e[3]['properties'], {'method': 'OTHER'});
      expect(e[4]['properties'], {'mode': 'ASAP'});
      expect(e[4].containsKey('refs'), isFalse);
    });
  });

  group('batching and the body', () {
    test('events wait for ONE one-shot timer and go out together, exactly as the endpoint takes them', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      expect(r.timers.started, 0); // nothing queued: no timer
      r.emitter.track('journey_search_started');
      r.clock.advance(const Duration(seconds: 1));
      r.emitter.track('journey_results_viewed', refs: {'journey': journeyUuid.toUpperCase()}, properties: {'results': 0});
      r.emitter.track('restaurant_viewed', refs: {'restaurant': 'burger-hub', 'journey': journeyUuid}, properties: {'origin': 'JOURNEY'});
      expect([r.timers.started, r.timers.pending.length, r.net.calls.length], [1, 1, 0]);
      expect(r.timers.pending.single.delay, AnalyticsEmitter.defaultFlushAfter);

      r.timers.fire();
      await r.emitter.flush();
      expect([r.net.calls.length, r.timers.pending.length, r.emitter.timerPending, r.emitter.queued], [1, 0, false, 0]);

      final request = r.net.calls.single;
      expect([request.method, request.url.toString()], ['POST', 'http://api.test/api/v1/analytics/events']);
      expect(request.headers['Content-Type'], startsWith('application/json'));
      final body = r.net.bodies.single;
      expect(body.keys.toList(), ['session', 'platform', 'app_version', 'events']); // unknown top-level fields are refused by the backend
      expect(body['session'], matches(uuidV4));
      expect(body['platform'], 'ANDROID');
      expect(body['app_version'], '0.1.0+42');
      final events = (body['events'] as List).cast<Map<String, dynamic>>();
      expect([for (final e in events) e['name']], ['journey_search_started', 'journey_results_viewed', 'restaurant_viewed']);
      for (final e in events) {
        expect(e['id'], matches(uuidV4));
        expect(e['occurred_at'], matches(utcStamp));
        expect(e.keys.toSet().difference({'id', 'name', 'occurred_at', 'refs', 'properties'}), isEmpty);
      }
      expect({for (final e in events) e['id']}, hasLength(3)); // one id per event
      // The clock of the test is a local time: what is sent is the same instant in UTC, with "Z".
      expect([for (final e in events) e['occurred_at']], ['2026-10-13T09:15:00.000Z', '2026-10-13T09:15:01.000Z', '2026-10-13T09:15:01.000Z']);
      expect(events[0].containsKey('refs'), isFalse);
      expect(events[1]['refs'], {'journey': journeyUuid});
      expect(events[1]['properties'], {'results': 0});
      expect(events[2]['refs'], {'restaurant': 'burger-hub', 'journey': journeyUuid});

      await r.emitter.flush(); // nothing is queued: nothing is sent and no timer is left
      expect([r.net.calls.length, r.timers.pending.length], [1, 0]);
    });

    test('a batch carries at most 20 events; a full batch does not wait for the timer', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      for (var i = 0; i < 45; i++) {
        r.emitter.track('item_added_to_cart', refs: {'restaurant': restaurantId}, properties: {'quantity': 1 + i % 5});
      }
      await r.emitter.flush();
      expect([for (final b in r.net.bodies) (b['events'] as List).length], [20, 20, 5]);
      expect({for (final e in r.net.events) e['id']}, hasLength(45));
      expect([r.emitter.queued, r.timers.pending.length], [0, 0]);
      expect(r.timers.mostAtOnce, 1); // never more than one timer
    });

    test('the app version is the build\'s, in the characters the backend accepts', () {
      expect('${AppConfig.appVersion}+${AppConfig.buildNumber}', '0.1.0+45'); // Module 41: BUILD_NAME / BUILD_NUMBER defaults
      expect(AnalyticsEmitter.cleanAppVersion('0.1.0+42'), '0.1.0+42');
      expect(AnalyticsEmitter.cleanAppVersion('0.1.0+42 (local review) #7'), '0.1.0+42localreview7');
      expect(AnalyticsEmitter.cleanAppVersion('1.2.3-beta+${'9' * 40}'), hasLength(24));
      expect(RegExp(r'^[0-9A-Za-z.+\-]{1,24}$').hasMatch(AnalyticsEmitter.cleanAppVersion('v 1.0_α/β+7')), isTrue);
    });
  });

  group('who is sending', () {
    test('a signed-in customer\'s token goes in the Authorization header — and nowhere else', () async {
      final r = AnalyticsRig(token: '17|customer-session-token');
      r.emitter.track('journey_search_started');
      await r.emitter.flush();
      expect(r.net.calls.single.headers['Authorization'], 'Bearer 17|customer-session-token');
      expect(r.net.calls.single.body, isNot(contains('customer-session-token')));
      expect(r.net.calls.single.url.query, isEmpty);
    });

    test('a guest sends no Authorization header; signing in does not change the session', () async {
      final r = AnalyticsRig();
      r.emitter.track('journey_search_started');
      await r.emitter.flush();
      expect(r.net.calls.single.headers.containsKey('Authorization'), isFalse);
      r.token = '17|customer-session-token';
      r.emitter.track('journey_search_started');
      await r.emitter.flush();
      expect(r.net.calls.last.headers['Authorization'], 'Bearer 17|customer-session-token');
      expect(r.net.bodies.last['session'], r.net.bodies.first['session']);
    });
  });

  group('the registry', () {
    test('an unknown name, kind of reference or property never reaches the queue', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      r.emitter.track('order_completed', refs: {'restaurant': restaurantId}); // a fact of the backend's own records
      r.emitter.track('payment_verified', refs: {'checkout': checkoutId});
      r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId, 'order': checkoutId}); // not a kind of reference
      r.emitter.track('menu_viewed', refs: {'restaurant': restaurantId, 'checkout': checkoutId}); // not one of this event
      r.emitter.track('journey_search_started', properties: {'query': 'Sector 62, Noida'}); // a search text
      r.emitter.track('item_added_to_cart', refs: {'restaurant': restaurantId}, properties: {'quantity': 1, 'amount': 27000});
      r.emitter.track('checkout_started', refs: {'checkout': checkoutId}, properties: {'phone': '+919876543210', 'email': 'rahul.sharma@example.com'});
      r.emitter.track('pickup_viewed', refs: {'restaurant': restaurantId}, properties: {'lat': 28.628, 'lng': 77.3649});
      expect([r.emitter.queued, r.timers.started], [0, 0]);
      await r.emitter.flush();
      expect(r.net.calls, isEmpty);
    });

    test('an event without a reference it needs is not sent; an optional one that is no backend id is left out', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      r.emitter.track('journey_results_viewed', properties: {'results': 2}); // no journey
      r.emitter.track('journey_results_viewed', refs: {'journey': 'j-local-1'}, properties: {'results': 2}); // a development journey
      r.emitter.track('item_viewed', refs: {'restaurant': restaurantId, 'item': 'classic-burger'}); // an item needs its id
      r.emitter.track('restaurant_viewed', refs: {'restaurant': 'Not A Slug'});
      r.emitter.track('payment_started', refs: {'checkout': 'ck_123'});
      expect(r.emitter.queued, 0);

      r.emitter.track('restaurant_viewed', refs: {'restaurant': 'burger-hub', 'journey': 'j-local-1'}, properties: {'origin': 'JOURNEY'});
      r.emitter.track('item_added_to_cart', refs: {'restaurant': restaurantId, 'item': 'combo-1'}, properties: {'quantity': 2});
      await r.emitter.flush();
      expect([for (final e in r.net.events) e['refs']], [{'restaurant': 'burger-hub'}, {'restaurant': restaurantId}]);
    });

    test('a property outside its rule is left out — the backend would refuse the whole event for it', () async {
      final r = AnalyticsRig();
      await r.emitter.ready;
      r.emitter.track('item_added_to_cart', refs: {'restaurant': restaurantId}, properties: {'quantity': 99});
      r.emitter.track('journey_results_viewed', refs: {'journey': journeyUuid}, properties: {'results': 501});
      r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, properties: {'origin': 'Sector 62, Noida'}); // free text has no way through
      r.emitter.track('pickup_selected', properties: {'mode': 'ASAP', 'method': 'DELIVERY', 'recommended': 'yes'});
      await r.emitter.flush();
      final e = r.net.events;
      expect(e, hasLength(4));
      expect([for (final x in e.take(3)) x.containsKey('properties')], [false, false, false]);
      expect(e[3]['properties'], {'mode': 'ASAP'});
      expect(r.net.calls.single.body, isNot(contains('Noida')));
    });

    test('the table is the contract: ten events, their references and properties', () {
      expect(analyticsEvents.keys.toList(), ['journey_search_started', 'journey_results_viewed', 'restaurant_viewed', 'menu_viewed', 'item_viewed', 'item_added_to_cart', 'pickup_viewed', 'pickup_selected', 'checkout_started', 'payment_started']);
      expect({for (final e in analyticsEvents.entries) e.key: e.value.required}, {
        'journey_search_started': [], 'journey_results_viewed': ['journey'], 'restaurant_viewed': ['restaurant'], 'menu_viewed': ['restaurant'], 'item_viewed': ['restaurant', 'item'],
        'item_added_to_cart': ['restaurant'], 'pickup_viewed': [], 'pickup_selected': [], 'checkout_started': ['checkout'], 'payment_started': ['checkout'],
      });
      expect({for (final e in analyticsEvents.entries) e.key: e.value.properties.keys.toList()}, {
        'journey_search_started': [], 'journey_results_viewed': ['results'], 'restaurant_viewed': ['origin'], 'menu_viewed': [], 'item_viewed': [],
        'item_added_to_cart': ['quantity'], 'pickup_viewed': [], 'pickup_selected': ['mode', 'method', 'recommended'], 'checkout_started': [], 'payment_started': ['method'],
      });
      for (final d in analyticsEvents.values) {
        expect(analyticsRefKinds.toSet().containsAll(d.refs), isTrue);
        expect(d.refs.toSet().containsAll(d.required), isTrue);
      }
    });
  });

  group('consent', () {
    test('switched off: nothing is queued, kept or sent, and what was waiting is thrown away', () async {
      final r = AnalyticsRig();
      r.emitter.track('journey_search_started');
      await r.emitter.ready;
      expect(r.emitter.queued, 1);
      await r.emitter.setConsent(false);
      expect([r.emitter.consent, r.emitter.queued, r.timers.pending.length], [false, 0, 0]);
      final timersBefore = r.timers.started;
      r.emitter.track('journey_search_started');
      r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, onceKey: 'restaurant_viewed:$restaurantId');
      await r.emitter.flush();
      expect([r.emitter.queued, r.timers.started - timersBefore, r.net.calls.length], [0, 0, 0]);
      expect(await r.store.read(AnalyticsEmitter.optOutKey), '1');
      expect(await r.store.read(AnalyticsEmitter.bufferKey), isNull);
    });

    test('the choice is remembered on the device, and can be taken back', () async {
      final store = MemoryKeyValueStore();
      await AnalyticsRig(store: store).emitter.setConsent(false);

      final next = AnalyticsRig(store: store);
      next.emitter.track('journey_search_started'); // before the flag has even been read
      await next.emitter.ready;
      await next.emitter.flush();
      expect([next.emitter.consent, next.emitter.queued, next.net.calls.length, next.timers.started], [false, 0, 0, 0]);

      await next.emitter.setConsent(true);
      expect(await store.read(AnalyticsEmitter.optOutKey), isNull);
      next.emitter.track('journey_search_started');
      await next.emitter.flush();
      expect(next.net.names, ['journey_search_started']);
    });

    test('consent is on by default, and events kept without a connection are dropped when it is withdrawn', () async {
      final r = AnalyticsRig()..net.offline();
      expect(r.emitter.consent, isTrue);
      r.emitter.track('journey_search_started');
      await r.emitter.flush();
      expect(await r.store.read(AnalyticsEmitter.bufferKey), isNotNull);
      await r.emitter.setConsent(false);
      expect(await r.store.read(AnalyticsEmitter.bufferKey), isNull);
      r.net.online();
      await r.emitter.flush();
      expect(r.net.calls, hasLength(1)); // only the attempt that failed
    });
  });

  group('without a connection', () {
    test('the events are kept and a later flush sends the very same events', () async {
      final r = AnalyticsRig()..net.offline();
      r.emitter.track('journey_search_started');
      r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, properties: {'origin': 'DIRECT'});
      await r.emitter.flush();
      expect([r.net.calls.length, r.emitter.queued, r.timers.pending.length], [1, 2, 0]); // kept; no timer of its own, no retry loop
      final lost = r.net.events;
      final stored = jsonDecode((await r.store.read(AnalyticsEmitter.bufferKey))!) as List;
      expect(stored, hasLength(2));

      r.net.online();
      r.clock.advance(const Duration(minutes: 2));
      await r.emitter.flush();
      expect(r.net.calls, hasLength(2));
      expect((r.net.bodies.last['events'] as List), lost); // same ids, same times: the backend stores a resent event once
      expect(r.net.bodies.last['session'], r.net.bodies.first['session']);
      expect([r.emitter.queued, await r.store.read(AnalyticsEmitter.bufferKey)], [0, isNull]);
    });

    test('the next event after a failed send waits for the retry pause, then everything goes out together', () async {
      final r = AnalyticsRig()..net.offline();
      r.emitter.track('journey_search_started');
      await r.emitter.flush();
      r.net.online();
      r.clock.advance(const Duration(seconds: 2));
      r.emitter.track('journey_search_started');
      expect(r.timers.pending.single.delay, const Duration(seconds: 28)); // not before 30 s after the failure
      r.timers.fire();
      await r.emitter.flush();
      expect(r.net.calls, hasLength(2));
      expect((r.net.bodies.last['events'] as List), hasLength(2));
    });

    test('the buffer survives a restart of the app and is sent under the session it belongs to', () async {
      final store = MemoryKeyValueStore();
      final before = AnalyticsRig(store: store)..net.offline();
      before.emitter.track('checkout_started', refs: {'checkout': checkoutId, 'restaurant': restaurantId}, onceKey: 'checkout_started:$checkoutId');
      await before.emitter.flush();
      final lost = before.net.bodies.single;

      final after = AnalyticsRig(store: store, clock: TestClock(before.clock.now.add(const Duration(hours: 3))));
      await after.emitter.ready;
      expect([after.emitter.queued, after.timers.pending.length], [1, 1]); // something is queued: one timer
      after.emitter.track('journey_search_started'); // three hours later: a new session for the new event
      after.timers.fire();
      await after.emitter.flush();
      expect(after.net.bodies, hasLength(2));
      expect(after.net.bodies.first, lost); // the kept event, unchanged, under its own session
      expect(after.net.bodies.last['session'], isNot(lost['session']));
      expect(await store.read(AnalyticsEmitter.bufferKey), isNull);
    });

    test('what the device kept is read back through the registry: anything else in the stored buffer is ignored', () async {
      final store = MemoryKeyValueStore();
      const session = '7c9e6679-7425-40de-944b-e07fc1f90ae7', id = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
      final at = TestClock().now.toUtc().toIso8601String();
      await store.write(AnalyticsEmitter.bufferKey, jsonEncode([
        {'s': session, 'e': {'id': id, 'name': 'menu_viewed', 'occurred_at': at, 'refs': {'restaurant': restaurantId}}},
        {'s': session, 'e': {'id': id, 'name': 'order_completed', 'occurred_at': at}},
        {'s': session, 'e': {'id': id, 'name': 'menu_viewed', 'occurred_at': at, 'refs': {'restaurant': restaurantId}, 'properties': {'phone': '+919876543210'}}},
        {'s': 'not-a-session', 'e': {'id': id, 'name': 'journey_search_started', 'occurred_at': at}},
        {'s': session, 'e': {'id': 'x', 'name': 'journey_search_started', 'occurred_at': at}},
        'garbage', 42,
      ]));
      final r = AnalyticsRig(store: store);
      await r.emitter.flush();
      expect(r.net.bodies.single, {'session': session, 'platform': 'ANDROID', 'app_version': '0.1.0+42', 'events': [{'id': id, 'name': 'menu_viewed', 'occurred_at': at, 'refs': {'restaurant': restaurantId}}]});

      await store.write(AnalyticsEmitter.bufferKey, '{not json');
      final broken = AnalyticsRig(store: store);
      broken.emitter.track('journey_search_started');
      await broken.emitter.flush();
      expect(broken.net.names, ['journey_search_started']); // a damaged buffer costs nothing but itself
    });

    test('an event older than 72 hours is dropped instead of sent', () async {
      final r = AnalyticsRig()..net.offline();
      r.emitter.track('journey_search_started');
      await r.emitter.flush();
      final old = r.net.events.single['id'];

      r.clock.advance(const Duration(hours: 71));
      r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId});
      await r.emitter.flush();
      expect(r.emitter.queued, 2); // 71 h: still acceptable

      r.net.online();
      r.clock.advance(const Duration(hours: 2)); // the first is 73 h old now, the second 2 h
      await r.emitter.flush();
      final sent = (r.net.bodies.last['events'] as List).cast<Map<String, dynamic>>();
      expect([for (final e in sent) e['name']], ['restaurant_viewed']);
      expect(sent.any((e) => e['id'] == old), isFalse);
      expect(r.emitter.queued, 0);

      // …and one that was kept across a restart is not even loaded.
      final store = MemoryKeyValueStore();
      final before = AnalyticsRig(store: store)..net.offline();
      before.emitter.track('journey_search_started');
      await before.emitter.flush();
      final after = AnalyticsRig(store: store, clock: TestClock(before.clock.now.add(const Duration(hours: 72, minutes: 1))));
      await after.emitter.ready;
      await after.emitter.flush();
      expect([after.emitter.queued, after.net.calls.length, after.timers.started], [0, 0, 0]);
      expect(await store.read(AnalyticsEmitter.bufferKey), isNull);
    });

    test('the buffer never holds more than 50 events: the oldest go first, in memory and on the device', () async {
      final r = AnalyticsRig()..net.offline();
      await r.emitter.ready;
      for (var i = 0; i < 120; i++) {
        r.emitter.track('journey_results_viewed', refs: {'journey': journeyUuid}, properties: {'results': i});
        expect(r.emitter.queued, lessThanOrEqualTo(AnalyticsEmitter.bufferLimit));
        if (i % 30 == 29) { r.clock.advance(const Duration(minutes: 1)); await r.emitter.flush(); }
      }
      await r.emitter.flush();
      expect(r.emitter.queued, 50);
      expect(jsonDecode((await r.store.read(AnalyticsEmitter.bufferKey))!) as List, hasLength(50));
      expect(r.timers.mostAtOnce, lessThanOrEqualTo(1));
      for (final b in r.net.bodies) { expect((b['events'] as List).length, lessThanOrEqualTo(AnalyticsEmitter.batchSize)); }

      r.net.calls.clear();
      r.net.online();
      await r.emitter.flush();
      expect([for (final b in r.net.bodies) (b['events'] as List).length], [20, 20, 10]);
      expect([for (final e in r.net.events) (e['properties'] as Map)['results']], [for (var i = 70; i < 120; i++) i]); // the 70 oldest were dropped
      expect([r.emitter.queued, await r.store.read(AnalyticsEmitter.bufferKey)], [0, isNull]);
    });
  });

  group('an error answer', () {
    for (final status in [400, 401, 413, 422, 429, 500, 503]) {
      test('HTTP $status drops the batch: nothing is kept and nothing is tried again', () async {
        final r = AnalyticsRig(token: '17|customer-session-token');
        r.net.answer = (_) => refused(status);
        r.emitter.track('journey_search_started');
        r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId});
        await r.emitter.flush();
        expect([r.net.calls.length, r.emitter.queued, r.timers.pending.length], [1, 0, 0]);
        expect(await r.store.read(AnalyticsEmitter.bufferKey), isNull);
        r.net.online();
        await r.emitter.flush();
        expect(r.net.calls, hasLength(1));
        r.emitter.track('journey_search_started'); // the next event goes out normally, at once after the usual pause
        expect(r.timers.pending.single.delay, AnalyticsEmitter.defaultFlushAfter);
        await r.emitter.flush();
        expect(r.net.names.last, 'journey_search_started');
        expect(r.net.calls, hasLength(2));
      });
    }
  });

  group('nothing it does can reach the customer', () {
    test('a device store that fails changes nothing: the event is still sent and no call throws', () async {
      final r = AnalyticsRig(store: _BrokenStore());
      expect(() => r.emitter.track('journey_search_started'), returnsNormally);
      await expectLater(r.emitter.flush(), completes);
      await expectLater(r.emitter.setConsent(true), completes);
      expect(r.net.names, ['journey_search_started']);
    });

    test('track returns before anything is read, stored or sent', () {
      final r = AnalyticsRig(store: CountingStore());
      r.emitter.track('journey_search_started');
      expect([r.net.calls.length, (r.store as CountingStore).writes], [0, 0]);
    });
  });

  group('disabled', () {
    test('a disabled emitter makes no request, starts no timer and never touches the device store', () async {
      final store = CountingStore();
      final r = AnalyticsRig(enabled: false, store: store);
      for (var i = 0; i < 60; i++) {
        r.emitter.track('journey_search_started');
        r.emitter.track('restaurant_viewed', refs: {'restaurant': restaurantId}, onceKey: 'restaurant_viewed:$restaurantId');
      }
      await r.emitter.ready;
      await r.emitter.flush();
      await r.emitter.setConsent(false);
      await r.emitter.setConsent(true);
      expect([r.net.calls.length, r.timers.started, store.reads, store.writes, r.emitter.queued, r.emitter.sessionId], [0, 0, 0, 0, 0, isNull]);
    });

    test('the mode: ANALYTICS_MODE when given, otherwise the order mode — and off in every test by default', () async {
      expect(resolveAnalyticsMode(defined: '', ordersOnBackend: true), 'api');
      expect(resolveAnalyticsMode(defined: '', ordersOnBackend: false), 'off');
      expect(resolveAnalyticsMode(defined: 'off', ordersOnBackend: true), 'off');
      expect(resolveAnalyticsMode(defined: 'api', ordersOnBackend: false), 'api');
      expect(resolveAnalyticsMode(defined: 'mock', ordersOnBackend: true), 'api'); // not a mode of analytics: the order mode decides
      expect(resolveAnalyticsMode(defined: 'mock', ordersOnBackend: false), 'off');

      // This test run was given no dart-define: nothing is installed, so the door the app uses does nothing.
      expect([analyticsMode, Analytics.enabled], ['off', false]);
      expect(() { Analytics.track('journey_search_started'); Analytics.journeySearchStarted(); Analytics.paymentStarted(checkoutId, method: 'UPI'); }, returnsNormally);
      await expectLater(Analytics.flush(), completes);
      await expectLater(Analytics.setConsent(false), completes);

      final off = AnalyticsRig(enabled: false);
      Analytics.install(off.emitter);
      Analytics.journeySearchStarted();
      await Analytics.flush();
      expect([Analytics.enabled, off.net.calls.length, off.timers.started], [false, 0, 0]);
    });
  });
}

class _BrokenStore implements KeyValueStore {
  @override
  Future<String?> read(String key) async => throw StateError('storage unavailable');
  @override
  Future<void> write(String key, String? value) async => throw StateError('storage unavailable');
}
