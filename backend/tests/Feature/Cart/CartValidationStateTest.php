<?php

namespace Tests\Feature\Cart;

use App\Enums\MenuItemStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemOption;
use App\Models\Menu;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Services\Menu\MenuService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * A cart is compared with the current menu and restaurant on every read. What changed after the customer added
 * something is reported — the line stays, explained; nothing disappears and no old price is kept quietly.
 */
class CartValidationStateTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    /** @var array{X-Cart-Token: string} */
    private array $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
        $this->guest = $this->guest();
    }

    public function test_a_price_change_is_detected_and_the_current_price_counts(): void
    {
        $plain = $this->item($this->mains, 'Paneer Roll', 20000);
        $line = $this->postJson('/api/v1/cart/items', $this->addBody($plain, [], 2), $this->guest)->assertCreated()->assertJsonPath('cart.subtotal_minor', 40000)->json('line.id');

        // The restaurant raises the price from ₹200 to ₹220 (through the menu service, as the dashboard does).
        app(MenuService::class)->updateItem($plain->refresh(), ['base_price_minor' => 22000, 'version' => $plain->version], $this->staff());

        $cart = $this->cartOf($this->guest);
        $cart->assertJsonPath('cart.validation.status', 'NEEDS_REVIEW')->assertJsonPath('cart.validation.can_proceed', false)
            ->assertJsonPath('cart.items.0.price_changed', true)->assertJsonPath('cart.items.0.unit_price_minor', 22000)->assertJsonPath('cart.items.0.line_subtotal_minor', 44000)
            ->assertJsonPath('cart.items.0.snapshot.unit_price_minor', 20000)->assertJsonPath('cart.items.0.included_in_subtotal', true)->assertJsonPath('cart.subtotal_minor', 44000)
            ->assertJsonPath('cart.items.0.issues.0.code', 'PRICE_CHANGED')->assertJsonPath('cart.items.0.issues.0.severity', 'REVIEW')->assertJsonPath('cart.items.0.issues.0.line_id', $line)
            ->assertJsonPath('cart.items.0.issues.0.details.previous_unit_price_minor', 20000)->assertJsonPath('cart.items.0.issues.0.details.current_unit_price_minor', 22000)
            ->assertJsonPath('cart.validation.menu_changed', true);
        $this->assertIssueCodes(['PRICE_CHANGED', 'MENU_CHANGED'], $cart);
        // Reading does not rewrite the snapshot: the change stays visible until the customer has dealt with it.
        $this->assertSame(20000, CartItem::query()->value('unit_price_minor'));
        $this->cartOf($this->guest)->assertJsonPath('cart.items.0.price_changed', true);

        // "Update my cart": the customer accepts today's prices.
        $this->postJson('/api/v1/cart/refresh', [], $this->guest)->assertOk()->assertJsonPath('result.price_changes_accepted', 1)
            ->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.validation.can_proceed', true)->assertJsonPath('cart.validation.issues', [])
            ->assertJsonPath('cart.items.0.price_changed', false)->assertJsonPath('cart.items.0.snapshot.unit_price_minor', 22000)->assertJsonPath('cart.subtotal_minor', 44000)->assertJsonPath('cart.version', 2);

        // A changed option price is a changed price too.
        $burger = $this->addBurger($this->guest, 1, ['Size' => ['Large']])->assertCreated()->json('line.id');
        MenuOption::query()->whereKey($this->groupAndOption($this->burger, 'Size', 'Large')['option']->id)->update(['price_adjustment_minor' => 7000]);
        $after = $this->cartOf($this->guest);
        $changed = collect($after->json('cart.items'))->firstWhere('id', $burger);
        $this->assertSame([27000, 25000, 'PRICE_CHANGED', 7000], [$changed['unit_price_minor'], $changed['snapshot']['unit_price_minor'], $changed['issues'][0]['code'], $changed['variants'][0]['options'][0]['price_adjustment_minor']]);
        $after->assertJsonPath('cart.subtotal_minor', 44000 + 27000);

        // Changing a line writes it at today's price: the customer saw it on the item page.
        $this->patchJson('/api/v1/cart/items/'.$burger, ['quantity' => 2], $this->guest)->assertOk()->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.subtotal_minor', 44000 + 54000);
    }

    public function test_an_item_that_became_unavailable_stays_in_the_cart_with_its_issue(): void
    {
        $burger = $this->addBurger($this->guest, 2, ['Size' => ['Large']])->assertCreated()->json('line.id');
        $fries = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1), $this->guest)->assertCreated()->json('line.id');

        foreach ([[MenuItemStatus::SoldOut, 'ITEM_SOLD_OUT'], [MenuItemStatus::TemporarilyUnavailable, 'ITEM_TEMPORARILY_UNAVAILABLE'], [MenuItemStatus::Disabled, 'ITEM_DISABLED'], [MenuItemStatus::Archived, 'ITEM_REMOVED']] as [$status, $code]) {
            $this->burger->forceFill(['status' => $status])->save();
            $cart = $this->cartOf($this->guest);
            $cart->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.validation.can_proceed', false)->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.item_count', 3)
                ->assertJsonPath('cart.items.0.id', $burger)->assertJsonPath('cart.items.0.name', 'Classic Burger')->assertJsonPath('cart.items.0.quantity', 2)
                ->assertJsonPath('cart.items.0.issues.0.code', $code)->assertJsonPath('cart.items.0.issues.0.severity', 'BLOCKING')->assertJsonPath('cart.items.0.availability.orderable', false)
                ->assertJsonPath('cart.items.0.included_in_subtotal', false)
                // The subtotal is what can be ordered: the fries alone.
                ->assertJsonPath('cart.subtotal_minor', 12000)->assertJsonPath('cart.items.1.id', $fries)->assertJsonPath('cart.items.1.issues', []);
        }
        $this->assertSame(2, CartItem::query()->count(), 'nothing was deleted behind the customer');

        // Such a line cannot be changed, only removed — or it comes back when the restaurant restocks.
        $this->patchJson('/api/v1/cart/items/'.$burger, ['quantity' => 1], $this->guest)->assertStatus(409)->assertJsonPath('error.code', 'item_unavailable');
        $this->burger->forceFill(['status' => MenuItemStatus::Active])->save();
        $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.subtotal_minor', 50000 + 12000);

        // A switched-off category takes its items off the menu for now.
        $this->mains->forceFill(['status' => 'INACTIVE'])->save();
        $off = $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.subtotal_minor', 0);
        $this->assertSame(['ITEM_DISABLED', 'ITEM_DISABLED'], array_column($off->json('cart.validation.issues'), 'code'));

        $this->deleteJson('/api/v1/cart/items/'.$burger, [], $this->guest)->assertOk()->assertJsonPath('cart.line_count', 1);
    }

    public function test_an_option_that_became_unavailable_asks_the_customer_to_edit_the_line(): void
    {
        $line = $this->addBurger($this->guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese', 'Olives']])->assertCreated()->assertJsonPath('cart.subtotal_minor', 28500)->json('line.id');
        $cheese = $this->groupAndOption($this->burger, 'Add-ons', 'Extra Cheese');

        MenuOption::query()->whereKey($cheese['option']->id)->update(['status' => 'TEMPORARILY_UNAVAILABLE']);
        $cart = $this->cartOf($this->guest);
        $cart->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.items.0.issues.0.code', 'OPTION_UNAVAILABLE')->assertJsonPath('cart.items.0.issues.0.details.options', ['Extra Cheese'])
            // The paid option is still listed (as the customer chose it) and marked; nothing was swapped or dropped.
            ->assertJsonPath('cart.items.0.modifiers.0.options.0.name', 'Extra Cheese')->assertJsonPath('cart.items.0.modifiers.0.options.0.available', false)
            ->assertJsonPath('cart.items.0.modifiers.0.options.1.name', 'Olives')->assertJsonPath('cart.items.0.modifiers.0.options.1.available', true)
            ->assertJsonPath('cart.items.0.priced_from', 'snapshot')->assertJsonPath('cart.items.0.unit_price_minor', 28500)->assertJsonPath('cart.items.0.included_in_subtotal', false)->assertJsonPath('cart.subtotal_minor', 0);
        $this->assertSame(3, CartItemOption::query()->count());

        // The quantity cannot be changed while the selection is invalid; choosing again fixes the line.
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2], $this->guest)->assertStatus(409)->assertJsonPath('error.code', 'option_unavailable');
        $this->patchJson('/api/v1/cart/items/'.$line, ['selections' => $this->choose($this->burger, ['Size' => ['Large'], 'Add-ons' => ['Olives']])], $this->guest)->assertOk()
            ->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.items.0.unit_price_minor', 26500);

        // A whole group taken away (archived) is reported with the options the customer had chosen from it.
        MenuOptionGroup::query()->whereKey($cheese['group']->id)->update(['status' => 'ARCHIVED']);
        $this->cartOf($this->guest)->assertJsonPath('cart.items.0.issues.0.code', 'OPTION_UNAVAILABLE')->assertJsonPath('cart.items.0.issues.0.details.options', ['Olives']);
        MenuOptionGroup::query()->whereKey($cheese['group']->id)->update(['status' => 'ACTIVE']);

        // A group that became required, or a rule that got stricter, needs a new choice.
        $fries = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->guest)->assertCreated()->json('line.id');
        app(MenuService::class)->updateItem($this->fries->refresh(), ['version' => $this->fries->version, 'groups' => [['kind' => 'VARIANT', 'name' => 'Portion', 'required' => true, 'min_selections' => 1, 'max_selections' => 1, 'options' => [['name' => 'Half', 'price_adjustment_minor' => 0], ['name' => 'Full', 'price_adjustment_minor' => 4000]]]]], $this->staff());
        $needs = collect($this->cartOf($this->guest)->json('cart.items'))->firstWhere('id', $fries);
        $this->assertSame(['REQUIRED_CUSTOMIZATION_MISSING', 'Portion', 'BLOCKING'], [$needs['issues'][0]['code'], $needs['issues'][0]['details']['group'], $needs['issues'][0]['severity']]);

        // The item's own maximum lowered below what is in the cart.
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 5], $this->guest)->assertOk();
        $this->burger->forceFill(['max_quantity' => 3])->save();
        $limited = collect($this->cartOf($this->guest)->json('cart.items'))->firstWhere('id', $line);
        $this->assertSame(['QUANTITY_LIMIT', 3, 5, 26500], [$limited['issues'][0]['code'], $limited['issues'][0]['details']['max'], $limited['quantity'], $limited['unit_price_minor']]);
    }

    public function test_the_state_of_the_restaurant_is_reported_and_never_empties_the_cart(): void
    {
        $this->addBurger($this->guest, 2)->assertCreated();
        $cartId = Cart::query()->value('public_id');

        // Paused by the restaurant.
        $this->burgerHub->forceFill(['accepting_orders' => false])->save();
        $paused = $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.validation.can_proceed', false)->assertJsonPath('cart.restaurant.availability.accepting_orders', false)
            ->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.subtotal_minor', 40000)->assertJsonPath('cart.items.0.issues', []);
        $this->assertIssueCodes(['RESTAURANT_NOT_ACCEPTING'], $paused);
        $this->assertSame(['BLOCKING', 'cart', 'NOT_ACCEPTING_ORDERS'], [$paused->json('cart.validation.issues.0.severity'), $paused->json('cart.validation.issues.0.scope'), $paused->json('cart.validation.issues.0.details.reason')]);
        // Items can still be added while it is paused (people order ahead); the cart says why it cannot proceed.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->guest)->assertCreated()->assertJsonPath('cart.validation.status', 'INVALID');
        $this->burgerHub->forceFill(['accepting_orders' => true])->save();

        // Closed right now is information, not a verdict: the pickup time is chosen later (Module 28).
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 23:30', 'Asia/Kolkata'));
        $closed = $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.validation.can_proceed', true)->assertJsonPath('cart.restaurant.availability.open_now', false);
        $this->assertIssueCodes(['RESTAURANT_CLOSED_NOW'], $closed);
        $this->assertSame(['INFO', '2026-10-08T03:30:00+00:00'], [$closed->json('cart.validation.issues.0.severity'), $closed->json('cart.validation.issues.0.details.opens_next_at')]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));

        // Suspended by an administrator: the restaurant is gone for customers, the cart is not.
        $this->burgerHub->forceFill(['status' => 'SUSPENDED'])->save();
        $suspended = $this->cartOf($this->guest)->assertJsonPath('cart.id', $cartId)->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.line_count', 2)
            ->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.restaurant.visible', false)->assertJsonPath('cart.restaurant.name', 'Burger Hub')->assertJsonMissingPath('cart.restaurant.address');
        $this->assertIssueCodes(['RESTAURANT_UNAVAILABLE'], $suspended);
        // It cannot be added to or changed; it can be emptied.
        $this->addBurger($this->guest)->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
        $this->patchJson('/api/v1/cart/items/'.$suspended->json('cart.items.0.id'), ['quantity' => 1], $this->guest)->assertStatus(409)->assertJsonPath('error.code', 'restaurant_unavailable');
        $this->assertSame(2, CartItem::query()->count());
        $this->burgerHub->forceFill(['status' => 'APPROVED'])->save();

        // The organization suspended has the same effect.
        $this->burgerHub->organization->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertIssueCodes(['RESTAURANT_UNAVAILABLE'], $this->cartOf($this->guest));
        $this->burgerHub->organization->forceFill(['status' => 'APPROVED'])->save();

        // The menu switched off.
        Menu::query()->where('location_id', $this->burgerHub->id)->update(['status' => 'INACTIVE']);
        $noMenu = $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.line_count', 2);
        $this->assertContains('MENU_UNAVAILABLE', array_column($noMenu->json('cart.validation.issues'), 'code'));
        $this->assertContains('ITEM_REMOVED', array_column($noMenu->json('cart.validation.issues'), 'code'));
        Menu::query()->where('location_id', $this->burgerHub->id)->update(['status' => 'ACTIVE']);

        $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.id', $cartId);
        $this->deleteJson('/api/v1/cart', [], $this->guest)->assertOk();
    }

    public function test_a_menu_change_marks_the_cart_for_revalidation_until_it_is_written_again(): void
    {
        $this->addBurger($this->guest)->assertCreated()->assertJsonPath('cart.validation.menu_changed', false);
        $menu = $this->menuOf($this->burgerHub);
        $this->assertSame((int) $menu->catalog_version, (int) Cart::query()->value('menu_catalog_version'));

        // Any customer-visible menu write raises the catalog version — here an unrelated new item.
        $this->item($this->mains, 'Cold Coffee', 9000);
        $changed = $this->cartOf($this->guest)->assertJsonPath('cart.validation.menu_changed', true)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.validation.can_proceed', true)
            ->assertJsonPath('cart.validation.catalog_version', (int) $menu->refresh()->catalog_version);
        $this->assertIssueCodes(['MENU_CHANGED'], $changed);
        $this->assertSame('INFO', $changed->json('cart.validation.issues.0.severity'));

        // The next write (or a refresh) records the version the cart was checked against.
        $this->postJson('/api/v1/cart/refresh', [], $this->guest)->assertOk()->assertJsonPath('cart.validation.menu_changed', false)->assertJsonPath('cart.validation.issues', []);
        $this->assertSame((int) $menu->catalog_version, (int) Cart::query()->value('menu_catalog_version'));
    }

    public function test_one_cart_one_currency(): void
    {
        $this->addBurger($this->guest)->assertCreated()->assertJsonPath('cart.currency', 'INR')->assertJsonPath('cart.items.0.currency', 'INR');

        // A location whose currency no longer matches its cart (it cannot happen through the API; the cart still notices).
        $this->burgerHub->forceFill(['currency' => 'AED'])->save();
        $cart = $this->cartOf($this->guest)->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.currency', 'INR');
        $this->assertContains('CURRENCY_MISMATCH', array_column($cart->json('cart.validation.issues'), 'code'));
        // …and nothing more can be added to it in another currency.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->guest)->assertStatus(409)->assertJsonPath('error.code', 'currency_mismatch');
        $this->burgerHub->forceFill(['currency' => 'INR'])->save();

        // A cart is in the currency of its restaurant, whatever that is: nothing in the cart domain says "INR".
        [$dubai, $shawarma] = $this->otherRestaurant('Dubai Grill');
        $dubai->forceFill(['currency' => 'AED'])->save();
        Menu::query()->where('location_id', $dubai->id)->update(['currency' => 'AED']);
        $shawarma->forceFill(['currency' => 'AED'])->save();
        $other = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($shawarma, [], 2), $other)->assertCreated()->assertJsonPath('cart.currency', 'AED')->assertJsonPath('cart.items.0.currency', 'AED')->assertJsonPath('cart.subtotal_minor', 30000);
    }
}
