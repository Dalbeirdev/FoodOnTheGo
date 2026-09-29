import '../cart/cart_models.dart';
import '../state/cart_state.dart' show AddItemInput;
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../menu/menu_options.dart';
import '../menu/menu_repository.dart';
import '../pricing/pricing_service.dart';
import 'order_models.dart';

/// Order history + reorder (Module 15) — Android.
/// History uses lightweight summaries (never the full snapshot); groups are UI mappings of the order status; the payment
/// status stays separate. A reorder is a NEW cart built from the CURRENT restaurant + menu after the customer reviews the
/// differences — nothing from the old order (payment, pickup code, QR, reference, pickup time) is reused.
enum OrderGroup { all, ongoing, completed, cancelled }
enum OrderSort { newest, oldest }
const ongoingStatuses = {OrderStatus.paymentPending, OrderStatus.confirmed, OrderStatus.awaitingRestaurantAcceptance, OrderStatus.accepted, OrderStatus.preparing, OrderStatus.readyForPickup, OrderStatus.pickupVerification};
const completedStatuses = {OrderStatus.pickedUp, OrderStatus.completed};
OrderGroup groupOf(OrderStatus s) => ongoingStatuses.contains(s) ? OrderGroup.ongoing : completedStatuses.contains(s) ? OrderGroup.completed : OrderGroup.cancelled;
bool isTrackable(OrderStatus s) => ongoingStatuses.contains(s) && s != OrderStatus.paymentPending;
bool isReviewable(OrderStatus s) => completedStatuses.contains(s);
bool hasRefund(OrderPaymentStatus p) => p == OrderPaymentStatus.refundPending || p == OrderPaymentStatus.partiallyRefunded || p == OrderPaymentStatus.refunded;

class OrderSummary {
  const OrderSummary({required this.publicId, required this.orderNumber, required this.restaurantName, required this.restaurantSlug, required this.restaurantTimezone, required this.createdAt, required this.pickupAt, required this.itemCount, required this.itemPreview, required this.currency, required this.totalMinor, required this.orderStatus, required this.paymentStatus, required this.reorderEligible});
  final String publicId, orderNumber, restaurantName, restaurantSlug, restaurantTimezone, itemPreview, currency;
  final DateTime createdAt, pickupAt; final int itemCount, totalMinor; final OrderStatus orderStatus; final OrderPaymentStatus paymentStatus; final bool reorderEligible;
  factory OrderSummary.of(Order o) => OrderSummary(publicId: o.publicId, orderNumber: o.orderNumber, restaurantName: o.restaurant.name, restaurantSlug: o.restaurant.slug, restaurantTimezone: o.restaurant.timezone, createdAt: o.createdAt, pickupAt: o.pickup.requestedAt, itemCount: o.itemCount, itemPreview: o.items.map((i) => '${i.itemName} × ${i.quantity}').join(' · '), currency: o.pricing.currency, totalMinor: o.pricing.totalMinor, orderStatus: o.orderStatus, paymentStatus: o.paymentStatus, reorderEligible: groupOf(o.orderStatus) != OrderGroup.ongoing && o.paymentStatus != OrderPaymentStatus.paymentPending);
}
class OrderListQuery {
  const OrderListQuery({this.group = OrderGroup.all, this.query = '', this.sort = OrderSort.newest, this.cursor, this.limit = 5});
  final OrderGroup group; final String query; final OrderSort sort; final String? cursor; final int limit;
}
class OrderPage {
  const OrderPage({required this.items, required this.nextCursor, required this.total});
  final List<OrderSummary> items; final String? nextCursor; final int total;
}
bool matchesQuery(OrderSummary o, String q) { final n = q.trim().toLowerCase(); if (n.isEmpty) return true; return o.orderNumber.toLowerCase().contains(n) || o.restaurantName.toLowerCase().contains(n); }
/// Filter + sort + cursor page (cursor = index into the filtered list; the backend uses its own tokens).
OrderPage pageSummaries(List<OrderSummary> all, OrderListQuery q) {
  final filtered = all.where((o) => (q.group == OrderGroup.all || groupOf(o.orderStatus) == q.group) && matchesQuery(o, q.query)).toList()
    ..sort((a, b) => q.sort == OrderSort.newest ? b.createdAt.compareTo(a.createdAt) : a.createdAt.compareTo(b.createdAt));
  final start = q.cursor == null ? 0 : (int.tryParse(q.cursor!) ?? 0).clamp(0, filtered.length);
  final end = (start + q.limit).clamp(0, filtered.length);
  return OrderPage(items: filtered.sublist(start, end), nextCursor: end < filtered.length ? '$end' : null, total: filtered.length);
}

// ---------------------------------------------------------------------------------------------------------
// Reorder
// ---------------------------------------------------------------------------------------------------------
enum ReorderLineStatus { ok, priceChanged, modifierMissing, unavailable }
enum ReorderRestaurantStatus { ok, notFound, inactive, notAccepting }
class ReorderLine {
  const ReorderLine({required this.lineId, required this.itemName, required this.itemSlug, required this.quantity, required this.status, required this.oldUnitMinor, this.newUnitMinor, this.missingOptions = const [], this.input});
  final String lineId, itemName, itemSlug; final int quantity, oldUnitMinor; final int? newUnitMinor; final ReorderLineStatus status; final List<String> missingOptions;
  /// Ready-to-add cart input built from CURRENT data; null when the item cannot be added as-is.
  final AddItemInput? input;
}
class ReorderPlan {
  const ReorderPlan({required this.restaurantStatus, required this.restaurantId, required this.restaurantSlug, required this.restaurantName, required this.currency, required this.currencyChanged, required this.lines});
  final ReorderRestaurantStatus restaurantStatus; final String restaurantId, restaurantSlug, restaurantName, currency; final bool currencyChanged; final List<ReorderLine> lines;
  List<ReorderLine> get addable => lines.where((l) => l.input != null).toList();
  int get reviewCount => lines.where((l) => l.status == ReorderLineStatus.priceChanged || l.status == ReorderLineStatus.modifierMissing).length;
  int get unavailableCount => lines.where((l) => l.status == ReorderLineStatus.unavailable).length;
}
abstract class ReorderService { Future<ReorderPlan> plan(Order order); }

