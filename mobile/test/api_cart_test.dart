/// The cart on the backend (Module 27) — Android: the mapping of the cart API onto the app model (every amount is the
/// backend's), what the gateway sends (ids, quantities and text — never a price; the guest token in a header only; a
/// version and an idempotency key on every change), the retry that cannot become a duplicate add, the error
/// vocabulary, the cart state on that gateway (awaited adds, the restaurant-conflict question, sign-in claim, the
/// two-restaurant choice, no connection) and the screens. Payloads are in the shape of the real API
/// (OpenAPI: CartEnvelope, CartChangeResult, CartClaimResult).
library;

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/api_auth_repository.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/cart/api_cart.dart';
import 'package:foodonthego/cart/cart_validation.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/screens/cart_screen.dart';
import 'package:foodonthego/state/account_state.dart';
import 'package:foodonthego/state/app_state.dart';
import 'package:foodonthego/state/auth_state.dart';
import 'package:foodonthego/state/cart_state.dart';
import 'package:foodonthego/state/discovery_state.dart';
import 'package:foodonthego/state/journey_state.dart';
import 'package:foodonthego/widgets/cart_merge_prompt.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

const r1 = '0a000000-0000-4000-8000-0000000000a1';
const r2 = '0a000000-0000-4000-8000-0000000000b2';
const itemId = '1b000000-0000-4000-8000-000000000001';
const lineId = '2c000000-0000-4000-8000-000000000001';
const gSize = '3d000000-0000-4000-8000-000000000001';
const oLarge = '4e000000-0000-4000-8000-000000000001';
const gAdd = '3d000000-0000-4000-8000-000000000002';
const oCheese = '4e000000-0000-4000-8000-000000000002';
final guestToken = 'gc1.abc123.${'a' * 64}.${'b' * 32}';

http.Response json(int status, Object? body, {Map<String, String> headers = const {}}) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8', ...headers});
http.Response failure(int status, String code, [String message = 'x', Map<String, dynamic> details = const {}]) => json(status, {'error': {'code': code, 'message': message, 'details': details, 'request_id': 'req-1'}});

Map<String, dynamic> issue(String code, String severity, {String? line, Map<String, dynamic> details = const {}}) => {'code': code, 'severity': severity, 'scope': line == null ? 'cart' : 'line', 'line_id': line, 'message': '$code message', 'details': details};
Map<String, dynamic> lineDto({String id = lineId, String name = 'Classic Burger', int quantity = 2, int unit = 27000, int snapshot = 27000, List<Map<String, dynamic>> issues = const [], bool included = true, bool orderable = true, bool cheeseAvailable = true}) => {
      'id': id, 'item': {'id': itemId, 'slug': 'classic-burger', 'name': name, 'image': null, 'version': 3}, 'name': name, 'quantity': quantity, 'min_quantity': 1, 'max_quantity': 20, 'special_instructions': 'No onions',
      'variants': [{'group_id': gSize, 'group_name': 'Size', 'kind': 'VARIANT', 'options': [{'id': oLarge, 'name': 'Large', 'price_adjustment_minor': 5000, 'available': true}]}],
      'modifiers': [{'group_id': gAdd, 'group_name': 'Add-ons', 'kind': 'MODIFIER', 'options': [{'id': oCheese, 'name': 'Extra Cheese', 'price_adjustment_minor': 2000, 'available': cheeseAvailable}]}],
      'base_price_minor': 20000, 'unit_price_minor': unit, 'line_subtotal_minor': unit * quantity, 'currency': 'INR',
      'snapshot': {'unit_price_minor': snapshot, 'base_price_minor': 20000, 'item_version': 3, 'taken_at': '2026-10-07T07:30:00+00:00'}, 'price_changed': unit != snapshot, 'priced_from': 'menu', 'included_in_subtotal': included,
      'availability': {'orderable': orderable, 'reason': orderable ? null : 'ITEM_SOLD_OUT'}, 'issues': issues,
    };
Map<String, dynamic> cartDto({int version = 4, String status = 'ACTIVE', String owner = 'guest', String restaurant = r1, String restaurantName = 'Burger Hub', String slug = 'burger-hub', List<Map<String, dynamic>>? items, int? itemCount, int? subtotal, String validation = 'VALID', bool canProceed = true, List<Map<String, dynamic>> issues = const [], Map<String, dynamic>? journey, bool visible = true}) {
  final lines = items ?? [lineDto()];
  return {
    'id': '5f000000-0000-4000-8000-000000000001', 'version': version, 'status': status, 'owner': owner, 'currency': 'INR', 'restaurant': {'id': restaurant, 'slug': slug, 'name': restaurantName, 'visible': visible}, 'journey': journey,
    'items': lines, 'item_count': itemCount ?? lines.fold<int>(0, (a, l) => a + (l['quantity'] as int)), 'line_count': lines.length, 'subtotal_minor': subtotal ?? lines.fold<int>(0, (a, l) => a + (l['line_subtotal_minor'] as int)),
    'validation': {'status': validation, 'can_proceed': canProceed, 'issues': [...issues, for (final l in lines) ...(l['issues'] as List).cast<Map<String, dynamic>>()], 'menu_changed': false, 'catalog_version': 7, 'checked_at': '2026-10-07T07:31:00+00:00'},
    'limits': {'lines': 50, 'instructions_max_length': 200}, 'expires_at': '2026-10-14T07:30:00+00:00', 'created_at': '2026-10-07T07:30:00+00:00', 'updated_at': '2026-10-07T07:30:00+00:00',
  };
}
Map<String, dynamic> journeyLink({String status = 'ACTIVE'}) => {
      'id': '9d3f2c61-5b0e-4b7a-9c57-0c6c1f2a4e10', 'status': status, 'requires_refresh': status != 'ACTIVE', 'origin': {'label': 'Sector 62, Noida'}, 'destination': {'label': 'Sector 18, Noida'}, 'restaurant_on_route': true,
      'distance_from_route_meters': 420, 'detour': {'duration_seconds': 240, 'distance_meters': 900}, 'estimated_arrival_at': '2026-10-07T08:05:00+00:00', 'estimate_source': 'development', 'linked_at': '2026-10-07T07:30:00+00:00',
    };
