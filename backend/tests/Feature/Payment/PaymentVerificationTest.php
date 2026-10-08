<?php

namespace Tests\Feature\Payment;

use App\Enums\CheckoutStatus;
use App\Enums\ProviderFailure;
use App\Events\Payment\PaymentVerified;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PickupSlotReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * What a device reports about a payment is a signal, never proof (Module 30). A payment becomes VERIFIED only when
 * the provider's own record shows a captured payment on the attempt's own order for the attempt's amount and
 * currency — and what a customer or a device claims cannot make it so, or stop it.
 */
class PaymentVerificationTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_genuine_proof_and_a_captured_payment_at_the_provider_verify_the_payment(): void
    {
        Event::fake([PaymentVerified::class]);
        [, $headers, $checkout, $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id'], 'captured', ['method' => 'CARD']);

        $verified = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->json('payment');

        $this->assertSame('VERIFIED', $verified['status']);
        $this->assertSame('PROCEED_TO_ORDER', $verified['next_action']);
        $this->assertNull($verified['failure']);
        $this->assertNotNull($verified['verified_at']);
        $this->assertNull($verified['provider_session']);
        $this->assertSame('CARD', $verified['method']['type']);
        $this->assertEqualsCanonicalizing(['network' => 'Visa', 'card_type' => 'debit', 'last4' => '1007'], $verified['method']['summary']);
        // Paid is not yet ordered: the order is created by the order domain from PaymentVerified (faked here) — none is invented.
        $this->assertSame(['status' => 'ORDER_PENDING', 'order_id' => null, 'order_number' => null], $verified['fulfillment']);
        $this->assertSame('Payment received. We\'re confirming your order.', $verified['message']);

        $attempt = $this->attempt($payment['id']);
        $this->assertSame($paid->reference, $attempt->provider_payment_reference);
        $this->assertNotNull($attempt->signature_verified_at);
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $e): bool => $e->paymentId === $payment['id'] && $e->checkoutId === $checkout['id'] && $e->amountMinor === 50000 && $e->currency === 'INR' && ! $e->late);

        // The checkout stays in payment — locked — and it and its pickup time are kept for the order.
        $session = $this->sessionModel($checkout['id']);
        $this->assertSame(CheckoutStatus::PaymentPending, $session->status);
        $this->assertSame(30, (int) round(now()->diffInMinutes($session->expires_at)));
        $this->assertTrue($session->expires_at->equalTo(PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->where('status', 'ACTIVE')->sole()->expires_at));
    }

    public function test_reporting_the_same_success_again_changes_nothing_and_announces_nothing_twice(): void
    {
        Event::fake([PaymentVerified::class]);
        [, $headers, , $payment] = $this->openPayment();
        $proof = $this->proofOf($payment['id'], $this->providerPays($payment['id']));

        $first = $this->completePayment($headers, $payment['id'], $proof)->assertOk()->json('payment');
        $again = $this->completePayment($headers, $payment['id'], $proof)->assertOk()->json('payment');
        $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED');

        $this->assertSame($first['verified_at'], $again['verified_at']);
        Event::assertDispatchedTimes(PaymentVerified::class, 1);
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'verified')->count());
    }

    public function test_a_success_that_a_client_invents_is_not_a_payment(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();

        // "It worked" without the provider's proof is not accepted at all.
        $this->completePayment($headers, $payment['id'], ['outcome' => 'success'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        // A status is not something a client can send.
        foreach ([['status' => 'VERIFIED'], ['verified' => true], ['verified_at' => now()->toIso8601String()], ['paid' => true], ['amount' => 50000], ['currency' => 'INR'], ['captured' => true]] as $claim) {
            $this->completePayment($headers, $payment['id'], ['outcome' => 'success', 'provider_payment_reference' => 'fakepay_invented0001', 'provider_signature' => str_repeat('ab', 32), ...$claim])
                ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        // An invented reference with an invented proof: the proof is checked with the server's key and fails.
        $this->completePayment($headers, $payment['id'], ['outcome' => 'success', 'provider_payment_reference' => 'fakepay_invented0001', 'provider_signature' => str_repeat('ab', 32)])
            ->assertStatus(422)->assertJsonPath('error.code', 'payment_signature_invalid');

        $attempt = $this->attempt($payment['id']);
        $this->assertSame('PROVIDER_READY', $attempt->status->value);
        $this->assertSame(1, (int) $attempt->signature_failures);
        $this->assertNull($attempt->verified_at);
        $this->assertNull($attempt->client_outcome);
        $this->assertSame(1, PaymentAttemptEvent::query()->where('type', 'signature_invalid')->count());
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
        // The customer can still pay for real.
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.next_action', 'OPEN_PROVIDER');
    }

    public function test_a_damaged_proof_of_a_real_payment_is_refused_and_the_payment_is_found_at_the_provider(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        // The customer really pays at the provider; the proof that reaches the device is damaged on the way.
        $paid = $this->providerPays($payment['id']);
        $damaged = ['outcome' => 'success', 'provider_payment_reference' => $paid->reference, 'provider_signature' => str_repeat('0', 64)];

        // The claim is refused …
        $this->completePayment($headers, $payment['id'], $damaged)->assertStatus(422)->assertJsonPath('error.code', 'payment_signature_invalid');
        // … and the provider, asked about OUR order at once, has the captured payment: verified on the provider's
        // word, with the proof still unverified and nothing of the claim recorded as the device's outcome.
        $attempt = $this->attempt($payment['id']);
        $this->assertSame('VERIFIED', $attempt->status->value);
        $this->assertNull($attempt->signature_verified_at);
        $this->assertNull($attempt->client_outcome);
        $this->assertSame(1, (int) $attempt->signature_failures);
        $this->assertSame($paid->reference, $attempt->provider_payment_reference);
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
    }

    public function test_a_genuine_payment_of_another_order_cannot_be_attached_to_this_checkout(): void
    {
        [, $asha, , $hers] = $this->openPayment('+919876543210');
        [, $ravi, , $his] = $this->openPayment('+919811122233');
        // Ravi really pays his own order — and tries to use that payment and its genuine proof for Asha's attempt too…
        $paid = $this->providerPays($his['id']);
        $proof = $this->proofOf($his['id'], $paid);

        // …as Asha (the proof is bound to Ravi's order, not hers)
        $this->completePayment($asha, $hers['id'], $proof)->assertStatus(422)->assertJsonPath('error.code', 'payment_signature_invalid');
        $this->assertSame('PROVIDER_READY', $this->attempt($hers['id'])->status->value);
        // …and Asha cannot reach Ravi's payment at all.
        $this->completePayment($asha, $his['id'], $proof)->assertNotFound()->assertJsonPath('error.code', 'payment_not_found');

        // For Ravi's own attempt it is what it is.
        $this->completePayment($ravi, $his['id'], $proof)->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $this->assertSame(1, PaymentAttempt::query()->where('status', 'VERIFIED')->count());
    }

    public function test_a_captured_payment_of_another_amount_is_never_verified(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        // The provider captured ₹501 for an attempt of ₹500. The proof is genuine; the amount is not ours.
        $paid = $this->providerPays($payment['id'], 'captured', ['amount_minor' => 50100]);

        $answer = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->json('payment');

        $this->assertSame('REVIEW_REQUIRED', $answer['status']);
        $this->assertSame('CONTACT_SUPPORT', $answer['next_action']);
        $this->assertNull($answer['verified_at']);
        $this->assertSame('NOT_APPLICABLE', $answer['fulfillment']['status']);
        $attempt = $this->attempt($payment['id']);
        $this->assertSame('PAYMENT_AMOUNT_MISMATCH', $attempt->status_reason);
        $this->assertNull($attempt->provider_payment_reference);
        // Money moved: the checkout is not handed back for a second payment.
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);
        $this->startPayment($headers, $checkout)->assertStatus(409);
    }

    public function test_a_captured_payment_in_another_currency_is_never_verified(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id'], 'captured', ['currency' => 'USD']);

        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'REVIEW_REQUIRED');
        $this->assertSame('PAYMENT_CURRENCY_MISMATCH', $this->attempt($payment['id'])->status_reason);
    }

    public function test_an_authorized_payment_is_not_paid_until_the_provider_captures_it(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $authorized = $this->providerPays($payment['id'], 'authorized');

        $waiting = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $authorized))->assertOk()->json('payment');
        $this->assertSame('AUTHORIZED', $waiting['status']);
        $this->assertSame('WAIT', $waiting['next_action']);
        $this->assertNull($waiting['verified_at']);
        $this->assertSame('NOT_APPLICABLE', $waiting['fulfillment']['status']);

        $this->fakeProvider()->settle($authorized->reference, 'captured');
        $this->travel(15)->seconds();
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED')->assertJsonPath('payment.next_action', 'PROCEED_TO_ORDER');
    }

    public function test_a_cancelled_payment_charges_nothing_and_the_checkout_can_be_paid_again(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();

        $cancelled = $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled'])->assertOk()->json('payment');

        $this->assertSame('CANCELLED', $cancelled['status']);
        $this->assertSame(['category' => 'CUSTOMER_CANCELLED', 'code' => 'PAYMENT_CANCELLED', 'message' => 'The payment was cancelled. Nothing was charged.', 'retryable' => true], $cancelled['failure']);
        $this->assertSame('RETRY_PAYMENT', $cancelled['next_action']);
        // The checkout is a confirmed checkout again: the cart is unlocked and the pickup time is still held.
        $session = $this->sessionModel($checkout['id']);
        $this->assertSame(CheckoutStatus::Ready, $session->status);
        $this->assertSame(1, PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->where('status', 'ACTIVE')->count());

        // A new attempt — the cancelled one stays as it is.
        $second = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $this->assertSame(2, $second['attempt_number']);
        $this->assertNotSame($payment['id'], $second['id']);
        $this->assertSame(['CANCELLED', 'PROVIDER_READY'], PaymentAttempt::query()->orderBy('attempt_number')->pluck('status')->map->value->all());
        $this->assertNotSame($this->attempt($payment['id'])->provider_order_reference, $this->attempt($second['id'])->provider_order_reference);
    }

    public function test_a_failed_payment_says_why_in_safe_words_and_can_be_tried_again(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $failed = $this->providerPays($payment['id'], 'failed', ['error_reason' => 'insufficient_funds']);

        $answer = $this->completePayment($headers, $payment['id'], ['outcome' => 'failed', 'provider_error' => ['code' => $failed->errorCode, 'reason' => $failed->errorReason]])->assertOk()->json('payment');

        $this->assertSame('FAILED', $answer['status']);
        $this->assertSame('INSUFFICIENT_FUNDS', $answer['failure']['category']);
        $this->assertSame('PAYMENT_FAILED', $answer['failure']['code']);
        $this->assertSame('SELECT_ANOTHER_METHOD', $answer['next_action']);
        $this->assertTrue($answer['failure']['retryable']);
        // Nothing of the provider's own wording, and nothing that blames the customer.
        $this->assertStringNotContainsStringIgnoringCase('insufficient', $answer['failure']['message']);
        $this->assertStringNotContainsStringIgnoringCase('BAD_REQUEST', json_encode($answer));
        // For support the provider's code is kept.
        $this->assertSame('insufficient_funds', $this->attempt($payment['id'])->provider_error_reason);

        $this->assertSame(CheckoutStatus::Ready, $this->sessionModel($checkout['id'])->status);
        $this->startPayment($headers, $checkout)->assertCreated()->assertJsonPath('payment.attempt_number', 2);
    }

    public function test_a_failure_inside_the_providers_interface_does_not_end_the_attempt_while_the_customer_can_still_pay_there(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        // One payment fails in the provider's interface; the interface is still open and lets the customer try again.
        $failed = $this->providerPays($payment['id'], 'failed', ['error_reason' => 'incorrect_otp']);
        $this->providerSends('payment.failed', $failed)->assertOk();
        $this->assertSame('PROCESSING', $this->attempt($payment['id'])->status->value);

        // The second try on the same order succeeds.
        $paid = $this->providerPays($payment['id']);
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $this->assertSame($paid->reference, $this->attempt($payment['id'])->provider_payment_reference);
    }

    public function test_a_device_saying_cancelled_or_failed_cannot_hide_a_payment_the_provider_captured(): void
    {
        foreach (['cancelled', 'failed'] as $i => $claim) {
            [, $headers, , $payment] = $this->openPayment('+9198111222'.(30 + $i));
            $this->providerPays($payment['id']);   // the money is captured …

            // … and the device reports the opposite (a lost connection, a closed window, or a lie).
            $this->completePayment($headers, $payment['id'], ['outcome' => $claim])->assertOk()->assertJsonPath('payment.status', 'VERIFIED')->assertJsonPath('payment.failure', null);
        }
        $this->assertSame(2, PaymentAttempt::query()->where('status', 'VERIFIED')->count());
    }

    public function test_when_the_provider_cannot_be_asked_a_payment_is_neither_paid_nor_failed(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $proof = $this->proofOf($payment['id'], $this->providerPays($payment['id']));
        $this->fakeProvider()->failNext('payments', ProviderFailure::Unavailable, 50);

        $unknown = $this->completePayment($headers, $payment['id'], $proof)->assertOk()->json('payment');

        $this->assertSame('UNKNOWN', $unknown['status']);
        $this->assertSame('REFRESH_STATUS', $unknown['next_action']);
        $this->assertNull($unknown['failure']);
        $this->assertSame('We could not confirm your payment yet. Please do not pay again — we are checking.', $unknown['message']);
        // No second payment beside one that may have taken the money.
        $this->startPayment($headers, $checkout)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_in_progress');
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($checkout['id'])->status);

        // The provider is back: asking for the status is enough.
        $this->fakeProvider()->recover();
        $this->travel(15)->seconds();
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
    }

    public function test_a_payment_is_found_only_by_the_customer_it_belongs_to(): void
    {
        [, $asha, , $payment] = $this->openPayment('+919876543210');
        [, $ravi] = $this->signedIn('+919811122233');

        $this->showPayment($ravi, $payment['id'])->assertNotFound()->assertJsonPath('error.code', 'payment_not_found');
        $this->completePayment($ravi, $payment['id'], ['outcome' => 'cancelled'])->assertNotFound()->assertJsonPath('error.code', 'payment_not_found');
        // Exactly as for an id that does not exist, and not at all without a session.
        $this->showPayment($asha, '00000000-0000-4000-8000-000000000000')->assertNotFound()->assertJsonPath('error.code', 'payment_not_found');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/payments/'.$payment['id'])->assertUnauthorized();
        $this->postJson('/api/v1/payments/'.$payment['id'].'/complete', ['outcome' => 'cancelled'])->assertUnauthorized();

        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
        $this->showPayment($asha, $payment['id'])->assertOk()->assertJsonPath('payment.id', $payment['id']);
    }

    public function test_restaurant_staff_and_administrators_have_no_customer_payment_endpoints(): void
    {
        [, , , $payment] = $this->openPayment();

        $this->actingAsPrincipal($this->staff());
        $this->getJson('/api/v1/payments/'.$payment['id'])->assertUnauthorized();
        $this->postJson('/api/v1/payments/'.$payment['id'].'/complete', ['outcome' => 'cancelled'])->assertUnauthorized();
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
    }
}
