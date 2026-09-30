<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\AvailabilityResource;
use App\Models\RouteCorridor;
use App\Services\Geo\RouteCorridorQuery;
use App\Services\Market\MarketAvailabilityService;
use App\Support\Geo\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /**
     * "Can FoodOnTheGo serve this location?" — decided here, never by the client. Public (a customer asks
     * before signing in) and rate limited. The location is used for the answer and is not stored.
     */
    public function location(Request $request, MarketAvailabilityService $availability, RouteCorridorQuery $corridors): JsonResponse
    {
        $location = Location::fromArray($request->validate(Location::rules()));
        $result = $availability->check($location);

        return response()->json([
            ...(new AvailabilityResource($result))->resolve($request),
            'route_corridors' => $result->supported
                ? $corridors->containing($location, $result->market)->map(fn (RouteCorridor $c): array => ['id' => $c->public_id, 'name' => $c->name, 'highway' => $c->highway])->all()
                : [],
        ]);
    }
}
