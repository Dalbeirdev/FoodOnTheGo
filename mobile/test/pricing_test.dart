import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/discovery/discovery_repository.dart' show globalRestaurants;
import 'package:foodonthego/menu/menu_options.dart';
import 'package:foodonthego/menu/menu_repository.dart';
import 'package:foodonthego/pricing/pricing_service.dart';

/// Module 08 — LocalPricingService (Android). Generic fixture: rules only, no Small/Medium/Large logic.
void main() {
  const base = MenuItem(id: 'r1:bowl', slug: 'bowl', restaurantId: 'r1', categoryId: 'c', name: 'Bowl', description: '', image: '', basePriceMinor: 1250, currency: 'EUR', customizable: true);
  const item = MenuItemDetail(
    item: base, restaurantSlug: 'r1', minimumQuantity: 1, maximumQuantity: 5, instructionsMaxLength: 20,
    variantGroups: [OptionGroup(id: 'g-portion', kind: OptionGroupKind.variant, name: 'Portion', required: true, minSelections: 1, maxSelections: 1, displayOrder: 0, options: [
      OptionChoice(id: 'p-s', name: 'A', priceAdjustmentMinor: -200), OptionChoice(id: 'p-l', name: 'B', priceAdjustmentMinor: 300, displayOrder: 1),
    ])],
    modifierGroups: [
      OptionGroup(id: 'g-extras', kind: OptionGroupKind.modifier, name: 'Extras', required: false, minSelections: 0, maxSelections: 2, displayOrder: 1, options: [
        OptionChoice(id: 'e-1', name: 'X', priceAdjustmentMinor: 100), OptionChoice(id: 'e-2', name: 'Y', priceAdjustmentMinor: 150, displayOrder: 1), OptionChoice(id: 'e-3', name: 'Z', priceAdjustmentMinor: 0, displayOrder: 2), OptionChoice(id: 'e-off', name: 'Off', priceAdjustmentMinor: 500, available: false, displayOrder: 3),
      ]),
      OptionGroup(id: 'g-two', kind: OptionGroupKind.modifier, name: 'Pick two', required: true, minSelections: 2, maxSelections: 2, displayOrder: 2, options: [
        OptionChoice(id: 't-1', name: '1', priceAdjustmentMinor: 0, defaultSelected: true), OptionChoice(id: 't-2', name: '2', priceAdjustmentMinor: 0, defaultSelected: true, displayOrder: 1), OptionChoice(id: 't-3', name: '3', priceAdjustmentMinor: 0, displayOrder: 2),
      ]),
    ],
  );

  test('TEST 2 — required variant missing blocks; selecting it passes', () {
    final sel = defaultSelections(item);
    expect(validateSelections(item, sel).map((i) => i.code), [IssueCode.required]);
    final next = selectOption(item, sel, 'g-portion', 'p-l').selections;
    expect(validateSelections(item, next), isEmpty);
  });
  test('TEST 3 — price = base + variant + modifiers, integers only', () {
    var sel = selectOption(item, defaultSelections(item), 'g-portion', 'p-l').selections;
    sel = selectOption(item, sel, 'g-extras', 'e-1').selections;
    sel = selectOption(item, sel, 'g-extras', 'e-3').selections;
    expect(unitPriceMinor(item, sel), 1250 + 300 + 100);
    expect(unitPriceMinor(item, selectOption(item, sel, 'g-portion', 'p-s').selections), 1250 - 200 + 100);
  });
  test('TEST 4 — max selections prevents a third choice', () {
    var sel = selectOption(item, defaultSelections(item), 'g-extras', 'e-1').selections;
    sel = selectOption(item, sel, 'g-extras', 'e-2').selections;
    final third = selectOption(item, sel, 'g-extras', 'e-3');
    expect(third.applied, isFalse); expect(third.reason, SelectReason.max);
    expect(sel['g-extras'], ['e-1', 'e-2']);
    expect(selectOption(item, sel, 'g-extras', 'e-1').selections['g-extras'], ['e-2']);
  });
  test('exactly-2 group: defaults satisfy, removing one fails with min', () {
    final sel = selectOption(item, defaultSelections(item), 'g-portion', 'p-s').selections;
    expect(validateSelections(item, sel), isEmpty);
    final less = selectOption(item, sel, 'g-two', 't-1').selections;
    expect(validateSelections(item, less), [const GroupIssue('g-two', IssueCode.min, min: 2)]);
  });
  test('TEST 5 — quantity clamped, totals multiply', () {
    expect(clampQuantity(1, 5, 0), 1); expect(clampQuantity(1, 5, -3), 1); expect(clampQuantity(1, 5, 99), 5);
    expect(lineTotalMinor(1650, 3), 4950);
  });
  test('TEST 7 — unavailable option cannot be selected; stale selection invalid', () {
    final r = selectOption(item, defaultSelections(item), 'g-extras', 'e-off');
    expect(r.applied, isFalse); expect(r.reason, SelectReason.unavailable);
    expect(validateSelections(item, {...defaultSelections(item), 'g-portion': ['p-s'], 'g-extras': ['e-off']}).any((i) => i.code == IssueCode.unavailable), isTrue);
  });
  test('TEST 8 / 22 — orderability', () {
    final r = globalRestaurants.firstWhere((x) => x.acceptingOrders);
    expect(orderability(base, r), Orderability.ok);
    expect(orderability(const MenuItem(id: 'x', slug: 'x', restaurantId: 'r1', categoryId: 'c', name: 'X', description: '', image: '', basePriceMinor: 1, currency: 'EUR', availability: ItemAvailability.soldOut), r), Orderability.itemUnavailable);
  });
  test('TEST 11 / 12 — configuration key', () {
    expect(configurationKey('i', {'g1': ['x', 'y'], 'g2': []}, ' No ice '), configurationKey('i', {'g2': [], 'g1': ['y', 'x']}, 'no ice'));
    expect(configurationKey('i', {'g1': ['x']}, ''), isNot(configurationKey('i', {'g1': ['x', 'y']}, '')));
    expect(configurationKey('i', {'g1': ['x']}, 'extra sauce'), isNot(configurationKey('i', {'g1': ['x']}, '')));
  });
  test('special instructions normalised and capped', () {
    expect(normalizeInstructions('  no   ice\n\nplease  ', 20), 'no ice please');
    expect(normalizeInstructions('x' * 50, 20).length, 20);
  });
  test('fixture option groups scale prices with the currency minor digits (INR ×100, JPY ×1)', () async {
    final repo = MockMenuRepository(latency: Duration.zero);
    final inr = (await repo.getItemDetail('dhaba-junction-ropar', (await repo.getItems('dhaba-junction-ropar')).items.first.slug))!;
    expect(inr.groups.map((g) => g.name), contains('Portion'));
    expect(inr.groups.expand((g) => g.options).any((o) => o.priceAdjustmentMinor == 4000), isTrue); // Extra gravy ₹40
    final jp = (await repo.getItemDetail('ippudo-shizuoka', (await repo.getItems('ippudo-shizuoka')).items.first.slug))!;
    expect(jp.groups.map((g) => g.name), containsAll(['麺の硬さ', 'トッピング']));
    expect(jp.groups.expand((g) => g.options).any((o) => o.name == 'チャーシュー' && o.priceAdjustmentMinor == 250), isTrue);
    expect(jp.groups.expand((g) => g.options).any((o) => o.name == '味玉' && !o.available), isTrue);
    expect(await repo.getItemDetail('dhaba-junction-ropar', 'classic-burger'), isNull); // belongs to burger-hub
  });
}
