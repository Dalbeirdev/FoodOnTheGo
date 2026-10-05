<?php

namespace App\Services\Journey\Providers;

use App\Contracts\Journey\RoutingProvider;
use App\Exceptions\ProviderException;
use App\Services\Journey\Data\DetourResult;
use App\Services\Journey\Data\RouteGeometry;
use App\Services\Journey\Data\RouteOptions;
use App\Services\Journey\Data\RoutePlan;
use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Google Routes API: computeRoutes for the base route, computeRouteMatrix for actual detours. Driving only.
 * A future departure makes the request traffic aware (the provider's own forecast — never a figure invented
 * here); a departure in the past is sent as "now".
 *
 * Detours are two matrix calls per batch (origin → waypoints, waypoints → destination), never one request per
 * restaurant, and only ever for the bounded shortlist the caller hands over.
 *
 * Live use needs ROUTING_DRIVER=google and ROUTING_API_KEY — PENDING PROVIDER CONFIGURATION in this repository;
 * the adapter is verified against recorded response shapes (contract tests), not against the live service.
 */
final class GoogleRoutingProvider implements RoutingProvider
{
    use CallsGoogleApis;

    public const NAME = 'google';

    private const ROUTE_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.legs.distanceMeters,routes.legs.duration,routes.travelAdvisory.tollInfo,routes.warnings,routes.routeLabels';

    private const MATRIX_MASK = 'originIndex,destinationIndex,duration,distanceMeters,status,condition';

