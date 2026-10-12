<?php

namespace App\Services\Finance\Payout;

/**
 * What a payout provider is asked to send (Module 36). The destination is the provider's own token for it — bank
 * details never pass through here.
 */
final readonly class PayoutRequest
{
    public function __construct(
        public string $idempotencyKey,
        public string $accountReference,
        public int $amountMinor,
        public string $currency,
        /** Our payout's public id, for the provider's records. */
        public string $reference,
        public string $narration,
    ) {}
}
