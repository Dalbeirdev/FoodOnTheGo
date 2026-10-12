<?php

namespace App\Services\Payment\Data;

use App\Support\Money;

/**
 * A refund asked of a provider (Module 35). The amount was decided on the server; the receipt is our own unique
 * reference of this one provider refund and doubles as the idempotency key — sending the same request again can
 * only ever lead to the same refund. The notes carry references, never anything about the customer.
 */
final readonly class ProviderRefundRequest
{
    /**
     * @param  array<string, string>  $notes
     */
    public function __construct(
        public string $paymentReference,
        public Money $amount,
        /** Our own unique reference: the provider refuses a second refund with it. */
        public string $receipt,
        /** Sent as the provider's idempotency key where it has one. Defaults to the receipt. */
        public ?string $idempotencyKey = null,
        public array $notes = [],
    ) {}

    public function key(): string
    {
        return $this->idempotencyKey ?? $this->receipt;
    }
}
