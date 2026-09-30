<?php

namespace App\Http\Resources\Geo;

use App\Models\RouteCorridor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RouteCorridor
 */
class RouteCorridorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'highway' => $this->highway,
            'status' => $this->status->value,
            'origin_city_id' => $this->originCity?->public_id,
            'destination_city_id' => $this->destinationCity?->public_id,
            'via_city_ids' => array_values($this->via_city_ids ?? []),
            'corridor_width_meters' => (int) $this->corridor_width_meters,
            'length_meters' => (int) round($this->length_m),
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_until' => $this->effective_until?->toIso8601String(),
            $this->mergeWhen($this->geojson !== null, fn (): array => ['geometry' => json_decode((string) $this->geojson, true)]),
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
