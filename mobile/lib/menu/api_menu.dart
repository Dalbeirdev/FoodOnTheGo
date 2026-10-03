/// Customer menu from the backend (Module 24) — mirrors customer-web/src/menu/api/apiMenu.ts.
///
/// With RESTAURANT_MODE=api the screens read a restaurant's published menu (GET /restaurants/{slug}/menu) and the
/// item page (GET /restaurants/{slug}/items/{itemSlug}) instead of the development fixtures. The backend decides
/// what exists and what can be ordered:
///  - only ACTIVE categories and customer-visible items come back; a disabled, archived or unknown item is a 404 and
///    becomes null here — nothing is invented;
///  - sold out, temporarily unavailable and an impossible required group are its answer per item; the restaurant's
///    own open / accepting state stays on the restaurant (the screens already combine the two);
///  - prices are integer minor units in the menu's currency. The item screen previews a configuration with the same
///    rule the backend applies (base + adjustments × quantity); the backend's own answer is [getPriceQuote] — the
///    only price that is ever charged (the cart and checkout modules use it).
///
/// The menu document is reused for 30 seconds per restaurant (search, filters and paging are applied here, as the
/// fixtures did); the catalog version the backend sends makes a changed menu visible on the next load.
library;

import '../data/api_client.dart';
import '../discovery/api_restaurants.dart' show knownRestaurants, restaurantsFromApi;
import '../discovery/discovery_repository.dart' show normalize;
import 'menu_options.dart';
import 'menu_repository.dart';

/// The backend's price for one configuration (OpenAPI: PriceQuote).
class PriceQuote {
  const PriceQuote({required this.basePriceMinor, required this.adjustmentsMinor, required this.unitPriceMinor, required this.quantity, required this.lineTotalMinor, required this.currency, required this.orderable, this.reason, required this.catalogVersion});
  final int basePriceMinor, adjustmentsMinor, unitPriceMinor, quantity, lineTotalMinor, catalogVersion;
  final String currency;
  final bool orderable;
  final String? reason;
}

/// Sold out and temporarily unavailable come from the item's state; an impossible required group makes it
/// unavailable. The restaurant being closed or paused is not an item property.
ItemAvailability itemAvailabilityFrom(Map<String, dynamic> d) {
  final status = d['status'] as String?;
  if (status == 'SOLD_OUT') return ItemAvailability.soldOut;
  if (status == 'TEMPORARILY_UNAVAILABLE') return ItemAvailability.temporarilyUnavailable;
  final availability = d['availability'] as Map<String, dynamic>?;
  return availability?['reason'] == 'REQUIRED_GROUP_UNAVAILABLE' ? ItemAvailability.unavailable : ItemAvailability.available;
}

String _firstImage(Map<String, dynamic> d) {
  for (final i in (d['images'] as List<dynamic>?) ?? const <dynamic>[]) {
    final url = (i as Map<String, dynamic>)['url'] as String?;
    if (url != null && (url.startsWith('http://') || url.startsWith('https://'))) return url;
  }
  return '';
}

MenuItem menuItemFrom(Map<String, dynamic> d, String restaurantId, String categoryId) => MenuItem(
      id: d['id'] as String,
      slug: d['slug'] as String,
      restaurantId: restaurantId,
      categoryId: categoryId,
      name: d['name'] as String,
      description: (d['description'] as String?) ?? '',
      image: _firstImage(d),
      basePriceMinor: (d['base_price_minor'] as num).toInt(),
      currency: d['currency'] as String,
      availability: itemAvailabilityFrom(d),
      dietaryTags: [for (final t in (d['dietary_tags'] as List<dynamic>?) ?? const <dynamic>[]) (t as Map<String, dynamic>)['name'] as String],
      customizable: d['customizable'] == true,
      prepTimeMin: (d['preparation_minutes'] as num?)?.toInt() ?? 0,
      displayOrder: (d['display_order'] as num?)?.toInt() ?? 0,
      featured: d['featured'] == true,
    );

