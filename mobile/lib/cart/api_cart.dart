/// The backend cart (Module 27) for the Android app.
///
///  - The cart is the server's: the app sends ids, quantities and text and shows what comes back. No price, subtotal
///    or currency is ever sent, and none is calculated here.
///  - A guest's cart answers to an opaque token the backend hands out (GET /cart). It is kept in the device's secure
///    storage and sent as the X-Cart-Token header only — never in a URL, never logged. A signed-in customer's cart
///    follows the account; the token is then dropped.
///  - Every change carries the cart version the app last saw and an Idempotency-Key. When the connection drops the
///    change is asked for ONCE more with the same key, so a lost answer never becomes a duplicate add.
library;

import 'dart:convert';
import 'dart:math';

import '../auth/api_auth_repository.dart';
import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../core/app_config.dart';
import '../data/api_client.dart';
import '../discovery/api_restaurants.dart' show knownRestaurants;
import 'cart_models.dart';

/// 'api' = the backend's cart, 'mock' = the device-only development cart. Tests set the override.
String? cartModeOverride;
bool get cartsFromApi => (cartModeOverride ?? AppConfig.cartMode) == 'api';

enum CartErrorCode { restaurantConflict, mergeConflict, versionConflict, expired, itemUnavailable, optionUnavailable, invalidCustomization, quantityLimit, lineLimit, currencyMismatch, restaurantUnavailable, notFound, rateLimited, offline, error }

class CartException implements Exception {
  CartException(this.code, this.message, {this.details = const {}});
  final CartErrorCode code;
  final String message;
  final Map<String, dynamic> details;
  @override
  String toString() => message;
}

const _codes = <String, CartErrorCode>{
  'cart_restaurant_conflict': CartErrorCode.restaurantConflict, 'cart_merge_conflict': CartErrorCode.mergeConflict, 'cart_version_conflict': CartErrorCode.versionConflict, 'cart_expired': CartErrorCode.expired,
  'item_unavailable': CartErrorCode.itemUnavailable, 'item_not_found': CartErrorCode.itemUnavailable, 'option_unavailable': CartErrorCode.optionUnavailable, 'invalid_customization': CartErrorCode.invalidCustomization,
  'quantity_limit': CartErrorCode.quantityLimit, 'cart_line_limit': CartErrorCode.lineLimit, 'currency_mismatch': CartErrorCode.currencyMismatch, 'restaurant_unavailable': CartErrorCode.restaurantUnavailable,
  'restaurant_not_found': CartErrorCode.restaurantUnavailable, 'cart_not_found': CartErrorCode.notFound, 'cart_line_not_found': CartErrorCode.notFound, 'rate_limited': CartErrorCode.rateLimited,
};

CartException toCartException(Object e) {
  if (e is CartException) return e;
  if (e is ApiException) {
    if (e.kind == ApiErrorKind.network) return CartException(CartErrorCode.offline, 'Cannot reach FoodOnTheGo right now. Your cart was not changed.');
    return CartException(_codes[e.code] ?? CartErrorCode.error, e.message, details: e.details);
  }
  return CartException(CartErrorCode.error, 'Your cart could not be updated. Please try again.');
}

/// What a customer is shown about a cart they are asked to give up: never its items.
class CartSummary {
  const CartSummary({required this.restaurantName, required this.itemCount, required this.subtotalMinor, required this.currency});
  final String restaurantName, currency;
  final int itemCount, subtotalMinor;
  factory CartSummary.fromJson(Map<String, dynamic> j) => CartSummary(restaurantName: (j['restaurant'] as Map<String, dynamic>)['name'] as String, itemCount: j['item_count'] as int, subtotalMinor: j['subtotal_minor'] as int, currency: j['currency'] as String);
}

class CartItemRequest {
  const CartItemRequest({required this.itemId, required this.selections, required this.quantity, this.specialInstructions = ''});
  final String itemId, specialInstructions;
  final int quantity;

  /// group id → chosen option ids.
  final Map<String, List<String>> selections;

  factory CartItemRequest.of(String itemId, List<SelectedOption> options, int quantity, String instructions) {
    final byGroup = <String, List<String>>{};
    for (final o in options) { (byGroup[o.groupId] ??= []).add(o.optionId); }
    return CartItemRequest(itemId: itemId, selections: byGroup, quantity: quantity, specialInstructions: instructions);
  }
  List<Map<String, dynamic>> get _selections => [for (final e in selections.entries) if (e.value.isNotEmpty) {'group_id': e.key, 'option_ids': e.value}];
  Map<String, dynamic> toBody() => {'item_id': itemId, 'selections': _selections, 'quantity': quantity, 'special_instructions': specialInstructions.trim().isEmpty ? null : specialInstructions};
}

