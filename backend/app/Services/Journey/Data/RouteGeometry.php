<?php

namespace App\Services\Journey\Data;

use App\Support\Geo\Polyline;
use InvalidArgumentException;

/**
 * The line a route follows: positions in GeoJSON order ([longitude, latitude], WGS84) plus the encoded
 * polyline map SDKs consume. One normalized form whatever the provider returned.
 */
final readonly class RouteGeometry
{
    /**
     * @param  list<array{0: float, 1: float}>  $positions
     */
    private function __construct(public array $positions, public string $encodedPolyline) {}

    /**
     * @param  list<array{0: float, 1: float}>  $positions  [lng, lat]
     */
    public static function fromPositions(array $positions): self
    {
        if (count($positions) < 2) {
            throw new InvalidArgumentException('A route needs at least two positions.');
        }
        foreach ($positions as $position) {
            if (! is_array($position) || count($position) < 2 || $position[0] < -180 || $position[0] > 180 || $position[1] < -90 || $position[1] > 90) {
                throw new InvalidArgumentException('Route positions must be [longitude, latitude] within the WGS84 range.');
            }
        }

        return new self(array_values(array_map(fn (array $p): array => [(float) $p[0], (float) $p[1]], $positions)), Polyline::encode($positions));
    }

    public static function fromEncodedPolyline(string $encoded): self
    {
        $positions = Polyline::decode($encoded);
        if (count($positions) < 2) {
            throw new InvalidArgumentException('A route needs at least two positions.');
        }

        return new self($positions, $encoded);
    }

    public function count(): int
    {
        return count($this->positions);
    }

    /**
     * @return array{type: string, coordinates: list<array{0: float, 1: float}>}
     */
    public function toGeoJson(): array
    {
        return ['type' => 'LineString', 'coordinates' => $this->positions];
    }

    public function toGeoJsonString(): string
    {
        return (string) json_encode($this->toGeoJson());
    }

    /**
     * [west, south, east, north]
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bounds(): array
    {
        $lngs = array_column($this->positions, 0);
        $lats = array_column($this->positions, 1);

        return [min($lngs), min($lats), max($lngs), max($lats)];
    }
}
