<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Journey\Data\PlaceSuggestion;
use App\Services\Journey\PlaceService;
use App\Services\Market\MarketAvailabilityService;
use App\Services\Market\MarketContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Places for the journey planner (Module 26). Search is restricted to the market's country; a suggestion is
 * only text until it is resolved here, server side, into coordinates — the one Location shape of Module 22 —
 * together with the coverage answer for that point. No coordinates travel in URLs.
 */
class PlaceController extends Controller
{
    /**
     * ?q=text (&country=XX). Public, rate limited (guests tighter), briefly cached per normalized query.
     */
    public function search(Request $request, PlaceService $places, MarketContext $markets): JsonResponse
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
        ]);
        $market = $markets->current($input['country'] ?? null);

        return response()->json([
            'data' => array_map(fn (PlaceSuggestion $s): array => $s->toArray(), $places->search($input['q'], $market)),
            'country_code' => $market->country_code,
            'provider' => $places->provider(),
        ]);
    }

    /**
     * {place_id} → the place's coordinates, address, time zone and whether FoodOnTheGo serves that point.
     */
    public function resolve(Request $request, PlaceService $places, MarketContext $markets, MarketAvailabilityService $availability): JsonResponse
    {
        $input = $request->validate([
            'place_id' => ['required', 'string', 'max:200'],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
        ]);
        $market = $markets->current($input['country'] ?? null);
        $place = $places->resolve($input['place_id'], $market);
        $coverage = $availability->check($place->location);

        return response()->json([
            'place' => ['provider' => $place->provider, 'id' => $place->placeId, 'name' => $place->name, 'kind' => $place->kind],
            'location' => [
                'latitude' => $place->location->latitude, 'longitude' => $place->location->longitude, 'formatted_address' => $place->location->formattedAddress,
                'country_code' => $place->location->countryCode, 'region' => $place->location->region, 'locality' => $place->location->locality,
                'postal_code' => $place->location->postalCode, 'timezone' => $place->location->timezone,
            ],
            'coverage' => [
                'supported' => $coverage->supported, 'reason' => $coverage->reason?->value, 'market' => $coverage->market?->country_code,
                'city' => $coverage->city?->name, 'service_area' => $coverage->serviceArea?->name,
            ],
            'provider' => $places->provider(),
        ]);
    }
}
