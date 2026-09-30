<?php

namespace App\Services\Geo;

use App\Models\Market;
use App\Models\RouteCorridor;
use App\Support\Geo\GeoJsonGeometry;
use App\Support\Geo\Location;
use Illuminate\Support\Collection;

/**
 * Finds route corridors relative to a point or a line. A corridor is its centreline plus its own width:
 * "inside the corridor" = within corridor_width_meters of the centreline, measured on the earth
 * (geography), never by buffering degrees.
 *
 * "Intersects" and "near" are different questions and are kept apart: `intersecting()` is true topology
 * (the line crosses the centreline), `within()` applies the corridor's own width as the tolerance.
 * No routing provider is involved — journey routes arrive with the journey module.
 */
final class RouteCorridorQuery
{
    /**
     * Corridors in effect whose band (centreline ± width) contains the point.
     *
     * @return Collection<int, RouteCorridor>
     */
    public function containing(Location $location, Market $market): Collection
    {
        [$point, $bindings] = $location->pointSql();

        return RouteCorridor::query()->inEffect()
            ->selectRaw("ST_Distance(route_corridors.centerline::geography, {$point}::geography) as distance_m", $bindings)
            ->where('route_corridors.market_id', $market->getKey())
            ->whereRaw("ST_DWithin(route_corridors.centerline::geography, {$point}::geography, route_corridors.corridor_width_meters)", $bindings)
            ->orderBy('distance_m')
            ->get();
    }

    /**
     * Corridors in effect whose centreline the given line actually crosses or touches.
     *
     * @return Collection<int, RouteCorridor>
     */
    public function intersecting(GeoJsonGeometry $line, Market $market): Collection
    {
        return RouteCorridor::query()->inEffect()
            ->where('route_corridors.market_id', $market->getKey())
            ->whereRaw('ST_Intersects(route_corridors.centerline, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))', [$line->json])
            ->orderBy('route_corridors.id')
            ->get();
    }

    /**
     * Corridors in effect whose band the given line enters (within the corridor's own width of the centreline).
     *
     * @return Collection<int, RouteCorridor>
     */
    public function within(GeoJsonGeometry $line, Market $market): Collection
    {
        return RouteCorridor::query()->inEffect()
            ->where('route_corridors.market_id', $market->getKey())
            ->whereRaw('ST_DWithin(route_corridors.centerline::geography, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)::geography, route_corridors.corridor_width_meters)', [$line->json])
            ->orderBy('route_corridors.id')
            ->get();
    }
}
