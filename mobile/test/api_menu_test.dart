import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/discovery/api_restaurants.dart';
import 'package:foodonthego/menu/api_menu.dart';
import 'package:foodonthego/menu/menu_options.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Module 24 — the customer menu from the backend. The network is stubbed with payloads in the shape of the real API
/// (backend/openapi/openapi.json: PublicMenu, PublicMenuItemDetail, PriceQuote).
http.Response _json(int status, Object body) => http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});

const i1 = '0c000000-0000-4000-8000-0000000000c1';
Map<String, dynamic> item({String id = i1, String slug = 'classic-burger', String name = 'Classic Burger', String status = 'ACTIVE', String? reason, List<Map<String, String>>? tags, bool customizable = true, int? prep = 12}) => {
      'id': id, 'slug': slug, 'name': name, 'description': 'Juicy grilled patty.', 'base_price_minor': 24900, 'currency': 'INR', 'status': status,
      'images': [{'id': 'img1', 'url': 'http://10.0.0.5:8001/api/v1/media/menu/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.jpg', 'alt_text': 'A burger', 'width': 800, 'height': 600, 'display_order': 0}],
      'dietary_tags': tags ?? [{'code': 'NON_VEGETARIAN', 'name': 'Non-vegetarian'}], 'customizable': customizable, 'preparation_minutes': prep, 'featured': true, 'display_order': 0, 'version': 3,
      'availability': {'visible': true, 'orderable': reason == null, 'reason': reason, 'restaurant_reason': null},
    };
Map<String, dynamic> menu() => {
      'restaurant': {'id': 'r1', 'slug': 'burger-hub', 'name': 'Burger Hub', 'currency': 'INR', 'timezone': 'Asia/Kolkata'}, 'availability': {'orderable': true},
      'menu': {'id': 'm1', 'name': 'Menu', 'currency': 'INR', 'catalog_version': 7, 'updated_at': null},
      'categories': [
        {'id': 'c1', 'name': 'Burgers', 'description': null, 'display_order': 0, 'items': [item(), item(id: 'i2', slug: 'smoky-bbq', name: 'Smoky BBQ', status: 'SOLD_OUT', reason: 'ITEM_SOLD_OUT', tags: [])]},
        {'id': 'c2', 'name': 'Sides', 'description': 'Small plates', 'display_order': 1, 'items': [item(id: 'i3', slug: 'fries', name: 'Fries', customizable: false, prep: null, tags: [{'code': 'VEGETARIAN', 'name': 'Vegetarian'}, {'code': 'VEGAN', 'name': 'Vegan'}])]},
      ],
      'dietary_tags': [{'code': 'NON_VEGETARIAN', 'name': 'Non-vegetarian'}, {'code': 'VEGETARIAN', 'name': 'Vegetarian'}, {'code': 'VEGAN', 'name': 'Vegan'}],
    };
Map<String, dynamic> detail() => {
      'data': {
        ...item(), 'restaurant': {'id': 'r1', 'slug': 'burger-hub', 'name': 'Burger Hub'}, 'category': {'id': 'c1', 'name': 'Burgers'}, 'menu': {'id': 'm1', 'currency': 'INR', 'catalog_version': 7},
        'min_quantity': 1, 'max_quantity': 20, 'instructions_max_length': 200, 'allergen_information': 'Contains gluten.', 'ingredients': null,
        'variant_groups': [{'id': 'g1', 'kind': 'VARIANT', 'name': 'Size', 'description': null, 'required': true, 'min_selections': 1, 'max_selections': 1, 'display_order': 0, 'options': [{'id': 'o1', 'name': 'Regular', 'price_adjustment_minor': 0, 'available': true, 'default_selected': true, 'display_order': 0}, {'id': 'o2', 'name': 'Large', 'price_adjustment_minor': 5000, 'available': true, 'default_selected': false, 'display_order': 1}]}],
        'modifier_groups': [{'id': 'g2', 'kind': 'MODIFIER', 'name': 'Add-ons', 'description': 'Choose up to 3', 'required': false, 'min_selections': 0, 'max_selections': 3, 'display_order': 1, 'options': [{'id': 'o3', 'name': 'Extra Cheese', 'price_adjustment_minor': 2000, 'available': true, 'default_selected': false, 'display_order': 0}, {'id': 'o4', 'name': 'Fried Egg', 'price_adjustment_minor': 3000, 'available': false, 'default_selected': false, 'display_order': 1}]}],
      },
      'restaurant_availability': {'orderable': true},
    };
const quote = {
  'data': {'base_price_minor': 20000, 'adjustments_minor': 7000, 'unit_price_minor': 27000, 'quantity': 2, 'line_total_minor': 54000, 'currency': 'INR', 'selections': <Object>[], 'item_id': i1, 'item_version': 3, 'catalog_version': 7},
  'availability': {'visible': true, 'orderable': true, 'reason': null, 'restaurant_reason': null},
};

