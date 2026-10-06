<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * ONE ACTIVE CART = ONE RESTAURANT LOCATION. An item of another restaurant is refused with a conflict; the cart
 * is replaced only by the explicit, confirmed switch — in one transaction.
 */
class CartRestaurantRuleTest extends TestCase
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

    public function test_an_item_of_another_restaurant_is_refused_and_nothing_is_replaced(): void
    {
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $this->addBurger($this->guest, 2)->assertCreated();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->guest)->assertCreated();

        $conflict = $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $this->guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_restaurant_conflict');
        // A safe summary of what would be lost, and what was asked for — enough for "Clear your cart and start a new one?".
        $conflict->assertJsonPath('error.details.current.restaurant.id', $this->burgerHub->public_id)->assertJsonPath('error.details.current.restaurant.name', 'Burger Hub')
            ->assertJsonPath('error.details.current.item_count', 3)->assertJsonPath('error.details.current.line_count', 2)->assertJsonPath('error.details.current.subtotal_minor', 52000)->assertJsonPath('error.details.current.currency', 'INR')
            ->assertJsonPath('error.details.requested.restaurant.id', $pizzaPoint->public_id)->assertJsonPath('error.details.requested.restaurant.name', 'Pizza Point')->assertJsonPath('error.details.cart_version', 2);
        $this->assertStringNotContainsString('Classic Burger', $conflict->getContent(), 'the summary does not list the items');

        // The cart is exactly as it was.
        $this->cartOf($this->guest)->assertJsonPath('cart.restaurant.id', $this->burgerHub->public_id)->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.version', 2);
        $this->assertSame(1, Cart::query()->count());
    }

    public function test_the_confirmed_switch_replaces_the_cart_atomically(): void
    {
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $old = $this->addBurger($this->guest, 2)->assertCreated()->json('cart.id');

        // Without the confirmation nothing happens.
        $switch = ['restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $pizza->public_id, 'quantity' => 2]];
        foreach ([[], ['confirm' => false], ['confirm' => 'no']] as $unconfirmed) {
            $this->postJson('/api/v1/cart/switch-restaurant', [...$switch, ...$unconfirmed], $this->guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->cartOf($this->guest)->assertJsonPath('cart.id', $old);

        $response = $this->postJson('/api/v1/cart/switch-restaurant', [...$switch, 'confirm' => true], $this->guest)->assertCreated();
        $response->assertJsonPath('cart.restaurant.id', $pizzaPoint->public_id)->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.items.0.name', 'Margherita')->assertJsonPath('cart.items.0.quantity', 2)
            ->assertJsonPath('cart.subtotal_minor', 30000)->assertJsonPath('cart.version', 1)->assertJsonPath('cart.owner', 'guest')
            ->assertJsonPath('replaced.restaurant.name', 'Burger Hub')->assertJsonPath('replaced.item_count', 2)->assertJsonPath('replaced.subtotal_minor', 40000)->assertJsonMissingPath('guest_token');
        $this->assertNotSame($old, $response->json('cart.id'));

        // The old cart is closed (kept for the retention period), the same guest token now answers for the new one.
        $closed = Cart::query()->where('public_id', $old)->firstOrFail();
        $this->assertSame(['ABANDONED', 'restaurant_switch'], [$closed->status->value, $closed->closed_reason->value]);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame(1, $this->activeCarts());
        $this->cartOf($this->guest)->assertJsonPath('cart.id', $response->json('cart.id'));

        // Switching without an item starts an empty cart of the other restaurant.
        $empty = $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $this->burgerHub->public_id], $this->guest)->assertCreated();
        $empty->assertJsonPath('cart.restaurant.id', $this->burgerHub->public_id)->assertJsonPath('cart.line_count', 0)->assertJsonPath('replaced.restaurant.name', 'Pizza Point')->assertJsonMissingPath('line');

        // "Switching" to the restaurant the cart already belongs to replaces nothing.
        $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $this->burgerHub->public_id, 'item' => ['item_id' => $this->fries->public_id]], $this->guest)->assertCreated()
            ->assertJsonPath('cart.id', $empty->json('cart.id'))->assertJsonPath('cart.line_count', 1)->assertJsonMissingPath('replaced');
    }

    public function test_a_failed_switch_leaves_the_old_cart_untouched(): void
    {
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $old = $this->addBurger($this->guest, 2)->assertCreated()->json('cart.id');

        $attempts = [
            // the new item is not valid: quantity above its maximum
            [['restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $pizza->public_id, 'quantity' => 21]], 422, 'quantity_limit'],
            // the item belongs to the OLD restaurant, not the new one
            [['restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $this->fries->public_id]], 404, 'item_not_found'],
            // a restaurant that does not exist
            [['restaurant_id' => '00000000-0000-4000-8000-000000000000', 'item' => ['item_id' => $pizza->public_id]], 404, 'restaurant_not_found'],
            // a stale cart version
            [['restaurant_id' => $pizzaPoint->public_id, 'cart_version' => 7], 409, 'cart_version_conflict'],
            // a price smuggled in with the item
            [['restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $pizza->public_id, 'unit_price_minor' => 1]], 422, 'validation_failed'],
        ];
        foreach ($attempts as [$body, $status, $code]) {
            $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, ...$body], $this->guest)->assertStatus($status)->assertJsonPath('error.code', $code);
        }

        // Not half-cleared, not closed, not replaced.
        $this->cartOf($this->guest)->assertJsonPath('cart.id', $old)->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.version', 1);
        $this->assertSame([1, 1], [Cart::query()->count(), CartItem::query()->count()]);
    }

    public function test_two_locations_of_the_same_organization_are_two_restaurants(): void
    {
        // Same organization, another outlet with its own menu.
        $second = $this->eligible('Burger Hub Sector 18', 28.57, 77.32, organization: $this->organization);
        $theirFries = $this->item($this->category($this->menuOf($second), 'Sides'), 'Masala Fries', 13000);
        $this->assertSame($this->burgerHub->organization_id, $second->organization_id);

        $this->addBurger($this->guest)->assertCreated();
        $this->postJson('/api/v1/cart/items', $this->addBody($theirFries), $this->guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_restaurant_conflict')
            ->assertJsonPath('error.details.requested.restaurant.id', $second->public_id);

        // An item id of outlet A under outlet B's id is not an item of B.
        $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $second->public_id, 'item' => ['item_id' => $this->fries->public_id]], $this->guest)->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
        $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $second->public_id, 'item' => ['item_id' => $theirFries->public_id]], $this->guest)->assertCreated()
            ->assertJsonPath('cart.restaurant.id', $second->public_id)->assertJsonPath('cart.items.0.unit_price_minor', 13000);
    }

    public function test_an_empty_cart_has_nothing_to_lose_and_simply_changes_restaurant(): void
    {
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $line = $this->addBurger($this->guest)->assertCreated()->json('line.id');
        $cart = $this->deleteJson('/api/v1/cart/items/'.$line, [], $this->guest)->assertOk()->assertJsonPath('cart.line_count', 0)->json('cart.id');

        $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $this->guest)->assertCreated()->assertJsonPath('cart.id', $cart)->assertJsonPath('cart.restaurant.id', $pizzaPoint->public_id)->assertJsonPath('cart.line_count', 1);
        $this->assertSame(1, Cart::query()->count());
    }

    public function test_a_restaurant_customers_cannot_see_cannot_start_a_cart(): void
    {
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        foreach ([['status' => 'SUSPENDED'], ['status' => 'UNDER_REVIEW'], ['status' => 'DRAFT']] as $state) {
            $pizzaPoint->forceFill($state)->save();
            $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $this->guest)->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
        }
        $pizzaPoint->forceFill(['status' => 'APPROVED'])->save();
        $this->market->forceFill(['status' => 'PAUSED'])->save();
        $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $this->guest)->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
        $this->assertSame(0, Cart::query()->count());
    }
}
