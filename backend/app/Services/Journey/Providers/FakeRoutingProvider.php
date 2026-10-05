<?php

namespace App\Services\Journey\Providers;

use App\Contracts\Journey\RoutingProvider;
use App\Enums\ProviderFailure;
use App\Exceptions\ProviderException;
use App\Services\Journey\Data\DetourResult;
use App\Services\Journey\Data\RouteGeometry;
use App\Services\Journey\Data\RouteOptions;
use App\Services\Journey\Data\RoutePlan;
use App\Support\Geo\Geo;
use App\Support\Geo\Location;
use Carbon\CarbonImmutable;
use Closure;

/**
 * DEVELOPMENT routing provider: a deterministic synthetic road model, so journeys, detours and arrival estimates
 * can be developed and tested without a vendor. It is NOT a map: distances are great-circle × a road factor,
 * speeds depend on trip length, geometry is a gently curving line through known corridor towns where one is
 * listed. Islands have no road (Port Blair), so "no route" is exercised too.
 *
 * A detour is derived from the base route itself — leave it at the nearest point, drive to the restaurant on local
 * roads, come back — so the base route, the detour and the arrival estimate are consistent with each other.
 *
 * Tests script outcomes: scriptDetour() fixes the detour of a waypoint (or makes it unreachable), failNext()
 * makes the next operation fail with a given kind. Every call is recorded in $calls, which the architectural
 * test uses to prove that only the bounded shortlist ever reaches the provider.
 *
 * Never acceptable in production (ProviderManager refuses it); plans are marked traffic-unaware.
 */
final class FakeRoutingProvider implements RoutingProvider
{
    public const NAME = 'fake';

    /** [lat, lng, radius m] — places with no road to the mainland. */
    private const ISLANDS = [[11.6234, 92.7265, 150000]];

    /** Corridor via-points between city pairs (either direction): [[lat, lng] endpoint A, endpoint B, via...]. */
    private const CORRIDORS = [
        [[28.6139, 77.209], [30.7333, 76.7794], [[29.3909, 76.9635], [29.6857, 76.9905], [30.3782, 76.7767]]],   // Delhi → Chandigarh (NH44 via Panipat, Karnal, Ambala)
        [[30.7333, 76.7794], [32.2643, 75.6421], [[30.9685, 76.5265], [31.5273, 75.9115]]],                       // Chandigarh → Pathankot (via Rupnagar, Hoshiarpur)
        [[30.7333, 76.7794], [32.7266, 74.857], [[30.9685, 76.5265], [31.5273, 75.9115], [32.2643, 75.6421]]],    // Chandigarh → Jammu
        [[28.6139, 77.209], [26.9124, 75.7873], [[28.4595, 77.0266], [27.888, 76.2848], [27.3903, 75.9599]]],     // Delhi → Jaipur (NH48 via Gurugram, Behror, Shahpura)
        [[28.6139, 77.209], [27.1767, 78.0081], [[27.4924, 77.6737]]],                                             // Delhi → Agra (via Mathura)
        [[23.0225, 72.5714], [21.1702, 72.8311], [[22.3072, 73.1812]]],                                            // Ahmedabad → Surat (via Vadodara)
        [[21.1702, 72.8311], [19.076, 72.8777], [[20.3893, 72.9106]]],                                             // Surat → Mumbai (via Vapi)
        [[19.076, 72.8777], [18.5204, 73.8567], [[18.9894, 73.1175], [18.7546, 73.4062]]],                        // Mumbai → Pune
        [[12.9716, 77.5946], [12.2958, 76.6394], [[12.7209, 77.2799], [12.5218, 76.8951]]],                       // Bengaluru → Mysuru
    ];

    private const CORRIDOR_MATCH_METERS = 20000;

    /** Local roads between the route and a restaurant: longer than the straight line, slower than the route. */
    private const ACCESS_ROAD_FACTOR = 1.3;

    private const ACCESS_SPEED_KMH = 30;

    /** Leaving and rejoining the route. */
    private const REJOIN_SECONDS = 60;

    /** @var list<array{operation: string, waypoints: int}> */
    public array $calls = [];

    /** Test hook: runs at the start of every detour batch (to observe what holds while a computation is running). */
    public ?Closure $onDetours = null;

    /** @var array<string, array{0: int|null, 1: int|null}> key => [detour seconds | null = no route, detour metres] */
    private array $scriptedDetours = [];

    /** @var array<string, list<ProviderFailure>> */
    private array $failNext = [];

    public function name(): string
    {
        return self::NAME;
    }

    public function isFake(): bool
    {
        return true;
    }

    public function supportsTrafficAwareDeparture(): bool
    {
        return false;
    }

