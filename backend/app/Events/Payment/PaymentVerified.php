<?php

namespace App\Events\Payment;

use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;

/**
 * A payment is VERIFIED: the provider captured exactly the checkout's total for this attempt (Module 30).
 *
 * This is the hand-over to order creation (Module 31). It is raised once, after the commit, when the attempt
 * becomes VERIFIED — but an event can be delivered more than once or be lost with a process, so whoever creates
 * the order must do it idempotently by $paymentId and must also pick up verified payments that have no order yet
 * (payment_attempts.fulfillment_status = ORDER_PENDING). Nothing listens yet: no order is created in this module.
 */
final readonly class PaymentVerified
{
    public string $paymentId;

    public string $checkoutId;

    public int $amountMinor;

    public string $currency;

    public string $provider;

    public CarbonImmutable $verifiedAt;

    /** True when the payment had already been closed as unpaid and the provider's evidence overturned that. */
    public bool $late;

    public function __construct(PaymentAttempt $attempt, bool $late = false)
    {
        $this->paymentId = (string) $attempt->public_id;
        $this->checkoutId = (string) $attempt->checkout_public_id;
        $this->amountMinor = (int) $attempt->amount_minor;
        $this->currency = (string) $attempt->currency;
        $this->provider = (string) $attempt->provider;
        $this->verifiedAt = CarbonImmutable::instance($attempt->verified_at ?? now());
        $this->late = $late;
    }
}
