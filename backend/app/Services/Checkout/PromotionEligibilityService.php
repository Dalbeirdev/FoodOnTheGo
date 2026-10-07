<?php

namespace App\Services\Checkout;

use App\Enums\PromotionRejection;
use App\Enums\PromotionScope;
use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Models\RestaurantLocation;
use App\Support\Rounding;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether a promotion applies to a checkout and what it takes off (Module 29). The backend is the only
 * authority: a client sends a code, never a discount.
 *
 * Checked in this order, on the server clock:
 *   status and time window → scope (platform / market / organization / location) → currency → minimum spend →
 *   total usage limit → per-customer limit.
 *
 * Rules that are deliberate and documented (docs/backend/checkout-domain.md):
 *  - codes are compared trimmed and upper-cased;
 *  - the minimum spend is measured on the merchandise subtotal BEFORE the discount;
 *  - a fixed amount never takes off more than the merchandise subtotal; a percentage is rounded half up to the minor
 *    unit and capped by its maximum discount and by the subtotal — a checkout total can never become negative;
 *  - a promotion with amounts applies only to a checkout in its currency (no conversion exists);
 *  - one promotion per checkout — codes do not stack;
 *  - usage counts completed redemptions (orders, Module 31) plus the uses currently held by confirmed checkouts.
 */
final class PromotionEligibilityService
{
    public function find(string $code): ?Promotion
    {
        $normalized = Promotion::normalizeCode($code);

        return $normalized === '' ? null : Promotion::query()->where('code', $normalized)->first();
    }

    /**
     * @param  int|null  $exceptCheckoutId  the checkout being priced: its own held use does not count against it
     */
    public function evaluate(
        ?Promotion $promotion,
        Customer $customer,
        RestaurantLocation $location,
        string $currency,
        int $subtotalMinor,
        ?CarbonInterface $at = null,
        ?int $exceptCheckoutId = null,
    ): PromotionDecision {
        $at = CarbonImmutable::instance($at ?? now());
        if ($promotion === null) {
            return PromotionDecision::rejected(null, PromotionRejection::NotFound);
        }

        $rejection = match ($promotion->effectiveStatus($at)) {
            PromotionStatus::Active => null,
            PromotionStatus::Scheduled => PromotionRejection::NotStarted,
            PromotionStatus::Expired => PromotionRejection::Expired,
            default => PromotionRejection::Inactive,
        };
        if ($rejection !== null) {
            return PromotionDecision::rejected($promotion, $rejection);
        }

        $inScope = match ($promotion->scope) {
            PromotionScope::Platform => true,
            PromotionScope::Market => (int) $promotion->market_id === (int) $location->market_id,
            PromotionScope::RestaurantOrganization => (int) $promotion->organization_id === (int) $location->organization_id,
            PromotionScope::RestaurantLocation => (int) $promotion->location_id === (int) $location->getKey(),
        };
        if (! $inScope) {
            return PromotionDecision::rejected($promotion, PromotionRejection::NotApplicable);
        }
        if ($promotion->currency !== null && $promotion->currency !== $currency) {
            return PromotionDecision::rejected($promotion, PromotionRejection::CurrencyMismatch);
        }
        if ($promotion->minimum_spend_minor !== null && $subtotalMinor < $promotion->minimum_spend_minor) {
            return PromotionDecision::rejected($promotion, PromotionRejection::MinimumNotMet, ['minimum_spend' => ['amount' => $promotion->minimum_spend_minor, 'currency' => $currency]]);
        }

        $held = fn () => PromotionReservation::query()
            ->where('promotion_id', $promotion->getKey())
            ->where('status', PromotionReservation::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at))
            ->when($exceptCheckoutId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('checkout_session_id')->orWhere('checkout_session_id', '!=', $exceptCheckoutId)));

        if ($promotion->usage_limit !== null && (int) $promotion->redeemed_count + $held()->count() >= $promotion->usage_limit) {
            return PromotionDecision::rejected($promotion, PromotionRejection::LimitReached);
        }
        if ($promotion->per_customer_limit !== null) {
            $redeemed = PromotionReservation::query()->where('promotion_id', $promotion->getKey())->where('customer_id', $customer->getKey())->where('status', PromotionReservation::STATUS_REDEEMED)->count();
            if ($redeemed + $held()->where('customer_id', $customer->getKey())->count() >= $promotion->per_customer_limit) {
                return PromotionDecision::rejected($promotion, PromotionRejection::CustomerLimitReached);
            }
        }

        return new PromotionDecision($promotion, null, self::discount($promotion, $subtotalMinor));
    }

    /**
     * What the promotion takes off a merchandise subtotal. Integers only; never more than the subtotal.
     */
    public static function discount(Promotion $promotion, int $subtotalMinor): int
    {
        $discount = match ($promotion->type) {
            PromotionType::FixedAmount => (int) $promotion->amount_minor,
            PromotionType::Percentage => Rounding::basisPoints($subtotalMinor, (int) $promotion->percent_basis_points),
        };
        if ($promotion->type === PromotionType::Percentage && $promotion->maximum_discount_minor !== null) {
            $discount = min($discount, $promotion->maximum_discount_minor);
        }

        return max(0, min($discount, $subtotalMinor));
    }
}
