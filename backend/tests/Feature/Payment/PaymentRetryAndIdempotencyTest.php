<?php

namespace Tests\Feature\Payment;

use App\Enums\CheckoutStatus;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProviderPaymentState;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Services\Menu\MenuService;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Payment\PaymentStateMachine;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * One checkout, one charge (Module 30): repeated requests, two devices, retries after failure and the rules the
 * database itself enforces. A checkout can collect failed, cancelled and expired attempts — and at most one open
 * attempt and one verified payment.
 */
class PaymentRetryAndIdempotencyTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_asking_again_for_the_same_checkout_returns_the_same_attempt_and_no_second_provider_order(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        $first = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $again = $this->startPayment($headers, $checkout)->assertOk()->json('payment');   // a double tap, a reload, the back button

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['provider_session']['order_reference'], $again['provider_session']['order_reference']);
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'provider_ready')->count());
    }

    public function test_the_same_idempotency_key_is_answered_once(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();
        $key = ['Idempotency-Key' => 'pay-attempt-'.Str::random(24)];

        $first = $this->startPayment($headers, $checkout, [], $key)->assertCreated();
        $replay = $this->startPayment($headers, $checkout, [], $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertEquals($first->json(), $replay->json());
        // The same key for another request is refused, and a malformed key is not accepted at all.
        $this->startPayment($headers, $checkout, ['payment_method' => ['type' => 'CARD']], $key)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->startPayment($headers, $checkout, [], ['Idempotency-Key' => 'short'])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_two_devices_of_one_customer_get_one_attempt(): void
    {
        [$customer, $phone, $checkout] = $this->readyCheckout();
        $tablet = $this->sessionOf($customer);

        $onPhone = $this->startPayment($phone, $checkout)->assertCreated()->json('payment');
        $onTablet = $this->startPayment($tablet, $checkout)->assertOk()->json('payment');

        $this->assertSame($onPhone['id'], $onTablet['id']);
        $this->assertSame(1, PaymentAttempt::query()->count());
        // Whichever device pays, the other one sees the same payment.
        $this->completePayment($tablet, $onTablet['id'], $this->proofOf($onTablet['id'], $this->providerPays($onTablet['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $this->showPayment($phone, $onPhone['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $this->startPayment($phone, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_verified');
    }

    public function test_when_two_requests_race_the_database_keeps_one_open_attempt(): void
    {
        [, , , $payment] = $this->openPayment();
        $open = $this->attempt($payment['id']);

        // A second request that got past every check at the same instant would write this row. The database refuses it.
        try {
            DB::transaction(fn () => $this->copyOf($open, ['attempt_number' => 2, 'provider_order_reference' => 'fakeorder_second00001'])->save());
            $this->fail('A second open attempt for the same checkout must be refused by the database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('payment_attempts_one_open_per_checkout', $e->getMessage());
        }
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_the_database_keeps_one_verified_payment_per_checkout_and_one_attempt_per_provider_transaction(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $verified = $this->attempt($payment['id']);

        $refusals = [
            // a second VERIFIED payment for the checkout
            'payment_attempts_one_verified_per_checkout' => ['attempt_number' => 2, 'provider_order_reference' => 'fakeorder_second00001', 'provider_payment_reference' => 'fakepay_second000001'],
            // the same provider transaction attached to another attempt (of any checkout)
            'payment_attempts_provider_payment_unique' => ['attempt_number' => 2, 'checkout_public_id' => (string) Str::uuid(), 'provider_order_reference' => 'fakeorder_third000001'],
            // the same provider order attached to another attempt
            'payment_attempts_provider_order_unique' => ['attempt_number' => 2, 'checkout_public_id' => (string) Str::uuid(), 'provider_payment_reference' => 'fakepay_fourth000001'],
            // VERIFIED without a provider payment
            'payment_attempts_verified_check' => ['attempt_number' => 2, 'checkout_public_id' => (string) Str::uuid(), 'provider_order_reference' => 'fakeorder_fifth000001', 'provider_payment_reference' => null],
            // an amount of nothing
            'payment_attempts_amount_check' => ['attempt_number' => 2, 'checkout_public_id' => (string) Str::uuid(), 'provider_order_reference' => 'fakeorder_sixth000001', 'provider_payment_reference' => 'fakepay_sixth0000001', 'amount_minor' => 0],
            // a fulfilment state on a payment that is not verified
            'payment_attempts_fulfillment_state_check' => ['attempt_number' => 2, 'checkout_public_id' => (string) Str::uuid(), 'provider_order_reference' => 'fakeorder_seven000001', 'provider_payment_reference' => null, 'status' => 'FAILED', 'verified_at' => null],
        ];
        foreach ($refusals as $constraint => $changes) {
            try {
                DB::transaction(fn () => $this->copyOf($verified, $changes)->save());
                $this->fail("The database must refuse: {$constraint}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_a_paid_checkout_cannot_be_paid_again(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk();

        $refused = $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_verified')->json('error.details');

        $this->assertSame(['payment_id' => $payment['id'], 'next_action' => 'PROCEED_TO_ORDER'], $refused);
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_every_attempt_of_a_checkout_is_kept_and_numbered_in_order(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();

        // 1 fails, 2 is cancelled, 3 succeeds.
        $first = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $failed = $this->providerPays($first['id'], 'failed');
        $this->completePayment($headers, $first['id'], ['outcome' => 'failed', 'provider_error' => ['code' => $failed->errorCode, 'reason' => $failed->errorReason]])->assertOk()->assertJsonPath('payment.status', 'FAILED');
        $second = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $this->completePayment($headers, $second['id'], ['outcome' => 'cancelled'])->assertOk()->assertJsonPath('payment.status', 'CANCELLED');
        $third = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $this->completePayment($headers, $third['id'], $this->proofOf($third['id'], $this->providerPays($third['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');

        $attempts = PaymentAttempt::query()->where('checkout_public_id', $checkout['id'])->orderBy('attempt_number')->get();
        $this->assertSame([[1, 'FAILED'], [2, 'CANCELLED'], [3, 'VERIFIED']], $attempts->map(fn (PaymentAttempt $a): array => [$a->attempt_number, $a->status->value])->all());
        $this->assertCount(3, $attempts->pluck('provider_order_reference')->unique());
        $this->assertCount(3, $attempts->pluck('provider_receipt')->unique());
        $this->assertSame([50000, 50000, 50000], $attempts->pluck('amount_minor')->all());
        // The checkout says which attempt is the latest.
        $this->showCheckout($headers, $checkout['id'])->assertOk()->assertJsonPath('checkout.payment.attempt.id', $third['id'])->assertJsonPath('checkout.payment.attempt.status', 'VERIFIED');
    }

    public function test_a_checkout_cannot_be_used_to_open_provider_sessions_without_end(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();
        foreach (range(1, 5) as $number) {
            $payment = $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.attempt_number', $number)->json('payment');
            $cancelled = $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled'])->assertOk()->json('payment');
        }

        // The fifth cancellation already says there is no sixth try.
        $this->assertSame('RETURN_TO_CHECKOUT', $cancelled['next_action']);
        $this->assertFalse($cancelled['failure']['retryable']);
        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_attempt_limit_reached')->assertJsonPath('error.details.next_action', 'RETURN_TO_CHECKOUT');
        $this->assertSame(5, PaymentAttempt::query()->count());
    }

    public function test_no_attempt_starts_and_nothing_is_held_beyond_the_payment_window(): void
    {
        config(['payments.window_minutes' => 12]);
        [, $headers, $checkout] = $this->readyCheckout();

        $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        // The attempt cannot outlast the window: 12 minutes instead of 15.
        $this->assertSame(12, (int) round(now()->diffInMinutes($this->attempt($payment['id'])->expires_at)));
        $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled'])->assertOk()->assertJsonPath('payment.next_action', 'RETRY_PAYMENT');

        $this->travel(13)->minutes();
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.next_action', 'RETURN_TO_CHECKOUT');
        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_window_closed');
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_the_amount_of_an_attempt_is_fixed_and_a_changed_checkout_gets_a_new_checkout_and_a_new_attempt(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled'])->assertOk();

        // The restaurant changes the price before the customer tries again: the old checkout is not paid at the old price.
        app(MenuService::class)->updateItem($this->burger->refresh(), ['base_price_minor' => 25000, 'version' => $this->burger->version], $this->staff());
        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');
        $this->assertSame(CheckoutStatus::Stale, $this->sessionModel($checkout['id'])->status);

        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $this->assertNotSame($checkout['id'], $ready['id']);
        $new = $this->startPayment($headers, $ready)->assertCreated()->json('payment');

        $this->assertSame($ready['pricing']['total'], $new['amount']);
        $this->assertNotSame(50000, $new['amount']['amount']);
        $this->assertSame(1, $new['attempt_number']);   // the first attempt of the new checkout
        // The old attempt is what it was: ₹500 for the old checkout, cancelled.
        $old = $this->attempt($payment['id']);
        $this->assertSame([50000, 'INR', 'CANCELLED', $checkout['id']], [$old->amount_minor, $old->currency, $old->status->value, $old->checkout_public_id]);
    }

    public function test_starting_payments_is_rate_limited(): void
    {
        config(['rate_limits.payment' => 3]);
        [, $headers, $checkout] = $this->readyCheckout();

        foreach (range(1, 3) as $_) {
            $this->startPayment($headers, $checkout)->assertSuccessful();
        }
        $this->startPayment($headers, $checkout)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    public function test_the_state_machine_allows_only_the_steps_of_a_payments_life(): void
    {
        $steps = fn (PaymentStatus $from): array => array_values(array_map(fn (PaymentStatus $s): string => $s->value, array_filter(PaymentStatus::cases(), fn (PaymentStatus $to): bool => $from->allows($to))));

        // VERIFIED is final: nothing that arrives later can take it back.
        $this->assertSame([], $steps(PaymentStatus::Verified));
        // What ended without money can only be overturned by the provider's evidence, or handed to a person.
        foreach ([PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Expired] as $ended) {
            $this->assertSame(['VERIFIED', 'REVIEW_REQUIRED'], $steps($ended));
        }
        $this->assertSame(['VERIFIED', 'FAILED'], $steps(PaymentStatus::ReviewRequired));
        $this->assertSame(['PROVIDER_READY', 'FAILED', 'EXPIRED', 'UNKNOWN'], $steps(PaymentStatus::Created));
        $this->assertSame(['VERIFIED', 'FAILED', 'UNKNOWN', 'REVIEW_REQUIRED'], $steps(PaymentStatus::Authorized));
        $this->assertNotContains('CREATED', array_merge(...array_map($steps, PaymentStatus::cases())));
        $this->assertSame(['CREATED', 'PROVIDER_READY', 'PROCESSING', 'PENDING', 'AUTHORIZED', 'UNKNOWN'], PaymentStatus::openValues());
    }

    public function test_verified_can_only_be_reached_with_the_providers_own_evidence_for_this_attempt(): void
    {
        [, , , $payment] = $this->openPayment();
        $machine = $this->app->make(PaymentStateMachine::class);
        $paid = $this->providerPays($payment['id']);
        $attempt = $this->attempt($payment['id']);
        $with = fn (array $changes): ProviderPayment => new ProviderPayment(
            $paid->reference, array_key_exists('order', $changes) ? $changes['order'] : $paid->orderReference, $changes['state'] ?? $paid->state, 'x', $changes['amount'] ?? $paid->amount,
        );

        // No general door to VERIFIED …
        $this->assertThrows(fn () => $machine->move($attempt, PaymentStatus::Verified, PaymentAttemptEvent::SOURCE_ADMIN), LogicException::class);
        // … and the one that exists does not open for evidence that is not exactly this attempt's captured payment.
        foreach ([
            ['state' => ProviderPaymentState::Authorized],
            ['state' => ProviderPaymentState::Failed],
            ['order' => 'fakeorder_somebodyelse'],
            ['order' => null],
            ['amount' => new Money(49999, 'INR')],
            ['amount' => new Money(50000, 'USD')],
        ] as $changes) {
            $this->assertThrows(fn () => $machine->verify($attempt, $with($changes), PaymentFulfillmentStatus::OrderPending, null, PaymentAttemptEvent::SOURCE_ADMIN), LogicException::class);
        }
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);

        // A step that is not allowed changes nothing and is noted on the timeline — never an error.
        DB::transaction(function () use ($machine, $payment, $paid): void {
            [$locked] = $this->app->make(PaymentAttemptStore::class)->locked($this->attempt($payment['id'])->getKey());
            $this->assertTrue($machine->verify($locked, $paid, PaymentFulfillmentStatus::OrderPending, null, PaymentAttemptEvent::SOURCE_JOB));
            $this->assertFalse($machine->verify($locked, $paid, PaymentFulfillmentStatus::OrderPending, null, PaymentAttemptEvent::SOURCE_JOB));
            $this->assertFalse($machine->move($locked, PaymentStatus::Failed, PaymentAttemptEvent::SOURCE_WEBHOOK));
            $this->assertFalse($machine->move($locked, PaymentStatus::Processing, PaymentAttemptEvent::SOURCE_WEBHOOK));
        });
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
        $this->assertSame(2, PaymentAttemptEvent::query()->where('type', 'step_refused')->count());
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function copyOf(PaymentAttempt $attempt, array $changes): PaymentAttempt
    {
        $copy = new PaymentAttempt;
        $copy->forceFill([...collect($attempt->getAttributes())->except(['id', 'public_id', 'created_at', 'updated_at'])->all(), 'provider_receipt' => 'pa_'.Str::lower(Str::random(32)), ...$changes]);

        return $copy;
    }
}
