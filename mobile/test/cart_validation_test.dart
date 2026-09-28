import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/cart/cart_models.dart';
import 'package:foodonthego/cart/cart_validation.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/menu/menu_repository.dart';

/// Module 09 — cart validation against the current menu + promo evaluation (Android).
void main() {
  final menu = MockMenuRepository(latency: Duration.zero);
  final burgerHub = globalRestaurants.firstWhere((r) => r.id == 'burger-hub');
  CartItem line({String id = 'l1', String slug = 'classic-burger', String menuItemId = 'classic-burger', int unit = 32000, String currency = 'INR', List<SelectedOption> mods = const []}) => CartItem(
        id: id, menuItemId: menuItemId, itemSlug: slug, restaurantId: 'burger-hub', itemName: 'Classic Burger', image: '', basePriceMinor: 25000, currency: currency,
        selectedVariants: const [SelectedOption(groupId: 'classic-burger:size', groupName: 'Size', optionId: 'size:large', optionName: 'Large', priceAdjustmentMinor: 7000)], selectedModifiers: mods,
        specialInstructions: '', quantity: 1, unitPriceMinor: unit, addedAt: DateTime(2026, 9, 28));
  Cart cartOf(List<CartItem> items) => Cart(id: 'c', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', currency: 'INR', items: items, createdAt: DateTime(2026), updatedAt: DateTime(2026));
  final open = DateTime.utc(2026, 9, 28, 7, 30); // 13:00 IST

  test('empty cart blocks; valid cart passes', () async {
    expect((await reviewCart(null, burgerHub, menu)).blocking, isTrue);
    final r = await reviewCart(cartOf([line()]), burgerHub, menu, now: open);
    expect(r.ok, isTrue); expect(r.lineIssues, isEmpty); expect(r.restaurantIssue, RestaurantIssue.none);
  });
  test('TEST 12 — stale unit price reported with old and new', () async {
    final r = await reviewCart(cartOf([line(unit: 30000)]), burgerHub, menu, now: open);
    expect(r.lineIssues.single.kind, LineIssueKind.priceChanged); expect(r.lineIssues.single.oldUnitMinor, 30000); expect(r.lineIssues.single.newUnitMinor, 32000); expect(r.blocking, isTrue);
  });
  test('TEST 11 — sold-out item and unknown item are unavailable', () async {
    expect((await reviewCart(cartOf([line(slug: 'chicken-wings', menuItemId: 'chicken-wings')]), burgerHub, menu, now: open)).lineIssues.single.kind, LineIssueKind.unavailable);
    expect((await reviewCart(cartOf([line(slug: 'nope')]), burgerHub, menu, now: open)).lineIssues.single.kind, LineIssueKind.unavailable);
  });
  test('sold-out modifier requires review', () async {
    final r = await reviewCart(cartOf([line(mods: const [SelectedOption(groupId: 'classic-burger:addons', groupName: 'Add-ons', optionId: 'addons:egg', optionName: 'Fried Egg', priceAdjustmentMinor: 4000)], unit: 36000)]), burgerHub, menu, now: open);
    expect(r.lineIssues.single.kind, LineIssueKind.modifierUnavailable); expect(r.lineIssues.single.optionNames, ['Fried Egg']);
  });
  test('closed warns, not accepting blocks, currency mismatch blocks', () async {
    final closed = await reviewCart(cartOf([line()]), burgerHub, menu, now: DateTime.utc(2026, 9, 28, 22, 30));
    expect(closed.restaurantIssue, RestaurantIssue.closed); expect(closed.blocking, isFalse);
    final na = await reviewCart(cartOf([line()]), burgerHub, menu, simulate: StaleSimulation.notAccepting, now: open);
    expect(na.restaurantIssue, RestaurantIssue.notAccepting); expect(na.blocking, isTrue);
    final cur = await reviewCart(cartOf([line(currency: 'USD')]), burgerHub, menu, now: open);
    expect(cur.currencyMismatch, isTrue); expect(cur.blocking, isTrue);
  });
  test('simulation switches', () async {
    expect((await reviewCart(cartOf([line()]), burgerHub, menu, simulate: StaleSimulation.price, now: open)).lineIssues.single.newUnitMinor, 35200);
    expect((await reviewCart(cartOf([line()]), burgerHub, menu, simulate: StaleSimulation.unavailable, now: open)).lineIssues.single.kind, LineIssueKind.unavailable);
    expect((await reviewCart(cartOf([line()]), burgerHub, menu, simulate: StaleSimulation.modifier, now: open)).lineIssues.single.optionNames, ['Large']);
  });
  test('promotions: applied / invalid / expired / min spend with integer money', () {
    expect(evaluatePromo('welcome10', 50000, 2).status, PromoStatus.applied);
    expect(evaluatePromo('NOPE', 50000, 2).status, PromoStatus.invalid);
    expect(evaluatePromo('EXPIRED', 50000, 2).status, PromoStatus.expired);
    expect(evaluatePromo('TRAVEL5', 1500, 2).status, PromoStatus.minSpend);
    expect(evaluatePromo('TRAVEL5', 2000, 2).status, PromoStatus.applied);
    expect(discountMinor(33333, evaluatePromo('WELCOME10', 33333, 2)), 3333);
    expect(estimatedTotalMinor(33333, 3333), 30000);
    expect(discountMinor(890, evaluatePromo('WELCOME10', 890, 0)), 89);
  });
}
