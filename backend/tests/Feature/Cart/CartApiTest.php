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
 * The cart as a guest and as a customer use it: fetch, add, change, remove, clear — priced by the backend.
 * Wednesday 2026-10-07 13:00 in India: the restaurant is open.
 */
class CartApiTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_a_guest_gets_a_token_and_no_cart_until_the_first_item(): void
    {
        $response = $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('cart', null);
        $token = $response->json('guest_token');
        $this->assertMatchesRegularExpression('/^gc1\.[0-9a-z]+\.[0-9a-f]{64}\.[0-9a-f]{32}$/', $token);

        // Asking again with the token: still no cart, and no second token.
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertOk()->assertJsonPath('cart', null)->assertJsonMissingPath('guest_token');
        $this->assertSame(0, Cart::query()->count(), 'a token alone creates nothing');
    }

    public function test_the_backend_prices_base_plus_variant_plus_modifier_times_quantity(): void
    {
        $guest = $this->guest();

        // ₹200 + Large ₹50 + Extra Cheese ₹20 = ₹270 a piece; two of them = ₹540.
        $response = $this->addBurger($guest, 2, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();

        $response->assertJsonPath('cart.currency', 'INR')->assertJsonPath('cart.owner', 'guest')->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.version', 1)
            ->assertJsonPath('cart.item_count', 2)->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.subtotal_minor', 54000)
            ->assertJsonPath('cart.items.0.base_price_minor', 20000)->assertJsonPath('cart.items.0.unit_price_minor', 27000)->assertJsonPath('cart.items.0.line_subtotal_minor', 54000)
            ->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.items.0.name', 'Classic Burger')->assertJsonPath('cart.items.0.item.slug', $this->burger->slug)
            ->assertJsonPath('cart.items.0.variants.0.group_name', 'Size')->assertJsonPath('cart.items.0.variants.0.kind', 'VARIANT')->assertJsonPath('cart.items.0.variants.0.options.0.name', 'Large')
            ->assertJsonPath('cart.items.0.variants.0.options.0.price_adjustment_minor', 5000)
            ->assertJsonPath('cart.items.0.modifiers.0.kind', 'MODIFIER')->assertJsonPath('cart.items.0.modifiers.0.options.0.name', 'Extra Cheese')->assertJsonPath('cart.items.0.modifiers.0.options.0.price_adjustment_minor', 2000)
            ->assertJsonPath('cart.items.0.priced_from', 'menu')->assertJsonPath('cart.items.0.price_changed', false)->assertJsonPath('cart.items.0.included_in_subtotal', true)
            ->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.validation.can_proceed', true)->assertJsonPath('cart.validation.issues', [])
            ->assertJsonPath('cart.restaurant.id', $this->burgerHub->public_id)->assertJsonPath('cart.restaurant.name', 'Burger Hub')->assertJsonPath('cart.restaurant.currency', 'INR')
            ->assertJsonPath('cart.restaurant.availability.orderable', true)->assertJsonPath('cart.journey', null)
            ->assertJsonPath('line.merged', false)->assertJsonMissingPath('guest_token');
        $this->assertSame($response->json('cart.items.0.id'), $response->json('line.id'));

        // What is stored is what the backend calculated, in minor units.
        $line = CartItem::query()->firstOrFail();
        $this->assertSame([20000, 27000, 'INR', 2], [$line->base_price_minor, $line->unit_price_minor, $line->currency, $line->quantity]);
        $this->assertSame(['VARIANT', 'MODIFIER'], $line->options()->get()->map(fn ($o) => $o->kind->value)->all());

        // The same answer when the cart is read again.
        $this->cartOf($guest)->assertJsonPath('cart.subtotal_minor', 54000)->assertJsonPath('cart.id', $response->json('cart.id'));
    }

    public function test_two_modifiers_and_a_free_option_are_priced_correctly(): void
    {
        $guest = $this->guest();

        // ₹200 + Extra Cheese ₹20 + Fried... no: Olives ₹15 → not the spec's figures; use a dedicated item for them.
        $item = $this->item($this->mains, 'Loaded Bowl', 20000, [
            ['kind' => 'MODIFIER', 'name' => 'Toppings', 'required' => false, 'min_selections' => 0, 'max_selections' => 3, 'options' => [
                ['name' => 'A', 'price_adjustment_minor' => 2000], ['name' => 'B', 'price_adjustment_minor' => 3000], ['name' => 'Free dip', 'price_adjustment_minor' => 0],
            ]],
        ]);

        // Base ₹200 + A ₹20 + B ₹30 = ₹250 per unit.
        $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Toppings' => ['A', 'B']]), $guest)->assertCreated()
            ->assertJsonPath('cart.items.0.unit_price_minor', 25000)->assertJsonPath('cart.subtotal_minor', 25000);

        // A free option (adjustment 0) is a valid choice of its own and makes a different line at the base price.
        $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Toppings' => ['Free dip']], 3), $guest)->assertCreated()
            ->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.items.1.unit_price_minor', 20000)->assertJsonPath('cart.items.1.line_subtotal_minor', 60000)
            ->assertJsonPath('cart.items.1.modifiers.0.options.0.price_adjustment_minor', 0)->assertJsonPath('cart.subtotal_minor', 85000)->assertJsonPath('cart.item_count', 4);
    }

    public function test_the_same_configuration_raises_the_quantity_and_a_different_one_is_its_own_line(): void
    {
        $guest = $this->guest();
        $first = $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese', 'Olives']], ['special_instructions' => 'No onions'])->assertCreated();

        // Same item, same options ticked in another order, same note written differently → the same line.
        $same = $this->addBurger($guest, 2, ['Add-ons' => ['Olives', 'Extra Cheese'], 'Size' => ['Large']], ['special_instructions' => '  no   ONIONS '])->assertCreated();
        $same->assertJsonPath('line.merged', true)->assertJsonPath('line.id', $first->json('line.id'))->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.items.0.quantity', 3)
            ->assertJsonPath('cart.items.0.special_instructions', 'No onions')->assertJsonPath('cart.version', 2);

        // Another size, or another note, is another line.
        $this->addBurger($guest, 1, ['Size' => ['Regular'], 'Add-ons' => ['Extra Cheese', 'Olives']], ['special_instructions' => 'No onions'])->assertCreated()->assertJsonPath('line.merged', false)->assertJsonPath('cart.line_count', 2);
        $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese', 'Olives']], ['special_instructions' => 'Extra onions'])->assertCreated()->assertJsonPath('cart.line_count', 3);
        $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese', 'Olives']])->assertCreated()->assertJsonPath('cart.line_count', 4);

        // Several different items of the same restaurant share the cart.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 2), $guest)->assertCreated()->assertJsonPath('cart.line_count', 5)
            ->assertJsonPath('cart.item_count', 8)->assertJsonPath('cart.restaurant.id', $this->burgerHub->public_id);
        $this->assertSame(1, $this->activeCarts());
    }

    public function test_a_line_can_be_changed_removed_and_the_cart_cleared(): void
    {
        $guest = $this->guest();
        $lineId = $this->addBurger($guest, 1, ['Size' => ['Regular']])->assertCreated()->json('line.id');
        $friesId = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated()->json('line.id');

        // Quantity only: the configuration stays.
        $this->patchJson('/api/v1/cart/items/'.$lineId, ['quantity' => 4], $guest)->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 4)->assertJsonPath('cart.items.0.line_subtotal_minor', 80000)->assertJsonPath('cart.version', 3)->assertJsonPath('line.id', $lineId);

        // Options and note: priced again by the backend.
        $this->patchJson('/api/v1/cart/items/'.$lineId, ['selections' => $this->choose($this->burger, ['Size' => ['Large'], 'Add-ons' => ['Jalapeños']]), 'special_instructions' => 'Well done'], $guest)->assertOk()
            ->assertJsonPath('cart.items.0.unit_price_minor', 26000)->assertJsonPath('cart.items.0.quantity', 4)->assertJsonPath('cart.items.0.special_instructions', 'Well done')
            ->assertJsonPath('cart.subtotal_minor', 4 * 26000 + 12000);

        // An empty note removes it; nothing to change is a validation error.
        $this->patchJson('/api/v1/cart/items/'.$lineId, ['special_instructions' => ''], $guest)->assertOk()->assertJsonPath('cart.items.0.special_instructions', null);
        $this->patchJson('/api/v1/cart/items/'.$lineId, [], $guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        // Changing a line into the configuration of another line makes them one.
        $otherId = $this->addBurger($guest, 2, ['Size' => ['Regular']])->assertCreated()->assertJsonPath('cart.line_count', 3)->json('line.id');
        $merged = $this->patchJson('/api/v1/cart/items/'.$lineId, ['selections' => $this->choose($this->burger, ['Size' => ['Regular']])], $guest)->assertOk();
        $merged->assertJsonPath('line.merged', true)->assertJsonPath('line.id', $otherId)->assertJsonPath('cart.line_count', 2);
        $this->assertSame(6, collect($merged->json('cart.items'))->firstWhere('id', $otherId)['quantity']);

        // Remove one line; the cart stays.
        $this->deleteJson('/api/v1/cart/items/'.$friesId, [], $guest)->assertOk()->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.subtotal_minor', 6 * 20000);
        $this->deleteJson('/api/v1/cart/items/'.$friesId, [], $guest)->assertNotFound()->assertJsonPath('error.code', 'cart_line_not_found');

        // Removing the last line leaves an empty ACTIVE cart; it cannot proceed.
        $this->deleteJson('/api/v1/cart/items/'.$otherId, [], $guest)->assertOk()->assertJsonPath('cart.line_count', 0)->assertJsonPath('cart.subtotal_minor', 0)
            ->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.validation.can_proceed', false)->assertJsonPath('cart.validation.status', 'VALID');

        // Clear ends the cart; clearing again is not an error; the next item starts a new cart.
        $old = $this->cartOf($guest)->json('cart.id');
        $this->deleteJson('/api/v1/cart', [], $guest)->assertOk()->assertJsonPath('cart', null);
        $this->deleteJson('/api/v1/cart', [], $guest)->assertOk()->assertJsonPath('cart', null);
        $this->cartOf($guest)->assertJsonPath('cart', null)->assertJsonMissingPath('guest_token');
        $this->assertSame('ABANDONED', Cart::query()->where('public_id', $old)->value('status')->value);
        $new = $this->addBurger($guest)->assertCreated()->assertJsonPath('cart.version', 1)->json('cart.id');
        $this->assertNotSame($old, $new);
    }

    public function test_a_customer_has_one_cart_on_every_device_and_keeps_it_after_signing_out(): void
    {
        [$customer, $phone] = $this->signedIn();
        $this->addBurger($phone, 2)->assertCreated()->assertJsonPath('cart.owner', 'customer')->assertJsonMissingPath('guest_token');

        // A second device of the same customer sees and changes the same cart.
        $tablet = $this->sessionOf($customer, 'tablet');
        $seen = $this->cartOf($tablet)->assertJsonPath('cart.item_count', 2)->assertJsonPath('cart.version', 1);
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $tablet)->assertCreated()->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.id', $seen->json('cart.id'));
        $this->cartOf($this->using($phone))->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.version', 2);
        $this->assertSame(1, $this->activeCarts());

        // Signing out does not hand the cart to whoever uses the device next: a guest sees nothing and gets a token.
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('cart', null);
        $this->assertNotNull($guest->json('guest_token'));
        // A guest who adds something after sign-out gets a cart of their own.
        $this->addBurger(['X-Cart-Token' => $guest->json('guest_token')])->assertCreated()->assertJsonPath('cart.owner', 'guest')->assertJsonPath('cart.item_count', 1);
        $this->assertSame(2, $this->activeCarts());

        // Signing in again: the account's cart is still there.
        $this->cartOf($this->sessionOf($customer, 'phone again'))->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.owner', 'customer');
    }

    public function test_a_mutation_without_any_identity_starts_a_guest_cart_and_hands_out_its_token_once(): void
    {
        $response = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries))->assertCreated()->assertJsonPath('cart.owner', 'guest');
        $token = $response->json('guest_token');
        $this->assertNotNull($token);

        $this->cartOf(['X-Cart-Token' => $token])->assertJsonPath('cart.id', $response->json('cart.id'))->assertJsonMissingPath('guest_token');
        // Only the hash is stored.
        $this->assertSame(hash('sha256', $token), Cart::query()->value('guest_token_hash'));
        $this->assertDatabaseMissing('carts', ['guest_token_hash' => $token]);

        // Without identity there is nothing to change or remove.
        $this->patchJson('/api/v1/cart/items/'.$response->json('line.id'), ['quantity' => 2])->assertNotFound()->assertJsonPath('error.code', 'cart_not_found');
        $this->deleteJson('/api/v1/cart/items/'.$response->json('line.id'))->assertNotFound();
        $this->postJson('/api/v1/cart/refresh')->assertNotFound();
    }

    public function test_the_cart_never_exposes_internal_ids_or_ownership_data(): void
    {
        [, $session] = $this->signedIn();
        $body = $this->addBurger($session, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated()->json();

        $flat = json_encode($body);
        foreach (['customer_id', 'guest_token_hash', 'spent_token_hash', 'restaurant_location_id', 'menu_item_id', 'cart_id', 'signature', 'option_id"', 'group_id":1'] as $internal) {
            $this->assertStringNotContainsString($internal, $flat);
        }
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        foreach ([$body['cart']['id'], $body['cart']['items'][0]['id'], $body['cart']['items'][0]['item']['id'], $body['cart']['items'][0]['variants'][0]['group_id'], $body['cart']['items'][0]['variants'][0]['options'][0]['id'], $body['cart']['restaurant']['id']] as $id) {
            $this->assertMatchesRegularExpression($uuid, $id);
        }
        $this->assertSame(['cart', 'line'], array_keys($body));
    }
}
