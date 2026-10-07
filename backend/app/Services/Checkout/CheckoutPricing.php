<?php

namespace App\Services\Checkout;

use App\Support\Money;

/**
 * The authoritative price of one checkout (Module 29): merchandise subtotal, promotion discount, configured taxes
 * and fees, and the total — all in the minor unit of ONE currency. Calculated by the backend from the current menu,
 * the cart, the promotion and the configured rules; nothing in it ever came from a client.
 *
 *   total = subtotal − discount + fees + tax added on top
 *
 * Tax that is already inside the prices is reported next to it and is not part of the sum. The fingerprint is a hash
 * of everything the total follows from: a payment is bound to it, so it can only ever be for exactly this price.
 */
final readonly class CheckoutPricing
{
    /**
     * @param  array<string, mixed>  $basis  what the fingerprint is a hash of (kept with the checkout)
     */
    public function __construct(
        public string $currency,
        public int $subtotalMinor,
        public PromotionDecision $promotion,
        public FeeResult $fees,
        public TaxResult $taxes,
        public string $fingerprint,
        public array $basis,
    ) {}

    public function discountMinor(): int
    {
        return $this->promotion->applied() ? $this->promotion->discountMinor : 0;
    }

    public function feeMinor(): int
    {
        return $this->fees->totalMinor();
    }

    /** Tax added on top of the prices. */
    public function taxMinor(): int
    {
        return $this->taxes->addedMinor();
    }

    public function taxIncludedMinor(): int
    {
        return $this->taxes->includedMinor();
    }

    public function totalMinor(): int
    {
        return max(0, $this->subtotalMinor - $this->discountMinor() + $this->feeMinor() + $this->taxMinor());
    }

    public function total(): Money
    {
        return new Money($this->totalMinor(), $this->currency);
    }

    /** A total of zero needs no payment provider. */
    public function paymentRequired(): bool
    {
        return $this->totalMinor() > 0;
    }
}
