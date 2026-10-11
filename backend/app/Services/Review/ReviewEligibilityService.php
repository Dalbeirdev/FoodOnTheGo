<?php

namespace App\Services\Review;

use App\Enums\OrderStatus;
use App\Enums\ReviewEligibilityReason;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantReview;
use Carbon\CarbonImmutable;

/**
 * Only a COMPLETED order of the authenticated customer, with a restaurant location, without a review yet and inside
 * the review window can be reviewed. Everything is read from the order row — nothing from the request.
 */
final class ReviewEligibilityService
{
    public function __construct(private readonly ReviewSettings $settings) {}

    public function check(Customer $customer, Order $order, ?RestaurantReview $existing = null, bool $existingKnown = false): ReviewEligibility
    {
        if ((int) $order->customer_id !== (int) $customer->getKey()) {
            return new ReviewEligibility(false, ReviewEligibilityReason::OrderNotOwned);
        }

        return $this->checkOrder($order, $existing, $existingKnown);
    }

    /**
     * The same answer for a caller that is already known to own the order (the owner-scoped order endpoints).
     */
    public function checkOrder(Order $order, ?RestaurantReview $existing = null, bool $existingKnown = false): ReviewEligibility
    {
        $ends = $this->windowEndsAt($order);
        if (! $existingKnown) {
            $existing = RestaurantReview::query()->where('order_id', $order->getKey())->first();
        }
        if ($existing !== null) {
            return new ReviewEligibility(false, ReviewEligibilityReason::ReviewAlreadyExists, $ends, $existing);
        }
        if ($order->status !== OrderStatus::Completed) {
            return new ReviewEligibility(false, ReviewEligibilityReason::OrderNotCompleted);
        }
        if ($order->location_id === null || $order->organization_id === null || $order->market_id === null || $ends === null) {
            return new ReviewEligibility(false, ReviewEligibilityReason::OrderNotEligible);
        }
        if (now()->greaterThan($ends)) {
            return new ReviewEligibility(false, ReviewEligibilityReason::ReviewWindowExpired, $ends);
        }

        return new ReviewEligibility(true, ReviewEligibilityReason::Eligible, $ends);
    }

    public function windowEndsAt(Order $order): ?CarbonImmutable
    {
        return $order->completed_at === null ? null : CarbonImmutable::instance($order->completed_at)->addDays($this->settings->windowDays());
    }

    public function editableUntil(RestaurantReview $review): ?CarbonImmutable
    {
        return $review->submitted_at === null ? null : CarbonImmutable::instance($review->submitted_at)->addHours($this->settings->editWindowHours());
    }

    /**
     * Whether the owner may still edit: the review is visible or waiting, the edit window is open and edits are left.
     */
    public function canEdit(RestaurantReview $review): bool
    {
        $until = $this->editableUntil($review);

        return $review->status->ownerMayChange() && $until !== null && now()->lessThanOrEqualTo($until) && $review->edit_count < $this->settings->maxEdits();
    }
}
