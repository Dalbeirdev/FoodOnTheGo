<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * Guest → customer: the cart built before signing in is claimed with its token, merged by one policy, and the
 * guest token is spent. Two carts of different restaurants are never merged and neither is dropped silently.
 */
class CartClaimTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_a_guest_cart_becomes_the_customers_cart_and_its_token_is_spent(): void
    {
        $guest = $this->guest();
        $line = $this->addBurger($guest, 2, ['Size' => ['Large']], ['special_instructions' => 'No onions'])->assertCreated()->json('line.id');
        $cartId = Cart::query()->value('public_id');

        // Guest → OTP sign-in → the device presents the token in the customer's session.
        [$customer, $session] = $this->signedIn();
        $this->cartOf($session)->assertJsonPath('cart', null);
        $claim = $this->postJson('/api/v1/cart/claim', [], [...$session, ...$guest])->assertOk();
        $claim->assertJsonPath('claim.outcome', 'claimed')->assertJsonPath('cart.id', $cartId)->assertJsonPath('cart.owner', 'customer')->assertJsonPath('cart.items.0.id', $line)
            ->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.items.0.special_instructions', 'No onions')->assertJsonPath('cart.subtotal_minor', 50000)->assertJsonPath('cart.version', 2);

        $row = Cart::query()->firstOrFail();
        $this->assertSame([$customer->id, null, hash('sha256', $guest['X-Cart-Token'])], [$row->customer_id, $row->guest_token_hash, $row->spent_token_hash]);
        // The customer's lifetime now applies (30 days instead of 7).
        $this->assertTrue($row->expires_at->greaterThan(now()->addDays(29)));

        // The old guest token addresses nothing any more — not this cart, and it cannot start a new one.
        $this->app['auth']->forgetGuards();
        $after = $this->getJson('/api/v1/cart', $guest)->assertOk()->assertJsonPath('cart', null);
        $this->assertNotNull($after->json('guest_token'));
        $this->assertNotSame($guest['X-Cart-Token'], $after->json('guest_token'));
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 9], $guest)->assertNotFound();
        $fresh = $this->addBurger($guest)->assertCreated();
        $this->assertNotNull($fresh->json('guest_token'), 'a spent token is treated as no token: a new one is issued');
        $this->assertNotSame($cartId, $fresh->json('cart.id'));

        // Claiming again changes nothing.
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'nothing_to_claim')->assertJsonPath('cart.id', $cartId)->assertJsonPath('cart.version', 2);
    }

    public function test_claiming_needs_the_customers_session_and_the_guest_token_never_an_id(): void
    {
        $guest = $this->guest();
        $this->addBurger($guest, 2)->assertCreated();
        $cartId = Cart::query()->value('public_id');

        // Not signed in.
        $this->postJson('/api/v1/cart/claim', [], $guest)->assertStatus(401);
        [, $session] = $this->signedIn();
        // Signed in, but only knowing the cart's id: nothing to claim.
        foreach ([['cart_id' => $cartId], ['id' => $cartId], ['guest_cart_id' => $cartId]] as $body) {
            $this->postJson('/api/v1/cart/claim', $body, $this->using($session))->assertOk()->assertJsonPath('claim.outcome', 'nothing_to_claim')->assertJsonPath('cart', null);
        }
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), 'X-Cart-Token' => $cartId])->assertOk()->assertJsonPath('claim.outcome', 'nothing_to_claim');
        $this->postJson('/api/v1/cart/claim', ['resolution' => 'take_everything'], [...$this->using($session), ...$guest])->assertStatus(422);
        $this->assertNull(Cart::query()->value('customer_id'));

        // An empty or expired guest cart is nothing to claim; it is closed and its token spent.
        $empty = $this->guest();
        $line = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $empty)->assertCreated()->json('line.id');
        $this->deleteJson('/api/v1/cart/items/'.$line, [], $empty)->assertOk();
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$empty])->assertOk()->assertJsonPath('claim.outcome', 'nothing_to_claim')->assertJsonPath('cart', null);
        $this->assertSame(1, $this->activeCarts());

        Carbon::setTestNow(now()->addDays(8));
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($this->sessionOf(Customer::query()->firstOrFail())), ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'nothing_to_claim')->assertJsonPath('cart', null);
        $this->assertSame(['EXPIRED', 'expired'], [Cart::query()->where('public_id', $cartId)->value('status')->value, Cart::query()->where('public_id', $cartId)->value('closed_reason')->value]);
    }

    public function test_two_carts_of_the_same_restaurant_are_merged_by_configuration(): void
    {
        [$customer, $session] = $this->signedIn();
        // In the account: 2 × Large + cheese ("No onions"), 18 × fries.
        $accountBurger = $this->addBurger($session, 2, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']], ['special_instructions' => 'No onions'])->assertCreated()->json('line.id');
        $accountFries = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 18), $session)->assertCreated()->json('line.id');
        $accountCart = Cart::query()->value('public_id');

        // As a guest on the same device: the same burger (options in another order, note in another spelling) × 3,
        // the same burger with a different note, a Regular one, and 5 × fries.
        $guest = $this->guest();
        $this->addBurger($guest, 3, ['Add-ons' => ['Extra Cheese'], 'Size' => ['Large']], ['special_instructions' => 'no onions'])->assertCreated();
        $otherNote = $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']], ['special_instructions' => 'Extra onions'])->assertCreated()->json('line.id');
        $regular = $this->addBurger($guest, 1, ['Size' => ['Regular']])->assertCreated()->json('line.id');
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 5), $guest)->assertCreated();
        $guestCart = Cart::query()->whereNull('customer_id')->value('public_id');

        $claim = $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$guest])->assertOk();
        $claim->assertJsonPath('claim.outcome', 'merged')->assertJsonPath('claim.lines_moved', 2)->assertJsonPath('claim.lines_combined', 2)->assertJsonPath('cart.id', $accountCart)->assertJsonPath('cart.line_count', 4);

        $items = collect($claim->json('cart.items'))->keyBy('id');
        // Same configuration: quantities are added.
        $this->assertSame([5, 'No onions'], [$items[$accountBurger]['quantity'], $items[$accountBurger]['special_instructions']]);
        // A different note or a different size stays its own line (and keeps its own id).
        $this->assertSame([1, 'Extra onions'], [$items[$otherNote]['quantity'], $items[$otherNote]['special_instructions']]);
        $this->assertSame([1, 'Regular'], [$items[$regular]['quantity'], $items[$regular]['variants'][0]['options'][0]['name']]);
        // 18 + 5 fries would be 23: the item allows 20. Capped — and said so, not done quietly.
        $this->assertSame(20, $items[$accountFries]['quantity']);
        $claim->assertJsonPath('claim.adjusted.0.line_id', $accountFries)->assertJsonPath('claim.adjusted.0.requested_quantity', 23)->assertJsonPath('claim.adjusted.0.quantity', 20)->assertJsonPath('claim.adjusted.0.max_quantity', 20);
        $claim->assertJsonPath('cart.subtotal_minor', 5 * 27000 + 27000 + 20000 + 20 * 12000)->assertJsonPath('cart.validation.status', 'VALID');

        // One cart is left for the customer; the guest cart is closed as merged and its token spent.
        $this->assertSame([1, 4], [$this->activeCarts(), CartItem::query()->count()]);
        $merged = Cart::query()->where('public_id', $guestCart)->firstOrFail();
        $this->assertSame(['INVALIDATED', 'merged', null], [$merged->status->value, $merged->closed_reason->value, $merged->guest_token_hash]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cart', $guest)->assertOk()->assertJsonPath('cart', null)->assertJsonStructure(['guest_token']);
        $this->assertSame($customer->id, Cart::query()->active()->value('customer_id'));
    }

    public function test_two_carts_of_different_restaurants_are_never_merged_and_the_customer_chooses(): void
    {
        [, $pizza] = $this->otherRestaurant();
        [$customer, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/items', $this->addBody($pizza, [], 2), $session)->assertCreated();
        $accountCart = Cart::query()->value('public_id');
        $guest = $this->guest();
        $this->addBurger($guest, 3)->assertCreated();
        $guestCart = Cart::query()->whereNull('customer_id')->value('public_id');

        $both = [...$this->using($session), ...$guest];
        $conflict = $this->postJson('/api/v1/cart/claim', [], $both)->assertStatus(409)->assertJsonPath('error.code', 'cart_merge_conflict');
        $conflict->assertJsonPath('error.details.guest.restaurant.name', 'Burger Hub')->assertJsonPath('error.details.guest.item_count', 3)->assertJsonPath('error.details.guest.subtotal_minor', 60000)
            ->assertJsonPath('error.details.account.restaurant.name', 'Pizza Point')->assertJsonPath('error.details.account.item_count', 2)->assertJsonPath('error.details.account.subtotal_minor', 30000)
            ->assertJsonPath('error.details.resolutions', ['keep_guest', 'keep_account']);

        // Until the customer has chosen, both carts are exactly as they were — asking again gives the same question.
        $this->postJson('/api/v1/cart/claim', [], $both)->assertStatus(409);
        $this->assertSame(2, $this->activeCarts());
        $this->cartOf($this->using($session))->assertJsonPath('cart.id', $accountCart)->assertJsonPath('cart.item_count', 2);
        $this->app['auth']->forgetGuards();
        $this->cartOf($guest)->assertJsonPath('cart.id', $guestCart)->assertJsonPath('cart.item_count', 3);

        // "Keep the cart I just built": the account's cart is closed, the guest cart becomes the customer's.
        $kept = $this->postJson('/api/v1/cart/claim', ['resolution' => 'keep_guest'], [...$this->using($session), ...$guest])->assertOk();
        $kept->assertJsonPath('claim.outcome', 'kept_guest')->assertJsonPath('cart.id', $guestCart)->assertJsonPath('cart.owner', 'customer')->assertJsonPath('cart.restaurant.name', 'Burger Hub')->assertJsonPath('cart.item_count', 3);
        $this->assertSame(['ABANDONED', 'replaced_at_sign_in'], [Cart::query()->where('public_id', $accountCart)->value('status')->value, Cart::query()->where('public_id', $accountCart)->value('closed_reason')->value]);
        $this->assertSame([1, $customer->id], [$this->activeCarts(), Cart::query()->active()->value('customer_id')]);

        // The other choice, with a fresh pair: "keep the cart in my account".
        $guest2 = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($pizza, [], 1), $guest2)->assertCreated();
        $guestCart2 = Cart::query()->whereNull('customer_id')->where('status', 'ACTIVE')->value('public_id');
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$guest2])->assertStatus(409);
        $this->postJson('/api/v1/cart/claim', ['resolution' => 'keep_account'], [...$this->using($session), ...$guest2])->assertOk()
            ->assertJsonPath('claim.outcome', 'kept_account')->assertJsonPath('cart.id', $guestCart)->assertJsonPath('cart.item_count', 3);
        $this->assertSame(['ABANDONED', 'discarded_at_sign_in'], [Cart::query()->where('public_id', $guestCart2)->value('status')->value, Cart::query()->where('public_id', $guestCart2)->value('closed_reason')->value]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cart', $guest2)->assertOk()->assertJsonPath('cart', null)->assertJsonStructure(['guest_token']);
    }

    public function test_an_empty_or_expired_account_cart_gives_way_to_the_guest_cart(): void
    {
        [, $pizza] = $this->otherRestaurant();
        [, $session] = $this->signedIn();
        // The account has an EMPTY cart of another restaurant: nothing to lose, no question asked.
        $line = $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $session)->assertCreated()->json('line.id');
        $this->deleteJson('/api/v1/cart/items/'.$line, [], $session)->assertOk();

        $guest = $this->guest();
        $this->addBurger($guest, 2)->assertCreated();
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'claimed')->assertJsonPath('cart.restaurant.name', 'Burger Hub')->assertJsonPath('cart.item_count', 2);
        $this->assertSame(1, $this->activeCarts());

        // The account has an EXPIRED cart with items: it gives way too.
        Carbon::setTestNow(now()->addDays(31));
        $guest2 = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($pizza, [], 1), $guest2)->assertCreated();
        $session2 = $this->sessionOf(Customer::query()->firstOrFail());
        $this->cartOf($session2)->assertJsonPath('cart.status', 'EXPIRED');
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($session2), ...$guest2])->assertOk()->assertJsonPath('claim.outcome', 'claimed')->assertJsonPath('cart.restaurant.name', 'Pizza Point')->assertJsonPath('cart.status', 'ACTIVE');
        $this->assertSame(1, $this->activeCarts());
    }
}
