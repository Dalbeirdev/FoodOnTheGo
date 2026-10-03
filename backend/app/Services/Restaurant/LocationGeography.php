<?php

namespace App\Services\Restaurant;

use App\Exceptions\ApiException;
use App\Models\City;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\ServiceArea;
use App\Services\Market\MarketLocationResolver;
use App\Support\Geo\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places a restaurant location in the market geography of Module 22. Nothing geographic is taken on trust
 * from a client: the coordinates are checked against the market, the city is checked against the
 * coordinates, and the service area is always found by PostGIS containment.
 *
 *  - market:        the coordinates must lie inside the market's bounds (a point in another country, or with
 *                   latitude and longitude swapped, is refused);
 *  - service area:  the covering area with the highest priority (Module 22 overlap rule), in any status —
 *                   stored as an association; availability is decided from the geometry at request time;
 *  - city / region: the city of the covering area. Outside every area the city must be given or is the
 *                   nearest known city, and the point must be within `restaurant.city_radius_meters` of it;
 *  - a location outside every service area can exist (it is simply not visible to customers).
 */
final class LocationGeography
{
    public function __construct(private readonly MarketLocationResolver $resolver) {}

    /**
     * @return array{city: City, region_id: int, service_area: ServiceArea|null}
     */
    public function resolve(Market $market, float $latitude, float $longitude, ?City $city = null): array
    {
        $point = new Location($latitude, $longitude);
        [$sql, $bindings] = $point->pointSql();

        $inside = DB::selectOne("select bounds is null or ST_Covers(bounds, {$sql}) as inside from markets where id = ?", [...$bindings, $market->getKey()]);
        if (! $inside->inside) {
            throw new ApiException(422, 'location_outside_market', 'These coordinates are not inside the market of this restaurant. Coordinates are latitude, longitude in WGS84.');
        }

        if ($city !== null && (int) $city->market_id !== (int) $market->getKey()) {
            throw ValidationException::withMessages(['city_id' => ['The city does not belong to the market of this restaurant.']]);
        }

        $areas = $this->resolver->serviceAreas($point, $market);
        $area = $city === null ? $areas->first() : ($areas->first(fn (ServiceArea $a): bool => (int) $a->city_id === (int) $city->getKey()) ?? $areas->first());

        if ($area !== null) {
            if ($city !== null && (int) $area->city_id !== (int) $city->getKey()) {
                throw ValidationException::withMessages(['city_id' => ["These coordinates lie in the service area \"{$area->name}\" of {$area->city->name}, not in the selected city."]]);
            }

            return ['city' => $area->city, 'region_id' => (int) $area->city->region_id, 'service_area' => $area];
        }

        $city ??= $this->resolver->nearestCity($point, $market)
            ?? throw ValidationException::withMessages(['latitude' => ['No known city lies near these coordinates. Add the city to the market first.']]);

        $distance = (float) DB::selectOne("select ST_Distance(center, {$sql}::geography) as m from cities where id = ?", [...$bindings, $city->getKey()])->m;
        if ($distance > (int) config('restaurant.city_radius_meters')) {
            throw ValidationException::withMessages(['latitude' => ["These coordinates are too far from {$city->name}."]]);
        }

        return ['city' => $city, 'region_id' => (int) $city->region_id, 'service_area' => null];
    }

    /**
     * Re-resolves the stored service-area association of every location of a market — after service areas were
     * added, redrawn or re-prioritised. One statement; the Module 22 overlap rule decides between areas.
     */
    public function reassignServiceAreas(Market $market): int
    {
        return DB::update(
            'update restaurant_locations l set service_area_id = ('
            .'select sa.id from service_areas sa where sa.market_id = l.market_id and ST_Covers(sa.geometry, l.location::geometry) '
            .'order by sa.priority desc, ST_Area(sa.geometry), sa.id limit 1), service_area_resolved_at = ? where l.market_id = ?',
            [now()->utc()->format('Y-m-d H:i:sP'), $market->getKey()],
        );
    }

    /**
     * The association for one location, straight from the geometry.
     */
    public function serviceAreaOf(RestaurantLocation $location): ?ServiceArea
    {
        $location->loadMissing('market');

        return $this->resolver->serviceAreas($location->position(), $location->market)->first();
    }
}
