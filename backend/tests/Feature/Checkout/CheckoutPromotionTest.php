<?php

namespace Tests\Feature\Checkout;

use App\Models\Market;
use App\Models\PickupSlotReservation;
use App\Models\PromotionReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * Promotions at checkout (Module 29): the backend decides whether a code applies — status and time window on the
 * server clock, scope, currency, minimum spend, usage limits — and what it takes off. One code per checkout.
 */
class CheckoutPromotionTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    private array $headers;

    private array $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        [, $this->headers] = $this->customerReadyForCheckout();   // ₹500
        $this->checkout = $this->openCheckout($this->headers)->assertCreated()->json('checkout');
    }

    private function apply(string $code, ?array $headers = null, ?string $id = null): TestResponse
    {
        return $this->postJson('/api/v1/checkouts/'.($id ?? $this->checkout['id']).'/promotion', ['code' => $code], $this->using($headers ?? $this->headers));
    }

    public function test_applying_a_code_prices_the_checkout_again_and_removing_it_restores_the_price(): void
    {
        $this->promotion(['code' => 'SAVE100', 'name' => '₹100 off your order']);

        $applied = $this->apply('SAVE100')->assertOk()->json('checkout');

        $this->assertSame($this->checkout['id'], $applied['id']);
        $this->assertSame(['code' => 'SAVE100', 'name' => '₹100 off your order', 'discount' => $this->inr(10000)], $applied['promotion']);
        $this->assertSame($this->inr(40000), $applied['pricing']['total']);
        $this->assertSame($this->checkout['version'] + 1, $applied['version']);
        $this->assertNotSame($this->checkout['pricing']['fingerprint'], $applied['pricing']['fingerprint']);

        $removed = $this->deleteJson('/api/v1/checkouts/'.$this->checkout['id'].'/promotion', [], $this->headers)->assertOk()->json('checkout');
        $this->assertNull($removed['promotion']);
        $this->assertSame([], $removed['pricing']['discounts']);
        $this->assertSame($this->inr(50000), $removed['pricing']['total']);
        $this->assertSame($this->checkout['pricing']['fingerprint'], $removed['pricing']['fingerprint']);
    }

    public function test_codes_are_compared_trimmed_and_without_regard_to_case(): void
    {
        $this->promotion(['code' => 'SAVE100']);

        $this->apply('  save100 ')->assertOk()->assertJsonPath('checkout.promotion.code', 'SAVE100');
    }

    public function test_one_code_per_checkout_a_second_code_replaces_the_first(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        $this->promotion(['code' => 'TEN', 'name' => '10 % off', 'type' => 'PERCENTAGE', 'percent_basis_points' => 1000]);

        $this->apply('SAVE100')->assertOk();
        $second = $this->apply('TEN')->assertOk()->json('checkout');

        // Not ₹100 and 10 %: codes do not stack.
        $this->assertCount(1, $second['pricing']['discounts']);
        $this->assertSame('TEN', $second['promotion']['code']);
        $this->assertSame($this->inr(45000), $second['pricing']['total']);
    }

    public function test_the_minimum_spend_is_measured_on_the_subtotal_before_the_discount(): void
    {
        $this->promotion(['code' => 'MIN500', 'minimum_spend_minor' => 50000]);
        $this->promotion(['code' => 'MIN501', 'minimum_spend_minor' => 50001]);

        // ₹500 reaches a ₹500 minimum …
        $this->apply('MIN500')->assertOk()->assertJsonPath('checkout.pricing.total.amount', 40000);
        // … and not one of ₹500.01; the answer says what the minimum is.
        $this->apply('MIN501')->assertStatus(422)->assertJsonPath('error.code', 'promotion_minimum_not_met')->assertJsonPath('error.details.minimum_spend', $this->inr(50001));
        // The refused code changed nothing.
        $this->showCheckout($this->headers, $this->checkout['id'])->assertJsonPath('checkout.promotion.code', 'MIN500');
    }

    public function test_the_time_window_is_judged_on_the_server_clock(): void
    {
        $this->promotion(['code' => 'ENDED', 'starts_at' => now()->subDays(5), 'ends_at' => now()->subSecond()]);
        $this->promotion(['code' => 'LATER', 'starts_at' => now()->addHour()]);
        $this->promotion(['code' => 'NOW', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $this->apply('ENDED')->assertStatus(422)->assertJsonPath('error.code', 'promotion_expired');
        // A code that has not started is not announced: it reads like any unknown code.
        $this->apply('LATER')->assertStatus(422)->assertJsonPath('error.code', 'promotion_not_found');
        $this->apply('NOW')->assertOk();

        // An hour later the server clock has passed its end: the checkout that carries it goes stale.
        $this->travel(61)->minutes();
        $again = $this->openCheckout($this->headers)->assertCreated()->json('checkout');
        $this->assertNull($again['promotion']);
        $this->assertSame($this->inr(50000), $again['pricing']['total']);
    }

    public function test_unknown_and_unpublished_codes_tell_a_customer_nothing_about_campaigns(): void
    {
        $this->promotion(['code' => 'DRAFTED', 'status' => 'DRAFT']);
        $this->promotion(['code' => 'PAUSED1', 'status' => 'PAUSED']);
        $this->promotion(['code' => 'DISABLED1', 'status' => 'DISABLED']);

        $answers = [];
        foreach (['NOSUCHCODE', 'DRAFTED', 'PAUSED1', 'DISABLED1'] as $code) {
            $answers[] = $this->apply($code)->assertStatus(422)->json('error');
        }
        // The same code and the same words for all four; no rule, no date, no limit in the answer.
        $this->assertCount(1, array_unique(array_map(fn (array $e): string => $e['code'].'|'.$e['message'].'|'.json_encode($e['details'] ?? null), $answers)));
        $this->assertSame('promotion_not_found', $answers[0]['code']);
    }

    public function test_a_promotion_applies_only_inside_its_scope(): void
    {
        [$pizza] = $this->otherRestaurant();
        $this->promotion(['code' => 'PIZZAONLY', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $pizza->getKey()]);
        $this->promotion(['code' => 'BURGERONLY', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $this->burgerHub->getKey()]);
        $this->promotion(['code' => 'ORGWIDE', 'scope' => 'RESTAURANT_ORGANIZATION', 'organization_id' => $this->burgerHub->organization_id]);
        $elsewhere = Market::factory()->create(['country_code' => 'AE', 'slug' => 'ae', 'name' => 'United Arab Emirates', 'default_currency' => 'AED']);
        $this->promotion(['code' => 'OTHERMARKET', 'scope' => 'MARKET', 'market_id' => $elsewhere->getKey(), 'currency' => null, 'type' => 'PERCENTAGE', 'percent_basis_points' => 1000]);
        $this->promotion(['code' => 'THISMARKET', 'scope' => 'MARKET', 'market_id' => $this->market->getKey()]);

        $this->apply('PIZZAONLY')->assertStatus(422)->assertJsonPath('error.code', 'promotion_not_applicable');
        $this->apply('OTHERMARKET')->assertStatus(422)->assertJsonPath('error.code', 'promotion_not_applicable');
        $this->apply('BURGERONLY')->assertOk();
        $this->apply('ORGWIDE')->assertOk();
        $this->apply('THISMARKET')->assertOk()->assertJsonPath('checkout.pricing.total.amount', 40000);
    }

    public function test_an_amount_in_another_currency_is_never_applied_to_this_checkout(): void
    {
        $this->promotion(['code' => 'USD10', 'currency' => 'USD', 'amount_minor' => 1000]);
        $this->promotion(['code' => 'USDMAX', 'type' => 'PERCENTAGE', 'percent_basis_points' => 5000, 'currency' => 'USD', 'maximum_discount_minor' => 500]);
        // A percentage without amounts has no currency and applies to any.
        $this->promotion(['code' => 'ANY10', 'type' => 'PERCENTAGE', 'percent_basis_points' => 1000]);

        $this->apply('USD10')->assertStatus(422)->assertJsonPath('error.code', 'promotion_not_applicable');
        $this->apply('USDMAX')->assertStatus(422)->assertJsonPath('error.code', 'promotion_not_applicable');
        $this->apply('ANY10')->assertOk()->assertJsonPath('checkout.pricing.currency', 'INR')->assertJsonPath('checkout.pricing.discount_total', $this->inr(5000));
    }

    public function test_a_used_up_promotion_and_a_customer_who_used_theirs_are_refused(): void
    {
        $this->promotion(['code' => 'GONE', 'usage_limit' => 3, 'redeemed_count' => 3]);
        $once = $this->promotion(['code' => 'ONCE', 'per_customer_limit' => 1]);
        // This customer redeemed ONCE with an earlier order (Module 31 writes such rows).
        (new PromotionReservation)->forceFill(['promotion_id' => $once->getKey(), 'customer_id' => $this->cartModel($this->headers)->customer_id, 'status' => 'REDEEMED'])->save();

        $this->apply('GONE')->assertStatus(422)->assertJsonPath('error.code', 'promotion_limit_reached');
        $this->apply('ONCE')->assertStatus(422)->assertJsonPath('error.code', 'promotion_customer_limit_reached');

        // Another customer can still use ONCE.
        [, $other] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $id = $this->openCheckout($other)->json('checkout.id');
        $this->apply('ONCE', $other, $id)->assertOk();
    }

    public function test_a_typed_code_that_does_not_apply_is_refused_when_opening_a_checkout_too(): void
    {
        $this->promotion(['code' => 'ENDED', 'ends_at' => now()->subMinute(), 'starts_at' => now()->subDay()]);

        $this->openCheckout($this->headers, ['promotion_code' => 'ENDED'])->assertStatus(422)->assertJsonPath('error.code', 'promotion_expired');
        $this->openCheckout($this->headers, ['promotion_code' => 'NOPE'])->assertStatus(422)->assertJsonPath('error.code', 'promotion_not_found');
        // The checkout that existed is untouched.
        $this->showCheckout($this->headers, $this->checkout['id'])->assertJsonPath('checkout.status', 'DRAFT')->assertJsonPath('checkout.promotion', null);
    }

    public function test_a_promotion_that_is_disabled_after_the_checkout_makes_it_stale_and_the_next_one_is_priced_without_it(): void
    {
        $promotion = $this->promotion(['code' => 'SAVE100']);
        $applied = $this->apply('SAVE100')->assertOk()->json('checkout');
        $ready = $this->confirmCheckout($this->headers, $applied)->assertOk()->json('checkout');
        $this->assertSame($this->inr(40000), $ready['payment']['amount']);

        // An administrator disables the campaign.
        $promotion->forceFill(['status' => 'DISABLED', 'version' => 2])->save();

        $stale = $this->showCheckout($this->headers, $ready['id'])->json('checkout');
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('PROMOTION_CHANGED', $stale['issues'][0]['code']);
        $this->assertNull($stale['payment'], 'the discounted amount can no longer be paid');
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('source', 'HOLD')->sole()->status);

        $next = $this->openCheckout($this->headers)->assertCreated()->json('checkout');
        $this->assertNull($next['promotion']);
        $this->assertSame($this->inr(50000), $next['pricing']['total']);
    }

    public function test_changing_the_promotion_of_a_confirmed_checkout_takes_it_back_to_draft(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        $ready = $this->confirmCheckout($this->headers, $this->checkout)->assertOk()->json('checkout');
        $this->assertTrue($ready['pickup']['hold']['held']);

        $changed = $this->apply('SAVE100')->assertOk()->json('checkout');

        // The total changed, so what was confirmed for the old total is undone: no hold, no payment preparation.
        $this->assertSame('DRAFT', $changed['status']);
        $this->assertFalse($changed['pickup']['hold']['held']);
        $this->assertNull($changed['payment']);
        $this->assertSame($this->inr(40000), $changed['pricing']['total']);
        // The old price can no longer be confirmed; the new one can.
        $this->confirmCheckout($this->headers, $ready)->assertConflict()->assertJsonPath('error.code', 'checkout_stale');
        $this->confirmCheckout($this->headers, $changed)->assertOk()->assertJsonPath('checkout.payment.amount', $this->inr(40000));
    }

    public function test_a_confirmed_checkout_holds_one_use_of_its_promotion_until_it_ends(): void
    {
        $promotion = $this->promotion(['code' => 'LASTONE', 'usage_limit' => 1]);
        $ready = $this->confirmCheckout($this->headers, $this->apply('LASTONE')->assertOk()->json('checkout'))->assertOk()->json('checkout');

        $use = PromotionReservation::query()->sole();
        $this->assertSame('ACTIVE', $use->status);
        $this->assertSame((int) $promotion->getKey(), (int) $use->promotion_id);
        $this->assertSame(0, (int) $promotion->refresh()->redeemed_count, 'a redemption is counted when an order exists, not at checkout');

        // While it is held nobody else gets the last use …
        [, $other] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $otherId = $this->openCheckout($other)->json('checkout.id');
        $this->apply('LASTONE', $other, $otherId)->assertStatus(422)->assertJsonPath('error.code', 'promotion_limit_reached');

        // … and when the first checkout ends, it is free again.
        $this->postJson('/api/v1/checkouts/'.$ready['id'].'/cancel', [], $this->using($this->headers))->assertOk();
        $this->assertSame('RELEASED', $use->refresh()->status);
        $this->apply('LASTONE', $other, $otherId)->assertOk();
    }
}
