<?php

namespace App\Support\Geo;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * A GeoJSON geometry accepted from an administrator: the one interchange format for every geometry in the
 * API (RFC 7946 — WGS84, positions are [longitude, latitude]).
 *
 * Validation happens in two steps, both before anything is stored:
 *  1. structure, in PHP: the expected type only (no Feature / FeatureCollection / GeometryCollection), no
 *     "crs" member (no other reference system is accepted), numeric positions inside the WGS84 range, closed
 *     rings, and a hard limit on the number of positions (protection against oversized payloads);
 *  2. topology, in PostGIS: ST_IsValid (no self-intersections), not empty. Invalid geometry is rejected,
 *     never silently repaired.
 */
final readonly class GeoJsonGeometry
{
    private function __construct(public string $type, public string $json, public int $positions) {}

    /**
     * @param  mixed  $value  decoded request value
     * @param  list<string>  $allowedTypes  e.g. ['Polygon', 'MultiPolygon'] or ['LineString']
     */
    public static function fromInput(mixed $value, array $allowedTypes): self
    {
        if (! is_array($value) || ! is_string($value['type'] ?? null) || ! is_array($value['coordinates'] ?? null)) {
            throw self::invalid('Geometry must be a GeoJSON object with "type" and "coordinates".');
        }
        if (! in_array($value['type'], $allowedTypes, true)) {
            throw self::invalid('Geometry type must be '.implode(' or ', $allowedTypes).'.');
        }
        if (array_diff(array_keys($value), ['type', 'coordinates', 'bbox']) !== []) {
            throw self::invalid('Only "type" and "coordinates" are accepted (coordinates are WGS84; no "crs").');
        }

        $count = 0;
        $limit = (int) config('geo.max_positions');
        $lines = match ($value['type']) {
            'LineString' => [$value['coordinates']],
            'Polygon' => $value['coordinates'],
            'MultiPolygon' => array_merge(...array_map(fn ($polygon) => is_array($polygon) ? array_values($polygon) : [null], $value['coordinates'] ?: [[null]])),
        };

        foreach ($lines as $line) {
            if (! is_array($line) || ! array_is_list($line)) {
                throw self::invalid('Coordinates are not a list of positions.');
            }
            $count += count($line);
            if ($count > $limit) {
                throw self::invalid("Geometry has too many positions (limit {$limit}).");
            }
            foreach ($line as $position) {
                if (! is_array($position) || count($position) < 2 || count($position) > 3 || ! is_numeric($position[0]) || ! is_numeric($position[1])
                    || $position[0] < -180 || $position[0] > 180 || $position[1] < -90 || $position[1] > 90) {
                    throw self::invalid('Every position must be [longitude, latitude] within the WGS84 range.');
                }
            }
            $minimum = $value['type'] === 'LineString' ? 2 : 4;
            if (count($line) < $minimum) {
                throw self::invalid($value['type'] === 'LineString' ? 'A line needs at least two positions.' : 'A polygon ring needs at least four positions.');
            }
            if ($value['type'] !== 'LineString' && $line[0] != $line[count($line) - 1]) {
                throw self::invalid('Every polygon ring must be closed (first position = last position).');
            }
        }

        $geometry = new self($value['type'], (string) json_encode(['type' => $value['type'], 'coordinates' => $value['coordinates']]), $count);
        $geometry->assertValidTopology();

        return $geometry;
    }

    /**
     * PostGIS is the authority on validity. The GeoJSON travels as a bound parameter.
     */
    private function assertValidTopology(): void
    {
        $check = DB::selectOne(
            'select ST_IsValid(g) as valid, ST_IsValidReason(g) as reason, ST_IsEmpty(g) as empty from (select ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) as g) t',
            [$this->json],
        );

        if ($check->empty) {
            throw self::invalid('Geometry is empty.');
        }
        if (! $check->valid) {
            throw self::invalid('Geometry is not valid: '.preg_replace('/\[.*$/', '', (string) $check->reason).'.');
        }
    }

    private static function invalid(string $message): ApiException
    {
        return new ApiException(422, 'invalid_geometry', $message);
    }
}
