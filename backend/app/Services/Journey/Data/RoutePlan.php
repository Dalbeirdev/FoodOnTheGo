<?php

namespace App\Services\Journey\Data;

use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A driving route as the platform understands it, whatever provider produced it: distance, duration, geometry,
 * bounds, legs, flags the traveller should know about (tolls, ferries) and provenance.
 */
final readonly class RoutePlan
{
    /**
     * @param  list<array{distance_meters: int, duration_seconds: int}>  $legs
     * @param  list<string>  $flags  e.g. TOLLS, FERRY
     * @param  list<string>  $warnings  provider warnings, plain text
     */
    public function __construct(
        public Location $origin,
        public Location $destination,
        public int $distanceMeters,
        public int $durationSeconds,
        public RouteGeometry $geometry,
        public array $legs,
        public string $provider,
        public bool $trafficAware,
        public CarbonImmutable $calculatedAt,
        public array $flags = [],
        public array $warnings = [],
    ) {}

    /**
     * The provider's answer in plain form for the short-lived route cache: figures, line and provenance — NOT
     * the two places (no address or label is cached; the request that hits the cache supplies its own ends).
     *
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return [
            'distance_meters' => $this->distanceMeters, 'duration_seconds' => $this->durationSeconds, 'encoded_polyline' => $this->geometry->encodedPolyline,
            'legs' => $this->legs, 'provider' => $this->provider, 'traffic_aware' => $this->trafficAware,
            'calculated_at' => $this->calculatedAt->utc()->toIso8601String(), 'flags' => $this->flags, 'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $row  written by toCache()
     */
    public static function fromCache(array $row, Location $origin, Location $destination): ?self
    {
        if (! is_string($row['encoded_polyline'] ?? null) || ! is_string($row['provider'] ?? null) || ! isset($row['distance_meters'], $row['duration_seconds'], $row['calculated_at'])) {
            return null;
        }
        try {
            return new self(
                $origin, $destination, (int) $row['distance_meters'], (int) $row['duration_seconds'], RouteGeometry::fromEncodedPolyline($row['encoded_polyline']),
                array_values((array) ($row['legs'] ?? [])), $row['provider'], (bool) ($row['traffic_aware'] ?? false), CarbonImmutable::parse((string) $row['calculated_at']),
                array_values((array) ($row['flags'] ?? [])), array_values((array) ($row['warnings'] ?? [])),
            );
        } catch (Throwable) {
            return null; // an unreadable cache entry is a cache miss, never an error for the traveller
        }
    }

    /**
     * [west, south, east, north]
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bounds(): array
    {
        return $this->geometry->bounds();
    }
}
