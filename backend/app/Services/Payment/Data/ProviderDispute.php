<?php

namespace App\Services\Payment\Data;

use App\Enums\DisputeStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A dispute / chargeback at the provider, normalized and reduced to what may be kept. No evidence documents, no
 * customer details: references, the state, the amounts, the network's reason code and the deadline.
 */
final readonly class ProviderDispute
{
    public function __construct(
        public string $reference,
        public string $paymentReference,
        public DisputeStatus $status,
        public string $providerStatus,
        public Money $amount,
        /** What the provider took back from the merchant (non-zero once a dispute is lost). */
        public int $amountDeductedMinor = 0,
        public ?string $phase = null,
        public ?string $reasonCode = null,
        public ?CarbonImmutable $respondBy = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_filter([
            'reference' => $this->reference,
            'payment' => $this->paymentReference,
            'status' => $this->status->value,
            'provider_status' => $this->providerStatus,
            'amount_minor' => $this->amount->amount,
            'currency' => $this->amount->currency,
            'amount_deducted_minor' => $this->amountDeductedMinor,
            'phase' => $this->phase,
            'reason_code' => $this->reasonCode,
            'respond_by' => $this->respondBy?->getTimestamp(),
            'created_at' => $this->createdAt?->getTimestamp(),
        ], fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function fromSummary(array $summary): self
    {
        return new self(
            reference: (string) ($summary['reference'] ?? ''),
            paymentReference: (string) ($summary['payment'] ?? ''),
            status: DisputeStatus::tryFrom((string) ($summary['status'] ?? '')) ?? DisputeStatus::Unknown,
            providerStatus: (string) ($summary['provider_status'] ?? ''),
            amount: new Money((int) ($summary['amount_minor'] ?? 0), (string) ($summary['currency'] ?? 'XXX')),
            amountDeductedMinor: max(0, (int) ($summary['amount_deducted_minor'] ?? 0)),
            phase: is_string($summary['phase'] ?? null) ? $summary['phase'] : null,
            reasonCode: is_string($summary['reason_code'] ?? null) ? $summary['reason_code'] : null,
            respondBy: is_int($summary['respond_by'] ?? null) ? CarbonImmutable::createFromTimestampUTC($summary['respond_by']) : null,
            createdAt: is_int($summary['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($summary['created_at']) : null,
        );
    }
}
