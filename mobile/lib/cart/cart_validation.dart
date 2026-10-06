/// Cart validation (Module 09) — Android. Re-checks every line against the CURRENT menu data and
/// the restaurant state. Frontend validation is a courtesy; the backend must revalidate
/// (CART REVALIDATION = FUTURE BACKEND REQUIREMENT). Promotions are a frontend area only.
library;

import '../discovery/discovery_repository.dart' show computeAvailability;
import '../discovery/restaurant_models.dart';
import '../menu/menu_options.dart';
import '../menu/menu_repository.dart';
import '../pricing/pricing_service.dart' show clampQuantity;
import 'cart_models.dart';

enum LineIssueKind { unavailable, modifierUnavailable, priceChanged, quantity, needsChoice }

class LineIssue {
  const LineIssue(this.itemId, this.kind, {this.oldUnitMinor, this.newUnitMinor, this.optionNames = const [], this.min, this.max, this.groupName});
  final String itemId;
  final String? groupName;
  final LineIssueKind kind;
  final int? oldUnitMinor, newUnitMinor, min, max;
  final List<String> optionNames;
}

enum RestaurantIssue { none, inactive, notAccepting, closed }
enum StaleSimulation { none, price, unavailable, modifier, closed, notAccepting }

class CartReview {
  const CartReview({required this.empty, required this.currencyMismatch, required this.restaurantIssue, required this.lineIssues, required this.blocking, this.expired = false, this.menuUnavailable = false, this.journeyStale = false});
  final bool empty, currencyMismatch, blocking;
  /// Backend carts (Module 27) only.
  final bool expired, menuUnavailable, journeyStale;
  final RestaurantIssue restaurantIssue;
  final List<LineIssue> lineIssues;
  bool get ok => !blocking;
  LineIssue? forItem(String id) => lineIssues.where((i) => i.itemId == id).firstOrNull;
}

/// CART_MODE=api: the review IS the backend's validation of the cart (Module 27) — nothing is checked in the app.
/// The first issue of a line decides how the line is shown; the backend's verdict decides whether the cart may proceed.
CartReview reviewFromServer(Cart cart) {
  final server = cart.server;
  if (server == null) return CartReview(empty: cart.items.isEmpty, currencyMismatch: false, restaurantIssue: RestaurantIssue.none, lineIssues: const [], blocking: true);
  final issues = <LineIssue>[];
  for (final item in cart.items) {
    final first = item.server?.issues.firstOrNull;
    if (first == null) continue;
    final d = first.details;
    if (first.code.startsWith('ITEM_')) {
      issues.add(LineIssue(item.id, LineIssueKind.unavailable));
    } else if (first.code == 'OPTION_UNAVAILABLE') {
      issues.add(LineIssue(item.id, LineIssueKind.modifierUnavailable, optionNames: [for (final o in (d['options'] as List? ?? const [])) '$o']));
    } else if (first.code == 'PRICE_CHANGED') {
      issues.add(LineIssue(item.id, LineIssueKind.priceChanged, oldUnitMinor: d['previous_unit_price_minor'] as int?, newUnitMinor: d['current_unit_price_minor'] as int?));
    } else if (first.code == 'QUANTITY_LIMIT') {
      issues.add(LineIssue(item.id, LineIssueKind.quantity, min: d['min'] as int?, max: d['max'] as int?));
    } else {
      issues.add(LineIssue(item.id, LineIssueKind.needsChoice, groupName: d['group'] as String?));
    }
  }
  final restaurant = server.has('RESTAURANT_UNAVAILABLE') ? RestaurantIssue.inactive : server.has('RESTAURANT_NOT_ACCEPTING') ? RestaurantIssue.notAccepting : server.has('RESTAURANT_CLOSED_NOW') ? RestaurantIssue.closed : RestaurantIssue.none;
  return CartReview(empty: cart.items.isEmpty, currencyMismatch: server.has('CURRENCY_MISMATCH'), restaurantIssue: restaurant, lineIssues: issues, blocking: !server.canProceed, expired: server.expired, menuUnavailable: server.has('MENU_UNAVAILABLE'), journeyStale: server.has('JOURNEY_STALE'));
}

/// Unit price the line would have today + option names that no longer exist / are unavailable.
(int, List<String>) currentUnitPriceMinor(CartItem item, MenuItemDetail d) {
  final missing = <String>[];
  var adj = 0;
  for (final o in item.allOptions) {
    final opt = d.groups.where((g) => g.id == o.groupId).firstOrNull?.options.where((x) => x.id == o.optionId).firstOrNull;
    if (opt == null || !opt.available) { missing.add(o.optionName); } else { adj += opt.priceAdjustmentMinor; }
  }
  final v = d.item.basePriceMinor + adj;
  return (v < 0 ? 0 : v, missing);
}