class MockReorderService implements ReorderService {
  MockReorderService({RestaurantRepository? restaurants, MenuRepository? menu, this.latency = const Duration(milliseconds: 300)}) : _restaurants = restaurants ?? MockRestaurantRepository(), _menu = menu ?? MockMenuRepository();
  final RestaurantRepository _restaurants; final MenuRepository _menu; final Duration latency;
  @override
  Future<ReorderPlan> plan(Order o) async {
    if (latency != Duration.zero) await Future<void>.delayed(latency);
    final r = await _restaurants.getRestaurantBySlug(o.restaurant.slug);
    ReorderPlan empty(ReorderRestaurantStatus st, String name) => ReorderPlan(restaurantStatus: st, restaurantId: o.restaurant.id, restaurantSlug: o.restaurant.slug, restaurantName: name, currency: o.pricing.currency, currencyChanged: false, lines: [for (final it in o.items) ReorderLine(lineId: it.lineId, itemName: it.itemName, itemSlug: it.menuItemId, quantity: it.quantity, status: ReorderLineStatus.unavailable, oldUnitMinor: it.unitPriceMinor)]);
    if (r == null) return empty(ReorderRestaurantStatus.notFound, o.restaurant.name);
    if (r.status != RestaurantStatus.active) return empty(ReorderRestaurantStatus.inactive, r.name);
    if (!r.acceptingOrders) return empty(ReorderRestaurantStatus.notAccepting, r.name);
    final lines = <ReorderLine>[];
    for (final it in o.items) {
      // Snapshot keeps the menu item id; the current document is looked up by slug (ids of the form 'restaurant:slug' carry it).
      final slug = it.menuItemId.contains(':') ? it.menuItemId.split(':').last : it.menuItemId;
      MenuItemDetail? d; try { d = await _menu.getItemDetail(r.id, slug); } catch (_) { d = null; }
      lines.add(resolveLine(it, d, r));
    }
    return ReorderPlan(restaurantStatus: ReorderRestaurantStatus.ok, restaurantId: r.id, restaurantSlug: r.slug, restaurantName: r.name, currency: r.currency, currencyChanged: r.currency != o.pricing.currency, lines: lines);
  }
}

/// Maps an old snapshot line onto the CURRENT item document: groups matched by name, options by name (ids may rotate).
ReorderLine resolveLine(OrderItemSnapshot it, MenuItemDetail? d, GlobalRestaurant r) {
  ReorderLine base(ReorderLineStatus st, {int? newUnit, List<String> missing = const [], AddItemInput? input}) => ReorderLine(lineId: it.lineId, itemName: it.itemName, itemSlug: it.menuItemId, quantity: it.quantity, status: st, oldUnitMinor: it.unitPriceMinor, newUnitMinor: newUnit, missingOptions: missing, input: input);
  if (d == null || !d.item.isAvailable) return base(ReorderLineStatus.unavailable);
  final groups = d.groups; final sel = <String, List<String>>{}; final missing = <String>[]; final chosen = <(OptionGroup, OptionChoice)>[];
  void pick(OrderOptionSnapshot o) {
    final g = groups.where((x) => x.name == o.groupName).firstOrNull; final opt = g?.options.where((x) => x.name == o.optionName).firstOrNull;
    if (g == null || opt == null || !opt.available) { missing.add('${o.groupName}: ${o.optionName}'); return; }
    sel[g.id] = [...(sel[g.id] ?? const <String>[]), opt.id]; chosen.add((g, opt));
  }
  it.variants.forEach(pick); it.modifiers.forEach(pick);
  for (final g in groups) { if (g.required && (sel[g.id]?.length ?? 0) < (g.minSelections < 1 ? 1 : g.minSelections) && !missing.any((m) => m.startsWith('${g.name}:'))) missing.add('${g.name}: —'); }
  if (missing.isNotEmpty || validateSelections(d, sel).isNotEmpty) return base(ReorderLineStatus.modifierMissing, missing: missing);
  final newUnit = unitPriceMinor(d, sel);
  SelectedOption so((OptionGroup, OptionChoice) c) => SelectedOption(groupId: c.$1.id, groupName: c.$1.name, optionId: c.$2.id, optionName: c.$2.name, priceAdjustmentMinor: c.$2.priceAdjustmentMinor);
  final input = AddItemInput(menuItemId: d.item.id, itemSlug: d.item.slug, itemName: d.item.name, image: d.item.image, basePriceMinor: d.item.basePriceMinor, currency: r.currency, restaurantId: r.id, restaurantSlug: r.slug, restaurantName: r.name, restaurantCurrency: r.currency,
      selectedVariants: [for (final c in chosen) if (c.$1.kind == OptionGroupKind.variant) so(c)], selectedModifiers: [for (final c in chosen) if (c.$1.kind == OptionGroupKind.modifier) so(c)], specialInstructions: it.specialInstructions, quantity: it.quantity > d.maximumQuantity ? d.maximumQuantity : it.quantity, unitPriceMinor: newUnit, minimumQuantity: d.minimumQuantity, maximumQuantity: d.maximumQuantity);
  return base(newUnit != it.unitPriceMinor ? ReorderLineStatus.priceChanged : ReorderLineStatus.ok, newUnit: newUnit, input: input);
}
