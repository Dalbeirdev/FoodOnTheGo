<?php

namespace App\Services\Finance\Payout;

use App\Enums\ProviderPayoutState;

/**
 * A payout as the provider reports it (Module 36), in provider-neutral fields. No bank detail is part of it.
 */
final readonly class ProviderPayout
{
    public function __construct(
        public string $reference,
        public ProviderPayoutState $state,
        public int $amountMinor,
        public string $currency,
        /** The provider's own word for the state. */
        public string $status,
        public ?string $idempotencyKey = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?string $bankReference = null,
    ) {}
}
