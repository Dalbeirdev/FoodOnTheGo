<?php

namespace App\Services\Finance\ProviderSettlement;

use Carbon\CarbonImmutable;

/**
 * One line of a provider's settlement report (Module 36), in provider-neutral fields. Every number is the provider's.
 */
final readonly class ProviderSettlementLine
{
    /**
     * @param  'PAYMENT'|'REFUND'|'TRANSFER'|'ADJUSTMENT'|'OTHER'  $type
     * @param  array<string, scalar|null>  $snapshot  the allow-listed fields as the provider sent them (no card, bank or customer data)
     */
    public function __construct(
        public string $entityReference,
        public string $type,
        public string $currency,
        public int $amountMinor,
        public int $creditMinor,
        public int $debitMinor,
        public int $feeMinor,
        public int $taxMinor,
        public bool $settled,
        public bool $onHold,
        public ?string $settlementReference,
        public ?string $bankReference,
        public ?string $paymentReference,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $settledAt,
        public array $snapshot,
    ) {}
}
