<?php

namespace App\Services\Payment\Data;

use App\Support\Money;

/**
 * The provider's order / payment session as the application needs it.
 */
final readonly class ProviderSession
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public string $receipt,
        /** The provider's own state name, kept for support. Business logic does not read it. */
        public string $providerStatus,
        public bool $paid = false,
    ) {}
}
