<?php

namespace App\Services\Finance\ProviderSettlement;

use Carbon\CarbonImmutable;

/**
 * A settlement as the payment provider reports it (Module 36): what it transferred, or will transfer, to the platform.
 */
final readonly class ProviderSettlementRecord
{
    /**
     * @param  'CREATED'|'PROCESSED'|'FAILED'|'UNKNOWN'  $status
     * @param  array<string, scalar|null>  $snapshot
     */
    public function __construct(
        public string $reference,
        public string $status,
        public string $providerStatus,
        public string $currency,
        public int $amountMinor,
        public int $feesMinor,
        public int $taxMinor,
        public ?string $bankReference,
        public ?CarbonImmutable $createdAt,
        public array $snapshot,
    ) {}
}
