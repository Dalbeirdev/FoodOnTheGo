<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Journey\JourneyResource;
use App\Services\Journey\JourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journey contexts (Module 26). Planning is open to guests and customers; a guest receives, once, the token
 * that lets them read their own journey (header X-Journey-Token). Reading goes through the `journey`
 * middleware, which only ever resolves a journey for its owner.
 */
class JourneyController extends Controller
{
    /**
     * POST /journeys/plan — origin + destination (each a place id, coordinates or a saved place), optional
     * departure (ISO 8601 with offset), route preferences and corridor width. Answers the stored journey.
     */
    public function plan(Request $request, JourneyService $journeys): JsonResponse
    {
        $input = $request->validate(self::rules());
        $result = $journeys->plan($input, $request->attributes->get('customer'));

        $body = (new JourneyResource($result['journey']))->resolve($request);
        if ($result['guest_token'] !== null) {
            $body['guest_token'] = $result['guest_token'];
        }

        return response()->json($body, 201);
    }

    public function show(Request $request): JourneyResource
    {
        return new JourneyResource($request->attributes->get('journey'));
    }

    /**
     * Origin and destination take the same three forms. Fields a client must never set are refused outright
     * (not ignored), so a payload shaped like a stored journey cannot smuggle an owner, a market or a route in.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $end = fn (string $prefix): array => [
            $prefix => ['required', 'array'],
            "{$prefix}.place_id" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}.saved_location_id" => ['sometimes', 'nullable', 'string', 'uuid'],
            "{$prefix}.lat" => ["required_with:{$prefix}.lng", 'numeric', 'between:-90,90'],
            "{$prefix}.lng" => ["required_with:{$prefix}.lat", 'numeric', 'between:-180,180'],
            "{$prefix}.label" => ['sometimes', 'nullable', 'string', 'max:120'],
            "{$prefix}.formatted_address" => ['sometimes', 'nullable', 'string', 'max:300'],
            "{$prefix}.country_code" => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            "{$prefix}.timezone" => ['sometimes', 'nullable', 'timezone:all'],
        ];

        return [
            ...$end('origin'),
            ...$end('destination'),
            'departure_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'preferences' => ['sometimes', 'array:avoid_tolls,avoid_ferries,avoid_highways'],
            'preferences.avoid_tolls' => ['sometimes', 'boolean'],
            'preferences.avoid_ferries' => ['sometimes', 'boolean'],
            'preferences.avoid_highways' => ['sometimes', 'boolean'],
            'corridor_width_meters' => ['sometimes', 'integer', 'between:'.config('journey.corridor.min_width_meters').','.config('journey.corridor.max_width_meters')],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            // never from a client
            'customer_id' => ['prohibited'],
            'market_id' => ['prohibited'],
            'market_status' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_mode' => ['prohibited'],
            'route' => ['prohibited'],
            'status' => ['prohibited'],
            'expires_at' => ['prohibited'],
            'guest_token' => ['prohibited'],
            'eligible' => ['prohibited'],
        ];
    }
}