    /**
     * @param  array<string, mixed>  $config  services.routing
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isFake(): bool
    {
        return false;
    }

    public function supportsTrafficAwareDeparture(): bool
    {
        return true;
    }

    public function route(Location $origin, Location $destination, RouteOptions $options): RoutePlan
    {
        $departure = $this->departure($options);
        $body = [
            'origin' => $this->waypoint($origin),
            'destination' => $this->waypoint($destination),
            'travelMode' => RouteOptions::TRAVEL_MODE,
            'routingPreference' => $departure !== null ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE',
            'computeAlternativeRoutes' => false,
            'routeModifiers' => ['avoidTolls' => $options->avoidTolls, 'avoidFerries' => $options->avoidFerries, 'avoidHighways' => $options->avoidHighways],
            'languageCode' => $options->languageCode,
            'units' => 'METRIC',
            'polylineQuality' => (string) config('journey.providers.google.polyline_quality', 'HIGH_QUALITY'),
        ];
        if ($departure !== null) {
            $body['departureTime'] = $departure->toRfc3339String();
        }

        $response = $this->call(
            fn () => $this->request(self::ROUTE_MASK)->post($this->baseUrl('https://routes.googleapis.com').'/directions/v2:computeRoutes', $body),
            fn (): ProviderException => ProviderException::routeNotFound(self::NAME),
        );

        $route = (array) ($response->json('routes.0') ?? []);
        if ($route === [] || ! isset($route['polyline']['encodedPolyline'])) {
            throw ProviderException::routeNotFound(self::NAME);
        }
        try {
            $geometry = RouteGeometry::fromEncodedPolyline((string) $route['polyline']['encodedPolyline']);
        } catch (InvalidArgumentException $e) {
            throw ProviderException::unavailable(self::NAME, 'unreadable polyline');
        }
        $flags = [];
        if (! empty($route['travelAdvisory']['tollInfo'])) {
            $flags[] = 'TOLLS';
        }
        $warnings = array_values(array_map(fn ($w): string => (string) $w, (array) ($route['warnings'] ?? [])));
        if (array_filter($warnings, fn (string $w): bool => stripos($w, 'ferr') !== false) !== []) {
            $flags[] = 'FERRY';
        }
        $legs = array_values(array_map(fn ($leg): array => ['distance_meters' => (int) ($leg['distanceMeters'] ?? 0), 'duration_seconds' => self::seconds($leg['duration'] ?? '0s')], (array) ($route['legs'] ?? [])));

        return new RoutePlan(
            $origin, $destination, (int) ($route['distanceMeters'] ?? 0), self::seconds($route['duration'] ?? '0s'), $geometry, $legs,
            self::NAME, $departure !== null, CarbonImmutable::now(), $flags, $warnings,
        );
    }

    public function detours(RoutePlan $plan, array $waypoints, RouteOptions $options): array
    {
        if ($waypoints === []) {
            return [];
        }
        $keys = array_map('strval', array_keys($waypoints));
        $points = array_values(array_map($this->matrixWaypoint(...), $waypoints));
        $departure = $this->departure($options);

        $toWaypoints = $this->matrix([$this->matrixWaypoint($plan->origin)], $points, $departure, $options);     // origin → each restaurant
        $fromWaypoints = $this->matrix($points, [$this->matrixWaypoint($plan->destination)], $departure, $options); // each restaurant → destination

        $results = [];
        foreach ($keys as $index => $key) {
            $first = $toWaypoints[$index] ?? null;    // originIndex 0, destinationIndex = $index
            $second = $fromWaypoints[$index] ?? null; // originIndex = $index, destinationIndex 0
            if ($first === null || $second === null) {
                $results[$key] = DetourResult::failed($key);
            } elseif ($first['condition'] !== 'ROUTE_EXISTS' || $second['condition'] !== 'ROUTE_EXISTS') {
                $results[$key] = DetourResult::noRoute($key);
            } else {
                $results[$key] = DetourResult::ok(
                    $key,
                    $first['seconds'] + $second['seconds'] - $plan->durationSeconds,
                    $first['meters'] + $second['meters'] - $plan->distanceMeters,
                    $first['seconds'],
                    $first['meters'],
                );
            }
        }

        return $results;
    }

    /**
     * One route-matrix call. Elements come back as a list with origin / destination indexes; they are keyed by
     * the index that varies (the waypoint's).
     *
     * @param  list<array<string, mixed>>  $origins
     * @param  list<array<string, mixed>>  $destinations
     * @return array<int, array{condition: string, seconds: int, meters: int}>
     */
    private function matrix(array $origins, array $destinations, ?CarbonImmutable $departure, RouteOptions $options): array
    {
        $body = [
            'origins' => $origins,
            'destinations' => $destinations,
            'travelMode' => RouteOptions::TRAVEL_MODE,
            'routingPreference' => $departure !== null ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE',
            'languageCode' => $options->languageCode,
            'units' => 'METRIC',
        ];
        if ($departure !== null) {
            $body['departureTime'] = $departure->toRfc3339String();
        }

        $response = $this->call(fn () => $this->request(self::MATRIX_MASK)->post($this->baseUrl('https://routes.googleapis.com').'/distanceMatrix/v2:computeRouteMatrix', $body));

        $elements = [];
        foreach ((array) $response->json() as $element) {
            if (! is_array($element)) {
                continue;
            }
            $index = count($origins) === 1 ? (int) ($element['destinationIndex'] ?? 0) : (int) ($element['originIndex'] ?? 0);
            $ok = ((int) ($element['status']['code'] ?? 0)) === 0;
            $elements[$index] = [
                'condition' => $ok ? (string) ($element['condition'] ?? 'ROUTE_NOT_FOUND') : 'FAILED',
                'seconds' => self::seconds($element['duration'] ?? '0s'),
                'meters' => (int) ($element['distanceMeters'] ?? 0),
            ];
        }

        return $elements;
    }

    /**
     * @return array<string, mixed>
     */
    private function waypoint(Location $location): array
    {
        return ['location' => ['latLng' => ['latitude' => $location->latitude, 'longitude' => $location->longitude]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function matrixWaypoint(Location $location): array
    {
        return ['waypoint' => $this->waypoint($location)];
    }

    /**
     * The departure the provider gets: a future instant, or nothing (= now). A past instant is never sent.
     */
    private function departure(RouteOptions $options): ?CarbonImmutable
    {
        return $options->departureAt !== null && $options->departureAt->isFuture() ? $options->departureAt->utc() : null;
    }
}
