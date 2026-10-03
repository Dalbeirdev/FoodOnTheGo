<?php

namespace App\Services\Restaurant;

use App\Enums\CityStatus;
use App\Enums\CoverageStatus;
use App\Enums\MarketStatus;
use App\Enums\RegionStatus;
use App\Enums\RestaurantStatus;
use App\Models\RestaurantLocation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which restaurant locations may a customer see", as SQL — shared by every customer
 * query so the rule cannot drift between endpoints. RestaurantAvailabilityService applies the same rule to a
 * single location in PHP (and explains the reason).
 *
 * VISIBLE to customers
 *   organization APPROVED  and  location APPROVED
 *   and the market serves customers (ACTIVE / PILOT)
 *   and the position lies in a service area that is public (ACTIVE or PAUSED) in a public city and region
 *       (PILOT / ACTIVE / PAUSED) — decided from the geometry, not from a stored id.
 *
 * SERVICEABLE (one condition of "orderable")
 *   the position lies in a service area that is ACTIVE and in effect, in a city and region that serve customers.
 *
 * A closed restaurant stays visible; a suspended, unapproved or out-of-coverage one is not.
 */
final class RestaurantVisibility
{
    public const PUBLIC_AREA = [CoverageStatus::Active, CoverageStatus::Paused];

    public const PUBLIC_CITY = [CityStatus::Pilot, CityStatus::Active, CityStatus::Paused];

    public const PUBLIC_REGION = [RegionStatus::Pilot, RegionStatus::Active, RegionStatus::Paused];

    /**
     * @param  Builder<RestaurantLocation>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('restaurant_locations.status', RestaurantStatus::Approved->value)
            ->whereExists(fn ($org) => $org->selectRaw('1')->from('restaurant_organizations')
                ->whereColumn('restaurant_organizations.id', 'restaurant_locations.organization_id')->where('restaurant_organizations.status', RestaurantStatus::Approved->value))
            ->whereExists(fn ($market) => $market->selectRaw('1')->from('markets')
                ->whereColumn('markets.id', 'restaurant_locations.market_id')->whereIn('markets.status', [MarketStatus::Active->value, MarketStatus::Pilot->value]))
            ->whereRaw($this->coveringArea(self::values(self::PUBLIC_AREA), self::values(self::PUBLIC_CITY), self::values(self::PUBLIC_REGION), false));
    }

    /**
     * Adds `geo_serviceable`: whether the area the location lies in is being served at $at.
     *
     * @param  Builder<RestaurantLocation>  $query
     */
    public function selectServiceable(Builder $query, CarbonInterface $at): void
    {
        $instant = $at->copy()->utc()->format('Y-m-d H:i:sP');

        $query->selectRaw('('.$this->coveringArea([CoverageStatus::Active->value], ['PILOT', 'ACTIVE'], ['PILOT', 'ACTIVE'], true).') as geo_serviceable', [$instant, $instant]);
    }

    /**
     * Keeps only locations that are open at $at according to their operational status, special hours and
     * weekly hours, each in its own time zone. Mirrors Schedule::isOpenAt() (a test compares the two).
     *
     * @param  Builder<RestaurantLocation>  $query
     */
    public function whereOpenAt(Builder $query, CarbonInterface $at): void
    {
        $covers = fn (string $p): string => "(({$p}.closes_at > {$p}.opens_at and {$p}.opens_at <= z.lt::time and z.lt::time < {$p}.closes_at) or ({$p}.closes_at <= {$p}.opens_at and z.lt::time >= {$p}.opens_at))";
        $tail = fn (string $p): string => "({$p}.closes_at <= {$p}.opens_at and z.lt::time < {$p}.closes_at)";
        $special = fn (string $date): string => "exists (select 1 from restaurant_special_hours s where s.location_id = restaurant_locations.id and s.date = {$date})";
        $specialPeriods = fn (string $date, string $condition): string => 'exists (select 1 from restaurant_special_hours s join restaurant_special_hour_periods p on p.special_hour_id = s.id '
            ."where s.location_id = restaurant_locations.id and s.date = {$date} and not s.is_closed and {$condition})";
        $weekly = fn (string $day, string $condition): string => "exists (select 1 from restaurant_location_hours h where h.location_id = restaurant_locations.id and h.kind = 'OPENING' and h.day_of_week = {$day} and {$condition})";

        $today = 'z.lt::date';
        $yesterday = '(z.lt::date - 1)';
        $dow = 'extract(dow from z.lt)::int';
        $previousDow = '((extract(dow from z.lt)::int + 6) % 7)';

        $query->whereRaw(
            "restaurant_locations.operational_status = 'OPERATING' and exists (select 1 from (select (?::timestamptz at time zone restaurant_locations.timezone) as lt) z where "
            ."(case when {$special($today)} then {$specialPeriods($today, $covers('p'))} else {$weekly($dow, $covers('h'))} end) "
            ."or (case when {$special($yesterday)} then {$specialPeriods($yesterday, $tail('p'))} else {$weekly($previousDow, $tail('h'))} end))",
            [$at->copy()->utc()->format('Y-m-d H:i:sP')],
        );
    }

    /**
     * SQL for "a service area with these statuses covers the location" (statuses are enum constants, never input).
     *
     * @param  list<string>  $areas
     * @param  list<string>  $cities
     * @param  list<string>  $regions
     */
    private function coveringArea(array $areas, array $cities, array $regions, bool $inEffect): string
    {
        $in = fn (array $values): string => "('".implode("', '", $values)."')";
        $effect = $inEffect ? ' and (sa.effective_from is null or sa.effective_from <= ?) and (sa.effective_until is null or sa.effective_until > ?)' : '';

        return 'exists (select 1 from service_areas sa join cities c on c.id = sa.city_id join market_regions r on r.id = c.region_id '
            .'where sa.market_id = restaurant_locations.market_id and sa.status in '.$in($areas).' and c.status in '.$in($cities).' and r.status in '.$in($regions)
            .$effect.' and ST_Covers(sa.geometry, restaurant_locations.location::geometry))';
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }
}