void main() {
  final calls = <http.Request>[];
  var routes = <String, http.Response Function(http.Request)>{};
  ApiMenuRepository repo() => ApiMenuRepository(
        slugOf: (id) => id == 'legacy-burger-hub' ? 'burger-hub' : id,
        client: ApiClient(baseUrl: 'http://api.test/api/v1', tokenProvider: () async => 'customer-token', client: MockClient((r) async {
          calls.add(r);
          final h = routes['${r.method} ${r.url.path.replaceFirst('/api/v1', '')}'];
          return h == null ? _json(404, {'error': {'code': 'item_not_found', 'message': 'This item is not on the menu.', 'details': <String, Object>{}}}) : h(r);
        })),
      );

  setUp(() {
    calls.clear();
    routes = {
      'GET /restaurants/burger-hub/menu': (_) => _json(200, menu()),
      'GET /restaurants/burger-hub/items/classic-burger': (_) => _json(200, detail()),
      'POST /restaurants/burger-hub/items/classic-burger/price-quote': (_) => _json(200, quote),
    };
  });

  group('the published menu', () {
    test('categories, items and labels come from one public request, mapped for the screens', () async {
      final r = repo();
      final cats = await r.getCategories('burger-hub');
      final page = await r.getItems('burger-hub', const MenuFilter(limit: 500));
      final tags = await r.getDietaryTags('burger-hub');
      expect(calls, hasLength(1));
      expect(calls.single.url.path, '/api/v1/restaurants/burger-hub/menu');
      expect(calls.single.headers.containsKey('Authorization'), isFalse);
      expect(cats.map((c) => (c.name, c.displayOrder, c.restaurantId)), [('Burgers', 0, 'burger-hub'), ('Sides', 1, 'burger-hub')]);
      expect(cats[1].description, 'Small plates');
      expect(page.total, 3);
      expect(page.items.map((i) => i.slug), ['classic-burger', 'smoky-bbq', 'fries']);
      final classic = page.items.first;
      expect([classic.id, classic.publicId, classic.categoryId, classic.basePriceMinor, classic.currency, classic.prepTimeMin], [i1, 'itm_$i1', 'c1', 24900, 'INR', 12]);
      expect([classic.availability, classic.customizable, classic.featured], [ItemAvailability.available, true, true]);
      expect(classic.dietaryTags, ['Non-vegetarian']);
      expect(classic.image, contains('/api/v1/media/menu/'));
      expect(page.items[1].availability, ItemAvailability.soldOut);
      expect([page.items[2].prepTimeMin, page.items[2].customizable, page.items[2].dietaryTags], [0, false, ['Vegetarian', 'Vegan']]);
      expect(tags, ['Non-vegetarian', 'Vegetarian', 'Vegan']);
    });

    test('a development restaurant id is turned into the backend slug', () async {
      await repo().getCategories('legacy-burger-hub');
      expect(calls.single.url.path, '/api/v1/restaurants/burger-hub/menu');
    });

    test('searches, filters and pages like the development repository', () async {
      final r = repo();
      expect((await r.getItems('burger-hub', const MenuFilter(search: 'FRIES'))).items.map((i) => i.name), ['Fries']);
      expect((await r.getItems('burger-hub', const MenuFilter(search: 'small plates'))).items.map((i) => i.name), ['Fries']);
      expect((await r.getItems('burger-hub', const MenuFilter(dietary: ['Vegetarian', 'Vegan']))).items.map((i) => i.name), ['Fries']);
      expect((await r.getItems('burger-hub', const MenuFilter(availableOnly: true))).items.map((i) => i.slug), ['classic-burger', 'fries']);
      expect((await r.getItems('burger-hub', const MenuFilter(categoryId: 'c1'))).total, 2);
      final first = await r.getItems('burger-hub', const MenuFilter(limit: 2));
      expect(first.items, hasLength(2));
      expect(first.nextCursor, 'c2');
      expect((await r.getItems('burger-hub', const MenuFilter(limit: 2, cursor: 'c2'))).items.map((i) => i.slug), ['fries']);
      expect((await r.getItemBySlug('burger-hub', 'smoky-bbq'))?.name, 'Smoky BBQ');
      expect(await r.getItemBySlug('burger-hub', 'no-such'), isNull);
      expect(calls.where((c) => c.url.path.endsWith('/menu')), hasLength(1));
    });

    test('the availability answer of the backend: sold out, temporarily unavailable, an impossible required group', () {
      expect(itemAvailabilityFrom(item()), ItemAvailability.available);
      expect(itemAvailabilityFrom(item(status: 'SOLD_OUT', reason: 'ITEM_SOLD_OUT')), ItemAvailability.soldOut);
      expect(itemAvailabilityFrom(item(status: 'TEMPORARILY_UNAVAILABLE', reason: 'ITEM_TEMPORARILY_UNAVAILABLE')), ItemAvailability.temporarilyUnavailable);
      expect(itemAvailabilityFrom(item(reason: 'REQUIRED_GROUP_UNAVAILABLE')), ItemAvailability.unavailable);
      // the restaurant being closed or paused is not an item property: the screens read it from the restaurant
      expect(itemAvailabilityFrom(item(reason: 'RESTAURANT_UNAVAILABLE')), ItemAvailability.available);
    });

    test('the document is reused for a while and forgotten on invalidate; a failure is a MenuException', () async {
      final r = repo();
      await r.getCategories('burger-hub');
      await r.getDietaryTags('burger-hub');
      expect(calls, hasLength(1));
      r.invalidate('burger-hub');
      await r.getCategories('burger-hub');
      expect(calls, hasLength(2));
      routes = {};
      await expectLater(repo().getCategories('burger-hub'), throwsA(isA<MenuException>()));
    });
  });

  group('the item screen and the price quote', () {
    test('maps the item with its groups; a temporarily unavailable option is shown as not available', () async {
      final d = await repo().getItemDetail('burger-hub', 'classic-burger');
      expect(calls.single.url.path, '/api/v1/restaurants/burger-hub/items/classic-burger');
      expect(d, isNotNull);
      expect([d!.item.id, d.item.slug, d.item.restaurantId, d.item.categoryId, d.restaurantSlug, d.allergenInformation], [i1, 'classic-burger', 'burger-hub', 'c1', 'burger-hub', 'Contains gluten.']);
      expect([d.minimumQuantity, d.maximumQuantity, d.instructionsMaxLength], [1, 20, 200]);
      expect(d.variantGroups, hasLength(1));
      final size = d.variantGroups.single;
      expect([size.id, size.kind, size.name, size.required, size.minSelections, size.maxSelections], ['g1', OptionGroupKind.variant, 'Size', true, 1, 1]);
      expect(size.options.map((o) => (o.name, o.priceAdjustmentMinor, o.available, o.defaultSelected)), [('Regular', 0, true, true), ('Large', 5000, true, false)]);
      final addons = d.modifierGroups.single;
      expect([addons.kind, addons.name, addons.description, addons.maxSelections], [OptionGroupKind.modifier, 'Add-ons', 'Choose up to 3', 3]);
      expect(addons.options.where((o) => o.name == 'Fried Egg').single.available, isFalse);
      expect(d.groups.map((g) => g.name), ['Size', 'Add-ons']);
    });

    test('an item the backend does not show is null; an item id is resolved to its slug first', () async {
      final r = repo();
      expect(await r.getItemDetail('burger-hub', 'old-wrap'), isNull);
      expect((await r.getItemDetail('burger-hub', i1))?.item.slug, 'classic-burger');
      expect(calls.map((c) => c.url.path), ['/api/v1/restaurants/burger-hub/items/old-wrap', '/api/v1/restaurants/burger-hub/menu', '/api/v1/restaurants/burger-hub/items/classic-burger']);
      expect(await r.getItemDetail('burger-hub', '0c000000-0000-4000-8000-0000000000ff'), isNull);
    });

    test('asks the backend for the price of a configuration and passes its refusal on unchanged', () async {
      final r = repo();
      final q = await r.getPriceQuote('burger-hub', 'classic-burger', {'g1': ['o2'], 'g2': ['o3'], 'g3': []}, quantity: 2);
      expect(calls.single.method, 'POST');
      expect(calls.single.url.path, '/api/v1/restaurants/burger-hub/items/classic-burger/price-quote');
      expect(jsonDecode(calls.single.body), {'selections': [{'group_id': 'g1', 'option_ids': ['o2']}, {'group_id': 'g2', 'option_ids': ['o3']}], 'quantity': 2});
      expect([q.basePriceMinor, q.adjustmentsMinor, q.unitPriceMinor, q.quantity, q.lineTotalMinor, q.currency, q.orderable, q.reason, q.catalogVersion], [20000, 7000, 27000, 2, 54000, 'INR', true, null, 7]);
      routes['POST /restaurants/burger-hub/items/classic-burger/price-quote'] = (_) => _json(422, {'error': {'code': 'invalid_selection', 'message': 'Refused', 'details': {'issues': [{'group_id': 'g1', 'code': 'required', 'min': 1}]}, 'request_id': 'req-1'}});
      await expectLater(r.getPriceQuote('burger-hub', 'classic-burger', {}), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'invalid_selection').having((e) => e.details['issues'], 'issues', [{'group_id': 'g1', 'code': 'required', 'min': 1}])));
    });
  });

  group('which repository the screens get', () {
    tearDown(() { restaurantModeOverride = null; menuApi = ApiMenuRepository(); });
    test('the backend in API mode, the fixtures otherwise', () {
      restaurantModeOverride = 'mock';
      expect(defaultMenuRepository(), isA<MockMenuRepository>());
      restaurantModeOverride = 'api';
      final shared = repo(); menuApi = shared;
      expect(defaultMenuRepository(), same(shared));
    });
  });
}
