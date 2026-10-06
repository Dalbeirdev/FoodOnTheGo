<?php

namespace Tests\Feature\Cart;

use App\Enums\CartCloseReason;
use App\Enums\Permission;
use App\Models\Cart;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * The development fixture cart, the cleanup at volume, and the administrators' counters.
 */
class CartFixtureAndMetricsTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    public function test_the_fixture_cart_is_a_real_backend_priced_cart_of_the_second_fixture_customer(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);

        $asha = Customer::query()->where('phone_e164', '+919876543212')->firstOrFail();
        $cart = $this->cartOf($this->sessionOf($asha));
        $cart->assertJsonPath('cart.owner', 'customer')->assertJsonPath('cart.restaurant.slug', 'pizza-point')->assertJsonPath('cart.currency', 'INR')->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.item_count', 3)
            // Margherita ₹249 + Medium ₹150 + Extra Cheese ₹60 (thin crust free) = ₹459; two of them + garlic bread ₹149.
            ->assertJsonPath('cart.items.0.name', 'Margherita')->assertJsonPath('cart.items.0.unit_price_minor', 45900)->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.items.0.special_instructions', 'Well done, please')
            ->assertJsonPath('cart.items.0.variants.0.options.0.name', 'Medium (10")')->assertJsonPath('cart.items.0.variants.1.options.0.name', 'Thin crust')->assertJsonPath('cart.items.0.modifiers.0.options.0.name', 'Extra Cheese')
            ->assertJsonPath('cart.items.1.name', 'Cheese Garlic Bread')->assertJsonPath('cart.items.1.unit_price_minor', 14900)
            ->assertJsonPath('cart.subtotal_minor', 2 * 45900 + 14900)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.journey', null);

        // The account the review flows sign in with starts with an empty cart.
        $rahul = Customer::query()->where('phone_e164', '+919876543210')->firstOrFail();
        $this->cartOf($this->sessionOf($rahul))->assertJsonPath('cart', null);

        // Seeding again changes nothing — also not after the customer changed the cart.
        $this->patchJson('/api/v1/cart/items/'.$cart->json('cart.items.1.id'), ['quantity' => 3], $this->sessionOf($asha))->assertOk();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, Cart::query()->count());
        $this->cartOf($this->sessionOf($asha))->assertJsonPath('cart.items.1.quantity', 3)->assertJsonPath('cart.version', 3);
    }

    public function test_the_cleanup_handles_many_carts_and_only_the_due_ones(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
        $carts = fn () => Cart::factory()->at($this->burgerHub);

        $carts()->count(230)->expiredFor(48)->create();                         // past lifetime and grace: to be closed
        $carts()->count(7)->expiredFor(3)->create();                            // expired, still inside the grace period
        $carts()->count(5)->create();                                           // alive
        $carts()->count(210)->closed(CartCloseReason::Cleared, 45)->create(['guest_token_hash' => null]);   // closed long ago: to be deleted
        $carts()->count(4)->closed(CartCloseReason::Merged, 3)->create(['guest_token_hash' => null]);       // closed recently: kept
        $converted = $carts()->create(['guest_token_hash' => null, 'status' => 'CONVERTED', 'closed_at' => now()->subDays(400), 'converted_at' => now()->subDays(400)]);

        $this->artisan('carts:prune')->expectsOutput('Closed 230 expired cart(s); deleted 210 closed cart(s) past retention.')->assertSuccessful();

        $this->assertSame(['ABANDONED' => 0, 'ACTIVE' => 12, 'CONVERTED' => 1, 'EXPIRED' => 230, 'INVALIDATED' => 4], [
            'ABANDONED' => Cart::query()->where('status', 'ABANDONED')->count(), 'ACTIVE' => Cart::query()->where('status', 'ACTIVE')->count(), 'CONVERTED' => Cart::query()->where('status', 'CONVERTED')->count(),
            'EXPIRED' => Cart::query()->where('status', 'EXPIRED')->count(), 'INVALIDATED' => Cart::query()->where('status', 'INVALIDATED')->count(),
        ]);
        $this->assertSame(230, Cart::query()->where('closed_reason', 'expired')->whereNotNull('closed_at')->count());
        $this->assertNotNull($converted->fresh());
        // Running it again finds nothing more to do.
        $this->artisan('carts:prune')->expectsOutput('Closed 0 expired cart(s); deleted 0 closed cart(s) past retention.')->assertSuccessful();

        // The administrators' numbers reflect the tables and what happened — and nothing else.
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $this->getJson('/api/v1/admin/system/carts')->assertOk()
            ->assertJsonPath('carts.active', 5)->assertJsonPath('carts.active_guest', 5)->assertJsonPath('carts.active_customer', 0)->assertJsonPath('carts.expired_awaiting_cleanup', 7)
            ->assertJsonPath('carts.closed.expired', 230)->assertJsonPath('carts.closed.abandoned', 0)->assertJsonPath('carts.closed.invalidated', 4)->assertJsonPath('carts.closed.converted', 1)
            ->assertJsonPath('events.expired', 230)->assertJsonPath('window_hours', 24)
            ->assertJsonStructure(['checked_at', 'carts' => ['active', 'closed'], 'events' => ['created', 'item_added', 'restaurant_conflict', 'restaurant_switched', 'claimed', 'merged', 'merge_conflict', 'revalidated', 'price_changes_accepted', 'expired'], 'lifetimes']);
    }

    public function test_the_counters_follow_what_customers_do(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $plain = $this->item($this->mains, 'Paneer Roll', 20000);

        $guest = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($plain), $guest)->assertCreated();
        $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $guest)->assertStatus(409);                                    // conflict
        $plain->forceFill(['base_price_minor' => 22000])->save();
        $this->postJson('/api/v1/cart/refresh', [], $guest)->assertOk();                                                           // one accepted price change
        $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $pizza->public_id]], $guest)->assertCreated();
        [, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $session)->assertCreated();
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$guest])->assertStatus(409);                       // merge conflict
        $this->postJson('/api/v1/cart/claim', ['resolution' => 'keep_guest'], [...$this->using($session), ...$guest])->assertOk();   // claimed
        $this->deleteJson('/api/v1/cart', [], $this->using($session))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $events = $this->getJson('/api/v1/admin/system/carts')->assertOk()->json('events');
        $this->assertEquals(
            ['created' => 3, 'item_added' => 3, 'restaurant_conflict' => 1, 'restaurant_switched' => 1, 'revalidated' => 1, 'price_changes_accepted' => 1, 'merge_conflict' => 1, 'claimed' => 1, 'merged' => 0, 'cleared' => 1],
            array_intersect_key($events, array_flip(['created', 'item_added', 'restaurant_conflict', 'restaurant_switched', 'revalidated', 'price_changes_accepted', 'merge_conflict', 'claimed', 'merged', 'cleared'])),
        );
    }
}
