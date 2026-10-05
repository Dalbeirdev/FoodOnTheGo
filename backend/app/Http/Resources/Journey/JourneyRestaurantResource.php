<?php

namespace App\Http\Resources\Journey;

use App\Http\Resources\Restaurant\PublicRestaurantResource;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One restaurant along a journey: the public restaurant (as GET /restaurants shows it — never its menu) plus
 * the route context the routing provider and the hours decided: distance to the route, progress along it, the
 * ACTUAL detour, the arrival estimate (an estimate, in the restaurant's time zone) and the availability at
 * that arrival. Nothing here is a straight-line guess.
 */
class JourneyRestaurantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{restaurant: RestaurantLocation, candidate: array<string, mixed>, arrival: RestaurantAvailability} $item */
        $item = $this->resource;
        $location = $item['restaurant'];
        $c = $item['candidate'];
        $arrivalAt = CarbonImmutable::parse($c['arrival_at']);

        return [
            'restaurant' => (new PublicRestaurantResource($location))->resolve($request),
            'route_context' => [
                'distance_to_route_meters' => (int) $c['distance_to_route_m'],
                'route_progress' => (float) $c['route_progress'],
                'segment' => (int) $c['segment'],
                'detour' => [
                    'seconds' => (int) $c['detour_seconds'],
                    'minutes' => (int) ceil($c['detour_seconds'] / 60),
                    'distance_meters' => (int) $c['detour_meters'],
                ],
                'arrival' => [
                    'estimated_at' => $arrivalAt->utc()->toIso8601String(),
                    'local_time' => $arrivalAt->setTimezone((string) $location->timezone)->format('H:i'),
                    'local_date' => $arrivalAt->setTimezone((string) $location->timezone)->format('Y-m-d'),
                    'timezone' => (string) $location->timezone,
                    'leg_seconds' => (int) $c['leg_seconds'],
                    'leg_distance_meters' => (int) $c['leg_meters'],
                    'is_estimate' => true,
                ],
                'open_at_arrival' => (bool) $c['open_at_arrival'],
                'orderable_at_arrival' => (bool) $c['orderable_at_arrival'],
                'availability_at_arrival' => $item['arrival']->forCustomers(),
                'accepting_orders_now' => $location->acceptsOrdersAt(now()),
                'prep_minutes' => $c['prep_minutes'] === null ? null : (int) $c['prep_minutes'],
            ],
        ];
    }
}