Map<String, dynamic> summary(String name, int count, int total) => {'restaurant': {'id': r1, 'slug': 'x', 'name': name}, 'item_count': count, 'line_count': 1, 'subtotal_minor': total, 'currency': 'INR'};

const burgerOptions = [
  SelectedOption(groupId: gSize, groupName: 'Size', optionId: oLarge, optionName: 'Large', priceAdjustmentMinor: 5000),
  SelectedOption(groupId: gAdd, groupName: 'Add-ons', optionId: oCheese, optionName: 'Extra Cheese', priceAdjustmentMinor: 2000),
];
AddItemInput input({String restaurant = r1, String name = 'Burger Hub', int quantity = 2}) => AddItemInput(
      menuItemId: itemId, itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', basePriceMinor: 20000, currency: 'INR', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: name, restaurantCurrency: 'INR', restaurantPublicId: restaurant,
      selectedVariants: [burgerOptions[0]], selectedModifiers: [burgerOptions[1]], specialInstructions: 'No onions', quantity: quantity, unitPriceMinor: 99999);

/// A recording backend: every request is kept; [answer] decides the reply (throw to simulate a dropped connection).
class Recorder {
  final calls = <http.Request>[];
  http.Response Function(http.Request request) answer = (_) => json(200, {'cart': null});
  late final client = MockClient((request) async { calls.add(request); return answer(request); });
  http.Request last(String method, String fragment) => calls.lastWhere((c) => c.method == method && c.url.path.contains(fragment));
  Map<String, dynamic> body(http.Request r) => jsonDecode(r.body) as Map<String, dynamic>;
}

({CartBackend backend, Recorder net, MemoryKeyValueStore store}) gateway({bool signedIn = false}) {
  final net = Recorder();
  final store = MemoryKeyValueStore();
  final backend = CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);
  if (signedIn) store.write(ApiAuthRepository.tokenKey, 'customer-session');
  return (backend: backend, net: net, store: store);
}

/// A scripted backend for the state and screen tests.
class ScriptedBackend extends CartBackend {
  ScriptedBackend() : super(store: MemoryKeyValueStore(), client: ApiClient(client: MockClient((_) async => json(200, {'cart': null})), baseUrl: 'http://api.test/api/v1'));
  Map<String, dynamic>? cart;
  bool guestToken = false;
  final log = <String>[];
  final fail = <String, CartException>{};
  void _step(String name) { log.add(name); final e = fail[name.split(' ').first]; if (e != null) throw e; }
  CartAnswer _answer([String? line, bool merged = false]) => CartAnswer(cart: toCart(cart), lineId: line, merged: merged);
  @override
  Future<bool> hasGuestToken() async => guestToken;
  @override
  Future<Cart?> load() async { _step('load'); return toCart(cart); }
  @override
  Future<CartAnswer> add(String restaurantPublicId, CartItemRequest item, {int? version, JourneyRef? journey}) async {
    _step('add $restaurantPublicId v$version${journey == null ? '' : ' j:${journey.id}'}');
    cart = cartDto(version: ((cart?['version'] as int?) ?? 0) + 1, restaurant: restaurantPublicId, items: [lineDto(quantity: item.quantity)]);
    return _answer(lineId);
  }
  @override
  Future<CartAnswer> update(String id, {int? quantity, CartItemRequest? configuration, int? version}) async {
    _step('update $id q${quantity ?? configuration?.quantity} v$version');
    cart = cartDto(version: ((cart?['version'] as int?) ?? 0) + 1, items: [lineDto(quantity: quantity ?? configuration?.quantity ?? 2)]);
    return _answer(id);
  }
  @override
  Future<CartAnswer> remove(String id, {int? version}) async { _step('remove $id v$version'); cart = cartDto(version: ((cart?['version'] as int?) ?? 0) + 1, items: []); return _answer(); }
  @override
  Future<void> clear() async { _step('clear'); cart = null; }
  @override
  Future<CartAnswer> refresh({int? version}) async { _step('refresh v$version'); return _answer(); }
  @override
  Future<CartAnswer> switchRestaurant(String restaurantPublicId, CartItemRequest? item, {int? version, JourneyRef? journey}) async {
    _step('switch $restaurantPublicId v$version');
    cart = cartDto(version: 1, restaurant: restaurantPublicId, restaurantName: 'Pizza Point', slug: 'pizza-point', items: [lineDto(quantity: 1)]);
    return _answer(lineId);
  }
  @override
  Future<CartAnswer> linkJourney(JourneyRef journey, {int? version}) async { _step('link ${journey.id}'); return _answer(); }
  @override
  Future<CartClaim> claim({String? resolution}) async { _step('claim${resolution == null ? '' : ' $resolution'}'); guestToken = false; return CartClaim(cart: toCart(cart), outcome: resolution == 'keep_guest' ? 'kept_guest' : 'claimed'); }
}