/// A journey the cart may be linked to: its id and the header that proves a guest's journey is theirs.
typedef JourneyRef = ({String id, Map<String, String> headers});

class CartAnswer {
  const CartAnswer({this.cart, this.lineId, this.merged = false, this.replaced, this.priceChangesAccepted = 0});
  final Cart? cart;
  final String? lineId;
  final bool merged;
  final CartSummary? replaced;
  final int priceChangesAccepted;
}

class CartClaim {
  const CartClaim({this.cart, required this.outcome, this.adjusted = const []});
  final Cart? cart;
  final String outcome;

  /// Lines whose quantity the merge limited to the item's maximum: (name, quantity kept).
  final List<(String, int)> adjusted;
}

/* ------------------------------------------------------------------ mapping */
CartIssue _issue(Map<String, dynamic> j) => CartIssue(
      code: j['code'] as String,
      severity: switch (j['severity']) { 'BLOCKING' => CartIssueSeverity.blocking, 'REVIEW' => CartIssueSeverity.review, _ => CartIssueSeverity.info },
      lineId: j['line_id'] as String?, message: (j['message'] as String?) ?? '', details: (j['details'] is Map<String, dynamic>) ? j['details'] as Map<String, dynamic> : const {},
    );

List<SelectedOption> _selected(List<dynamic> groups) => [
      for (final g in groups.cast<Map<String, dynamic>>())
        for (final o in (g['options'] as List).cast<Map<String, dynamic>>())
          SelectedOption(groupId: (g['group_id'] as String?) ?? '', groupName: g['group_name'] as String, optionId: (o['id'] as String?) ?? '', optionName: o['name'] as String, priceAdjustmentMinor: o['price_adjustment_minor'] as int),
    ];

/// The id the screens (and the still-development pickup / checkout flow) use for this restaurant.
String appRestaurantId(String publicId, String slug) => knownRestaurants.where((r) => r.backendId == publicId || r.slug == slug).firstOrNull?.id ?? slug;

CartItem _line(Map<String, dynamic> j, String restaurantId) {
  final item = j['item'] as Map<String, dynamic>;
  final snapshot = j['snapshot'] as Map<String, dynamic>;
  final image = (item['image'] as Map<String, dynamic>?)?['url'] as String?;
  return CartItem(
    id: j['id'] as String, menuItemId: item['id'] as String, itemSlug: item['slug'] as String, restaurantId: restaurantId, itemName: (j['name'] as String?) ?? item['name'] as String,
    image: image != null && (image.startsWith('http://') || image.startsWith('https://')) ? image : '',
    basePriceMinor: j['base_price_minor'] as int, currency: j['currency'] as String, selectedVariants: _selected(j['variants'] as List), selectedModifiers: _selected(j['modifiers'] as List),
    specialInstructions: (j['special_instructions'] as String?) ?? '', quantity: j['quantity'] as int, minimumQuantity: j['min_quantity'] as int, maximumQuantity: j['max_quantity'] as int,
    // The backend's figures, as they are.
    unitPriceMinor: j['unit_price_minor'] as int, addedAt: DateTime.tryParse((snapshot['taken_at'] as String?) ?? '') ?? DateTime.now(),
    server: ServerLineState(
      issues: [for (final i in (j['issues'] as List).cast<Map<String, dynamic>>()) _issue(i)], priceChanged: j['price_changed'] as bool, previousUnitPriceMinor: snapshot['unit_price_minor'] as int,
      lineSubtotalMinor: j['line_subtotal_minor'] as int, includedInSubtotal: j['included_in_subtotal'] as bool, orderable: (j['availability'] as Map<String, dynamic>)['orderable'] as bool,
    ),
  );
}

CartJourneyLink _journey(Map<String, dynamic> j) {
  final detour = j['detour'] as Map<String, dynamic>?;
  return CartJourneyLink(
    id: j['id'] as String, stale: j['status'] != 'ACTIVE', originLabel: (j['origin'] as Map<String, dynamic>)['label'] as String?, destinationLabel: (j['destination'] as Map<String, dynamic>)['label'] as String?,
    restaurantOnRoute: j['restaurant_on_route'] as bool, distanceFromRouteM: j['distance_from_route_meters'] as int?, detourSeconds: detour?['duration_seconds'] as int?, detourMeters: detour?['distance_meters'] as int?,
    estimatedArrivalAt: DateTime.tryParse((j['estimated_arrival_at'] as String?) ?? ''), estimateSource: j['estimate_source'] as String?,
  );
}

