<?php

namespace App\Services\Market;

use App\Models\RouteCorridor;
use App\Services\Geo\RouteCorridorQuery;
use App\Support\Geo\GeoJsonGeometry;
use App\Support\Geo\Location;
use Illuminate\Support\Collection;

/**
 * What the journey module will build on: availability of the two ends of a journey and the operational
 * corridors a route passes through. It deliberately contains no routing — the route geometry will be
 * supplied by the routing provider later and handed to corridorsAlong().
 */
final class JourneyCoverage
{
    public function __construct(
        private readonly MarketAvailabilityService $availability,
        private readonly RouteCorridorQuery $corridors,
    ) {}

    /**
     * @return array{origin: Availability, destination: Availability, same_market: bool}
     */
    public function endpoints(Location $origin, Location $destination): array
    {
        $from = $this->availability->check($origin);
        $to = $this->availability->check($destination);

        return [
            'origin' => $from,
            'destination' => $to,
            'same_market' => $from->market !== null && $to->market !== null && $from->market->is($to->market),
        ];
    }

    /**
     * Corridors in effect that a route line passes through (within each corridor's own width).
     *
     * @return Collection<int, RouteCorridor>
     */
    public function corridorsAlong(GeoJsonGeometry $route, Availability $origin): Collection
    {
        return $origin->market === null ? new Collection : $this->corridors->within($route, $origin->market);
    }
}
