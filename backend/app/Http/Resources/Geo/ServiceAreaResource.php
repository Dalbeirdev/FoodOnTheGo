<?php

namespace App\Http\Resources\Geo;

use App\Models\ServiceArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lists carry the summary (area, vertex count, bounding box); the polygon itself is included only when
 * the query asked for it.
 *
 * @mixin ServiceArea
 */
class ServiceAreaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'city_id' => $this->city->public_id,
            'city_name' => $this->city->name,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'priority' => (int) $this->priority,
            'launch_stage' => $this->launch_stage,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_until' => $this->effective_until?->toIso8601String(),
            'area_square_meters' => (int) round($this->area_m2),
            'vertices' => (int) $this->vertices,
            'bbox' => json_decode((string) $this->bbox_geojson, true),
            $this->mergeWhen($this->geojson !== null, fn (): array => ['geometry' => json_decode((string) $this->geojson, true)]),
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
