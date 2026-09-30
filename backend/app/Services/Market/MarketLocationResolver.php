<?php

namespace App\Services\Market;

use App\Models\City;
use App\Models\Market;
use App\Models\ServiceArea;
use App\Support\Geo\Location;
use Illuminate\Support\Collection;

/**
 * Finds the geography around a point with PostGIS. It reports what is there, in any status; whether that
 * amounts to "serviceable" is decided by MarketAvailabilityService.
 *
 * Limitations, stated honestly:
 *  - the market is found through a coarse bounding envelope per market (markets.bounds), not a legal border.
 *    A point just across a border can fall inside the envelope; a country code supplied with the location
 *    that contradicts the market is treated as "not this market";
 *  - regions have no boundary geometry yet: the region is the one of the resolved city;
 *  - the city is the city of the covering service area, otherwise the nearest known city within
 *    config geo.city_match_radius_meters.
 */
final class MarketLocationResolver
{
    /**
     * The market whose envelope contains the point (any status), preferring the smallest envelope.
     */
    public function market(Location $location): ?Market
    {
        [$point, $bindings] = $location->pointSql();

        $market = Market::query()
            ->whereNotNull('bounds')
            ->whereRaw("ST_Covers(bounds, {$point})", $bindings)
            ->orderByRaw('ST_Area(bounds)')
            ->first();

        if ($market !== null && $location->countryCode !== null && $location->countryCode !== $market->country_code) {
            return null;
        }

        return $market;
    }

    /**
     * Service areas of the market that cover the point, in any status, best match first.
     *
     * Boundary rule: ST_Covers — a point exactly on the edge of an area is inside it.
     * Overlap rule: higher `priority` first, then the smaller area (the more specific one), then the older row.
     *
     * @return Collection<int, ServiceArea>
     */
    public function serviceAreas(Location $location, Market $market): Collection
    {
        [$point, $bindings] = $location->pointSql();

        return ServiceArea::query()
            ->with('city.region')
            ->where('service_areas.market_id', $market->getKey())
            ->whereRaw("ST_Covers(service_areas.geometry, {$point})", $bindings)
            ->orderByDesc('service_areas.priority')
            ->orderByRaw('ST_Area(service_areas.geometry)')
            ->orderBy('service_areas.id')
            ->get();
    }

    /**
     * Nearest known city of the market within the match radius (any status), with its distance in metres.
     */
    public function nearestCity(Location $location, Market $market): ?City
    {
        [$point, $bindings] = $location->pointSql();

        return City::query()
            ->with('region')
            ->selectRaw("ST_Distance(cities.center, {$point}::geography) as distance_m", $bindings)
            ->where('cities.market_id', $market->getKey())
            ->whereRaw("ST_DWithin(cities.center, {$point}::geography, ?)", [...$bindings, (int) config('geo.city_match_radius_meters')])
            ->orderByRaw("cities.center <-> {$point}::geography", $bindings)
            ->first();
    }

    /**
     * Nearest customer-serving service area, for support / admin diagnostics ("how far is coverage?").
     * Never used to turn an unsupported location into a supported one.
     *
     * @return array{service_area: ServiceArea, distance_m: float}|null
     */
    public function nearestActiveServiceArea(Location $location, Market $market): ?array
    {
        [$point, $bindings] = $location->pointSql();

        $area = ServiceArea::query()->inEffect()
            ->selectRaw("ST_Distance(service_areas.geometry::geography, {$point}::geography) as distance_m", $bindings)
            ->where('service_areas.market_id', $market->getKey())
            ->orderByRaw("service_areas.geometry <-> {$point}", $bindings)
            ->limit(5)->get()->sortBy('distance_m')->first();

        return $area === null ? null : ['service_area' => $area, 'distance_m' => (float) $area->getAttribute('distance_m')];
    }
}
