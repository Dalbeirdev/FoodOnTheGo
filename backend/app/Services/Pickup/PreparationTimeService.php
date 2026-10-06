<?php

namespace App\Services\Pickup;

use App\Models\Cart;
use App\Models\RestaurantPickupSettings;

/**
 * Estimates the preparation time of a cart before it is ordered (Module 28).
 *
 * The algorithm is deliberately simple and conservative:
 *
 *   1. base      = max(restaurant default_prep_minutes, the slowest item's own preparation_minutes)
 *                  A kitchen prepares the lines of an order in parallel, so item times are NEVER summed; the
 *                  restaurant's default is the floor, and one slow item raises the whole order to its time.
 *   2. quantity  = a step adjustment for large orders (config/pickup.php): the first `free_items` units cost
 *                  nothing; every further `items_per_step` units (started) add `minutes_per_step`, capped.
 *   3. estimate  = base + quantity, bounded by the platform maximum.
 *
 * The restaurant's buffer and minimum lead time are carried on the estimate (PreparationEstimate::readyInMinutes).
 * Nothing here knows about the kitchen's current workload: adjusting the time of a real order belongs to the
 * orders module. Every number is configuration — the restaurant's or the platform's.
 */
final class PreparationTimeService
{
    /**
     * @param  iterable<array{0: int|null, 1: int}>  $lines  an item's own preparation minutes (or null) and its quantity
     */
    public function estimate(RestaurantPickupSettings $settings, iterable $lines): PreparationEstimate
    {
        $slowest = 0;
        $quantity = 0;
        foreach ($lines as [$minutes, $units]) {
            $slowest = max($slowest, (int) ($minutes ?? 0));
            $quantity += max(0, (int) $units);
        }

        $default = (int) $settings->default_prep_minutes;
        $extra = $this->quantityExtra($quantity);
        $estimate = min(max($default, $slowest) + $extra, max(1, (int) config('pickup.preparation.max_minutes')));

        return new PreparationEstimate(
            defaultMinutes: $default,
            itemMinutes: $slowest,
            quantityExtraMinutes: $extra,
            minutes: $estimate,
            bufferMinutes: (int) $settings->buffer_minutes,
            minimumLeadMinutes: (int) $settings->minimum_lead_minutes,
            totalQuantity: $quantity,
        );
    }

    /**
     * The estimate for a cart. Uses the lines and menu items the cart already has loaded — no query per line.
     */
    public function forCart(Cart $cart, RestaurantPickupSettings $settings): PreparationEstimate
    {
        $lines = [];
        foreach ($cart->items as $line) {
            $lines[] = [$line->menuItem?->preparation_minutes, (int) $line->quantity];
        }

        return $this->estimate($settings, $lines);
    }

    /**
     * The estimate of an order that needs no more than the restaurant's default (settings preview).
     */
    public function forDefault(RestaurantPickupSettings $settings): PreparationEstimate
    {
        return $this->estimate($settings, []);
    }

    private function quantityExtra(int $quantity): int
    {
        $free = max(0, (int) config('pickup.preparation.quantity.free_items'));
        $step = max(1, (int) config('pickup.preparation.quantity.items_per_step'));
        $perStep = max(0, (int) config('pickup.preparation.quantity.minutes_per_step'));
        if ($perStep === 0 || $quantity <= $free) {
            return 0;
        }

        return min((int) ceil(($quantity - $free) / $step) * $perStep, max(0, (int) config('pickup.preparation.quantity.max_extra_minutes')));
    }
}
