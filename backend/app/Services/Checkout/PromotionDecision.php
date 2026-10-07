<?php

namespace App\Services\Checkout;

use App\Enums\PromotionRejection;
use App\Models\Promotion;

/**
 * What the backend decided about a promotion code for one checkout: it applies and takes `discountMinor` off the
 * merchandise subtotal, or it does not and says why.
 */
final readonly class PromotionDecision
{
    /**
     * @param  array<string, mixed>  $details  customer-safe facts about a rejection (e.g. the minimum spend)
     */
    public function __construct(
        public ?Promotion $promotion,
        public ?PromotionRejection $rejection,
        public int $discountMinor = 0,
        public array $details = [],
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function rejected(?Promotion $promotion, PromotionRejection $reason, array $details = []): self
    {
        return new self($promotion, $reason, 0, $details);
    }

    /** A promotion was asked for and it applies. */
    public function applied(): bool
    {
        return $this->promotion !== null && $this->rejection === null;
    }

    /** A promotion was asked for and it does not apply. */
    public function refused(): bool
    {
        return $this->rejection !== null;
    }
}
