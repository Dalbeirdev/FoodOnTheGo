<?php

namespace App\Services\Review;

use App\Enums\ReviewEligibilityReason;
use App\Models\RestaurantReview;
use Carbon\CarbonImmutable;

/**
 * The answer to "may this customer review this order now?" — stable, with its reason.
 */
final readonly class ReviewEligibility
{
    public function __construct(
        public bool $eligible,
        public ReviewEligibilityReason $reason,
        public ?CarbonImmutable $windowEndsAt = null,
        public ?RestaurantReview $review = null,
    ) {}
}
