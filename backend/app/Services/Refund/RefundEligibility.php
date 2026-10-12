<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Support\Money;

/**
 * Whether money of one payment can be refunded now, and how much of it (Module 35). Every figure is the server's:
 * what was paid, what the provider confirmed as refunded, what is held by refunds that are not settled, what a lost
 * dispute took, and what is left.
 */
final readonly class RefundEligibility
{
    public function __construct(
        public bool $eligible,
        public ?string $code,
        public string $message,
        public string $currency,
        public int $paidMinor = 0,
        public int $refundedMinor = 0,
        public int $reservedMinor = 0,
        public int $chargedBackMinor = 0,
        public bool $disputeOpen = false,
        public int $status = 409,
    ) {}

    public function availableMinor(): int
    {
        return max(0, $this->paidMinor - $this->refundedMinor - $this->reservedMinor - $this->chargedBackMinor);
    }

    public function available(): Money
    {
        return new Money($this->availableMinor(), $this->currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $m = fn (int $v): array => (new Money($v, $this->currency))->toArray();

        return [
            'eligible' => $this->eligible,
            'reason' => $this->code,
            'currency' => $this->currency,
            'paid' => $m($this->paidMinor),
            'refunded' => $m($this->refundedMinor),
            'in_flight' => $m($this->reservedMinor),
            'charged_back' => $m($this->chargedBackMinor),
            'available' => $m($this->availableMinor()),
            'dispute_open' => $this->disputeOpen,
        ];
    }

    public function refusal(): ApiException
    {
        return new ApiException($this->status, (string) $this->code, $this->message, ['available_minor' => $this->availableMinor(), 'currency' => $this->currency, 'dispute_open' => $this->disputeOpen]);
    }
}
