<?php

namespace App\Contracts\Journey;

use App\Exceptions\ProviderException;
use App\Services\Journey\Data\DetourResult;
use App\Services\Journey\Data\RouteOptions;
use App\Services\Journey\Data\RoutePlan;
use App\Support\Geo\Location;

/**
 * Driving routes and actual detours. PostGIS shortlists candidates; a routing provider is the only source of
 * road distance, duration, geometry and detour — nothing is ever estimated from a straight line when the
 * provider cannot answer.
 *
 * Which implementation runs is configuration (ROUTING_DRIVER). A provider applies explicit timeouts, maps
 * vendor errors to ProviderException and never logs coordinates, polylines or credentials.
 */
interface RoutingProvider
{
    public function name(): string;

    public function isFake(): bool;

    /**
     * Whether a future departure changes the provider's answer (traffic-aware routing). When false, a departure
     * time only shifts the arrival estimates and the plan is marked as not traffic aware.
     */
    public function supportsTrafficAwareDeparture(): bool;

    /**
     * The recommended driving route between two points.
     *
     * @throws ProviderException ROUTE_NOT_FOUND when no road connects them
     */
    public function route(Location $origin, Location $destination, RouteOptions $options): RoutePlan;

    /**
     * Actual detours for a BOUNDED set of waypoints: origin → waypoint → destination minus the base route,
     * batched as the provider allows. Keys are the caller's identifiers.
     *
     * @param  array<string, Location>  $waypoints
     * @return array<string, DetourResult> one entry per key
     *
     * @throws ProviderException
     */
    public function detours(RoutePlan $plan, array $waypoints, RouteOptions $options): array;
}
