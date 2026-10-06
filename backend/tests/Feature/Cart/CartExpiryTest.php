<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * Carts do not live forever: a guest's 7 days, a customer's 30, renewed by every change. An expired cart says so
 * and can only be cleared; the cleanup closes and later deletes what nobody came back for.
 */
class CartExpiryTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_lifetimes_come_from_configuration_and_every_change_renews_them(): void
    {
        $guest = $this->guest();
        $created = $this->addBurger($guest)->assertCreated();
        $this->assertSame(now()->addHours(168)->utc()->toIso8601String(), $created->json('cart.expires_at'));

        // Six days later a change renews the lifetime; reading alone does not.
        $this->travel(6)->days();
        $this->cartOf($guest)->assertJsonPath('cart.expires_at', $created->json('cart.expires_at'));
        $renewed = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated();
        $this->assertSame(now()->addHours(168)->utc()->toIso8601String(), $renewed->json('cart.expires_at'));

        // A customer's cart lives longer; both figures are configuration.
        [, $session] = $this->signedIn();
        $this->assertSame(now()->addHours(720)->utc()->toIso8601String(), $this->addBurger($session)->assertCreated()->json('cart.expires_at'));
        config(['cart.ttl.guest_hours' => 2, 'cart.ttl.customer_hours' => 48]);
        $this->assertSame(now()->addHours(48)->utc()->toIso8601String(), $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($session))->assertCreated()->json('cart.expires_at'));
        $this->app['auth']->forgetGuards();
        $this->assertSame(now()->addHours(2)->utc()->toIso8601String(), $this->addBurger($this->guest())->assertCreated()->json('cart.expires_at'));
    }

    public function test_an_expired_cart_says_so_cannot_be_changed_and_is_replaced_only_by_clearing_it(): void
    {
        $guest = $this->guest();
        $line = $this->addBurger($guest, 2)->assertCreated()->json('line.id');
        $old = Cart::query()->value('public_id');

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $expired = $this->cartOf($guest);
        // The customer still sees what they had, and why it cannot go on.
        $expired->assertJsonPath('cart.id', $old)->assertJsonPath('cart.status', 'EXPIRED')->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.items.0.quantity', 2)
            ->assertJsonPath('cart.validation.status', 'INVALID')->assertJsonPath('cart.validation.can_proceed', false);
        $this->assertContains('CART_EXPIRED', array_column($expired->json('cart.validation.issues'), 'code'));

        // No change of any kind is applied to it.
        $attempts = [
            fn () => $this->addBurger($guest),
            fn () => $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest),
            fn () => $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 5], $guest),
            fn () => $this->deleteJson('/api/v1/cart/items/'.$line, [], $guest),
            fn () => $this->postJson('/api/v1/cart/refresh', [], $guest),
            fn () => $this->deleteJson('/api/v1/cart/journey', [], $guest),
        ];
        foreach ($attempts as $attempt) {
            $attempt()->assertStatus(410)->assertJsonPath('error.code', 'cart_expired')->assertJsonStructure(['error' => ['details' => ['expired_at']]]);
        }
        $this->assertSame([1, 2, 1], [Cart::query()->count(), (int) CartItem::query()->value('quantity'), (int) Cart::query()->value('version')]);

        // Clearing is the way out; then a new cart starts.
        $this->deleteJson('/api/v1/cart', [], $guest)->assertOk()->assertJsonPath('cart', null);
        $this->assertSame(['EXPIRED', 'expired'], [Cart::query()->where('public_id', $old)->value('status')->value, Cart::query()->where('public_id', $old)->value('closed_reason')->value]);
        $new = $this->addBurger($guest)->assertCreated()->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.items.0.quantity', 1)->assertJsonMissingPath('guest_token');
        $this->assertNotSame($old, $new->json('cart.id'));

        // A confirmed switch away from an expired cart is the same clearing, in one step.
        $this->travel(8)->days();
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $pizza->public_id]], $guest)->assertCreated()
            ->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.restaurant.name', 'Pizza Point')->assertJsonPath('replaced.restaurant.name', 'Burger Hub');
        $this->assertSame(1, $this->activeCarts());
    }

    public function test_the_cleanup_closes_expired_carts_and_deletes_closed_ones_after_retention(): void
    {
        // Three guests and a customer, all with something in their carts.
        $stale = $this->guest();
        $this->addBurger($stale, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();
        $cleared = $this->guest();
        $this->addBurger($cleared)->assertCreated();
        $this->deleteJson('/api/v1/cart', [], $cleared)->assertOk();
        [, $session] = $this->signedIn();
        $this->addBurger($session, 3)->assertCreated();
        // A cart an order was created from (Module 31 will set this): the hand-over record.
        $converted = $this->guest();
        $this->addBurger($converted)->assertCreated();
        Cart::query()->where('guest_token_hash', hash('sha256', $converted['X-Cart-Token']))->update(['status' => 'CONVERTED', 'closed_at' => now(), 'converted_at' => now()]);

        // Nothing is due yet.
        $this->artisan('carts:prune')->expectsOutput('Closed 0 expired cart(s); deleted 0 closed cart(s) past retention.')->assertSuccessful();

        // 7 days: the guest cart is past its lifetime but inside the grace period (24 h) — still the guest's to see.
        $this->travel(7)->days();
        $this->travel(1)->hours();
        $this->artisan('carts:prune')->expectsOutput('Closed 0 expired cart(s); deleted 0 closed cart(s) past retention.')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->cartOf($stale)->assertJsonPath('cart.status', 'EXPIRED');

        // After the grace period it is closed; the customer's cart (30 days) is untouched.
        $this->travel(24)->hours();
        $this->artisan('carts:prune')->expectsOutput('Closed 1 expired cart(s); deleted 0 closed cart(s) past retention.')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['ACTIVE', 'EXPIRED', 'ABANDONED', 'CONVERTED'], Cart::query()->pluck('status')->map->value->all());
        // Closed, the guest no longer has a cart — and can start a new one with the same token.
        $this->app['auth']->forgetGuards();
        $this->cartOf($stale)->assertJsonPath('cart', null)->assertJsonMissingPath('guest_token');
        // The lines of a closed cart are kept for the retention period.
        $this->assertSame(4, CartItem::query()->count());

        // 30 days after they were closed, closed carts go — with their lines and options; CONVERTED never does.
        $this->travel(31)->days();
        $this->artisan('carts:prune')->assertSuccessful();
        $left = Cart::query()->pluck('status')->map->value->all();
        $this->assertEqualsCanonicalizing(['CONVERTED', 'EXPIRED'], $left, 'the cleared and the expired guest carts were deleted; the customer cart just expired and was closed; the converted one stays');
        $this->assertSame(2, CartItem::query()->count());
        $this->assertSame(0, CartItemOption::query()->whereNotIn('cart_item_id', CartItem::query()->select('id'))->count());
        $this->assertSame(1, Cart::query()->where('status', 'CONVERTED')->count());

        // The menu rows the deleted lines pointed at are untouched.
        $this->assertNotNull($this->burger->fresh());
    }

    public function test_an_old_guest_token_cannot_start_a_new_cart_but_keeps_answering_for_its_living_one(): void
    {
        $guest = $this->guest();
        // 31 days later the token was never used: it is too old to start a cart, so a new one is issued.
        $this->travel(31)->days();
        $started = $this->addBurger($guest)->assertCreated();
        $this->assertNotNull($started->json('guest_token'));
        $this->assertNotSame($guest['X-Cart-Token'], $started->json('guest_token'));

        // A token with a living cart keeps working however old it is, as long as the cart is renewed.
        $active = ['X-Cart-Token' => $started->json('guest_token')];
        foreach (range(1, 6) as $ignored) {
            $this->travel(6)->days();
            $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $active)->assertCreated()->assertJsonMissingPath('guest_token');
        }
        $this->cartOf($active)->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.line_count', 2)->assertJsonPath('cart.items.1.quantity', 6);
    }
}
