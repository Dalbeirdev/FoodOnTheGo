<?php

namespace Tests\Feature\Payment;

use App\Enums\CheckoutStatus;
use App\Enums\ProviderFailure;
use App\Events\Payment\PaymentVerified;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PickupSlotReservation;
use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * Reconciliation (Module 30): payments that nobody reports are settled by asking the provider — pending ones that
 * succeed or fail later, customers who disappear, attempts that run out — and what cannot be settled is never
 * guessed: it stays UNKNOWN, is tried again with growing waits, and in the end is handed to a person.
 *
 * A VERIFIED payment whose checkout can no longer become an order is not lost: it is marked as needing recovery.
 */
class PaymentReconciliationTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_pending_payment_stays_pending_and_no_second_payment_starts_beside_it(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $this->providerPays($payment['id'], 'created');   // the bank has not answered yet

        // The device has nothing to report but that the interface closed.
        $this->travel(15)->seconds();
        $pending = $this->showPayment($headers, $payment['id'])->assertOk()->json('payment');
        $this->assertSame('PENDING', $pending['status']);
        $this->assertSame('WAIT', $pending['next_action']);
        $this->assertSame(5, $pending['retry_after_seconds']);
        $this->assertNull($pending['failure']);
        $this->assertSame('NOT_APPLICABLE', $pending['fulfillment']['status']);

        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_in_progress')->assertJsonPath('error.details.payment_id', $payment['id']);
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
    }

    public function test_a_pending_payment_that_succeeds_later_is_verified_by_the_sweep(): void
    {
        Event::fake([PaymentVerified::class]);
        [, $headers, , $payment] = $this->openPayment();
        $pending = $this->providerPays($payment['id'], 'created');
        $this->travel(15)->seconds();
        $this->showPayment($headers, $payment['id'])->assertJsonPath('payment.status', 'PENDING');

        // Minutes later the bank confirms. Nobody tells FoodOnTheGo: no callback, no webhook.
        $this->fakeProvider()->settle($pending->reference, 'captured');
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);
        $this->assertSame('ORDER_PENDING', $attempt->fulfillment_status->value);
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        $this->assertSame('JOB', PaymentAttemptEvent::query()->where('type', 'verified')->sole()->source);
    }

    public function test_a_pending_payment_that_fails_later_is_closed_and_the_checkout_can_be_paid_again(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $pending = $this->providerPays($payment['id'], 'created');
        $this->completePayment($headers, $payment['id'], ['outcome' => 'failed'])->assertOk()->assertJsonPath('payment.status', 'PENDING');   // the device gave up; the provider has not

        $this->fakeProvider()->settle($pending->reference, 'failed', 'payment_timed_out');
        $this->travel(2)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['FAILED', 'TIMEOUT'], [$attempt->status->value, $attempt->failure_category->value]);
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);
        $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.attempt_number', 2);
    }

    public function test_a_customer_who_disappears_after_paying_is_found_paid_when_the_attempt_runs_out(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $this->providerPays($payment['id']);   // paid — and then the phone died. No callback, no webhook, no status request.

        $this->travel(16)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);
        $this->assertSame('ORDER_PENDING', $attempt->fulfillment_status->value);
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
    }

    public function test_an_attempt_nobody_paid_expires_and_gives_the_checkout_back_while_its_time_lasts(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();

        $this->travel(16)->minutes();   // 15 minutes to pay are over; the checkout has 5 more
        $expired = $this->showPayment($headers, $payment['id'])->assertOk()->json('payment');

        $this->assertSame('EXPIRED', $expired['status']);
        $this->assertSame('EXPIRED', $expired['failure']['category']);
        $this->assertSame('RETRY_PAYMENT', $expired['next_action']);
        $this->assertNull($expired['provider_session']);
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);
        $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.attempt_number', 2);
    }

    public function test_an_attempt_that_runs_out_with_its_checkout_ends_both_and_frees_the_pickup_time(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();

        $this->travel(25)->minutes();   // beyond the attempt and beyond the checkout's grace
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('EXPIRED', $this->attempt($payment['id'])->status->value);
        $session = $this->sessionModel($checkout['id']);
        $this->assertSame(CheckoutStatus::Expired, $session->status);
        $this->assertSame(0, PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->where('status', 'ACTIVE')->count());
        // Nothing can be paid any more: the customer starts from the cart again.
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.next_action', 'RETURN_TO_CHECKOUT');
        $this->startPayment($headers, $checkout)->assertStatus(410)->assertJsonPath('error.code', 'checkout_expired');
    }

    public function test_a_payment_captured_after_its_attempt_was_closed_is_still_a_payment(): void
    {
        Event::fake([PaymentVerified::class]);
        [, $headers, $checkout, $payment] = $this->openPayment();
        $this->travel(16)->minutes();
        $this->showPayment($headers, $payment['id'])->assertJsonPath('payment.status', 'EXPIRED');
        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);

        // The customer's bank confirms a payment that was started at the last second. The provider tells us.
        $late = $this->providerPays($payment['id']);
        $this->providerSends('payment.captured', $late)->assertOk();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);
        $this->assertSame('LATE_SUCCESS', $attempt->status_reason);
        Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $e): bool => $e->paymentId === $payment['id'] && $e->late);
        // Its checkout was still a valid confirmed checkout: it is taken back into payment and waits for the order.
        $this->assertSame('ORDER_PENDING', $attempt->fulfillment_status->value);
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_verified');
    }

    public function test_a_late_payment_whose_checkout_is_gone_needs_recovery_and_is_never_dropped(): void
    {
        [, , $checkout, $payment] = $this->openPayment();
        $this->travel(25)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(CheckoutStatus::Expired, $this->sessionModel($checkout['id'])->status);

        $this->providerSends('payment.captured', $this->providerPays($payment['id']))->assertOk();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED', 'CHECKOUT_NOT_IN_PAYMENT'], [$attempt->status->value, $attempt->fulfillment_status->value, $attempt->fulfillment_reason]);
        $this->assertNotNull($attempt->verified_at);
    }

    public function test_a_second_capture_for_a_checkout_that_is_already_paid_is_a_duplicate_for_a_person_to_return(): void
    {
        [, $headers, $checkout, $first] = $this->openPayment();
        $this->travel(16)->minutes();
        $this->showPayment($headers, $first['id'])->assertJsonPath('payment.status', 'EXPIRED');
        // The customer pays again with a second attempt …
        $second = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $this->completePayment($headers, $second['id'], $this->proofOf($second['id'], $this->providerPays($second['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');

        // … and then the FIRST attempt's payment is captured after all: the customer was charged twice.
        $this->providerSends('payment.captured', $this->providerPays($first['id']))->assertOk();

        $duplicate = $this->attempt($first['id']);
        $this->assertSame(['REVIEW_REQUIRED', 'DUPLICATE_PAYMENT'], [$duplicate->status->value, $duplicate->status_reason]);
        $this->assertNull($duplicate->verified_at);
        // One verified payment for the checkout — a rule of the database as well.
        $this->assertSame(1, PaymentAttempt::query()->where('checkout_public_id', $checkout['id'])->where('status', 'VERIFIED')->count());
        $this->assertSame('VERIFIED', $this->attempt($second['id'])->status->value);
    }

    public function test_an_open_attempt_beside_a_verified_payment_is_closed_before_it_can_charge_again(): void
    {
        [, $headers, $checkout, $first] = $this->openPayment();
        $this->travel(16)->minutes();
        $this->showPayment($headers, $first['id'])->assertJsonPath('payment.status', 'EXPIRED');
        $second = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');

        // The first attempt turns out paid while the second one is open and nobody has paid it.
        $this->providerSends('payment.captured', $this->providerPays($first['id']))->assertOk();
        $this->assertSame('VERIFIED', $this->attempt($first['id'])->status->value);

        $this->travel(15)->seconds();
        $closed = $this->showPayment($headers, $second['id'])->assertOk()->json('payment');
        $this->assertSame('CANCELLED', $closed['status']);
        $this->assertNull($closed['provider_session']);
        $this->assertSame('SUPERSEDED', $this->attempt($second['id'])->status_reason);
        $this->assertSame('RETURN_TO_CHECKOUT', $closed['next_action']);
    }

    public function test_while_the_provider_is_down_nothing_is_decided_and_the_sweep_keeps_trying_with_growing_waits(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $proof = $this->proofOf($payment['id'], $this->providerPays($payment['id']));
        $this->fakeProvider()->failNext('payments', ProviderFailure::Timeout, 500);
        $this->completePayment($headers, $payment['id'], $proof)->assertOk()->assertJsonPath('payment.status', 'UNKNOWN');

        $waits = [];
        for ($run = 1; $run <= 3; $run++) {
            $attempt = $this->attempt($payment['id']);
            $waits[] = (int) round(now()->diffInSeconds($attempt->next_reconcile_at));
            $this->travelTo($attempt->next_reconcile_at->addSecond());
            $this->artisan('payments:reconcile')->assertSuccessful();
            $this->assertSame(['UNKNOWN', $run], [$this->attempt($payment['id'])->status->value, (int) $this->attempt($payment['id'])->reconcile_runs]);
        }
        $this->assertSame([60, 120, 300], $waits);   // each wait longer than the one before
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
        $this->assertNull($this->attempt($payment['id'])->failure_category);

        // The provider recovers: the next look settles it.
        $this->fakeProvider()->recover();
        $this->travelTo($this->attempt($payment['id'])->next_reconcile_at->addSecond());
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }

    public function test_what_the_sweep_cannot_settle_in_all_its_tries_is_handed_to_a_person_and_never_guessed(): void
    {
        config(['payments.reconcile.backoff_seconds' => [60, 120]]);
        [, $headers, $checkout, $payment] = $this->openPayment();
        $proof = $this->proofOf($payment['id'], $this->providerPays($payment['id']));
        $this->fakeProvider()->failNext('payments', ProviderFailure::Unavailable, 500);
        $this->completePayment($headers, $payment['id'], $proof)->assertOk()->assertJsonPath('payment.status', 'UNKNOWN');

        for ($run = 1; $run <= 2; $run++) {
            $this->travelTo($this->attempt($payment['id'])->next_reconcile_at->addSecond());
            $this->artisan('payments:reconcile')->assertSuccessful();
        }

        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['REVIEW_REQUIRED', 'RECONCILIATION_EXHAUSTED'], [$attempt->status->value, $attempt->status_reason]);
        $this->assertNull($attempt->verified_at);
        $this->assertNull($attempt->next_reconcile_at);
        $answer = $this->showPayment($headers, $payment['id'])->assertOk()->json('payment');
        $this->assertSame('CONTACT_SUPPORT', $answer['next_action']);
        $this->assertSame('We are checking this payment. Please do not pay again.', $answer['message']);
        $this->assertNotEmpty($answer['support_reference']);
        // The money may have moved: the checkout is not handed back for another payment.
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
        $this->startPayment($headers, $checkout)->assertStatus(409);

        // A later look with the provider back still finds the truth.
        $this->fakeProvider()->recover();
        $this->app->make(PaymentReconciliationService::class)->reconcile($attempt, PaymentAttemptEvent::SOURCE_ADMIN);
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }

    public function test_looking_again_is_harmless_and_two_lookers_do_not_ask_the_provider_at_once(): void
    {
        Event::fake([PaymentVerified::class]);
        [, , , $payment] = $this->openPayment();
        $this->providerPays($payment['id']);
        $service = $this->app->make(PaymentReconciliationService::class);
        $attempt = $this->attempt($payment['id']);

        // Somebody else is looking right now: this caller takes what is there and asks nobody.
        $lock = Cache::lock('payments:reconcile:'.$attempt->getKey(), 30);
        $this->assertTrue($lock->get());
        $this->assertSame('PROVIDER_READY', $service->reconcile($attempt, PaymentAttemptEvent::SOURCE_JOB)->status->value);
        $lock->release();

        foreach (range(1, 3) as $_) {
            $this->assertSame('VERIFIED', $service->reconcile($this->attempt($payment['id']), PaymentAttemptEvent::SOURCE_JOB)->status->value);
        }
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'verified')->count());
    }

    public function test_a_verified_payment_whose_held_pickup_time_runs_out_before_an_order_exists_needs_recovery(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->assertJsonPath('payment.fulfillment.status', 'ORDER_PENDING');

        // No order module exists yet: the 30 minutes kept for it pass.
        $this->travel(31)->minutes();
        $this->artisan('checkouts:prune')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);   // the money is still the money
        $this->assertSame(['RECOVERY_REQUIRED', 'CHECKOUT_EXPIRED'], [$attempt->fulfillment_status->value, $attempt->fulfillment_reason]);
        $answer = $this->showPayment($headers, $payment['id'])->assertOk()->json('payment');
        $this->assertSame('Payment received. We could not confirm your order yet — you do not need to pay again.', $answer['message']);
        $this->assertSame('PROCEED_TO_ORDER', $answer['next_action']);
        // The checkout stays what it was — a paid checkout is never deleted or reopened by housekeeping.
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
    }

    public function test_a_payment_that_succeeds_after_the_restaurant_stopped_taking_orders_needs_recovery(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        // T1 the checkout was valid · T2 the provider's interface is open and the restaurant pauses · T3 the payment succeeds.
        $this->burgerHub->forceFill(['accepting_orders' => false])->save();

        $answer = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->json('payment');

        $this->assertSame('VERIFIED', $answer['status']);
        $this->assertSame('RECOVERY_REQUIRED', $answer['fulfillment']['status']);
        $this->assertSame('RESTAURANT_NOT_ACCEPTING', $this->attempt($payment['id'])->fulfillment_reason);
        $this->assertNull($answer['failure']);   // the payment did not fail
    }

    public function test_the_sweep_survives_an_attempt_it_cannot_look_at(): void
    {
        [, , , $payment] = $this->openPayment();
        // An attempt of a provider that is no longer configured here: it cannot be asked, and nothing is assumed.
        $this->attempt($payment['id'])->forceFill(['provider' => 'razorpay', 'client_outcome' => 'success', 'status' => 'PROCESSING', 'next_reconcile_at' => now()->subMinute()])->save();
        [, , , $other] = $this->openPayment('+919811122233');
        $this->providerPays($other['id']);
        $this->attempt($other['id'])->forceFill(['next_reconcile_at' => now()->subMinute()])->save();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('UNKNOWN', $this->attempt($payment['id'])->status->value);
        $this->assertSame('VERIFIED', $this->attempt($other['id'])->status->value);
    }
}