Cart? toCart(Map<String, dynamic>? j) {
  if (j == null) return null;
  final restaurant = j['restaurant'] as Map<String, dynamic>;
  final validation = j['validation'] as Map<String, dynamic>;
  final restaurantId = appRestaurantId(restaurant['id'] as String, restaurant['slug'] as String);
  return Cart(
    id: j['id'] as String, restaurantId: restaurantId, restaurantSlug: restaurant['slug'] as String, restaurantName: restaurant['name'] as String, currency: j['currency'] as String,
    items: [for (final l in (j['items'] as List).cast<Map<String, dynamic>>()) _line(l, restaurantId)],
    createdAt: DateTime.tryParse((j['created_at'] as String?) ?? '') ?? DateTime.now(), updatedAt: DateTime.tryParse((j['updated_at'] as String?) ?? '') ?? DateTime.now(),
    server: ServerCartState(
      id: j['id'] as String, version: j['version'] as int, expired: j['status'] == 'EXPIRED', owner: j['owner'] as String, restaurantPublicId: restaurant['id'] as String, restaurantVisible: restaurant['visible'] as bool,
      subtotalMinor: j['subtotal_minor'] as int, itemCount: j['item_count'] as int, validationStatus: validation['status'] as String, canProceed: validation['can_proceed'] as bool,
      issues: [for (final i in (validation['issues'] as List).cast<Map<String, dynamic>>()) _issue(i)], menuChanged: validation['menu_changed'] as bool,
      journey: j['journey'] == null ? null : _journey(j['journey'] as Map<String, dynamic>),
    ),
  );
}

/* ------------------------------------------------------------------ gateway */
class CartBackend {
  CartBackend({ApiClient? client, KeyValueStore? store, Future<bool> Function()? signedIn, this.retryAfter = const Duration(milliseconds: 700)}) {
    _store = store ?? SecureKeyValueStore();
    _signedIn = signedIn ?? () async => await _store.read(ApiAuthRepository.tokenKey) != null;
    this.client = client ?? ApiClient(tokenProvider: () => _store.read(ApiAuthRepository.tokenKey), onUnauthenticated: () => onUnauthenticated?.call());
  }

  static const tokenKey = 'fotg.cart.token';
  late final ApiClient client;
  late final KeyValueStore _store;
  late final Future<bool> Function() _signedIn;
  final Duration retryAfter;

  /// Set by the app: a customer session the backend no longer accepts ends here as it does everywhere else.
  void Function()? onUnauthenticated;
  /// The app's own word on the session (true = a customer is signed in, false = a guest, null = go by the stored token).
  /// It wins over what is still in storage: right after sign-out the session token is stored for a moment longer, and a
  /// cart read in that moment must already be the guest's — never a request with the old customer token.
  bool? session;
  Future<bool> _customer() async => session ?? await _signedIn();
  String? _memoryToken;
  static final _random = Random.secure();

  Future<String?> _token() async => _memoryToken ??= await _store.read(tokenKey);
  Future<void> _keep(Object? token) async { if (token is String && token.isNotEmpty) { _memoryToken = token; await _store.write(tokenKey, token); } }
  Future<void> _drop() async { _memoryToken = null; await _store.write(tokenKey, null); }
  Future<bool> hasGuestToken() async => await _token() != null;

  /// The guest token is only sent for a guest: a signed-in customer is the customer.
  Future<Map<String, String>> _guestHeaders() async { if (await _customer()) return const {}; final t = await _token(); return t == null ? const {} : {'X-Cart-Token': t}; }
  static String _key(String operation) => '$operation-${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}-${List.generate(20, (_) => _random.nextInt(36).toRadixString(36)).join()}';

  Future<CartAnswer> _answer(Object? data) async {
    final j = data is Map<String, dynamic> ? data : const <String, dynamic>{};
    await _keep(j['guest_token']);
    final line = j['line'] as Map<String, dynamic>?;
    return CartAnswer(
      cart: toCart(j['cart'] as Map<String, dynamic>?), lineId: line?['id'] as String?, merged: (line?['merged'] as bool?) ?? false,
      replaced: j['replaced'] == null ? null : CartSummary.fromJson(j['replaced'] as Map<String, dynamic>), priceChangesAccepted: ((j['result'] as Map<String, dynamic>?)?['price_changes_accepted'] as int?) ?? 0,
    );
  }