OptionGroup optionGroupFrom(Map<String, dynamic> g) => OptionGroup(
      id: g['id'] as String,
      kind: g['kind'] == 'VARIANT' ? OptionGroupKind.variant : OptionGroupKind.modifier,
      name: g['name'] as String,
      description: g['description'] as String?,
      required: g['required'] == true,
      minSelections: (g['min_selections'] as num).toInt(),
      maxSelections: (g['max_selections'] as num).toInt(),
      displayOrder: (g['display_order'] as num?)?.toInt() ?? 0,
      options: [
        for (final o in g['options'] as List<dynamic>)
          OptionChoice(
            id: (o as Map<String, dynamic>)['id'] as String,
            name: o['name'] as String,
            priceAdjustmentMinor: (o['price_adjustment_minor'] as num).toInt(),
            available: o['available'] == true,
            defaultSelected: o['default_selected'] == true,
            displayOrder: (o['display_order'] as num?)?.toInt() ?? 0,
          ),
      ],
    );

MenuItemDetail itemDetailFrom(Map<String, dynamic> d, String restaurantId) => MenuItemDetail(
      item: menuItemFrom(d, restaurantId, (d['category'] as Map<String, dynamic>)['id'] as String),
      restaurantSlug: (d['restaurant'] as Map<String, dynamic>)['slug'] as String,
      allergenInformation: d['allergen_information'] as String?,
      minimumQuantity: (d['min_quantity'] as num?)?.toInt() ?? 1,
      maximumQuantity: (d['max_quantity'] as num?)?.toInt() ?? 20,
      instructionsMaxLength: (d['instructions_max_length'] as num?)?.toInt() ?? 200,
      variantGroups: [for (final g in d['variant_groups'] as List<dynamic>) optionGroupFrom(g as Map<String, dynamic>)],
      modifierGroups: [for (final g in d['modifier_groups'] as List<dynamic>) optionGroupFrom(g as Map<String, dynamic>)],
    );

class ApiMenuRepository implements MenuRepository {
  ApiMenuRepository({ApiClient? client, String Function(String restaurantId)? slugOf})
      : _api = client ?? ApiClient(),
        _slugOf = slugOf ?? slugForRestaurant;

  static const reuseFor = Duration(seconds: 30);
  static final _uuid = RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', caseSensitive: false);
  final ApiClient _api;
  final String Function(String restaurantId) _slugOf;
  final _documents = <String, (Map<String, dynamic>, DateTime)>{};
  final _loading = <String, Future<Map<String, dynamic>>>{};

  /// The screens address a restaurant by the id its development data used; the backend wants the slug (they
  /// coincide for restaurants the fixtures never knew).
  static String slugForRestaurant(String restaurantId) => knownRestaurants.where((r) => r.id == restaurantId || r.backendId == restaurantId).firstOrNull?.slug ?? restaurantId;

  /// Forget what was loaded (after a known change, or in tests).
  void invalidate([String? restaurantId]) {
    if (restaurantId == null) {
      _documents.clear();
    } else {
      _documents.remove(_slugOf(restaurantId));
    }
  }

  MenuException _menuError(ApiException e) => MenuException(e.kind == ApiErrorKind.network ? e.message : 'The menu could not be loaded. Please try again.');

  Future<Map<String, dynamic>> _document(String restaurantId) {
    final slug = _slugOf(restaurantId);
    final cached = _documents[slug];
    if (cached != null && DateTime.now().difference(cached.$2) < reuseFor) return Future.value(cached.$1);
    return _loading.putIfAbsent(slug, () async {
      try {
        final doc = await _api.get('/restaurants/${Uri.encodeComponent(slug)}/menu', auth: false);
        _documents[slug] = (doc, DateTime.now());
        return doc;
      } on ApiException catch (e) {
        throw _menuError(e);
      } on MenuException {
        rethrow;
      } catch (_) {
        throw const MenuException('The menu could not be loaded. Please try again.');
      } finally {
        _loading.remove(slug);
      }
    });
  }

  List<MenuCategory> _categories(Map<String, dynamic> doc, String restaurantId) => [
        for (final c in doc['categories'] as List<dynamic>)
          MenuCategory(id: (c as Map<String, dynamic>)['id'] as String, restaurantId: restaurantId, name: c['name'] as String, description: c['description'] as String?, displayOrder: (c['display_order'] as num?)?.toInt() ?? 0),
      ];
  List<MenuItem> _items(Map<String, dynamic> doc, String restaurantId) => [
        for (final c in doc['categories'] as List<dynamic>)
          for (final i in (c as Map<String, dynamic>)['items'] as List<dynamic>) menuItemFrom(i as Map<String, dynamic>, restaurantId, c['id'] as String),
      ];

  @override
  Future<List<MenuCategory>> getCategories(String restaurantId) async => _categories(await _document(restaurantId), restaurantId);

