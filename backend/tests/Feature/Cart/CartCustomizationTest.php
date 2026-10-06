<?php

namespace Tests\Feature\Cart;

use App\Enums\MenuItemStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * What the backend refuses to put into a cart: selections that break the item's rules, options and items that do
 * not belong, quantities out of bounds, and anything a client must not decide (prices, currency, ownership).
 */
class CartCustomizationTest extends TestCase
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

    public function test_required_groups_and_min_max_rules_are_enforced(): void
    {
        // The required Size is missing.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->burger), $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'invalid_customization')
            ->assertJsonPath('error.details.issues.0.code', 'required')->assertJsonPath('error.details.issues.0.group_id', $this->groupAndOption($this->burger, 'Size', 'Regular')['group']->public_id);

        // Single select: two sizes are one too many.
        $this->addBurger($this->guest, 1, ['Size' => ['Regular', 'Large']])->assertStatus(422)->assertJsonPath('error.code', 'invalid_customization')->assertJsonPath('error.details.issues.0.code', 'max_selections');

        // Multi select: at most three add-ons, and the same option cannot be counted twice.
        $item = $this->item($this->mains, 'Wrap', 15000, [['kind' => 'MODIFIER', 'name' => 'Extras', 'required' => true, 'min_selections' => 2, 'max_selections' => 3, 'options' => [
            ['name' => 'A', 'price_adjustment_minor' => 1000], ['name' => 'B', 'price_adjustment_minor' => 1000], ['name' => 'C', 'price_adjustment_minor' => 1000], ['name' => 'D', 'price_adjustment_minor' => 1000],
        ]]]);
        $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Extras' => ['A']]), $this->guest)->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'min_selections')->assertJsonPath('error.details.issues.0.min', 2);
        $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Extras' => ['A', 'B', 'C', 'D']]), $this->guest)->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'max_selections')->assertJsonPath('error.details.issues.0.max', 3);
        $twice = $this->addBody($item, ['Extras' => ['A', 'B']]);
        $twice['selections'][0]['option_ids'][] = $twice['selections'][0]['option_ids'][0];
        $this->postJson('/api/v1/cart/items', $twice, $this->guest)->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'duplicate_option');
        // The same group sent twice does not double its allowance either.
        $split = $this->addBody($item, ['Extras' => ['A', 'B']]);
        $split['selections'][] = ['group_id' => $split['selections'][0]['group_id'], 'option_ids' => [$this->groupAndOption($item, 'Extras', 'C')['option']->public_id]];
        $this->postJson('/api/v1/cart/items', $split, $this->guest)->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'duplicate_group');

        $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Extras' => ['A', 'B', 'C']]), $this->guest)->assertCreated()->assertJsonPath('cart.items.0.unit_price_minor', 18000);
        $this->assertSame(1, CartItem::query()->count(), 'nothing invalid was stored');
    }

    public function test_an_option_must_belong_to_its_group_and_the_group_to_the_item(): void
    {
        $size = $this->groupAndOption($this->burger, 'Size', 'Large');
        $addons = $this->groupAndOption($this->burger, 'Add-ons', 'Extra Cheese');
        $other = $this->item($this->mains, 'Veg Burger', 18000, $this->standardGroups());
        $otherSize = $this->groupAndOption($other, 'Size', 'Large');

        $body = fn (array $selections): array => ['restaurant_id' => $this->burgerHub->public_id, 'item_id' => $this->burger->public_id, 'selections' => $selections, 'quantity' => 1];

        // A valid option id under the wrong group of the same item.
        $this->postJson('/api/v1/cart/items', $body([['group_id' => $size['group']->public_id, 'option_ids' => [$addons['option']->public_id]]]), $this->guest)
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_customization')->assertJsonPath('error.details.issues.0.code', 'unknown_option');
        // A group (and option) of another item of the same restaurant.
        $this->postJson('/api/v1/cart/items', $body([['group_id' => $otherSize['group']->public_id, 'option_ids' => [$otherSize['option']->public_id]]]), $this->guest)
            ->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'unknown_group');
        // This item's group with another item's option.
        $this->postJson('/api/v1/cart/items', $body([['group_id' => $size['group']->public_id, 'option_ids' => [$otherSize['option']->public_id]]]), $this->guest)
            ->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'unknown_option');
        // Ids that are not ids at all never reach the database.
        $this->postJson('/api/v1/cart/items', $body([['group_id' => 'x', 'option_ids' => ['1 or 1=1']]]), $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_options_and_items_of_another_restaurant_cannot_be_used(): void
    {
        [$pizzaPoint] = $this->otherRestaurant();
        $pizza = $this->item($this->category($this->menuOf($pizzaPoint), 'Specials'), 'Farmhouse', 30000, $this->standardGroups());
        $theirs = $this->groupAndOption($pizza, 'Size', 'Large');
        $mine = $this->groupAndOption($this->burger, 'Size', 'Large');

        // Restaurant A's item with restaurant B's group / option ids.
        $this->postJson('/api/v1/cart/items', ['restaurant_id' => $this->burgerHub->public_id, 'item_id' => $this->burger->public_id, 'selections' => [['group_id' => $theirs['group']->public_id, 'option_ids' => [$theirs['option']->public_id]]]], $this->guest)
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_customization')->assertJsonPath('error.details.issues.0.code', 'unknown_group');
        $this->postJson('/api/v1/cart/items', ['restaurant_id' => $this->burgerHub->public_id, 'item_id' => $this->burger->public_id, 'selections' => [['group_id' => $mine['group']->public_id, 'option_ids' => [$theirs['option']->public_id]]]], $this->guest)
            ->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'unknown_option');

        // Restaurant B's item under restaurant A's id: A has no such item.
        $this->postJson('/api/v1/cart/items', ['restaurant_id' => $this->burgerHub->public_id, 'item_id' => $pizza->public_id, 'selections' => [['group_id' => $theirs['group']->public_id, 'option_ids' => [$theirs['option']->public_id]]]], $this->guest)
            ->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
        $this->assertSame(0, Cart::query()->count());
    }

    public function test_unavailable_options_and_items_cannot_be_newly_added(): void
    {
        // Fried Egg is temporarily unavailable.
        $this->addBurger($this->guest, 1, ['Size' => ['Regular'], 'Add-ons' => ['Fried Egg']])->assertStatus(409)->assertJsonPath('error.code', 'option_unavailable')
            ->assertJsonPath('error.details.issues.0.code', 'option_unavailable');

        // A disabled option is not selectable either.
        MenuOption::query()->whereKey($this->groupAndOption($this->burger, 'Add-ons', 'Olives')['option']->id)->update(['status' => 'DISABLED']);
        $this->addBurger($this->guest, 1, ['Size' => ['Regular'], 'Add-ons' => ['Olives']])->assertStatus(409)->assertJsonPath('error.code', 'option_unavailable');

        // Sold out and temporarily unavailable items are visible but cannot be added; disabled and archived ones do not exist for a customer.
        foreach ([[MenuItemStatus::SoldOut, 409, 'item_unavailable', 'ITEM_SOLD_OUT'], [MenuItemStatus::TemporarilyUnavailable, 409, 'item_unavailable', 'ITEM_TEMPORARILY_UNAVAILABLE'], [MenuItemStatus::Disabled, 404, 'item_not_found', null], [MenuItemStatus::Archived, 404, 'item_not_found', null]] as [$status, $http, $code, $reason]) {
            $this->fries->forceFill(['status' => $status])->save();
            $response = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->guest)->assertStatus($http)->assertJsonPath('error.code', $code);
            if ($reason !== null) {
                $response->assertJsonPath('error.details.reason', $reason);
            }
        }
        // An inactive category hides its items.
        $this->fries->forceFill(['status' => MenuItemStatus::Active])->save();
        $this->mains->forceFill(['status' => 'INACTIVE'])->save();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->guest)->assertNotFound()->assertJsonPath('error.code', 'item_not_found');

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_quantity_is_bounded_by_the_backend(): void
    {
        foreach ([0, -1, 100000, 51, 'two', 1.5] as $quantity) {
            $this->postJson('/api/v1/cart/items', ['restaurant_id' => $this->burgerHub->public_id, 'item_id' => $this->fries->public_id, 'quantity' => $quantity], $this->guest)
                ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        // Within the platform bound but above the item's own maximum (20).
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 21), $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'quantity_limit')->assertJsonPath('error.details.max', 20);

        // Adding more of the same line cannot go past the maximum either — and is never silently capped.
        $line = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 15), $this->guest)->assertCreated()->json('line.id');
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 6), $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'quantity_limit')
            ->assertJsonPath('error.details.in_cart', 15)->assertJsonPath('error.details.requested', 6)->assertJsonPath('error.details.max', 20);
        $this->cartOf($this->guest)->assertJsonPath('cart.items.0.quantity', 15);
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 5), $this->guest)->assertCreated()->assertJsonPath('cart.items.0.quantity', 20);

        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 21], $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'quantity_limit');
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 0], $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->cartOf($this->guest)->assertJsonPath('cart.items.0.quantity', 20);

        // The number of different lines is bounded by configuration.
        config(['cart.limits.lines' => 2]);
        $this->addBurger($this->guest)->assertCreated();
        $this->addBurger($this->guest, 1, ['Size' => ['Large']])->assertStatus(422)->assertJsonPath('error.code', 'cart_line_limit')->assertJsonPath('error.details.max_lines', 2);
        // …but more of an existing line is still fine.
        $this->addBurger($this->guest)->assertCreated()->assertJsonPath('cart.line_count', 2);
    }

    public function test_a_client_cannot_set_prices_currency_ownership_or_status(): void
    {
        $valid = $this->addBody($this->burger, ['Size' => ['Large']]);
        foreach ([
            'price' => 1, 'unit_price_minor' => 1, 'base_price_minor' => 1, 'line_subtotal_minor' => 1, 'subtotal' => 1, 'subtotal_minor' => 1, 'total_minor' => 1, 'currency' => 'USD',
            'customer_id' => 1, 'guest_token' => 'x', 'owner' => 'customer', 'status' => 'CONVERTED', 'version' => 99, 'restaurant_location_id' => 1, 'validation_status' => 'VALID',
            'expires_at' => '2030-01-01T00:00:00Z', 'id' => '00000000-0000-4000-8000-000000000000',
        ] as $field => $value) {
            $fields = $this->postJson('/api/v1/cart/items', [...$valid, $field => $value], $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->json('error.details.fields');
            $this->assertArrayHasKey($field, $fields, $field);
        }
        // A price smuggled in next to an option.
        $withOptionPrice = $valid;
        $withOptionPrice['selections'][0]['price_adjustment_minor'] = -20000;
        $this->postJson('/api/v1/cart/items', $withOptionPrice, $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Cart::query()->count());

        // The same on a change: neither the price nor the item or the restaurant of a line can be rewritten.
        $line = $this->postJson('/api/v1/cart/items', $valid, $this->guest)->assertCreated()->assertJsonPath('cart.items.0.unit_price_minor', 25000)->json('line.id');
        foreach (['unit_price_minor' => 1, 'currency' => 'USD', 'status' => 'CONVERTED', 'item_id' => $this->fries->public_id, 'restaurant_id' => $this->burgerHub->public_id, 'customer_id' => 5] as $field => $value) {
            $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2, $field => $value], $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->cartOf($this->guest)->assertJsonPath('cart.items.0.quantity', 1)->assertJsonPath('cart.items.0.unit_price_minor', 25000)->assertJsonPath('cart.currency', 'INR')->assertJsonPath('cart.status', 'ACTIVE');
    }

    public function test_special_instructions_are_untrusted_plain_text(): void
    {
        $script = '<script>alert("x")</script> <img src=x onerror=alert(1)> no onions & "extra" napkins';
        $response = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['special_instructions' => "  {$script}\n\t"]), $this->guest)->assertCreated();

        // Stored and returned exactly as text: nothing interprets it, nothing is lost, and JSON carries it safely.
        $response->assertJsonPath('cart.items.0.special_instructions', $script);
        $this->assertSame($script, CartItem::query()->value('special_instructions'));
        // It travels as JSON that a browser must not sniff into anything else, and is never cached.
        $response->assertHeader('Content-Type', 'application/json')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Control characters are removed, white space collapsed, Unicode kept (and normalized).
        $this->postJson('/api/v1/cart/items', $this->addBody($this->burger, ['Size' => ['Regular']], 1, ['special_instructions' => "कम\u{0000} तीखा\r\n\r\n  please \u{1F336}\u{FE0F} cafe\u{0301}"]), $this->guest)->assertCreated()
            ->assertJsonPath('cart.items.1.special_instructions', "कम तीखा please \u{1F336}\u{FE0F} caf\u{00E9}");

        // Too long (200 characters after cleaning) is refused, not cut.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['special_instructions' => str_repeat('a', 201)]), $this->guest)
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.fields.special_instructions.0', 'max:200');
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['special_instructions' => str_repeat('न', 200)]), $this->guest)->assertCreated();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['special_instructions' => ['a' => 'b']]), $this->guest)->assertStatus(422);

        // A note is never a way to get something the menu does not offer: it does not change the price.
        $this->cartOf($this->guest)->assertJsonPath('cart.items.0.unit_price_minor', 12000);
    }
}