    public function scriptDetour(string $key, ?int $detourSeconds, ?int $detourMeters = null): void
    {
        $this->scriptedDetours[$key] = [$detourSeconds, $detourMeters ?? ($detourSeconds === null ? null : (int) round($detourSeconds * 12))];
    }

    public function failNext(string $operation, ProviderFailure $failure, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failNext[$operation][] = $failure;
        }
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->scriptedDetours = [];
        $this->failNext = [];
        $this->onDetours = null;
    }

    /**
     * @return list<array{operation: string, waypoints: int}>
     */
    public function detourCalls(): array
    {
        return array_values(array_filter($this->calls, fn (array $c): bool => $c['operation'] === 'detours'));
    }

    public function route(Location $origin, Location $destination, RouteOptions $options): RoutePlan
    {
        $this->consumeFailure('route');
        $this->calls[] = ['operation' => 'route', 'waypoints' => 0];

        if ($this->onIsland($origin) !== $this->onIsland($destination)) {
            throw ProviderException::routeNotFound(self::NAME);
        }

        $stops = [[$origin->latitude, $origin->longitude], ...$this->via($origin, $destination), [$destination->latitude, $destination->longitude]];
        $distance = 0;
        $duration = 0;
        $positions = [];
        $legs = [];
        for ($i = 1; $i < count($stops); $i++) {
            [$d, $t] = $this->road($stops[$i - 1][0], $stops[$i - 1][1], $stops[$i][0], $stops[$i][1]);
            $distance += $d;
            $duration += $t;
            $legs[] = ['distance_meters' => $d, 'duration_seconds' => $t];
            $segment = $this->curve($stops[$i - 1], $stops[$i]);
            array_push($positions, ...($i === 1 ? $segment : array_slice($segment, 1)));
        }

        return new RoutePlan(
            $origin, $destination, $distance, $duration, RouteGeometry::fromPositions($positions), $legs,
            self::NAME, false, CarbonImmutable::now(), $this->flags($distance), ['Development route model — not road data'],
        );
    }

    public function detours(RoutePlan $plan, array $waypoints, RouteOptions $options): array
    {
        $this->consumeFailure('detours');
        $this->calls[] = ['operation' => 'detours', 'waypoints' => count($waypoints)];
        if ($this->onDetours !== null) {
            ($this->onDetours)($waypoints);
        }

        $line = $this->measured($plan);
        $results = [];
        foreach ($waypoints as $key => $waypoint) {
            $key = (string) $key;
            [$offRoute, $progress] = $this->nearestOnRoute($line, $waypoint);
            $access = (int) round($offRoute * self::ACCESS_ROAD_FACTOR); // metres of local road between the route and the restaurant

            if (array_key_exists($key, $this->scriptedDetours)) {
                [$seconds, $meters] = $this->scriptedDetours[$key];
                $results[$key] = $seconds === null ? DetourResult::noRoute($key) : DetourResult::ok(
                    $key, $seconds, (int) $meters, (int) round($progress * $plan->durationSeconds + $seconds / 2), (int) round($progress * $plan->distanceMeters + $access),
                );

                continue;
            }
            if ($this->onIsland($waypoint) !== $this->onIsland($plan->origin)) {
                $results[$key] = DetourResult::noRoute($key);

                continue;
            }
            // Leave the route at the nearest point, drive to the restaurant, come back the same way.
            $detourMeters = 2 * $access;
            $detourSeconds = (int) round($detourMeters / (self::ACCESS_SPEED_KMH / 3.6)) + self::REJOIN_SECONDS;
            $results[$key] = DetourResult::ok(
                $key, $detourSeconds, $detourMeters, (int) round($progress * $plan->durationSeconds + $detourSeconds / 2), (int) round($progress * $plan->distanceMeters + $access),
            );
        }

        return $results;
    }

    /**
     * The route line with the length of every segment (metres), measured once per batch.
     *
     * @return array{positions: list<array{0: float, 1: float}>, lengths: list<float>, total: float}
     */
    private function measured(RoutePlan $plan): array
    {
        $positions = $plan->geometry->positions;
        $lengths = [];
        for ($i = 1; $i < count($positions); $i++) {
            $lengths[] = Geo::distanceMeters($positions[$i - 1][1], $positions[$i - 1][0], $positions[$i][1], $positions[$i][0]);
        }

        return ['positions' => $positions, 'lengths' => $lengths, 'total' => max(1.0, array_sum($lengths))];
    }

    /**
     * Distance (m) from a point to the route line and the fraction of the route travelled at the nearest point.
     *
     * @param  array{positions: list<array{0: float, 1: float}>, lengths: list<float>, total: float}  $line
     * @return array{0: float, 1: float}
     */
    private function nearestOnRoute(array $line, Location $point): array
    {
        $kx = 111320.0 * max(0.05, cos(deg2rad($point->latitude)));
        $ky = 110540.0;
        $px = $point->longitude * $kx;
        $py = $point->latitude * $ky;
        $best = INF;
        $progress = 0.0;
        $before = 0.0;
        foreach ($line['lengths'] as $i => $length) {
            [$aLng, $aLat] = $line['positions'][$i];
            [$bLng, $bLat] = $line['positions'][$i + 1];
            $ax = $aLng * $kx;
            $ay = $aLat * $ky;
            $dx = $bLng * $kx - $ax;
            $dy = $bLat * $ky - $ay;
            $norm = $dx * $dx + $dy * $dy;
            $t = $norm <= 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $norm));
            $distance = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
            if ($distance < $best) {
                $best = $distance;
                $progress = ($before + $t * $length) / $line['total'];
            }
            $before += $length;
        }

        return [$best === INF ? 0.0 : $best, max(0.0, min(1.0, $progress))];
    }

    /**
     * Road distance (m) and driving time (s) between two points in the synthetic model.
     *
     * @return array{0: int, 1: int}
     */
    public function road(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $straight = Geo::distanceMeters($lat1, $lng1, $lat2, $lng2);
        $factor = match (true) {
            $straight < 20000 => 1.30, $straight < 100000 => 1.20, default => 1.15
        };
        $speedKmh = match (true) {
            $straight < 5000 => 25, $straight < 20000 => 35, $straight < 100000 => 50, default => 65
        };
        $distance = (int) round($straight * $factor);

        return [$distance, (int) round($distance / ($speedKmh / 3.6))];
    }

    private function consumeFailure(string $operation): void
    {
        if (empty($this->failNext[$operation])) {
            return;
        }
        $failure = array_shift($this->failNext[$operation]);
        if ($failure !== null) {
            throw new ProviderException($failure, self::NAME);
        }
    }

    private function onIsland(Location $location): bool
    {
        foreach (self::ISLANDS as [$lat, $lng, $radius]) {
            if (Geo::distanceMeters($location->latitude, $location->longitude, $lat, $lng) <= $radius) {
                return true;
            }
        }

        return false;
    }

    /**
     * Via-points of a known corridor when both ends are near its endpoints (either direction).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function via(Location $origin, Location $destination): array
    {
        foreach (self::CORRIDORS as [$a, $b, $via]) {
            $near = fn (Location $l, array $p): bool => Geo::distanceMeters($l->latitude, $l->longitude, $p[0], $p[1]) <= self::CORRIDOR_MATCH_METERS;
            if ($near($origin, $a) && $near($destination, $b)) {
                return $via;
            }
            if ($near($origin, $b) && $near($destination, $a)) {
                return array_reverse($via);
            }
        }

        return [];
    }

    /**
     * A gently curving line between two stops, as [lng, lat] positions (GeoJSON order).
     *
     * @param  array{0: float, 1: float}  $from  [lat, lng]
     * @param  array{0: float, 1: float}  $to
     * @return list<array{0: float, 1: float}>
     */
    private function curve(array $from, array $to): array
    {
        $length = Geo::distanceMeters($from[0], $from[1], $to[0], $to[1]);
        $steps = (int) min(60, max(4, round($length / 2000)));
        $amplitude = min(1500.0, 0.02 * $length);
        $midLat = ($from[0] + $to[0]) / 2;
        $mPerDegLat = 110540.0;
        $mPerDegLng = 111320.0 * max(0.05, cos(deg2rad($midLat)));
        // direction in local metres, then its perpendicular
        $dx = ($to[1] - $from[1]) * $mPerDegLng;
        $dy = ($to[0] - $from[0]) * $mPerDegLat;
        $norm = max(1.0, hypot($dx, $dy));
        $px = -$dy / $norm;
        $py = $dx / $norm;

        $positions = [];
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $offset = $amplitude * sin(2 * M_PI * $t);
            $lat = $from[0] + ($to[0] - $from[0]) * $t + ($py * $offset) / $mPerDegLat;
            $lng = $from[1] + ($to[1] - $from[1]) * $t + ($px * $offset) / $mPerDegLng;
            $positions[] = [round($lng, 6), round($lat, 6)];
        }
        $positions[0] = [round($from[1], 6), round($from[0], 6)];
        $positions[$steps] = [round($to[1], 6), round($to[0], 6)];

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function flags(int $distance): array
    {
        return $distance >= 150000 ? ['TOLLS'] : [];
    }
}
