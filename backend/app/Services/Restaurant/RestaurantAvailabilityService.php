<?php

namespace App\Services\Restaurant;

use App\Enums\CityStatus;
use App\Enums\CoverageStatus;
use App\Enums\OpenState;
use App\Enums\RegionStatus;
use App\Enums\RestaurantAvailabilityReason as Reason;
use App\Enums\RestaurantStatus;
use App\Models\RestaurantLocation;
use App\Models\ServiceArea;
use App\Services\Market\MarketLocationResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The backend's answer to "can a customer see this restaurant, and can they order from it right now?".
 * Controllers and resources never work this out themselves.
 *
 * Orderable needs the whole chain, checked in this order (the first failure is the reason):
 *
 *   market serves customers
 *   organization APPROVED            (SUSPENDED is reported separately)
 *   location APPROVED                (SUSPENDED is reported separately)
 *   the position lies in a public service area             — otherwise the restaurant is not visible at all
 *   region, city and service area are serving right now    (Module 22 statuses and effective dates)
 *   the outlet is not temporarily closed
 *   pickup is offered (enabled, a mode and a method the market allows)
 *   FoodOnTheGo orders are being accepted (no pause running)
 *   it is open now (special hours, then weekly hours, in the location's time zone)
 *
 * Visible = everything up to and including "lies in a public service area". A closed, paused or not-accepting
 * restaurant stays visible; a suspended, unapproved or out-of-coverage one does not.
 *
 * Geography is decided from the geometry on every call (never from the stored service_area_id), so a redrawn
 * or paused service area takes effect immediately. Three ways to the same rule, so nothing costs a query per row:
 *   - a customer list has already filtered by visibility in SQL and selected `geo_serviceable`;
 *   - a staff / administrator list calls preloadCoverage() once for all its locations;
 *   - a single location is resolved on the spot.
 */
final class RestaurantAvailabilityService
{
    public function __construct(
        private readonly RestaurantHoursService $hours,
        private readonly MarketLocationResolver $resolver,
    ) {}

    public function evaluate(RestaurantLocation $location, ?CarbonInterface $at = null): RestaurantAvailability
    {
        $at = CarbonImmutable::instance($at ?? now());
        $location->loadMissing(['organization', 'market', 'pickupSettings', 'pickupMethods', 'hours', 'specialHours.periods']);

        $reason = $this->administrativeReason($location);
        $visible = $reason === null;

        if ($visible) {
            [$visible, $reason] = $this->geography($location, $at);
        }

        $schedule = $this->hours->schedule($location);
        $openState = $this->hours->openState($location, $at);
        $accepting = $location->acceptsOrdersAt($at);

        $reason ??= match (true) {
            $openState === OpenState::TemporarilyClosed => Reason::TemporarilyClosed,
            ! PickupRules::available($location) => Reason::PickupUnavailable,
            ! $accepting => Reason::NotAcceptingOrders,
            $openState !== OpenState::Open => Reason::ClosedNow,
            default => null,
        };

        return new RestaurantAvailability(
            visible: $visible,
            orderable: $reason === null,
            reason: $reason,
            openState: $openState,
            acceptingOrders: $accepting,
            closesAt: $openState === OpenState::Open ? $schedule->closesAt($at) : null,
            opensNextAt: $openState === OpenState::Closed ? $schedule->opensNextAt($at) : null,
            checkedAt: $at,
        );
    }

    public function isVisibleToCustomer(RestaurantLocation $location, ?CarbonInterface $at = null): bool
    {
        return $this->evaluate($location, $at)->visible;
    }

    public function isOrderable(RestaurantLocation $location, ?CarbonInterface $at = null): bool
    {
        return $this->evaluate($location, $at)->orderable;
    }

    public function isCurrentlyOpen(RestaurantLocation $location, ?CarbonInterface $at = null): bool
    {
        return $this->hours->isOpenAt($location, $at);
    }

    public function isAcceptingOrders(RestaurantLocation $location, ?CarbonInterface $at = null): bool
    {
        return $location->acceptsOrdersAt($at ?? now());
    }

    public function availabilityReason(RestaurantLocation $location, ?CarbonInterface $at = null): ?Reason
    {
        return $this->evaluate($location, $at)->reason;
    }

    /**
     * Approval chain: market, organization, location.
     */
    private function administrativeReason(RestaurantLocation $location): ?Reason
    {
        return match (true) {
            ! $location->market->status->isServingCustomers() => Reason::MarketUnavailable,
            $location->organization->status === RestaurantStatus::Suspended => Reason::RestaurantSuspended,
            ! $location->organization->status->isApproved() => Reason::RestaurantNotApproved,
            $location->status === RestaurantStatus::Suspended => Reason::LocationSuspended,
            ! $location->status->isApproved() => Reason::LocationNotApproved,
            default => null,
        };
    }

    /**
     * Finds the service areas covering every given location in ONE query and keeps them on the models, so a
     * list of locations can be evaluated without a query per location.
     *
     * @param  Collection<int, RestaurantLocation>  $locations
     */
    public function preloadCoverage(Collection $locations): void
    {
        if ($locations->isEmpty()) {
            return;
        }

        // Same boundary rule (ST_Covers) and overlap order (priority, then the smaller area, then the older row) as MarketLocationResolver.
        $rows = DB::table('restaurant_locations as l')
            ->join('service_areas as sa', fn ($join) => $join->on('sa.market_id', '=', 'l.market_id')->whereRaw('ST_Covers(sa.geometry, l.location::geometry)'))
            ->join('cities as c', 'c.id', '=', 'sa.city_id')
            ->join('market_regions as r', 'r.id', '=', 'c.region_id')
            ->whereIn('l.id', $locations->modelKeys())
            ->orderByDesc('sa.priority')->orderByRaw('ST_Area(sa.geometry)')->orderBy('sa.id')
            ->get(['l.id as location_id', 'sa.status', 'sa.effective_from', 'sa.effective_until', 'c.status as city_status', 'r.status as region_status'])
            ->groupBy('location_id');

        foreach ($locations as $location) {
            $location->setRelation('coverage', collect($rows[$location->getKey()] ?? [])->map(fn (object $row): array => [
                'status' => CoverageStatus::from($row->status),
                'effective_from' => $row->effective_from === null ? null : CarbonImmutable::parse($row->effective_from),
                'effective_until' => $row->effective_until === null ? null : CarbonImmutable::parse($row->effective_until),
                'city' => CityStatus::from($row->city_status),
                'region' => RegionStatus::from($row->region_status),
            ])->values());
        }
    }

    /**
     * @return array{0: bool, 1: Reason|null} visible, reason
     */
    private function geography(RestaurantLocation $location, CarbonImmutable $at): array
    {
        // A customer list has already applied the visibility rule in SQL and selected whether the area is being served.
        if ($location->getAttribute('geo_serviceable') !== null) {
            return [true, $location->getAttribute('geo_serviceable') ? null : Reason::ServiceAreaUnavailable];
        }

        $areas = $location->relationLoaded('coverage')
            ? $location->getRelation('coverage')
            : $this->resolver->serviceAreas($location->position(), $location->market)->map(fn (ServiceArea $a): array => [
                'status' => $a->status, 'effective_from' => $a->effective_from, 'effective_until' => $a->effective_until, 'city' => $a->city->status, 'region' => $a->city->region->status,
            ]);

        $public = $areas->filter(fn (array $a): bool => in_array($a['status'], RestaurantVisibility::PUBLIC_AREA, true)
            && in_array($a['city'], RestaurantVisibility::PUBLIC_CITY, true)
            && in_array($a['region'], RestaurantVisibility::PUBLIC_REGION, true));

        if ($public->isEmpty()) {
            return [false, Reason::OutsideServiceArea];
        }

        foreach ($public as $area) {
            $inEffect = $area['status']->servesCustomers()
                && ($area['effective_from'] === null || $area['effective_from']->lessThanOrEqualTo($at))
                && ($area['effective_until'] === null || $area['effective_until']->greaterThan($at));

            if ($area['region']->servesCustomers() && $area['city']->servesCustomers() && $inEffect) {
                return [true, null];
            }
        }

        $first = $public->first();

        return [true, match (true) {
            ! $first['region']->servesCustomers() => Reason::RegionUnavailable,
            ! $first['city']->servesCustomers() => Reason::CityUnavailable,
            default => Reason::ServiceAreaUnavailable,
        }];
    }
}
