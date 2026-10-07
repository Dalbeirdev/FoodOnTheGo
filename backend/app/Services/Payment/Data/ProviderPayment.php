<?php

namespace App\Services\Payment\Data;

use App\Enums\ProviderPaymentState;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One transaction at the provider, normalized and reduced to what may be kept: state, references, amount, the
 * method in safe terms and the provider's error codes. An adapter builds it; nothing else of the provider's answer
 * travels further.
 */
final readonly class ProviderPayment
{
    /**
     * @param  array<string, string|bool|null>  $methodSummary  safe fields only — a card's network, type and last four digits,
     *                                                          a masked UPI address, a bank code, a wallet's name
     */
    public function __construct(
        public string $reference,
        public ?string $orderReference,
        public ProviderPaymentState $state,
        public string $providerStatus,
        public Money $amount,
        public ?string $methodType = null,
        public array $methodSummary = [],
        public ?string $errorCode = null,
        public ?string $errorReason = null,
        public ?string $errorSource = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function captured(): bool
    {
        return $this->state === ProviderPaymentState::Captured;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_filter([
            'payment' => $this->reference,
            'order' => $this->orderReference,
            'state' => $this->state->value,
            'provider_status' => $this->providerStatus,
            'amount_minor' => $this->amount->amount,
            'currency' => $this->amount->currency,
            'method' => $this->methodType,
            'error_code' => $this->errorCode,
            'error_reason' => $this->errorReason,
        ], fn ($v): bool => $v !== null);
    }
}
