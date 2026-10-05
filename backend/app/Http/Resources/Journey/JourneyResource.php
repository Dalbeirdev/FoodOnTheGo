<?php

namespace App\Http\Resources\Journey;

use App\Enums\JourneySort;
use App\Models\Journey;
use App\Services\Journey\JourneyPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A journey as its owner reads it: both ends, the departure, the normalized route (distance, duration, GeoJSON
 * geometry and encoded polyline, bounds, legs, flags, provenance), the coverage answer and the search defaults
 * the client may vary. Never the guest token hash, never internal ids.
 *
 * @mixin Journey
 */
class JourneyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Journey $journey */
        $journey = $this->resource;
        $journey->loadMissing('market');
        $policy = app(JourneyPolicy::class)->forMarket($journey->market);
        $bounds = (array) $journey->bounds;
        $origin = (array) $journey->origin;

        return [
            'id' => $journey->public_id,
            'status' => $journey->isExpired() ? 'EXPIRED' : 'ACTIVE',
            'owner' => $journey->isGuest() ? 'guest' : 'customer',
            'market' => $journey->market->country_code,
            'origin' => $this->end($origin),
            'destination' => $this->end((array) $journey->destination),
            'departure' => [
                'at' => $journey->departure_at?->utc()->toIso8601String(),
                'given' => (bool) $journey->departure_given,
                'timezone' => $origin['timezone'] ?? $journey->market->default_timezone,
            ],
            'route' => [
                'distance_meters' => (int) $journey->distance_meters,
                'duration_seconds' => (int) $journey->duration_seconds,
                'encoded_polyline' => (string) $journey->encoded_polyline,
                'geometry' => json_decode((string) $journey->route_geojson, true),
                'bounds' => ['west' => (float) ($bounds[0] ?? 0), 'south' => (float) ($bounds[1] ?? 0), 'east' => (float) ($bounds[2] ?? 0), 'north' => (float) ($bounds[3] ?? 0)],
                'legs' => array_values((array) $journey->legs),
                'flags' => array_values((array) $journey->flags),
                'warnings' => array_values((array) $journey->warnings),
                'provider' => ['name' => (string) $journey->provider, 'mode' => (string) $journey->provider_mode],
                'traffic_aware' => (bool) $journey->traffic_aware,
                'preferences' => (object) ((array) $journey->preferences),
                'calculated_at' => $journey->route_calculated_at?->utc()->toIso8601String(),
                'points' => (int) $journey->route_points,
            ],
            'coverage' => (array) $journey->coverage,
            'search' => [
                'corridor_width_meters' => (int) $journey->corridor_width_meters,
                'corridor_min_meters' => $policy['corridor_min_meters'],
                'corridor_max_meters' => $policy['corridor_max_meters'],
                'max_detour_minutes' => $policy['max_detour_minutes'],
                'max_detour_max_minutes' => $policy['max_detour_max_minutes'],
                'candidate_cap' => $policy['candidate_cap'],
                'page_size' => $policy['page_size'],
                'sorts' => JourneySort::values(),
            ],
            'expires_at' => $journey->expires_at?->utc()->toIso8601String(),
            'created_at' => $journey->created_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $end
     * @return array<string, mixed>
     */
    private function end(array $end): array
    {
        return [
            'label' => $end['label'] ?? null,
            'formatted_address' => $end['formatted_address'] ?? null,
            'location' => ['latitude' => (float) $end['lat'], 'longitude' => (float) $end['lng']],
            'country_code' => $end['country_code'] ?? null,
            'region' => $end['region'] ?? null,
            'locality' => $end['locality'] ?? null,
            'timezone' => $end['timezone'] ?? null,
            'place' => $end['place'] ?? null,
            'source' => $end['source'] ?? 'coordinates',
            'kind' => $end['kind'] ?? 'landmark',
        ];
    }
}
