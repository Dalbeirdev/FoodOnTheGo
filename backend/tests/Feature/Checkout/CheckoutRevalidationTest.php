<?php

namespace Tests\Feature\Checkout;

use App\Enums\MenuItemStatus;
use App\Models\CheckoutSession;
use App\Models\MenuOption;
use App\Models\PickupSlotReservation;
use App\Services\Market\MarketContext;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * The final revalidation (Module 29): before a checkout exists — and every time one is read — the cart, the menu,
 * the prices, the restaurant and the pickup selection are checked again against what is true NOW. A checkout that no
 * longer matches is STALE and is replaced, never repaired; nobody pays against an outdated snapshot.
 */
class CheckoutRevalidationTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_changed_price_is_never_kept_the_customer_gets_the_current_total_and_is_told(): void
    {
        // Cart built at ₹200 per burger: 2 Regular = ₹400.
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Regular']]);
        $this->assertSame(40000, $this->cartOf($headers)->json('cart.subtotal_minor'));

        // The restaurant raises the price to ₹250 before checkout is opened.
        app(MenuService::class)->updateItem($this->burger->refresh(), ['base_price_minor' => 25000, 'version' => $this->burger->version], $this->staff());

        $checkout = $this->openCheckout($headers)->assertCreated()->json('checkout');

        $this->assertSame($this->inr(50000), $checkout['pricing']['total'], 'the current price, not the one the cart was built at');
        $this->assertSame(25000, $checkout['items'][0]['unit_price_minor']);
        $change = collect($checkout['changes'])->firstWhere('code', 'PRICE_CHANGED');
        $this->assertSame($this->inr(20000), $change['previous_unit_price']);
        $this->assertSame($this->inr(25000), $change['unit_price']);
        // The cart itself was brought up to date: it no longer asks for a review.
        $this->cartOf($headers)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.subtotal_minor', 50000);
    }

    public function test_a_price_change_after_the_checkout_makes_it_stale_and_the_new_one_shows_the_new_total(): void
    {
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Regular']]);
        $first = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->assertSame($this->inr(40000), $first['pricing']['total']);

        app(MenuService::class)->updateItem($this->burger->refresh(), ['base_price_minor' => 22000, 'version' => $this->burger->version], $this->staff());

        $stale = $this->showCheckout($headers, $first['id'])->assertOk()->json('checkout');
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('PRICE_CHANGED', $stale['issues'][0]['code']);
        // It cannot be confirmed any more — not even with the fingerprint the customer was shown.
        $this->confirmCheckout($headers, $first)->assertConflict()->assertJsonPath('error.code', 'checkout_stale');

        $second = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($this->inr(44000), $second['pricing']['total']);
        $this->assertNotSame($first['pricing']['fingerprint'], $second['pricing']['fingerprint']);
        $this->assertSame('PRICE_CHANGED', $second['changes'][0]['code']);
    }

    public function test_an_item_that_is_sold_out_blocks_checkout_and_sends_the_customer_to_the_cart(): void
    {
        [, $headers] = $this->customerReadyForCheckout();
        $ready = $this->confirmCheckout($headers, $this->openCheckout($headers)->json('checkout'))->assertOk()->json('checkout');

        app(MenuService::class)->setItemStatus($this->burger, MenuItemStatus::SoldOut, $this->staff());

        $stale = $this->showCheckout($headers, $ready['id'])->json('checkout');
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('ITEM_UNAVAILABLE', $stale['issues'][0]['code']);
        $this->assertSame('cart', $stale['issues'][0]['recover']);
        $this->assertFalse($stale['pickup']['hold']['held'], 'a stale checkout holds no pickup time');
        $this->assertNull($stale['payment']);
        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'item_unavailable')->assertJsonPath('error.details.issues.0.recover', 'cart');
    }

    public function test_an_option_that_is_no_longer_offered_blocks_checkout(): void
    {
        [, $headers] = $this->signedIn();
        $this->addBurger($headers, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();
        $this->selectAsap($headers)->assertOk();
        $this->openCheckout($headers)->assertCreated();

        $this->burger->load('optionGroups.options');
        $cheese = $this->burger->optionGroups->firstWhere('name', 'Add-ons')->options->firstWhere('name', 'Extra Cheese');
        MenuOption::query()->whereKey($cheese->getKey())->update(['status' => 'TEMPORARILY_UNAVAILABLE']);

        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'cart_invalid')->assertJsonPath('error.details.issues.0.details.cart_issue', 'OPTION_UNAVAILABLE');
    }

    public function test_a_restaurant_that_pauses_is_suspended_or_whose_market_stops_blocks_checkout(): void
    {
        [, $headers] = $this->customerReadyForCheckout();
        $first = $this->openCheckout($headers)->assertCreated()->json('checkout');

        $this->burgerHub->forceFill(['accepting_orders' => false])->save();
        $this->showCheckout($headers, $first['id'])->assertJsonPath('checkout.status', 'STALE')->assertJsonPath('checkout.issues.0.code', 'RESTAURANT_NOT_ACCEPTING');
        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'restaurant_not_accepting');
        $this->burgerHub->forceFill(['accepting_orders' => true])->save();

        $second = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->burgerHub->forceFill(['status' => 'SUSPENDED'])->save();
        $this->showCheckout($headers, $second['id'])->assertJsonPath('checkout.status', 'STALE')->assertJsonPath('checkout.issues.0.code', 'RESTAURANT_UNAVAILABLE');
        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'restaurant_unavailable');
        $this->burgerHub->forceFill(['status' => 'APPROVED'])->save();

        $third = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->market->forceFill(['status' => 'PAUSED'])->save();
        app(MarketContext::class)->flush();
        $this->assertSame('STALE', $this->showCheckout($headers, $third['id'])->json('checkout.status'));
        $this->assertSame(409, $this->openCheckout($headers)->status());
    }

    public function test_changing_the_cart_makes_the_checkout_stale_at_once_and_frees_the_held_pickup_time(): void
    {
        [, $headers, $ready] = $this->readyCheckout(2, '13:00');
        $this->assertSame('ACTIVE', PickupSlotReservation::query()->where('source', 'HOLD')->sole()->status);

        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $headers)->assertCreated();

        // Without anybody reading the checkout: it is stale and the place is free again.
        $this->assertSame('STALE', $this->sessionModel($ready['id'])->status->value);
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('source', 'HOLD')->sole()->status);
        $stale = $this->showCheckout($headers, $ready['id'])->json('checkout');
        $this->assertSame('CART_CHANGED', $stale['issues'][0]['code']);
        $this->confirmCheckout($headers, $ready)->assertConflict()->assertJsonPath('error.code', 'checkout_stale');

        $next = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->assertSame($this->inr(62000), $next['pricing']['total']);
        $this->assertCount(2, $next['items']);
    }

    public function test_choosing_another_pickup_time_makes_the_checkout_stale(): void
    {
        [, $headers, $ready] = $this->readyCheckout(2, '13:00');

        $this->selectSlot($headers, '14:00')->assertOk();

        $this->assertSame('STALE', $this->sessionModel($ready['id'])->status->value);
        $this->showCheckout($headers, $ready['id'])->assertJsonPath('checkout.issues.0.code', 'PICKUP_CHANGED');
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('source', 'HOLD')->sole()->status);
        $next = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->assertSame($this->localIso('14:00'), $next['pickup']['local_pickup_at']);
    }

    public function test_a_pickup_time_that_is_no_longer_possible_sends_the_customer_back_to_the_pickup_step(): void
    {
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], '13:00');
        $first = $this->openCheckout($headers)->assertCreated()->json('checkout');

        // The restaurant now needs longer than the chosen time allows.
        $this->settings(['default_prep_minutes' => 120]);
        $stale = $this->showCheckout($headers, $first['id'])->json('checkout');
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('PICKUP_INVALID', $stale['issues'][0]['code']);
        $this->assertSame('pickup', $stale['issues'][0]['recover']);
        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'pickup_invalid')->assertJsonPath('error.details.issues.0.details.pickup_issue', 'SLOT_TOO_EARLY');
    }

    public function test_a_pickup_time_that_has_passed_blocks_checkout(): void
    {
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], '13:00');

        $this->clock('2026-10-07 13:05');

        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'pickup_selection_expired')->assertJsonPath('error.details.issues.0.recover', 'pickup');
    }

    public function test_a_slot_that_others_filled_blocks_checkout_until_another_time_is_chosen(): void
    {
        $this->settings(['capacity_per_slot' => 1]);
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], '13:00');

        $this->reserve('13:00');

        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'pickup_slot_full')->assertJsonPath('error.details.issues.0.recover', 'pickup');
        $this->selectSlot($headers, '13:15')->assertOk();
        $this->openCheckout($headers)->assertCreated();
    }

    public function test_an_expired_checkout_cannot_be_confirmed_and_gives_its_hold_back(): void
    {
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], '14:00');
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');

        // The draft's 15 minutes pass on the SERVER clock.
        $this->travel(15)->minutes();
        $this->confirmCheckout($headers, $draft)->assertStatus(410)->assertJsonPath('error.code', 'checkout_expired');
        $expired = $this->showCheckout($headers, $draft['id'])->json('checkout');
        $this->assertSame('EXPIRED', $expired['status']);
        $this->assertSame('CHECKOUT_EXPIRED', $expired['issues'][0]['code']);
        $this->assertNull($expired['payment']);

        // A new one, confirmed — and its 10 minutes pass too.
        $ready = $this->confirmCheckout($headers, $this->openCheckout($headers)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->assertTrue($ready['pickup']['hold']['held']);
        $this->travel(10)->minutes();
        $after = $this->showCheckout($headers, $ready['id'])->json('checkout');
        $this->assertSame('EXPIRED', $after['status']);
        $this->assertFalse($after['pickup']['hold']['held']);
        $this->assertNull($after['payment'], 'an expired checkout cannot start a payment');
        $this->assertSame(0, PickupSlotReservation::query()->where('status', 'ACTIVE')->where('source', 'HOLD')->count());
        // The cart and the pickup choice are still there: checkout simply starts again.
        $this->openCheckout($headers)->assertCreated()->assertJsonPath('checkout.status', 'DRAFT');
    }

    public function test_the_device_clock_plays_no_part_in_expiry(): void
    {
        [, $headers] = $this->customerReadyForCheckout();
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');

        // A client that claims another time, a later expiry or a status is refused; the checkout is untouched.
        foreach ([['expires_at' => '2030-01-01T00:00:00Z'], ['now' => '2020-01-01T00:00:00Z'], ['client_time' => '2020-01-01T00:00:00Z'], ['status' => 'READY']] as $claim) {
            $this->postJson('/api/v1/checkouts', $claim, $headers)->assertStatus(422);
            $this->postJson('/api/v1/checkouts/'.$draft['id'].'/confirm', [...$claim, 'pricing_fingerprint' => $draft['pricing']['fingerprint'], 'accepted_documents' => []], $headers)->assertStatus(422);
        }
        $this->assertSame($draft['expires_at'], $this->showCheckout($headers, $draft['id'])->json('checkout.expires_at'));
    }

    public function test_run_out_checkouts_are_cleaned_up_and_closed_ones_deleted_after_retention(): void
    {
        [, $headers, $ready] = $this->readyCheckout(2, '14:00');
        [, $other] = $this->customerReadyForCheckout(1, ['Size' => ['Regular']], null, '+919876543211');
        $draft = $this->openCheckout($other)->assertCreated()->json('checkout');

        $this->travel(20)->minutes();
        $this->artisan('checkouts:prune')->assertSuccessful();

        $this->assertSame('EXPIRED', $this->sessionModel($ready['id'])->status->value);
        $this->assertSame('EXPIRED', $this->sessionModel($draft['id'])->status->value);
        $this->assertSame(0, PickupSlotReservation::query()->where('source', 'HOLD')->where('status', 'ACTIVE')->count());

        $this->travel(31)->days();
        $this->artisan('checkouts:prune')->assertSuccessful();
        $this->assertSame(0, CheckoutSession::query()->count());
    }
}
