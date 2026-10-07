<?php

namespace Tests\Feature\Payment;

use App\Enums\CheckoutStatus;
use App\Enums\ProviderFailure;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PickupSlotReservation;
use App\Services\Menu\MenuService;
use App\Services\Payment\Data\ProviderSessionRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * Starting a payment (Module 30): a confirmed checkout gets a payment attempt and a provider session for exactly its
 * total — whatever a client sends — and goes into payment with its pickup time held for as long as paying takes.
 */
class PaymentInitializationTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_confirmed_checkout_gets_a_payment_attempt_for_exactly_its_total_in_its_currency(): void
    {
        [$customer, $headers, $checkout] = $this->readyCheckout();   // 2 large burgers = ₹500.00
        $this->assertSame(['amount' => 50000, 'currency' => 'INR'], $checkout['pricing']['total']);

        $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');

        $this->assertSame('PROVIDER_READY', $payment['status']);
        $this->assertSame(['amount' => 50000, 'currency' => 'INR'], $payment['amount']);
        $this->assertSame($checkout['id'], $payment['checkout_id']);
        $this->assertSame(1, $payment['attempt_number']);
        $this->assertSame(['name' => 'fake', 'mode' => 'FAKE'], $payment['provider']);
        $this->assertSame('OPEN_PROVIDER', $payment['next_action']);
        $this->assertSame('NOT_APPLICABLE', $payment['fulfillment']['status']);
        $this->assertNull($payment['fulfillment']['order_id']);
        $this->assertNull($payment['failure']);
        $this->assertNull($payment['verified_at']);

        $attempt = $this->attempt($payment['id']);
        $this->assertSame(50000, $attempt->amount_minor);
        $this->assertSame('INR', $attempt->currency);
        $this->assertSame($customer->getKey(), $attempt->customer_id);
        $this->assertSame($checkout['pricing']['fingerprint'], $attempt->pricing_fingerprint);
        $this->assertSame('Burger Hub', $attempt->restaurant_name);
        $this->assertNotNull($attempt->provider_order_reference);
        $this->assertNull($attempt->provider_payment_reference);
        // The provider was asked for the same amount, in the provider's unit: 50000 paise for ₹500.00.
        $session = $this->fakeProvider()->findSessionByReceipt($attempt->provider_receipt);
        $this->assertTrue($session->amount->equals(new Money(50000, 'INR')));
        $this->assertSame($attempt->provider_order_reference, $session->reference);
    }

    public function test_the_answer_carries_what_the_providers_interface_needs_and_nothing_secret(): void
    {
        [, , , $payment] = $this->openPayment();
        $session = $payment['provider_session'];

        $this->assertSame('fake', $session['provider']);
        $this->assertSame('fake_key_local', $session['key_id']);
        $this->assertSame($this->attempt($payment['id'])->provider_order_reference, $session['order_reference']);
        $this->assertSame(['amount' => 50000, 'currency' => 'INR'], $session['amount']);
        $this->assertSame('FoodOnTheGo', $session['name']);
        $this->assertSame('+919876543210', $session['prefill']['contact']);
        $this->assertGreaterThan(0, $session['timeout_seconds']);
        $this->assertSame('UPI', $session['preferred_method']);   // what the customer chose at checkout — a context, not a restriction

        $json = json_encode($payment);
        foreach (['secret', 'signature', 'webhook', 'receipt', 'customer_id', 'internal'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $json);
        }
        $this->assertArrayNotHasKey('provider_receipt', $payment);
    }

    public function test_an_amount_or_a_currency_sent_by_a_client_is_refused_and_never_reaches_the_provider(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        foreach ([['amount' => 100], ['amount_minor' => 100], ['currency' => 'USD'], ['total' => ['amount' => 100, 'currency' => 'INR']], ['status' => 'VERIFIED'], ['verified_at' => '2026-10-07T00:00:00Z'], ['customer_id' => 1], ['provider_order_reference' => 'order_x'], ['card_number' => '4111111111111111'], ['cvv' => '123'], ['upi_pin' => '1234']] as $tampering) {
            $field = array_key_first($tampering);
            $this->startPayment($headers, $checkout, $tampering)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        }
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);

        // Without the tampering the payment is for ₹500 — the checkout's total.
        $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.amount.amount', 50000)->assertJsonPath('payment.amount.currency', 'INR');
    }

    public function test_the_checkout_goes_into_payment_and_its_pickup_time_is_held_for_as_long_as_paying_takes(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();
        $before = $this->sessionModel($checkout['id']);
        $readyUntil = CarbonImmutable::instance($before->expires_at);
        $this->assertSame(10, (int) round(now()->diffInMinutes($readyUntil)));

        $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');

        $session = $this->sessionModel($checkout['id']);
        $this->assertSame(CheckoutStatus::PaymentPending, $session->status);
        // 15 minutes to pay + 5 minutes of grace: longer than the 10 minutes a confirmed checkout lives.
        $this->assertSame(15, (int) round(now()->diffInMinutes($this->attempt($payment['id'])->expires_at)));
        $this->assertSame(20, (int) round(now()->diffInMinutes($session->expires_at)));
        $hold = PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->where('status', PickupSlotReservation::STATUS_ACTIVE)->sole();
        $this->assertTrue($session->expires_at->equalTo($hold->expires_at), 'The held pickup time lasts exactly as long as the checkout.');

        // After the 10 minutes the checkout would have lived, housekeeping gives nothing away.
        $this->travel(12)->minutes();
        $this->artisan('checkouts:prune')->assertSuccessful();
        $this->assertSame(PickupSlotReservation::STATUS_ACTIVE, $hold->refresh()->status);
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
    }

    public function test_while_a_payment_is_open_the_cart_and_the_pickup_time_cannot_be_changed(): void
    {
        [, $headers, $checkout] = $this->openPayment();

        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($headers))
            ->assertStatus(409)->assertJsonPath('error.code', 'cart_locked_for_payment');
        $this->deleteJson('/api/v1/cart/pickup-selection', [], $this->using($headers))->assertStatus(409)->assertJsonPath('error.code', 'cart_locked_for_payment');
        // And the checkout itself cannot be opened again, changed or cancelled beside the payment.
        $this->openCheckout($headers)->assertStatus(409)->assertJsonPath('error.code', 'payment_in_progress');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cancel', [], $this->using($headers))->assertStatus(409)->assertJsonPath('error.code', 'payment_in_progress');
    }

    public function test_a_checkout_that_is_not_confirmed_expired_stale_or_not_the_customers_cannot_be_paid(): void
    {
        // not confirmed
        [, $headers] = $this->customerReadyForCheckout();
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $this->startPayment($headers, $draft)->assertStatus(409)->assertJsonPath('error.code', 'checkout_not_ready');

        // another price than the one confirmed
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $this->startPayment($headers, ['id' => $ready['id'], 'pricing' => ['fingerprint' => str_repeat('a', 64)]])->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');

        // somebody else's checkout does not exist for this customer
        [, $other] = $this->signedIn('+919811122233');
        $this->startPayment($other, $ready)->assertNotFound()->assertJsonPath('error.code', 'checkout_not_found');
        // a guest has no payments at all
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/checkouts/'.$ready['id'].'/payment-attempts', ['pricing_fingerprint' => $ready['pricing']['fingerprint']])->assertUnauthorized();

        // expired: the confirmed checkout ran out before Pay was pressed
        $this->travel(11)->minutes();
        $this->startPayment($headers, $ready)->assertStatus(410)->assertJsonPath('error.code', 'checkout_expired');
        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public function test_a_checkout_whose_price_or_restaurant_changed_is_not_paid(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();
        // The restaurant raises the price after the customer confirmed.
        app(MenuService::class)->updateItem($this->burger->refresh(), ['base_price_minor' => 25000, 'version' => $this->burger->version], $this->staff());

        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');
        $this->assertSame(CheckoutStatus::Stale, $this->sessionModel($checkout['id'])->status);   // the judgement is kept
        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public function test_a_checkout_with_nothing_to_pay_gets_no_payment(): void
    {
        $this->promotion(['code' => 'FREE100', 'type' => 'PERCENTAGE', 'amount_minor' => null, 'currency' => null, 'percent_basis_points' => 10000]);
        [, $headers] = $this->customerReadyForCheckout();
        $draft = $this->openCheckout($headers, ['promotion_code' => 'FREE100'])->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->assertJsonPath('checkout.payment.status', 'PAYMENT_NOT_REQUIRED')->json('checkout');

        $this->startPayment($headers, $ready)->assertStatus(409)->assertJsonPath('error.code', 'payment_not_required');
        $this->assertSame(0, PaymentAttempt::query()->count());
    }

    public function test_a_payment_method_is_a_type_the_market_offers_and_never_cash(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        foreach (['CASH_AT_PICKUP', 'CASH', 'COD', 'BITCOIN'] as $type) {
            $this->startPayment($headers, $checkout, ['payment_method' => ['type' => $type]])->assertStatus(422)->assertJsonPath('error.code', 'payment_method_unavailable');
        }
        $this->startPayment($headers, $checkout, ['payment_method' => ['type' => 'CARD', 'card_number' => '4111111111111111']])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, PaymentAttempt::query()->count());

        $this->startPayment($headers, $checkout, ['payment_method' => ['type' => 'card']])->assertCreated()->assertJsonPath('payment.requested_method_type', 'CARD');
    }

    public function test_the_attempt_and_the_step_to_the_provider_are_on_its_timeline(): void
    {
        [, , , $payment] = $this->openPayment();

        $lines = PaymentAttemptEvent::query()->where('payment_attempt_id', $this->attempt($payment['id'])->getKey())->orderBy('id')->get();
        $this->assertSame(['attempt_created', 'provider_ready'], $lines->pluck('type')->all());
        $this->assertSame(['SYSTEM', 'PROVIDER'], $lines->pluck('source')->all());
        $this->assertSame(['CREATED', 'PROVIDER_READY'], [$lines[1]->from_status, $lines[1]->to_status]);
    }

    public function test_the_provider_refusing_or_being_down_charges_nothing_and_lets_the_customer_try_again(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        $this->fakeProvider()->failNext('create_session', ProviderFailure::Rejected);
        $refused = $this->startPayment($headers, $checkout)->assertStatus(503)->assertJsonPath('error.code', 'payment_initialization_failed')->assertJsonPath('error.details.retryable', true)->json('error.details');
        $failed = $this->attempt($refused['payment_id']);
        $this->assertSame('FAILED', $failed->status->value);
        $this->assertSame('INITIALIZATION_FAILED', $failed->failure_category->value);
        $this->assertNull($failed->provider_order_reference);
        // Nothing was charged and the checkout is a confirmed checkout again.
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);

        $this->fakeProvider()->failNext('create_session', ProviderFailure::Unavailable);
        $this->startPayment($headers, $checkout)->assertStatus(503)->assertJsonPath('error.code', 'payment_provider_unavailable');

        // The third try works — and the two failed attempts are still there.
        $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.attempt_number', 3)->assertJsonPath('payment.status', 'PROVIDER_READY');
        $this->assertSame(['FAILED', 'FAILED', 'PROVIDER_READY'], PaymentAttempt::query()->orderBy('attempt_number')->pluck('status')->map->value->all());
    }

    public function test_a_lost_answer_from_the_provider_does_not_create_a_second_provider_order(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        // The provider creates the order, the answer is lost, and the provider can be asked by our receipt.
        $this->fakeProvider()->failNext('create_session', ProviderFailure::Timeout, lostAnswer: true);
        $payment = $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.status', 'PROVIDER_READY')->json('payment');

        $attempt = $this->attempt($payment['id']);
        $this->assertSame($this->fakeProvider()->findSessionByReceipt($attempt->provider_receipt)->reference, $attempt->provider_order_reference);
        $this->assertSame(1, PaymentAttempt::query()->count());
        // Asking the provider again with the same receipt gives the same order, not a second one.
        $again = $this->fakeProvider()->createSession(new ProviderSessionRequest(new Money(50000, 'INR'), $attempt->provider_receipt));
        $this->assertSame($attempt->provider_order_reference, $again->reference);
    }

    public function test_when_it_cannot_be_known_whether_the_provider_has_the_order_nothing_is_assumed(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        // The answer is lost AND the provider cannot be asked afterwards.
        $this->fakeProvider()->failNext('create_session', ProviderFailure::Timeout, lostAnswer: true);
        $this->fakeProvider()->failNext('find_session', ProviderFailure::Unavailable, 20);
        $unknown = $this->startPayment($headers, $checkout)->assertStatus(503)->assertJsonPath('error.code', 'payment_initialization_unknown')->assertJsonPath('error.details.retryable', false)->json('error.details');

        $attempt = $this->attempt($unknown['payment_id']);
        $this->assertSame('UNKNOWN', $attempt->status->value);
        $this->assertSame('INITIALIZATION_UNKNOWN', $attempt->status_reason);
        // A blind second try is refused: the first one is not settled.
        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_in_progress');
        $this->assertSame(1, PaymentAttempt::query()->count());

        // The provider answers again: the order exists and nobody has paid — the customer can pay with it after all.
        $this->fakeProvider()->recover();
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $attempt->refresh();
        $this->assertSame('PROVIDER_READY', $attempt->status->value);
        $this->assertNotNull($attempt->provider_order_reference);
        $this->showPayment($headers, $unknown['payment_id'])->assertOk()->assertJsonPath('payment.next_action', 'OPEN_PROVIDER');
    }

    public function test_with_no_provider_configured_payments_are_unavailable_and_no_checkout_is_touched(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        config(['services.payment.driver' => null]);
        $this->startPayment($headers, $checkout)->assertStatus(503)->assertJsonPath('error.code', 'payment_provider_unavailable');
        // Razorpay named but not configured: no fallback to the fake provider, no invented payment.
        config(['services.payment.driver' => 'razorpay']);
        $this->startPayment($headers, $checkout)->assertStatus(503)->assertJsonPath('error.code', 'payment_provider_unavailable');
        // The fake provider where fake providers are not allowed (as in production).
        config(['services.payment.driver' => 'fake', 'payments.providers.allow_fake' => false]);
        $this->startPayment($headers, $checkout)->assertStatus(503)->assertJsonPath('error.code', 'payment_provider_unavailable');

        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);
        $this->showCheckout($headers, $checkout['id'])->assertOk()->assertJsonPath('checkout.payment.provider.status', 'UNAVAILABLE');
    }
}
