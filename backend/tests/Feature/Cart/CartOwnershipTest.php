<?php

namespace Tests\Feature\Cart;

use App\Enums\CustomerStatus;
use App\Enums\Permission;
use App\Models\Cart;
use App\Services\Cart\GuestCartToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * A cart is private: its owner reads and changes it, nobody else can — not another customer, not another guest,
 * not restaurant staff, not an administrator. No request is ever authorized by a cart id or a line id.
 */
class CartOwnershipTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_one_guest_cannot_reach_another_guests_cart(): void
    {
        $a = $this->guest();
        $b = $this->guest();
        $lineA = $this->addBurger($a, 2)->assertCreated()->json('line.id');
        $cartA = Cart::query()->value('public_id');
        $lineB = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $b)->assertCreated()->json('line.id');

        // B knows A's line id (and cart id): it gets B nowhere.
        $this->cartOf($b)->assertJsonPath('cart.items.0.id', $lineB)->assertJsonPath('cart.line_count', 1);
        $this->patchJson('/api/v1/cart/items/'.$lineA, ['quantity' => 9], $b)->assertNotFound()->assertJsonPath('error.code', 'cart_line_not_found');
        $this->deleteJson('/api/v1/cart/items/'.$lineA, [], $b)->assertNotFound()->assertJsonPath('error.code', 'cart_line_not_found');
        foreach (['/api/v1/cart/'.$cartA, '/api/v1/carts/'.$cartA, '/api/v1/cart?id='.$cartA, '/api/v1/cart?cart_id='.$cartA] as $guess) {
            $response = $this->getJson($guess, $b);
            $this->assertTrue($response->status() === 404 || $response->json('cart.items.0.id') === $lineB, $guess);
        }

        // A token that is not one of ours, a tampered one, an empty one: nobody — never somebody else's cart.
        $token = $a['X-Cart-Token'];
        $tampered = substr($token, 0, -1).($token[-1] === '0' ? '1' : '0');
        $otherSecret = preg_replace('/\.[0-9a-f]{64}\./', '.'.str_repeat('a', 64).'.', $token);
        foreach (['', 'gc1.abc', str_repeat('0', 64), $tampered, $otherSecret, 'Bearer '.$token] as $bad) {
            $this->getJson('/api/v1/cart', ['X-Cart-Token' => $bad])->assertOk()->assertJsonPath('cart', null)->assertJsonStructure(['guest_token']);
            $this->patchJson('/api/v1/cart/items/'.$lineA, ['quantity' => 9], ['X-Cart-Token' => $bad])->assertNotFound();
        }
        // The token is not accepted anywhere but its header.
        $this->getJson('/api/v1/cart?cart_token='.$token.'&X-Cart-Token='.$token)->assertOk()->assertJsonPath('cart', null);
        $this->postJson('/api/v1/cart/refresh', ['cart_token' => $token])->assertNotFound();

        // A is untouched.
        $this->cartOf($a)->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.version', 1);
    }

    public function test_one_customer_cannot_reach_another_customers_cart(): void
    {
        [, $asha] = $this->signedIn('+919876543212');
        $lineA = $this->addBurger($asha, 3)->assertCreated()->json('line.id');
        [, $rahul] = $this->signedIn('+919876543210');

        $this->cartOf($rahul)->assertJsonPath('cart', null)->assertJsonMissingPath('guest_token');
        $this->patchJson('/api/v1/cart/items/'.$lineA, ['quantity' => 1], $rahul)->assertNotFound()->assertJsonPath('error.code', 'cart_not_found');
        $this->deleteJson('/api/v1/cart/items/'.$lineA, [], $rahul)->assertNotFound();
        $this->deleteJson('/api/v1/cart', [], $rahul)->assertOk();
        $this->postJson('/api/v1/cart/refresh', [], $rahul)->assertNotFound();

        // With a cart of his own, Asha's line id is still not his.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $rahul)->assertCreated();
        $this->patchJson('/api/v1/cart/items/'.$lineA, ['quantity' => 1], $rahul)->assertNotFound()->assertJsonPath('error.code', 'cart_line_not_found');

        $this->cartOf($this->using($asha))->assertJsonPath('cart.items.0.quantity', 3)->assertJsonPath('cart.version', 1);
        $this->assertSame(2, $this->activeCarts());

        // A signed-in customer is the customer: a guest token sent along does not switch them to that guest's cart.
        $guest = $this->guest();
        $this->addBurger($guest, 5)->assertCreated();
        $this->cartOf([...$this->using($asha), ...$guest])->assertJsonPath('cart.owner', 'customer')->assertJsonPath('cart.items.0.quantity', 3);
    }

    public function test_an_account_that_cannot_be_used_cannot_use_its_cart(): void
    {
        [$customer, $session] = $this->signedIn();
        $this->addBurger($session)->assertCreated();

        $customer->forceFill(['status' => CustomerStatus::Suspended])->save();
        $this->getJson('/api/v1/cart', $this->using($session))->assertStatus(403)->assertJsonPath('error.code', 'account_not_active');
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($session))->assertStatus(403);
    }

    public function test_restaurant_staff_and_administrators_have_no_access_to_carts(): void
    {
        $guest = $this->guest();
        $line = $this->addBurger($guest, 2)->assertCreated()->json('line.id');
        [, $session] = $this->signedIn();
        $this->addBurger($session, 4)->assertCreated();

        // The owner of the very restaurant the carts are for: their token is not a customer's, so they are "nobody" here.
        $owner = $this->staff();
        $this->actingAsPrincipal($owner);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('cart', null);
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 1])->assertNotFound();
        $this->postJson('/api/v1/cart/claim', [], $guest)->assertStatus(401);
        // Nothing in the restaurant or admin API lists or shows a cart.
        foreach (['/api/v1/restaurant/carts', '/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/carts', '/api/v1/admin/carts', '/api/v1/admin/system/carts/'.Cart::query()->value('public_id')] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->getJson('/api/v1/admin/system/carts')->assertStatus(401);

        // An administrator sees numbers, never contents — and needs the system permission for those.
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($this->adminWith([]));
        $this->getJson('/api/v1/admin/system/carts')->assertForbidden();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('cart', null);

        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $numbers = $this->getJson('/api/v1/admin/system/carts')->assertOk();
        $numbers->assertJsonPath('carts.active', 2)->assertJsonPath('carts.active_guest', 1)->assertJsonPath('carts.active_customer', 1)->assertJsonPath('carts.expired_awaiting_cleanup', 0)
            ->assertJsonPath('events.created', 2)->assertJsonPath('events.item_added', 2)->assertJsonPath('lifetimes.guest_hours', 168);
        foreach (['Classic Burger', 'Burger Hub', $line, '+91', 'guest_token', 'customer_id'] as $private) {
            $this->assertStringNotContainsString($private, $numbers->getContent());
        }
    }

    public function test_the_guest_token_is_stored_hashed_and_never_logged(): void
    {
        Log::spy();
        $guest = $this->guest();
        $token = $guest['X-Cart-Token'];
        $this->addBurger($guest, 1, ['Size' => ['Regular']], ['special_instructions' => 'Leave at gate 4, call 98765 43210'])->assertCreated();
        $this->patchJson('/api/v1/cart/items/'.Cart::query()->firstOrFail()->items()->value('public_id'), ['quantity' => 2], $guest)->assertOk();

        $row = (array) DB::table('carts')->first();
        $this->assertSame(GuestCartToken::hash($token), $row['guest_token_hash']);
        $this->assertStringNotContainsString($token, json_encode($row));

        // Cart events are logged with ids and numbers only: no token, no instructions, no item names.
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($token): bool {
            $line = $message.' '.json_encode($context);
            $this->assertStringNotContainsString($token, $line);
            $this->assertStringNotContainsString('gate 4', $line);
            $this->assertStringNotContainsString('Classic Burger', $line);

            return str_starts_with($message, 'cart.');
        })->atLeast()->times(2);
    }

    public function test_the_database_itself_keeps_one_active_cart_per_owner_and_one_restaurant_per_cart(): void
    {
        [$customer, $session] = $this->signedIn();
        $this->addBurger($session)->assertCreated();
        $cart = Cart::query()->firstOrFail();
        $row = fn (array $overrides): array => [
            'public_id' => (string) Str::uuid(), 'customer_id' => null, 'guest_token_hash' => null, 'restaurant_location_id' => $this->burgerHub->id, 'status' => 'ACTIVE', 'currency' => 'INR',
            'version' => 1, 'expires_at' => now()->addDay(), 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ];
        $refused = function (array $overrides) use ($row): bool {
            try {
                DB::transaction(fn () => DB::table('carts')->insert($row($overrides)));

                return false;
            } catch (QueryException) {
                return true;
            }
        };

        $this->assertTrue($refused(['customer_id' => $customer->id]), 'a second ACTIVE cart of the same customer');
        $this->assertTrue($refused([]), 'an ACTIVE cart without an owner');
        $this->assertTrue($refused(['customer_id' => $customer->id + 1000, 'guest_token_hash' => str_repeat('a', 64)]), 'two owners (and an unknown customer)');
        $this->assertTrue($refused(['guest_token_hash' => str_repeat('b', 64), 'status' => 'PAID']), 'an unknown status');
        $this->assertTrue($refused(['guest_token_hash' => str_repeat('b', 64), 'currency' => 'inr']), 'a malformed currency');
        $this->assertTrue($refused(['guest_token_hash' => str_repeat('b', 64), 'restaurant_location_id' => null]), 'a cart without a restaurant');
        $this->assertTrue($refused(['guest_token_hash' => str_repeat('b', 64), 'status' => 'ABANDONED']), 'a closed cart without closed_at');
        $this->assertFalse($refused(['guest_token_hash' => str_repeat('b', 64)]));
        $this->assertTrue($refused(['guest_token_hash' => str_repeat('b', 64)]), 'a second ACTIVE cart for the same guest token');
        // A closed cart of the same customer is history and does not count.
        $this->assertFalse($refused(['customer_id' => $customer->id, 'status' => 'ABANDONED', 'closed_at' => now()]));

        // Lines: quantity bounds, one line per configuration, real menu rows only.
        $line = (array) DB::table('cart_items')->first();
        unset($line['id']);
        $lineRefused = function (array $overrides) use ($line): bool {
            try {
                DB::transaction(fn () => DB::table('cart_items')->insert([...$line, 'public_id' => (string) Str::uuid(), ...$overrides]));

                return false;
            } catch (QueryException) {
                return true;
            }
        };
        $this->assertTrue($lineRefused([]), 'the same configuration twice in one cart');
        $this->assertTrue($lineRefused(['signature' => str_repeat('c', 64), 'quantity' => 0]));
        $this->assertTrue($lineRefused(['signature' => str_repeat('c', 64), 'quantity' => 1001]));
        $this->assertTrue($lineRefused(['signature' => str_repeat('c', 64), 'menu_item_id' => 999999]));
        $this->assertTrue($lineRefused(['signature' => str_repeat('c', 64), 'cart_id' => 999999]));
        $this->assertFalse($lineRefused(['signature' => str_repeat('c', 64)]));

        // A menu row that a cart line points at cannot be hard-deleted from under it.
        try {
            DB::transaction(fn () => DB::table('menu_items')->where('id', $this->burger->id)->delete());
            $this->fail('the menu item was deleted');
        } catch (QueryException) {
            $this->assertSame($cart->id, (int) DB::table('cart_items')->where('menu_item_id', $this->burger->id)->value('cart_id'));
        }
    }
}