/// A cart state that has read its cart once. Widget tests create it under the test clock (not in runAsync): a future made
/// outside that clock would never continue inside it.
Future<CartState> stateOn(ScriptedBackend backend, {CartRepository? device}) async { final s = CartState(backend: backend, repository: device ?? MemoryCartRepository()); await s.ready; return s; }

void main() {
  setUp(() => cartModeOverride = null);

  group('cart API mapping', () {
    test('shows the backend figures as they are and keeps what only the backend knows', () {
      final cart = toCart(cartDto(journey: journeyLink()))!;
      expect([cart.restaurantSlug, cart.restaurantName, cart.currency, cart.items.length], ['burger-hub', 'Burger Hub', 'INR', 1]);
      final line = cart.items.single;
      expect([line.id, line.menuItemId, line.itemSlug, line.quantity, line.unitPriceMinor, line.lineTotalMinor, line.basePriceMinor, line.specialInstructions, line.maximumQuantity], [lineId, itemId, 'classic-burger', 2, 27000, 54000, 20000, 'No onions', 20]);
      expect(line.selectedVariants.single.optionId, oLarge);
      expect(line.selectedModifiers.single.priceAdjustmentMinor, 2000);
      final server = cart.server!;
      expect([server.version, server.expired, server.owner, server.restaurantPublicId, server.subtotalMinor, server.itemCount, server.canProceed], [4, false, 'guest', r1, 54000, 2, true]);
      expect([server.journey!.stale, server.journey!.originLabel, server.journey!.destinationLabel, server.journey!.detourSeconds, server.journey!.estimateSource], [false, 'Sector 62, Noida', 'Sector 18, Noida', 240, 'development']);
      expect(toCart(null), isNull);
    });

    test('takes the subtotal from the backend and turns its issues into what the cart screen shows', () {
      final cart = toCart(cartDto(
        validation: 'INVALID', canProceed: false, subtotal: 58000, issues: [issue('RESTAURANT_NOT_ACCEPTING', 'BLOCKING'), issue('JOURNEY_STALE', 'INFO')],
        items: [
          lineDto(id: 'l1', unit: 29000, issues: [issue('PRICE_CHANGED', 'REVIEW', line: 'l1', details: {'previous_unit_price_minor': 27000, 'current_unit_price_minor': 29000})]),
          lineDto(id: 'l2', included: false, orderable: false, issues: [issue('ITEM_SOLD_OUT', 'BLOCKING', line: 'l2')]),
          lineDto(id: 'l3', cheeseAvailable: false, included: false, issues: [issue('OPTION_UNAVAILABLE', 'BLOCKING', line: 'l3', details: {'options': ['Extra Cheese']})]),
          lineDto(id: 'l4', included: false, issues: [issue('REQUIRED_CUSTOMIZATION_MISSING', 'BLOCKING', line: 'l4', details: {'group': 'Portion'})]),
          lineDto(id: 'l5', included: false, issues: [issue('QUANTITY_LIMIT', 'BLOCKING', line: 'l5', details: {'min': 1, 'max': 3})]),
        ],
      ))!;
      // A line that cannot be ordered is in the cart (and in the badge) but not in the subtotal — the backend decides that.
      expect([cart.itemCount, cart.subtotalMinor], [10, 58000]);
      final review = reviewFromServer(cart);
      expect(review.lineIssues.map((i) => i.kind), [LineIssueKind.priceChanged, LineIssueKind.unavailable, LineIssueKind.modifierUnavailable, LineIssueKind.needsChoice, LineIssueKind.quantity]);
      expect([review.forItem('l1')!.oldUnitMinor, review.forItem('l1')!.newUnitMinor, review.forItem('l3')!.optionNames, review.forItem('l4')!.groupName, review.forItem('l5')!.max], [27000, 29000, ['Extra Cheese'], 'Portion', 3]);
      expect([review.restaurantIssue, review.blocking, review.journeyStale, review.expired], [RestaurantIssue.notAccepting, true, true, false]);

      // The verdict is the backend's: a closed restaurant alone does not block, an expired cart does.
      expect(reviewFromServer(toCart(cartDto(issues: [issue('RESTAURANT_CLOSED_NOW', 'INFO')]))!).blocking, isFalse);
      final expired = reviewFromServer(toCart(cartDto(status: 'EXPIRED', validation: 'INVALID', canProceed: false, issues: [issue('CART_EXPIRED', 'BLOCKING')]))!);
      expect([expired.expired, expired.blocking], [true, true]);
    });
  });

  group('CartBackend', () {
    test('keeps the guest token it is handed in secure storage and sends it in a header only', () async {
      final g = gateway();
      g.net.answer = (r) => json(200, r.headers.containsKey('X-Cart-Token') ? {'cart': cartDto()} : {'cart': null, 'guest_token': guestToken});
      expect(await g.backend.load(), isNull);
      expect(await g.store.read(CartBackend.tokenKey), guestToken);
      expect(g.net.calls.single.headers.containsKey('X-Cart-Token'), isFalse);

      expect((await g.backend.load())!.items, hasLength(1));
      expect(g.net.calls.last.headers['X-Cart-Token'], guestToken);
      expect(g.net.calls.every((c) => !c.url.toString().contains('gc1')), isTrue);
      expect(g.net.calls.last.headers.containsKey('Authorization'), isFalse);
    });

    test('sends ids, a quantity and text — never a price, a currency or an owner', () async {
      final g = gateway();
      await g.store.write(CartBackend.tokenKey, guestToken);
      g.net.answer = (_) => json(201, {'cart': cartDto(), 'line': {'id': lineId, 'merged': false}});
      final request = CartItemRequest.of(itemId, burgerOptions, 2, ' No onions ');
      final answer = await g.backend.add(r1, request, version: 3, journey: (id: 'journey-1', headers: {'X-Journey-Token': 'j' * 64}));
      expect([answer.lineId, answer.merged], [lineId, false]);

      final add = g.net.last('POST', '/cart/items');
      expect(g.net.body(add), {
        'restaurant_id': r1, 'item_id': itemId, 'selections': [{'group_id': gSize, 'option_ids': [oLarge]}, {'group_id': gAdd, 'option_ids': [oCheese]}], 'quantity': 2, 'special_instructions': ' No onions ', 'journey_id': 'journey-1', 'cart_version': 3,
      });
      expect(RegExp('price|subtotal|currency|customer|status|total').hasMatch(add.body), isFalse);
      expect([add.headers['X-Cart-Token'], add.headers['X-Journey-Token']], [guestToken, 'j' * 64]);
      expect(add.headers['Idempotency-Key'], startsWith('add-'));

      await g.backend.update(lineId, quantity: 3, version: 4);
      expect(g.net.body(g.net.last('PATCH', '/cart/items/')), {'quantity': 3, 'cart_version': 4});
      await g.backend.update(lineId, configuration: CartItemRequest.of(itemId, [burgerOptions[0]], 2, ''));
      expect(g.net.body(g.net.last('PATCH', '/cart/items/')), {'quantity': 2, 'selections': [{'group_id': gSize, 'option_ids': [oLarge]}], 'special_instructions': null});
      await g.backend.remove(lineId, version: 5);
      expect(g.net.last('DELETE', '/cart/items/').url.queryParameters, {'cart_version': '5'});
      await g.backend.refresh(version: 6);
      expect(g.net.body(g.net.last('POST', '/cart/refresh')), {'cart_version': 6});
      await g.backend.switchRestaurant(r2, request, version: 6);
      expect(g.net.body(g.net.last('POST', '/cart/switch-restaurant')), {'confirm': true, 'restaurant_id': r2, 'item': request.toBody(), 'cart_version': 6});
      await g.backend.linkJourney((id: 'journey-2', headers: const {}), version: 7);
      expect(g.net.body(g.net.last('PUT', '/cart/journey')), {'journey_id': 'journey-2', 'cart_version': 7});
      await g.backend.clear();
      expect(g.net.last('DELETE', '/cart').url.path, endsWith('/cart'));
      // Every change that could be repeated by accident carries a key of its own.
      final keys = [for (final c in g.net.calls) if (c.headers['Idempotency-Key'] != null) c.headers['Idempotency-Key']!];
      expect(keys.toSet().length, keys.length);
    });

    test('RETRY: after a dropped connection the change is asked for once more with the SAME idempotency key — no duplicate add', () async {
      final g = gateway();
      await g.store.write(CartBackend.tokenKey, guestToken);
      // The backend applies a key once and replays its first answer for the same key (as the real one does).
      final applied = <String, int>{};
      var quantity = 0;
      var attempts = 0;
      g.net.answer = (r) {
        attempts++;
        final key = r.headers['Idempotency-Key']!;
        final replay = applied.containsKey(key);
        if (!replay) { quantity += (jsonDecode(r.body) as Map<String, dynamic>)['quantity'] as int; applied[key] = quantity; }
        // The first answer is lost on the way back: the app sees a network error although the add WAS applied.
        if (attempts == 1) throw http.ClientException('connection reset');
        return json(201, {'cart': cartDto(items: [lineDto(quantity: applied[key]!)]), 'line': {'id': lineId, 'merged': false}}, headers: replay ? {'idempotency-replayed': 'true'} : const {});
      };
      final answer = await g.backend.add(r1, CartItemRequest.of(itemId, burgerOptions, 2, ''));
      final posts = g.net.calls.where((c) => c.method == 'POST').toList();
      expect(posts, hasLength(2));
      expect(posts[0].headers['Idempotency-Key'], posts[1].headers['Idempotency-Key']);
      expect([quantity, answer.cart!.items.single.quantity], [2, 2], reason: 'applied once: 2, not 4');

      // Still unreachable: one retry, then the customer is told nothing was changed.
      g.net.answer = (_) => throw http.ClientException('offline');
      final before = g.net.calls.length;
      await expectLater(g.backend.add(r1, CartItemRequest.of(itemId, const [], 1, '')), throwsA(isA<CartException>().having((e) => e.code, 'code', CartErrorCode.offline).having((e) => e.message, 'message', contains('not changed'))));
      expect(g.net.calls.length - before, 2);
    });

    test('a signed-in customer is the customer; the guest token is only used to claim that guest cart, and is then dropped', () async {
      final g = gateway(signedIn: true);
      await g.store.write(CartBackend.tokenKey, guestToken);
      g.net.answer = (_) => json(200, {'cart': cartDto(owner: 'customer')});
      await g.backend.load();
      expect([g.net.calls.last.headers['Authorization'], g.net.calls.last.headers.containsKey('X-Cart-Token')], ['Bearer customer-session', false]);

      // Two restaurants: the token is kept until the customer has chosen.
      g.net.answer = (_) => failure(409, 'cart_merge_conflict', 'Choose which one to keep.', {'guest': summary('Burger Hub', 3, 60000), 'account': summary('Pizza Point', 2, 30000), 'resolutions': ['keep_guest', 'keep_account']});
      await expectLater(g.backend.claim(), throwsA(isA<CartException>().having((e) => e.code, 'code', CartErrorCode.mergeConflict)));
      expect(await g.backend.hasGuestToken(), isTrue);

      g.net.answer = (_) => json(200, {'cart': cartDto(owner: 'customer'), 'claim': {'outcome': 'merged', 'lines_moved': 1, 'lines_combined': 1, 'adjusted': [{'line_id': lineId, 'name': 'Masala Fries', 'requested_quantity': 23, 'quantity': 20, 'max_quantity': 20}]}});
      final claim = await g.backend.claim(resolution: 'keep_guest');
      final sent = g.net.last('POST', '/cart/claim');
      expect([sent.headers['X-Cart-Token'], sent.headers['Authorization'], g.net.body(sent)], [guestToken, 'Bearer customer-session', {'resolution': 'keep_guest'}]);
      expect([claim.outcome, claim.adjusted], ['merged', [('Masala Fries', 20)]]);
      expect([await g.backend.hasGuestToken(), await g.store.read(CartBackend.tokenKey)], [false, null]);

      // SIGN-OUT: the app says "guest" while the old session token is still stored for a moment — the cart is read as a
      // guest's, without that token.
      g.backend.session = false;
      g.net.answer = (_) => json(200, {'cart': null, 'guest_token': guestToken});
      await g.backend.load();
      expect([await g.store.read(ApiAuthRepository.tokenKey), g.net.calls.last.headers.containsKey('Authorization')], ['customer-session', false]);
      await g.backend.refresh().catchError((_) => const CartAnswer());
      expect([g.net.calls.last.headers.containsKey('Authorization'), g.net.calls.last.headers['X-Cart-Token']], [false, guestToken]);
    });

    test('speaks the backend error vocabulary', () async {
      final g = gateway();
      const cases = {
        'cart_restaurant_conflict': (409, CartErrorCode.restaurantConflict), 'cart_version_conflict': (409, CartErrorCode.versionConflict), 'cart_expired': (410, CartErrorCode.expired), 'item_unavailable': (409, CartErrorCode.itemUnavailable),
        'item_not_found': (404, CartErrorCode.itemUnavailable), 'option_unavailable': (409, CartErrorCode.optionUnavailable), 'invalid_customization': (422, CartErrorCode.invalidCustomization), 'quantity_limit': (422, CartErrorCode.quantityLimit),
        'cart_line_limit': (422, CartErrorCode.lineLimit), 'currency_mismatch': (409, CartErrorCode.currencyMismatch), 'restaurant_not_found': (404, CartErrorCode.restaurantUnavailable), 'cart_line_not_found': (404, CartErrorCode.notFound),
        'rate_limited': (429, CartErrorCode.rateLimited), 'internal_error': (500, CartErrorCode.error),
      };
      for (final e in cases.entries) {
        g.net.answer = (_) => failure(e.value.$1, e.key, 'secret stack trace');
        await expectLater(g.backend.refresh(), throwsA(isA<CartException>().having((x) => x.code, e.key, e.value.$2)));
      }
      g.net.answer = (_) => failure(500, 'internal_error', 'secret stack trace');
      await expectLater(g.backend.refresh(), throwsA(isA<CartException>().having((x) => x.message, 'message', isNot(contains('secret')))));
    });
  });

  group('CartState on the backend', () {
    test('reads the cart at start and reports the backend figures', () async {
      final backend = ScriptedBackend()..cart = cartDto();
      final cart = await stateOn(backend);
      expect([cart.backendMode, cart.hydrated, cart.offline, cart.count, cart.subtotalMinor, cart.currency, cart.status], [true, true, false, 2, 54000, 'INR', CartStatus.active]);
      expect(cart.items.single.id, lineId);
    });

    test('an add counts once the backend has answered, with the version the app last saw and the journey the customer is on', () async {
      final backend = ScriptedBackend();
      final cart = await stateOn(backend);
      cart.journeyRef = () async => (id: 'journey-1', headers: const <String, String>{});

      final added = await cart.addToCart(input());
      expect([added.ok, added.merged, cart.count, cart.subtotalMinor], [true, false, 2, 54000]);
      expect(backend.log, contains('add $r1 vnull j:journey-1'));
      // The app's own preview price (99999) is nowhere: the line shows the backend's 27000.
      expect(cart.items.single.unitPriceMinor, 27000);
      await cart.addToCart(input(quantity: 1));
      expect(backend.log.last, 'add $r1 v1 j:journey-1');
    });

    test('asks before replacing a cart of another restaurant and then calls the explicit switch', () async {
      final backend = ScriptedBackend()..cart = cartDto();
      final cart = await stateOn(backend);

      final refused = await cart.addToCart(input(restaurant: r2, name: 'Pizza Point'));
      expect([refused.ok, refused.failure, cart.conflict!.current.restaurantName, cart.conflict!.input.restaurantName], [false, AddFailure.restaurantConflict, 'Burger Hub', 'Pizza Point']);
      expect(backend.log.any((l) => l.startsWith('add') || l.startsWith('switch')), isFalse, reason: 'nothing was sent: the customer has not answered yet');

      cart.cancelReplace();
      expect([cart.conflict, cart.cart!.restaurantName], [null, 'Burger Hub']);

      await cart.addToCart(input(restaurant: r2, name: 'Pizza Point'));
      final replaced = await cart.confirmReplaceAndWait();
      expect([replaced.ok, cart.cart!.restaurantName, cart.conflict], [true, 'Pizza Point', null]);
      expect(backend.log, contains('switch $r2 v4'));
    });

    test('shows a changed quantity at once, sends the changes one after the other, and keeps what the backend answered', () async {
      final backend = ScriptedBackend()..cart = cartDto();
      final cart = await stateOn(backend);

      cart.updateQuantity(lineId, 3);
      cart.updateQuantity(lineId, 4);
      expect([cart.items.single.quantity, cart.status], [4, CartStatus.updating]);
      await cart.reload();
      expect(backend.log.where((l) => l.startsWith('update')), ['update $lineId q3 v4', 'update $lineId q4 v5']);
      expect([cart.count, cart.subtotalMinor, cart.status], [4, 108000, CartStatus.active]);

      cart.updateQuantity(lineId, 0);
      await cart.reload();
      expect([backend.log.contains('remove $lineId v6'), cart.count], [true, 0]);
    });

    test('saving an edited line asks the backend to price that line again — the app\'s own price never counts', () async {
      final backend = ScriptedBackend()..cart = cartDto();
      final cart = await stateOn(backend);

      final saved = await cart.saveItem(lineId, input(quantity: 3));
      expect(backend.log.last, 'update $lineId q3 v4');
      expect([saved.ok, saved.item?.id, cart.items.single.quantity, cart.items.single.unitPriceMinor], [true, lineId, 3, 27000]);

      backend.fail['update'] = CartException(CartErrorCode.optionUnavailable, 'One of the options is not available right now.');
      final refused = await cart.saveItem(lineId, input(quantity: 5));
      expect([refused.ok, refused.message, cart.items.single.quantity], [false, 'One of the options is not available right now.', 3]);
    });

    test('says what the backend refused and shows the backend cart again', () async {
      final backend = ScriptedBackend()..cart = cartDto();
      final cart = await stateOn(backend);

      backend.fail['update'] = CartException(CartErrorCode.versionConflict, 'Your cart changed somewhere else. Reload it and try again.');
      cart.updateQuantity(lineId, 9);
      await cart.reload();
      expect([cart.lastError, cart.items.single.quantity], ['Your cart changed somewhere else. Reload it and try again.', 2]);
      cart.dismissError();
      expect(cart.lastError, isNull);

      backend.fail..clear()..['add'] = CartException(CartErrorCode.itemUnavailable, 'This item is not available right now.');
      final res = await cart.addToCart(input());
      expect([res.ok, res.failure, res.message], [false, AddFailure.unavailable, 'This item is not available right now.']);
    });

    test('NO CONNECTION: the last cart the backend sent is shown, marked as such, and nothing pretends to change it', () async {
      final device = MemoryCartRepository();
      final online = ScriptedBackend()..cart = cartDto();
      final first = await stateOn(online, device: device);
      expect(first.count, 2);

      // The app is started again without a connection.
      final offline = ScriptedBackend()..fail['load'] = CartException(CartErrorCode.offline, 'offline');
      final cart = await stateOn(offline, device: device);
      expect([cart.offline, cart.hydrated, cart.count, cart.items.single.itemName], [true, true, 2, 'Classic Burger']);

      cart.updateQuantity(lineId, 5);
      cart.removeItem(lineId);
      expect([cart.items.single.quantity, cart.lastError], [2, contains('not changed')]);
      expect(offline.log.any((l) => l.startsWith('update') || l.startsWith('remove')), isFalse);

      offline.fail['add'] = CartException(CartErrorCode.offline, 'Cannot reach FoodOnTheGo right now. Your cart was not changed.');
      final res = await cart.addToCart(input());
      expect([res.ok, res.failure, cart.count], [false, AddFailure.offline, 2]);

      // Back online: the backend's cart replaces the copy.
      offline.fail.clear();
      offline.cart = cartDto(items: [lineDto(quantity: 3)]);
      await cart.reload();
      expect([cart.offline, cart.count], [false, 3]);

      // No connection and nothing kept: the cart is unknown, not empty.
      final none = ScriptedBackend()..fail['load'] = CartException(CartErrorCode.offline, 'offline');
      final unknown = await stateOn(none);
      expect([unknown.offline, unknown.cart, unknown.status], [true, null, CartStatus.error]);
    });

    test('APP RESTART: the cart comes back from the backend (the guest token is in secure storage)', () async {
      final store = MemoryKeyValueStore();
      Map<String, dynamic>? serverCart;
      final net = Recorder()
        ..answer = (r) {
          if (r.method == 'GET') return json(200, r.headers['X-Cart-Token'] == guestToken ? {'cart': serverCart} : {'cart': null, 'guest_token': guestToken});
          serverCart = cartDto(version: 1);
          return json(201, {'cart': serverCart, 'line': {'id': lineId, 'merged': false}});
        };
      CartBackend backend() => CartBackend(store: store, client: ApiClient(client: net.client, baseUrl: 'http://api.test/api/v1', tokenProvider: () => store.read(ApiAuthRepository.tokenKey)), retryAfter: Duration.zero);

      final first = CartState(backend: backend(), repository: MemoryCartRepository()); await first.ready;
      expect((await first.addToCart(input())).ok, isTrue);
      // A new process: new state, new gateway, the same secure storage.
      final second = CartState(backend: backend(), repository: MemoryCartRepository()); await second.ready;
      expect([second.count, second.items.single.id, second.offline], [2, lineId, false]);
      expect(net.calls.last.headers['X-Cart-Token'], guestToken);
    });

    test('claims the guest cart after sign-in; two restaurants are never merged — the customer chooses', () async {
      final backend = ScriptedBackend()..cart = cartDto()..guestToken = true;
      final cart = await stateOn(backend);
      await cart.onSessionChanged(true);
      expect([backend.log.contains('claim'), cart.count, cart.mergeConflict], [true, 2, null]);

      // Sign-out: back to a guest cart of this device.
      backend.cart = null;
      await cart.onSessionChanged(false);
      expect([backend.log.where((l) => l == 'load').length, cart.cart], [2, null]);

      // A guest cart of another restaurant meets the account's cart.
      backend..cart = cartDto(owner: 'customer', restaurant: r2, restaurantName: 'Pizza Point', slug: 'pizza-point')..guestToken = true;
      backend.fail['claim'] = CartException(CartErrorCode.mergeConflict, 'Choose.', details: {'guest': summary('Burger Hub', 3, 60000), 'account': summary('Pizza Point', 2, 30000)});
      await cart.onSessionChanged(true);
      expect([cart.mergeConflict!.guest.restaurantName, cart.mergeConflict!.guest.itemCount, cart.mergeConflict!.account.restaurantName, cart.cart!.restaurantName], ['Burger Hub', 3, 'Pizza Point', 'Pizza Point']);

      backend.fail.clear();
      backend.cart = cartDto(owner: 'customer');
      await cart.resolveMerge('keep_guest');
      expect([backend.log.last, cart.mergeConflict, cart.cart!.restaurantName], ['claim keep_guest', null, 'Burger Hub']);
    });
  });

  group('screens on the backend cart', () {
    Widget app(CartState cart) => MultiProvider(
          providers: [
            ChangeNotifierProvider(create: (_) => HealthState()),
            ChangeNotifierProvider(create: (_) => AuthState(repository: MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero))),
            ChangeNotifierProvider(create: (_) => AccountState(repositories: AccountRepositories.mock(MockAccountStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
            ChangeNotifierProvider(create: (_) => JourneyState(repositories: JourneyRepositories.mock(MockJourneyStore(store: MemoryKeyValueStore(), latency: Duration.zero)))),
            ChangeNotifierProvider(create: (_) => DiscoveryState(repository: MockRestaurantRepository(latency: Duration.zero))),
            ChangeNotifierProvider.value(value: cart),
          ],
          child: MaterialApp.router(
            builder: (context, child) => CartMergePrompt(child: child!),
            routerConfig: GoRouter(initialLocation: '/cart', routes: [
              GoRoute(path: '/cart', builder: (_, _) => CartScreen(restaurantRepository: MockRestaurantRepository(latency: Duration.zero), menuRepository: MockMenuRepository(latency: Duration.zero))),
              GoRoute(path: '/pickup-time', builder: (_, _) => const Scaffold(body: Text('pickup'))),
              GoRoute(path: '/plan-journey', builder: (_, _) => const Scaffold(body: Text('plan'))),
              GoRoute(path: '/restaurants', builder: (_, _) => const Scaffold(body: Text('list'))),
              GoRoute(path: '/restaurants/:id', builder: (_, s) => Scaffold(body: Text('restaurant ${s.pathParameters['id']}'))),
            ]),
          ),
        );
    void tall(WidgetTester t) { t.view.physicalSize = const Size(390, 3200); t.view.devicePixelRatio = 1; addTearDown(t.view.resetPhysicalSize); addTearDown(t.view.resetDevicePixelRatio); }

    testWidgets('shows the backend issues line by line and blocks on the backend verdict', (t) async {
      tall(t);
      final backend = ScriptedBackend()
        ..cart = cartDto(
          validation: 'INVALID', canProceed: false, subtotal: 44000, issues: [issue('RESTAURANT_NOT_ACCEPTING', 'BLOCKING')],
          items: [
            lineDto(id: 'l1', name: 'Paneer Roll', unit: 22000, snapshot: 20000, issues: [issue('PRICE_CHANGED', 'REVIEW', line: 'l1', details: {'previous_unit_price_minor': 20000, 'current_unit_price_minor': 22000})]),
            lineDto(id: 'l2', name: 'Masala Fries', included: false, orderable: false, issues: [issue('ITEM_SOLD_OUT', 'BLOCKING', line: 'l2')]),
          ],
        );
      final cart = await stateOn(backend);
      await t.pumpWidget(app(cart)); await t.pumpAndSettle();

      expect(find.text('Price updated from ₹200.00 to ₹220.00 each.'), findsOneWidget);
      expect(find.text('This item is no longer available.'), findsOneWidget);
      expect(find.textContaining('not accepting orders'), findsOneWidget);
      // The subtotal is the backend's: the sold-out line is listed but not counted.
      expect(find.text('₹440.00'), findsWidgets);
      expect(find.text('Review cart'), findsOneWidget);
      expect(find.textContaining('development cart'), findsNothing);
      expect(backend.log.where((l) => l == 'load').length, greaterThanOrEqualTo(2), reason: 'the screen reads the cart again when it opens');

      await t.tap(find.text('Accept updated price')); await t.pumpAndSettle();
      expect(backend.log, contains('refresh v4'));
      // The sold-out line cannot be stepped: tapping + sends nothing.
      final before = backend.log.length;
      await t.tap(find.bySemanticsLabel('Increase quantity Masala Fries')); await t.pumpAndSettle();
      expect(backend.log.length, before);
    });

    testWidgets('tells an expired cart and an ended journey apart from an ordinary cart, and can start a new one', (t) async {
      tall(t);
      final backend = ScriptedBackend()..cart = cartDto(status: 'EXPIRED', validation: 'INVALID', canProceed: false, issues: [issue('CART_EXPIRED', 'BLOCKING'), issue('JOURNEY_STALE', 'INFO')], journey: journeyLink(status: 'STALE'));
      final cart = await stateOn(backend);
      await t.pumpWidget(app(cart)); await t.pumpAndSettle();

      expect(find.textContaining('This cart has expired.'), findsOneWidget);
      expect(find.text('Sector 62, Noida → Sector 18, Noida'), findsOneWidget);
      expect(find.text('This journey has ended. Plan it again for a current detour and arrival time.'), findsOneWidget);
      expect(find.textContaining('detour · arrive'), findsNothing, reason: 'an old detour is not shown as current');
      expect(find.text('Review cart'), findsOneWidget);

      await t.tap(find.text('Start a new cart')); await t.pumpAndSettle();
      expect(backend.log, contains('clear'));
      expect(find.text('Your cart is empty.'), findsOneWidget);
    });

    testWidgets('a living journey link shows the detour and arrival the backend kept, labelled as the development model', (t) async {
      tall(t);
      final backend = ScriptedBackend()..cart = cartDto(journey: journeyLink());
      final cart = await stateOn(backend);
      await t.pumpWidget(app(cart)); await t.pumpAndSettle();
      expect(find.text('Sector 62, Noida → Sector 18, Noida'), findsOneWidget);
      expect(find.textContaining('4m detour'), findsOneWidget);
      expect(find.textContaining('development routing model'), findsOneWidget);
      expect(find.text('Continue to pickup'), findsOneWidget);
    });

    testWidgets('no connection: the last known cart is shown with a notice and cannot be changed; an unknown cart is not "empty"', (t) async {
      tall(t);
      final device = MemoryCartRepository();
      await stateOn(ScriptedBackend()..cart = cartDto(), device: device);
      final offline = ScriptedBackend()..fail['load'] = CartException(CartErrorCode.offline, 'offline');
      final cart = await stateOn(offline, device: device);
      await t.pumpWidget(app(cart)); await t.pumpAndSettle();
      expect(find.textContaining('No connection: this is your cart as it was last loaded'), findsOneWidget);
      expect(find.text('Classic Burger'), findsOneWidget);
      await t.tap(find.bySemanticsLabel('Increase quantity Classic Burger')); await t.pumpAndSettle();
      expect(offline.log.any((l) => l.startsWith('update')), isFalse);

      final unknown = await stateOn(ScriptedBackend()..fail['load'] = CartException(CartErrorCode.offline, 'offline'));
      await t.pumpWidget(app(unknown)); await t.pumpAndSettle();
      expect(find.text('We could not load your cart.'), findsOneWidget);
      expect(find.text('Your cart is empty.'), findsNothing);
    });

    testWidgets('the two-carts question appears over whatever screen is open and tells the backend the answer', (t) async {
      tall(t);
      final backend = ScriptedBackend()..cart = cartDto(owner: 'customer', restaurant: r2, restaurantName: 'Pizza Point', slug: 'pizza-point')..guestToken = true;
      backend.fail['claim'] = CartException(CartErrorCode.mergeConflict, 'Choose.', details: {'guest': summary('Burger Hub', 3, 60000), 'account': summary('Pizza Point', 2, 30000)});
      final cart = await stateOn(backend);
      await t.pumpWidget(app(cart)); await t.pumpAndSettle();
      await cart.onSessionChanged(true); await t.pumpAndSettle();

      expect(find.text('You have two carts'), findsOneWidget);
      expect(find.text('Keep Burger Hub · 3 item(s) · ₹600.00'), findsOneWidget);
      expect(find.text('Keep Pizza Point · 2 item(s) · ₹300.00'), findsOneWidget);

      backend.fail.clear();
      backend.cart = cartDto(owner: 'customer');
      await t.tap(find.text('Keep Burger Hub · 3 item(s) · ₹600.00')); await t.pumpAndSettle();
      expect(backend.log, contains('claim keep_guest'));
      expect(find.text('You have two carts'), findsNothing);
    });
  });
}