  /// Search, filters and paging over the published document, exactly as the development repository did.
  @override
  Future<MenuPage> getItems(String restaurantId, [MenuFilter f = const MenuFilter()]) async {
    final doc = await _document(restaurantId);
    final byId = {for (final c in _categories(doc, restaurantId)) c.id: c};
    final q = normalize(f.search).trim();
    var list = _items(doc, restaurantId).where((i) => f.categoryId == null || i.categoryId == f.categoryId).toList();
    if (q.isNotEmpty) list = list.where((i) => [i.name, i.description, byId[i.categoryId]?.name ?? '', byId[i.categoryId]?.description ?? ''].map(normalize).any((h) => h.contains(q))).toList();
    if (f.dietary.isNotEmpty) list = list.where((i) => f.dietary.every(i.dietaryTags.contains)).toList();
    if (f.availableOnly) list = list.where((i) => i.isAvailable).toList();
    final offset = f.cursor != null && RegExp(r'^c\d+$').hasMatch(f.cursor!) ? int.parse(f.cursor!.substring(1)) : 0;
    return MenuPage(items: list.skip(offset).take(f.limit).toList(), nextCursor: offset + f.limit < list.length ? 'c${offset + f.limit}' : null, total: list.length);
  }

  @override
  Future<MenuItem?> getItemBySlug(String restaurantId, String slug) async => _items(await _document(restaurantId), restaurantId).where((i) => i.slug == slug || i.id == slug).firstOrNull;

  /// Null = the backend says this item is not on the menu (unknown, disabled, archived, hidden category, another
  /// restaurant's item).
  @override
  Future<MenuItemDetail?> getItemDetail(String restaurantId, String slug) async {
    var itemSlug = slug;
    if (_uuid.hasMatch(slug)) {
      MenuItem? known;
      try {
        known = await getItemBySlug(restaurantId, slug);
      } catch (_) {
        known = null;
      }
      if (known == null) return null;
      itemSlug = known.slug;
    }
    try {
      final res = await _api.get('/restaurants/${Uri.encodeComponent(_slugOf(restaurantId))}/items/${Uri.encodeComponent(itemSlug)}', auth: false);
      return itemDetailFrom(res['data'] as Map<String, dynamic>, restaurantId);
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.notFound) return null;
      throw _menuError(e);
    }
  }

  /// The labels in use on this menu, as the backend names them.
  @override
  Future<List<String>> getDietaryTags(String restaurantId) async => [for (final t in (await _document(restaurantId))['dietary_tags'] as List<dynamic>) (t as Map<String, dynamic>)['name'] as String];

  /// The backend's price for a configuration (base + adjustments, × quantity). A refused selection arrives as an
  /// [ApiException] (422 invalid_selection with `details['issues']`), exactly as the cart will see it later.
  Future<PriceQuote> getPriceQuote(String restaurantId, String itemSlug, Map<String, List<String>> selections, {int quantity = 1}) async {
    final res = await _api.post(
      '/restaurants/${Uri.encodeComponent(_slugOf(restaurantId))}/items/${Uri.encodeComponent(itemSlug)}/price-quote',
      auth: false,
      body: {
        'selections': [for (final e in selections.entries) if (e.value.isNotEmpty) {'group_id': e.key, 'option_ids': e.value}],
        'quantity': quantity,
      },
    );
    final data = res['data'] as Map<String, dynamic>;
    final availability = res['availability'] as Map<String, dynamic>;
    return PriceQuote(
      basePriceMinor: (data['base_price_minor'] as num).toInt(),
      adjustmentsMinor: (data['adjustments_minor'] as num).toInt(),
      unitPriceMinor: (data['unit_price_minor'] as num).toInt(),
      quantity: (data['quantity'] as num).toInt(),
      lineTotalMinor: (data['line_total_minor'] as num).toInt(),
      currency: data['currency'] as String,
      orderable: availability['orderable'] == true,
      reason: availability['reason'] as String?,
      catalogVersion: (data['catalog_version'] as num).toInt(),
    );
  }
}

ApiMenuRepository _shared = ApiMenuRepository();
/// The one menu repository of the running app in API mode (shares its 30-second document cache between screens).
ApiMenuRepository get menuApi => _shared;
set menuApi(ApiMenuRepository repo) => _shared = repo;

/// The menu repository a screen uses when none is injected: the backend in API mode, the development fixtures otherwise.
MenuRepository defaultMenuRepository() => restaurantsFromApi ? menuApi : MockMenuRepository();
