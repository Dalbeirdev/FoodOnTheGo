<?php

namespace App\Services\Payment\Data;

use App\Enums\ProviderRefundState;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One refund at the provider, normalized and reduced to what may be kept: its reference, the payment it belongs
 * to, the amount, the state, our own receipt and the bank's trace number when there is one. An adapter builds it;
 * nothing else of the provider's answer travels further.
 */
final readonly class ProviderRefund
{
    public function __construct(
        public string $reference,
        public string $paymentReference,
        public ProviderRefundState $state,
        /** The provider's own status word, kept for support. Business logic does not read it. */
        public string $providerStatus,
        public Money $amount,
        /** Our receipt, as the provider returned it — how an unanswered creation is found again. */
        public ?string $receipt = null,
        /** The acquirer's reference of the refund (ARN / RRN / UTR) once the provider has one. */
        public ?string $traceReference = null,
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
            'state' => $this->state->value,
            'provider_status' => $this->providerStatus,
            'amount_minor' => $this->amount->amount,
            'currency' => $this->amount->currency,
            'receipt' => $this->receipt,
            'trace' => $this->traceReference,
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
            state: ProviderRefundState::tryFrom((string) ($summary['state'] ?? '')) ?? ProviderRefundState::Unknown,
            providerStatus: (string) ($summary['provider_status'] ?? ''),
            amount: new Money((int) ($summary['amount_minor'] ?? 0), (string) ($summary['currency'] ?? 'XXX')),
            receipt: is_string($summary['receipt'] ?? null) ? $summary['receipt'] : null,
            traceReference: is_string($summary['trace'] ?? null) ? $summary['trace'] : null,
        );
    }
}
