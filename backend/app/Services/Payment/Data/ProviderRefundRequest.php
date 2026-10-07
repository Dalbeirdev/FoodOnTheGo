<?php

namespace App\Services\Payment\Data;

use App\Support\Money;

/**
 * A refund asked of a provider. Part of the provider contract for the refund domain of a later module.
 */
final readonly class ProviderRefundRequest
{
    public function __construct(
        public string $paymentReference,
        public Money $amount,
        /** Our own unique reference: the provider refuses a second refund with it. */
        public string $receipt,
    ) {}
}