Future<CartReview> reviewCart(Cart? cart, GlobalRestaurant? restaurant, MenuRepository menu, {StaleSimulation simulate = StaleSimulation.none, DateTime? now}) async {
  if (cart == null || cart.items.isEmpty) return const CartReview(empty: true, currencyMismatch: false, restaurantIssue: RestaurantIssue.none, lineIssues: [], blocking: true);
  final currencyMismatch = cart.items.any((i) => i.currency != cart.currency);
  var restaurantIssue = RestaurantIssue.none;
  if (restaurant != null) {
    final av = computeAvailability(restaurant, (now ?? DateTime.now()).toUtc());
    if (restaurant.status != RestaurantStatus.active) {
      restaurantIssue = RestaurantIssue.inactive;
    } else if (!restaurant.acceptingOrders || simulate == StaleSimulation.notAccepting) {
      restaurantIssue = RestaurantIssue.notAccepting;
    } else if (!(av.status == AvailabilityStatus.open || av.status == AvailabilityStatus.closingSoon) || simulate == StaleSimulation.closed) {
      restaurantIssue = RestaurantIssue.closed;
    }
  }
  final issues = <LineIssue>[];
  final firstWithOptions = cart.items.indexWhere((i) => i.allOptions.isNotEmpty);
  for (var index = 0; index < cart.items.length; index++) {
    final item = cart.items[index];
    MenuItemDetail? d;
    try { d = await menu.getItemDetail(item.restaurantId, item.itemSlug); } catch (_) { d = null; }
    if (d == null || !d.item.isAvailable || (simulate == StaleSimulation.unavailable && index == 0)) { issues.add(LineIssue(item.id, LineIssueKind.unavailable)); continue; }
    final (unit, missing) = currentUnitPriceMinor(item, d);
    final simulatedMissing = simulate == StaleSimulation.modifier && index == firstWithOptions ? item.allOptions.take(1).map((o) => o.optionName).toList() : const <String>[];
    if (missing.isNotEmpty || simulatedMissing.isNotEmpty) { issues.add(LineIssue(item.id, LineIssueKind.modifierUnavailable, optionNames: [...missing, ...simulatedMissing])); continue; }
    final current = simulate == StaleSimulation.price && index == 0 ? (unit * 1.1).round() : unit;
    if (current != item.unitPriceMinor) { issues.add(LineIssue(item.id, LineIssueKind.priceChanged, oldUnitMinor: item.unitPriceMinor, newUnitMinor: current)); continue; }
    if (clampQuantity(d.minimumQuantity, d.maximumQuantity, item.quantity) != item.quantity) issues.add(LineIssue(item.id, LineIssueKind.quantity, min: d.minimumQuantity, max: d.maximumQuantity));
  }
  final blocking = currencyMismatch || restaurantIssue == RestaurantIssue.inactive || restaurantIssue == RestaurantIssue.notAccepting || issues.isNotEmpty;
  return CartReview(empty: false, currencyMismatch: currencyMismatch, restaurantIssue: restaurantIssue, lineIssues: issues, blocking: blocking);
}

/* -------- Promotions (development fixtures; REAL PROMOTION VALIDATION = FUTURE BACKEND) -------- */
enum PromoStatus { idle, applied, invalid, expired, minSpend }

class PromoState {
  const PromoState({this.code = '', this.status = PromoStatus.idle, this.percent = 0, this.minSpendMinor = 0});
  final String code;
  final PromoStatus status;
  final int percent, minSpendMinor;
  static const none = PromoState();
}

const _promoFixtures = <String, ({int percent, bool expired, num minSpendMajor})>{
  'WELCOME10': (percent: 10, expired: false, minSpendMajor: 0),
  'TRAVEL5': (percent: 5, expired: false, minSpendMajor: 20),
  'EXPIRED': (percent: 20, expired: true, minSpendMajor: 0),
};

PromoState evaluatePromo(String code, int subtotalMinor, int minorDigits) {
  final key = code.trim().toUpperCase();
  final f = _promoFixtures[key];
  if (key.isEmpty || f == null) return PromoState(code: key, status: PromoStatus.invalid);
  if (f.expired) return PromoState(code: key, status: PromoStatus.expired);
  var scale = 1; for (var i = 0; i < minorDigits; i++) { scale *= 10; }
  final minSpend = (f.minSpendMajor * scale).round();
  if (subtotalMinor < minSpend) return PromoState(code: key, status: PromoStatus.minSpend, percent: f.percent, minSpendMinor: minSpend);
  return PromoState(code: key, status: PromoStatus.applied, percent: f.percent, minSpendMinor: minSpend);
}

int discountMinor(int subtotalMinor, PromoState promo) => promo.status == PromoStatus.applied ? ((subtotalMinor * promo.percent / 100).round()).clamp(0, subtotalMinor) : 0;
int estimatedTotalMinor(int subtotalMinor, int discount) => (subtotalMinor - discount) < 0 ? 0 : subtotalMinor - discount;
