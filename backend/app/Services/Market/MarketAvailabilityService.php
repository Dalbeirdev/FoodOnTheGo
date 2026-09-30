<?php

namespace App\Services\Market;

use App\Enums\AvailabilityReason;
use App\Enums\MarketStatus;
use App\Models\ServiceArea;
use App\Support\Geo\Location;

/**
 * The backend's answer to "can FoodOnTheGo serve this location?" — authoritative; clients never decide.
 *
 * A location is supported only when the whole chain is open:
 *
 *   market ACTIVE / PILOT  →  region ACTIVE / PILOT  →  city ACTIVE / PILOT  →  service area ACTIVE and in effect
 *
 * A child cannot be more available than its parent: an ACTIVE service area under a paused city, or an
 * ACTIVE city under a paused market, is not available. Effective dates are compared with the server clock.
 */
final class MarketAvailabilityService
{
    public function __construct(private readonly MarketLocationResolver $resolver) {}

    public function check(Location $location): Availability
    {
        $market = $this->resolver->market($location);

        if ($market === null || ! $market->status->isServingCustomers()) {
            // A paused market has been public, so saying "paused" reveals nothing; draft / closed ones stay invisible.
            return $market !== null && $market->status === MarketStatus::Paused
                ? Availability::unsupported(AvailabilityReason::MarketPaused, $market)
                : Availability::unsupported(AvailabilityReason::MarketUnsupported);
        }

        $areas = $this->resolver->serviceAreas($location, $market);
        $firstBlocked = null;

        foreach ($areas as $area) {
            $blocked = $this->blockedBy($area);
            if ($blocked === null) {
                return new Availability(true, null, $market, $area->city->region, $area->city, $area);
            }
            $firstBlocked ??= [$blocked, $area];
        }

        if ($firstBlocked !== null) {
            [$reason, $area] = $firstBlocked;
            $cityOpen = $area->city->status->servesCustomers() && $area->city->region->status->servesCustomers();

            return Availability::unsupported($reason, $market, $cityOpen ? $area->city->region : null, $cityOpen ? $area->city : null);
        }

        $city = $this->resolver->nearestCity($location, $market);

        if ($city === null) {
            return Availability::unsupported(AvailabilityReason::OutsideServiceArea, $market);
        }
        if (! $city->region->status->servesCustomers()) {
            return Availability::unsupported(AvailabilityReason::RegionUnavailable, $market);
        }
        if (! $city->status->servesCustomers()) {
            return Availability::unsupported(AvailabilityReason::CityUnavailable, $market, $city->region);
        }

        return Availability::unsupported(AvailabilityReason::OutsideServiceArea, $market, $city->region, $city);
    }

    /**
     * Why a covering service area does not make the point serviceable, or null when it does.
     */
    private function blockedBy(ServiceArea $area): ?AvailabilityReason
    {
        if (! $area->city->region->status->servesCustomers()) {
            return AvailabilityReason::RegionUnavailable;
        }
        if (! $area->city->status->servesCustomers()) {
            return AvailabilityReason::CityUnavailable;
        }

        $inEffect = $area->status->servesCustomers()
            && ($area->effective_from === null || $area->effective_from->isPast())
            && ($area->effective_until === null || $area->effective_until->isFuture());

        return $inEffect ? null : AvailabilityReason::ServiceAreaPaused;
    }
}
