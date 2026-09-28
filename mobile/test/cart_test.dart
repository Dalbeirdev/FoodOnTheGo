import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/data/mock_data.dart' show menuItemById;
import 'package:foodonthego/state/cart_state.dart';

/// Module 08 — centralized CartState (Android): one restaurant per cart, merge identical configurations, persistence.
void main() {
  AddItemInput input({String menuItemId = 'r-a:burger', String restaurantId = 'r-a', String currency = 'INR', String rc = 'INR', String note = '', int qty = 1, List<SelectedOption> mods = const [], int unit = 25000, int max = 20}) => AddItemInput(
        menuItemId: menuItemId, itemSlug: 'burger', itemName: 'Burger', image: '', basePriceMinor: 25000, currency: currency, restaurantId: restaurantId, restaurantSlug: restaurantId, restaurantName: restaurantId == 'r-a' ? 'Restaurant A' : 'Restaurant B', restaurantCurrency: rc,
        selectedVariants: const [SelectedOption(groupId: 'size', groupName: 'Size', optionId: 'size:regular', optionName: 'Regular', priceAdjustmentMinor: 0)], selectedModifiers: mods, specialInstructions: note, quantity: qty, unitPriceMinor: unit, maximumQuantity: max);
  const cheese = SelectedOption(groupId: 'addons', groupName: 'Add-ons', optionId: 'addons:cheese', optionName: 'Extra Cheese', priceAdjustmentMinor: 3000);

  test('TEST 1 / 9 — structured items from one restaurant share one cart', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    expect(cart.status, CartStatus.empty);
    expect(cart.addItem(input()).ok, isTrue);
    expect(cart.addItem(input(menuItemId: 'r-a:fries', qty: 2, unit: 12000)).ok, isTrue);
    expect(cart.status, CartStatus.active);
    expect(cart.cart!.restaurantId, 'r-a'); expect(cart.items.length, 2); expect(cart.count, 3); expect(cart.subtotalMinor, 25000 + 24000);
  });
  test('TEST 11 — identical configuration merges quantities', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(input(note: 'No ice'));
    final r = cart.addItem(input(note: 'no ice '));
    expect(r.merged, isTrue); expect(cart.items.length, 1); expect(cart.items.first.quantity, 2); expect(cart.items.first.lineTotalMinor, 50000);
  });
  test('TEST 12 — different configurations stay separate', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(input());
    cart.addItem(input(mods: const [cheese], unit: 28000));
    cart.addItem(input(note: 'pack separately'));
    expect(cart.items.length, 3); expect(cart.subtotalMinor, 25000 + 28000 + 25000);
  });
  test('TEST 10 — other restaurant raises a conflict; cancel keeps, confirm replaces', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(input());
    final r = cart.addItem(input(menuItemId: 'r-b:taco', restaurantId: 'r-b', currency: 'USD', rc: 'USD', unit: 900));
    expect(r.failure, AddFailure.restaurantConflict); expect(cart.conflict!.current.restaurantName, 'Restaurant A'); expect(cart.cart!.restaurantId, 'r-a');
    cart.cancelReplace(); expect(cart.conflict, isNull); expect(cart.cart!.restaurantId, 'r-a');
    cart.addItem(input(menuItemId: 'r-b:taco', restaurantId: 'r-b', currency: 'USD', rc: 'USD', unit: 900));
    cart.confirmReplace();
    expect(cart.cart!.restaurantId, 'r-b'); expect(cart.currency, 'USD'); expect(cart.count, 1);
  });
  test('currency mismatch and invalid quantity are rejected', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    expect(cart.addItem(input(currency: 'USD')).failure, AddFailure.currencyMismatch);
    expect(cart.addItem(input(qty: 0)).failure, AddFailure.invalidQuantity);
    expect(cart.status, CartStatus.empty);
  });
  test('updateQuantity / removeItem / clearCart respect bounds', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.addItem(input(max: 3));
    final id = cart.items.first.id;
    cart.updateQuantity(id, 10); expect(cart.items.first.quantity, 3);
    cart.updateQuantity(id, 0); expect(cart.status, CartStatus.empty);
    cart.addItem(input()); cart.removeItem(cart.items.first.id); expect(cart.cart, isNull);
    cart.addItem(input()); cart.note = 'x'; cart.clearCart(); expect(cart.count, 0); expect(cart.note, '');
  });
  test('legacy API (Module 01 screens) still works with major-unit lines', () async {
    final cart = CartState(repository: MemoryCartRepository()); await cart.ready;
    cart.add(menuItemById('classic-combo')!, 'burger-hub');
    cart.add(menuItemById('classic-combo')!, 'burger-hub');
    expect(cart.count, 2); expect(cart.lines.length, 1); expect(cart.lines.first.item.price, menuItemById('classic-combo')!.price);
    expect(cart.subtotal, 2 * menuItemById('classic-combo')!.price); expect(cart.comboSaving, 50);
    cart.remove(cart.lines.first.item.id); expect(cart.count, 1);
    cart.removeLine(cart.lines.first.item.id); expect(cart.count, 0);
  });
  test('persists through the repository and hydrates a new state', () async {
    final repo = MemoryCartRepository();
    final first = CartState(repository: repo); await first.ready;
    first.addItem(input(mods: const [cheese], unit: 28000, note: 'hot'));
    await Future<void>.delayed(const Duration(milliseconds: 10));
    final saved = await repo.load();
    expect(saved!.items.first.selectedModifiers.first.optionName, 'Extra Cheese');
    final second = CartState(repository: repo); await second.ready;
    expect(second.count, 1); expect(second.items.first.specialInstructions, 'hot');
    // JSON round-trip keeps structure
    final json = saved.toJson(); final back = Cart.fromJson(json);
    expect(back.items.first.id, saved.items.first.id); expect(back.subtotalMinor, 28000);
  });
}