  Future<CartAnswer> _send(String method, String path, {Object? body, String? operation, Map<String, String> extra = const {}, Map<String, String?> query = const {}}) async {
    final key = operation == null ? null : _key(operation);
    Future<Object?> call() async => client.send(method, path, body: body, query: query, idempotencyKey: key, auth: await _customer(), extraHeaders: {...extra, ...await _guestHeaders()});
    try {
      try {
        return await _answer(await call());
      } on ApiException catch (e) {
        // A lost connection may have lost only the ANSWER. Ask once more with the same key: the backend then replays
        // its first answer instead of applying the change a second time.
        if (e.kind != ApiErrorKind.network || key == null) rethrow;
        await Future<void>.delayed(retryAfter);
        return await _answer(await call());
      }
    } catch (e) {
      throw toCartException(e);
    }
  }

  /// The caller's cart. A guest without a token receives one and keeps it.
  Future<Cart?> load() async => (await _send('GET', '/cart')).cart;

  Future<CartAnswer> add(String restaurantPublicId, CartItemRequest item, {int? version, JourneyRef? journey}) =>
      _send('POST', '/cart/items', operation: 'add', extra: journey?.headers ?? const {}, body: {'restaurant_id': restaurantPublicId, ...item.toBody(), 'journey_id': ?journey?.id, 'cart_version': ?version});

  Future<CartAnswer> update(String lineId, {int? quantity, CartItemRequest? configuration, int? version}) => _send('PATCH', '/cart/items/$lineId', operation: 'upd', body: {
        'quantity': ?(quantity ?? configuration?.quantity),
        if (configuration != null) ...{'selections': configuration.toBody()['selections'], 'special_instructions': configuration.toBody()['special_instructions']},
        'cart_version': ?version,
      });

  Future<CartAnswer> remove(String lineId, {int? version}) => _send('DELETE', '/cart/items/$lineId', operation: 'del', query: {'cart_version': version?.toString()});
  Future<void> clear() async { await _send('DELETE', '/cart'); }

  /// "Update my cart": accepts today's prices as the new baseline.
  Future<CartAnswer> refresh({int? version}) => _send('POST', '/cart/refresh', operation: 'ref', body: {'cart_version': ?version});

  /// The confirmed switch: the old cart is replaced by one of the other restaurant, with this item, in one step.
  Future<CartAnswer> switchRestaurant(String restaurantPublicId, CartItemRequest? item, {int? version, JourneyRef? journey}) => _send('POST', '/cart/switch-restaurant', operation: 'swt', extra: journey?.headers ?? const {}, body: {
        'confirm': true, 'restaurant_id': restaurantPublicId, if (item != null) 'item': item.toBody(), 'journey_id': ?journey?.id, 'cart_version': ?version,
      });

  Future<CartAnswer> linkJourney(JourneyRef journey, {int? version}) => _send('PUT', '/cart/journey', extra: journey.headers, body: {'journey_id': journey.id, 'cart_version': ?version});

  /// After signing in: take over the cart built as a guest (named by its token). A [CartErrorCode.mergeConflict]
  /// (two restaurants) is thrown with both summaries until a resolution is given.
  Future<CartClaim> claim({String? resolution}) async {
    final token = await _token();
    try {
      final j = await client.post('/cart/claim', body: {'resolution': ?resolution}, idempotencyKey: _key('clm'), headers: {'X-Cart-Token': ?token});
      await _drop(); // claimed, merged, discarded or nothing there: the guest token has done its work
      final claim = j['claim'] as Map<String, dynamic>;
      return CartClaim(cart: toCart(j['cart'] as Map<String, dynamic>?), outcome: claim['outcome'] as String, adjusted: [for (final a in ((claim['adjusted'] as List?) ?? const []).cast<Map<String, dynamic>>()) (a['name'] as String, a['quantity'] as int)]);
    } catch (e) {
      throw toCartException(e);
    }
  }

  /// Sign-out and tests: forget the guest token of this device.
  Future<void> forgetToken() => _drop();
}

/// Offline copy: the last cart the backend sent, kept on the device so it can be SHOWN (never changed) without a connection.
String encodeOfflineCopy(Cart cart) => jsonEncode(cart.toJson());
