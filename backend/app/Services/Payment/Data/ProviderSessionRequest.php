<?php

namespace App\Services\Payment\Data;

use App\Support\Money;

/**
 * What a provider is asked to open a payment for. The amount is the checkout's total; the receipt is our own opaque
 * reference (unique per attempt, so the provider can refuse a second creation with it); the notes carry references
 * only — never a cart, a journey or anything about the customer.
 */
final readonly class ProviderSessionRequest
{
    /**
     * @param  array<string, string>  $notes
     */
    public function __construct(
        public Money $amount,
        public string $receipt,
        public array $notes = [],
    ) {}
}
