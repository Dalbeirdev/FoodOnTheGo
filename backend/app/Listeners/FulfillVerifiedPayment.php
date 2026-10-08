<?php

namespace App\Listeners;

use App\Events\Payment\PaymentVerified;
use App\Jobs\Order\FulfillCheckoutJob;

/**
 * A payment is VERIFIED: the order is created (Module 31). The work is queued — the request that verified the
 * payment answers at once — and is idempotent, because PaymentVerified may be delivered more than once and the
 * sweep picks up what the queue drops.
 */
final class FulfillVerifiedPayment
{
    public function handle(PaymentVerified $event): void
    {
        FulfillCheckoutJob::dispatch($event->paymentId);
    }
}
