<?php

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * Geospatial conventions.
 *
 * - Coordinate standard: WGS84, SRID 4326. Axis order in PostGIS is (longitude, latitude).
 * - Points that are searched by distance (restaurant locations) use `geography(Point, 4326)`:
 *   ST_DWithin / ST_Distance on geography work in metres on the spheroid and use GiST indexes.
 * - Shapes (route lines, service-area polygons, corridors) use `geometry(<Type>, 4326)` and are cast to
 *   geography when a metric distance is needed.
 * - Every spatial column gets a GiST index.
 * - PostGIS shortlists candidates; real driving detours come from the routing provider, never from SQL.
 */
final class Geo
{
    public const SRID = 4326;

    /**
     * SQL fragment + bindings for a geography point, for use in selectRaw / whereRaw.
     *
     * @return array{0: string, 1: array{0: float, 1: float}}
     */
    public static function pointSql(float $latitude, float $longitude): array
    {
        self::assertCoordinates($latitude, $longitude);

        return ['ST_SetSRID(ST_MakePoint(?, ?), '.self::SRID.')::geography', [$longitude, $latitude]];
    }

    public static function assertCoordinates(float $latitude, float $longitude): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Coordinates are outside the WGS84 range.');
        }
    }
}
