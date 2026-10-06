<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * Versions and idempotency keys: a stale device is told so, and a repeated request (double tap, mobile retry)
 * is answered with the first answer instead of being applied twice.
 */
class CartConcurrencyTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_every_change_raises_the_version_and_a_stale_version_is_refused(): void
    {
        [$customer, $phone] = $this->signedIn();
        $line = $this->addBurger($phone, 1)->assertCreated()->assertJsonPath('cart.version', 1)->json('line.id');
        $tablet = $this->sessionOf($customer, 'tablet');

        // Both devices read version 1 and both change the quantity "from version 1": the first one wins.
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2, 'cart_version' => 1], $this->using($phone))->assertOk()->assertJsonPath('cart.version', 2);
        $stale = $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 5, 'cart_version' => 1], $this->using($tablet))->assertStatus(409)->assertJsonPath('error.code', 'cart_version_conflict');
        $stale->assertJsonPath('error.details.current_version', 2);
        $this->cartOf($this->using($tablet))->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.version', 2);

        // After reloading, the tablet's change goes through.
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 5, 'cart_version' => 2], $this->using($tablet))->assertOk()->assertJsonPath('cart.version', 3)->assertJsonPath('cart.items.0.quantity', 5);

        // The same rule on every kind of change — and no version sent means "apply to whatever is there".
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['cart_version' => 1]), $this->using($phone))->assertStatus(409)->assertJsonPath('error.code', 'cart_version_conflict');
        $this->postJson('/api/v1/cart/refresh', ['cart_version' => 1], $this->using($phone))->assertStatus(409);
        $this->deleteJson('/api/v1/cart/items/'.$line.'?cart_version=1', [], $this->using($phone))->assertStatus(409);
        $this->putJson('/api/v1/cart/journey', ['journey_id' => '00000000-0000-4000-8000-000000000000', 'cart_version' => 1], $this->using($phone))->assertStatus(409);
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($phone))->assertCreated()->assertJsonPath('cart.version', 4);
        $this->deleteJson('/api/v1/cart/items/'.$line.'?cart_version=4', [], $this->using($phone))->assertOk()->assertJsonPath('cart.version', 5)->assertJsonPath('cart.line_count', 1);
        $this->postJson('/api/v1/cart/refresh', ['cart_version' => 5], $this->using($phone))->assertOk()->assertJsonPath('cart.version', 6);
    }

    public function test_a_repeated_request_with_the_same_idempotency_key_is_not_applied_twice(): void
    {
        $guest = $this->guest();
        $key = ['Idempotency-Key' => 'add-7f3a9c1e-0001-4000-8000-aaaaaaaaaaaa'];
        $body = $this->addBody($this->fries, [], 2);

        $first = $this->postJson('/api/v1/cart/items', $body, [...$guest, ...$key])->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
        // The double tap / the retry after a timeout: same key, same request.
        $again = $this->postJson('/api/v1/cart/items', $body, [...$guest, ...$key])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('cart.items.0.quantity'), $again->json('cart.items.0.quantity'));
        $this->assertSame($first->json('line.id'), $again->json('line.id'));
        $this->cartOf($guest)->assertJsonPath('cart.items.0.quantity', 2)->assertJsonPath('cart.version', 1)->assertJsonPath('cart.line_count', 1);

        // Without a key the same request is a second add — that is what the key is for.
        $this->postJson('/api/v1/cart/items', $body, $guest)->assertCreated()->assertJsonPath('cart.items.0.quantity', 4);

        // The same key with another request is refused, not guessed at.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 3), [...$guest, ...$key])->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->postJson('/api/v1/cart/items', $body, [...$guest, 'Idempotency-Key' => 'short'])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

        // A key belongs to one cart owner: another guest using the same key is a different request altogether.
        $other = $this->guest();
        $this->postJson('/api/v1/cart/items', $body, [...$other, ...$key])->assertCreated()->assertHeaderMissing('Idempotency-Replayed')->assertJsonPath('cart.items.0.quantity', 2);
        // …and a customer's keys are scoped to the customer.
        [, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/items', $body, [...$session, ...$key])->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
        $this->postJson('/api/v1/cart/items', $body, [...$this->using($session), ...$key])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->cartOf($this->using($session))->assertJsonPath('cart.items.0.quantity', 2);

        $actors = IdempotencyKey::query()->where('operation', 'cart.add')->pluck('actor')->all();
        $this->assertCount(3, $actors);
        $this->assertCount(2, array_filter($actors, fn (string $a): bool => str_starts_with($a, 'guest:')));
        $this->assertCount(1, array_filter($actors, fn (string $a): bool => str_starts_with($a, 'customer:')));
        // The stored answer of a guest's request does not contain a token.
        $this->assertStringNotContainsString('gc1.', (string) json_encode(IdempotencyKey::query()->pluck('response_body')->all()));
    }

    public function test_changes_removals_and_switches_can_be_retried_safely(): void
    {
        [, $pizza] = $this->otherRestaurant();
        $guest = $this->guest();
        $line = $this->addBurger($guest, 1)->assertCreated()->json('line.id');

        // A retried "+1" (sent as the new absolute quantity) cannot overshoot; with a key the answer is replayed.
        $key = ['Idempotency-Key' => 'upd-7f3a9c1e-0002-4000-8000-bbbbbbbbbbbb'];
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2, 'cart_version' => 1], [...$guest, ...$key])->assertOk()->assertJsonPath('cart.version', 2);
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2, 'cart_version' => 1], [...$guest, ...$key])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('cart.version', 2);

        // A retried confirmed switch replaces the cart once.
        $switch = ['confirm' => true, 'restaurant_id' => $pizza->menu->location->public_id, 'item' => ['item_id' => $pizza->public_id, 'quantity' => 1]];
        $key = ['Idempotency-Key' => 'swt-7f3a9c1e-0003-4000-8000-cccccccccccc'];
        $first = $this->postJson('/api/v1/cart/switch-restaurant', $switch, [...$guest, ...$key])->assertCreated();
        $this->postJson('/api/v1/cart/switch-restaurant', $switch, [...$guest, ...$key])->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('cart.id', $first->json('cart.id'));
        $this->assertSame([2, 1], [Cart::query()->count(), $this->activeCarts()]);
        $this->cartOf($guest)->assertJsonPath('cart.items.0.quantity', 1);

        // A retried removal: the second attempt replays the first answer instead of failing with "not found".
        $key = ['Idempotency-Key' => 'del-7f3a9c1e-0004-4000-8000-dddddddddddd'];
        $pizzaLine = $first->json('line.id');
        $this->deleteJson('/api/v1/cart/items/'.$pizzaLine, [], [...$guest, ...$key])->assertOk()->assertJsonPath('cart.line_count', 0);
        $this->deleteJson('/api/v1/cart/items/'.$pizzaLine, [], [...$guest, ...$key])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(0, CartItem::query()->whereHas('cart', fn ($q) => $q->where('status', 'ACTIVE'))->count());
    }

    public function test_a_request_without_an_identity_cannot_be_deduplicated_and_says_so_by_issuing_a_token(): void
    {
        // No session and no cart token: there is nothing to scope a key to. Each such request starts its own guest
        // cart and hands out its token — a client keeps the token from GET /cart before its first change.
        $key = ['Idempotency-Key' => 'anon-7f3a9c1e-0005-4000-8000-eeeeeeeeeeee'];
        $a = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $key)->assertCreated();
        $b = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $key)->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertNotSame($a->json('guest_token'), $b->json('guest_token'));
        $this->assertSame(0, IdempotencyKey::query()->count());

        // Starting anonymous carts is bounded per address.
        config(['rate_limits.cart_create_guest' => 3]);
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries))->assertCreated();
        $limited = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries))->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertGreaterThan(0, $limited->json('error.details.retry_after_seconds'));
        // A guest who already has a cart is not affected: this bounds new carts, not shopping.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), ['X-Cart-Token' => $a->json('guest_token')])->assertCreated()->assertJsonPath('cart.items.0.quantity', 2);
        $this->assertSame(3, $this->activeCarts());
    }
}
