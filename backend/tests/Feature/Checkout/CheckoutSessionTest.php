<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\PickupSlotReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * The checkout session itself (Module 29): created for a signed-in customer from a valid cart and pickup selection,
 * priced by the backend, short-lived, one per cart, confirmed into READY with the pickup time held.
 */
class CheckoutSessionTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_basic_checkout_is_the_merchandise_subtotal_when_no_promotion_tax_or_fee_is_configured(): void
    {
        [, $headers] = $this->customerReadyForCheckout();

        $checkout = $this->openCheckout($headers)->assertCreated()->json('checkout');

        // Two Large burgers of ₹250: ₹500 — and nothing else, because nothing else is configured.
        $this->assertSame('DRAFT', $checkout['status']);
        $this->assertSame($this->inr(50000), $checkout['pricing']['items_subtotal']);
        $this->assertSame($this->inr(0), $checkout['pricing']['discount_total']);
        $this->assertSame([], $checkout['pricing']['discounts']);
        $this->assertSame([], $checkout['pricing']['taxes']);
        $this->assertSame([], $checkout['pricing']['fees']);
        $this->assertSame($this->inr(0), $checkout['pricing']['tax_total']);
        $this->assertSame($this->inr(0), $checkout['pricing']['fee_total']);
        $this->assertSame($this->inr(50000), $checkout['pricing']['total']);
        $this->assertSame('INR', $checkout['pricing']['currency']);
        $this->assertTrue($checkout['pricing']['payment_required']);
        $this->assertSame(['tax' => 'NOT_CONFIGURED', 'fees' => 'NOT_CONFIGURED'], $checkout['pricing']['configuration']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $checkout['pricing']['fingerprint']);

        // What the customer is shown: the restaurant, the validated items and the pickup — from the backend.
        $this->assertSame('Burger Hub', $checkout['restaurant']['name']);
        $this->assertCount(1, $checkout['items']);
        $this->assertSame(2, $checkout['items'][0]['quantity']);
        $this->assertSame(25000, $checkout['items'][0]['unit_price_minor']);
        $this->assertSame(50000, $checkout['items'][0]['line_subtotal_minor']);
        $this->assertSame('ASAP', $checkout['pickup']['mode']);
        $this->assertSame('Asia/Kolkata', $checkout['pickup']['timezone']);
        $this->assertFalse($checkout['pickup']['hold']['held'], 'a draft holds nothing');
        $this->assertNull($checkout['payment'], 'nothing is prepared for payment before the checkout is confirmed');
        $this->assertSame('+919876543210', $checkout['customer']['phone']);
        $this->assertSame('READY', $checkout['payment_methods']['provider_status']);   // the test environment's (fake) provider
        $this->assertSame('NOT_APPROVED', $checkout['payment_methods']['cash_at_pickup']);
    }

    public function test_the_checkout_is_stored_with_an_opaque_id_its_cart_version_and_a_short_expiry(): void
    {
        [$customer, $headers] = $this->customerReadyForCheckout();

        $checkout = $this->openCheckout($headers)->assertCreated()->json('checkout');

        $session = $this->sessionModel($checkout['id']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $checkout['id']);
        $this->assertNotSame((string) $session->getKey(), $checkout['id']);
        $this->assertSame((int) $customer->getKey(), (int) $session->customer_id);
        $this->assertSame((int) $this->cartModel($headers)->version, (int) $session->cart_version);
        $this->assertSame((int) $this->burgerHub->getKey(), (int) $session->location_id);
        $this->assertSame('INR', $session->currency);
        $this->assertSame(50000, $session->total_minor);
        // 15 minutes by configuration, on the server clock.
        $this->assertTrue(now()->addMinutes(15)->equalTo($session->expires_at));
        $this->assertSame(now()->addMinutes(15)->utc()->toIso8601String(), $checkout['expires_at']);
        $this->assertSame(now()->utc()->toIso8601String(), $checkout['server_time']);
        foreach (['customer_id', 'cart_id', 'location_id', 'market_id', 'promotion_id', 'pickup_selection_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $checkout);
        }
    }

    public function test_the_ttl_comes_from_configuration(): void
    {
        config(['checkout.ttl.draft_minutes' => 4, 'checkout.ttl.ready_minutes' => 3]);
        [, $headers] = $this->customerReadyForCheckout();

        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->assertSame(now()->addMinutes(4)->utc()->toIso8601String(), $draft['expires_at']);

        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $this->assertSame(now()->addMinutes(3)->utc()->toIso8601String(), $ready['expires_at']);
        $this->assertSame($ready['expires_at'], $ready['pickup']['hold']['expires_at'], 'a checkout never outlives its hold');
    }

    public function test_opening_again_with_nothing_changed_returns_the_same_checkout(): void
    {
        [, $headers] = $this->customerReadyForCheckout();

        $first = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $second = $this->openCheckout($headers)->assertOk()->json('checkout');
        // Another device of the same customer.
        $third = $this->openCheckout($this->sessionOf($this->signedInCustomer($headers), 'tablet'))->assertOk()->json('checkout');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $third['id']);
        $this->assertSame(1, CheckoutSession::query()->count());
    }

    public function test_confirming_makes_it_ready_holds_the_pickup_time_and_prepares_the_payment(): void
    {
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], '13:00');
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');

        $ready = $this->confirmCheckout($headers, $draft, ['order_note' => 'Please pack separately'])->assertOk()->json('checkout');

        $this->assertSame('READY', $ready['status']);
        $this->assertSame($draft['id'], $ready['id']);
        $this->assertSame('Please pack separately', $ready['order_note']);
        $this->assertSame(['type' => 'UPI', 'saved_method_id' => null], $ready['payment_methods']['selected']);
        $this->assertTrue($ready['pickup']['hold']['held']);
        $this->assertSame(now()->addMinutes(10)->utc()->toIso8601String(), $ready['expires_at']);

        // One capacity unit — one order — is held in the chosen slot, for exactly as long as the checkout lives.
        $hold = PickupSlotReservation::query()->where('source', 'HOLD')->sole();
        $this->assertSame('ACTIVE', $hold->status);
        $this->assertSame(1, (int) $hold->units);
        $this->assertTrue($this->localInstant('13:00')->equalTo($hold->slot_starts_at));
        $this->assertTrue(now()->addMinutes(10)->equalTo($hold->expires_at));
        $this->assertSame((int) $this->sessionModel($ready['id'])->getKey(), (int) $hold->checkout_session_id);

        // The payment is PREPARED from the checkout — amount, currency, the price it is bound to. Nothing is charged.
        $this->assertSame('READY_FOR_PAYMENT', $ready['payment']['status']);
        $this->assertSame($this->inr(50000), $ready['payment']['amount']);
        $this->assertSame('INR', $ready['payment']['currency']);
        $this->assertSame($ready['id'], $ready['payment']['checkout_id']);
        $this->assertSame($ready['pricing']['fingerprint'], $ready['payment']['pricing_fingerprint']);
        $this->assertSame(['status' => 'READY', 'name' => 'fake', 'mode' => 'FAKE'], $ready['payment']['provider']);
        $this->assertMatchesRegularExpression('/\Apay_[0-9a-f]{40}\z/', $ready['payment']['idempotency_key']);
    }

    public function test_confirming_twice_changes_nothing_and_holds_nothing_twice(): void
    {
        [, $headers, $ready] = $this->readyCheckout(2, '13:00');
        $this->travel(4)->minutes();

        $again = $this->confirmCheckout($headers, $ready)->assertOk()->json('checkout');

        $this->assertSame($ready['id'], $again['id']);
        $this->assertSame($ready['version'], $again['version']);
        $this->assertSame($ready['expires_at'], $again['expires_at'], 'repeating the confirmation does not prolong the hold');
        $this->assertSame($ready['payment']['idempotency_key'], $again['payment']['idempotency_key']);
        $this->assertSame(1, PickupSlotReservation::query()->where('source', 'HOLD')->count());
    }

    public function test_cancelling_gives_the_pickup_time_back_and_is_safe_to_repeat(): void
    {
        [, $headers, $ready] = $this->readyCheckout(2, '13:00');

        $cancelled = $this->postJson('/api/v1/checkouts/'.$ready['id'].'/cancel', [], $headers)->assertOk()->json('checkout');
        $this->postJson('/api/v1/checkouts/'.$ready['id'].'/cancel', [], $headers)->assertOk()->assertJsonPath('checkout.status', 'CANCELLED');

        $this->assertSame('CANCELLED', $cancelled['status']);
        $this->assertFalse($cancelled['pickup']['hold']['held']);
        $this->assertNull($cancelled['payment']);
        $this->assertSame('RELEASED', PickupSlotReservation::query()->where('source', 'HOLD')->sole()->status);
        // The cart and the pickup choice are untouched: a new checkout can be opened.
        $this->openCheckout($headers)->assertCreated()->assertJsonPath('checkout.status', 'DRAFT');
    }

    public function test_checkout_needs_a_cart_with_items_and_a_pickup_time(): void
    {
        [, $headers] = $this->signedIn();
        $this->openCheckout($headers)->assertNotFound()->assertJsonPath('error.code', 'cart_not_found');

        $this->addBurger($headers)->assertCreated();
        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'pickup_required')->assertJsonPath('error.details.issues.0.recover', 'pickup');

        $line = $this->cartOf($headers)->json('cart.items.0.id');
        $this->deleteJson('/api/v1/cart/items/'.$line, [], $headers)->assertOk();
        $this->openCheckout($headers)->assertConflict()->assertJsonPath('error.code', 'cart_empty')->assertJsonPath('error.details.issues.0.recover', 'cart');
        $this->assertSame(0, CheckoutSession::query()->count());
    }

    private function signedInCustomer(array $headers): Customer
    {
        return $this->cartModel($headers)->customer;
    }
}
