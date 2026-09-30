<?php

namespace App\Http\Resources\Geo;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin City
 */
class CityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'region_id' => $this->region->public_id,
            'region_code' => $this->region->code,
            'name' => $this->name,
            'slug' => $this->slug,
            'aliases' => array_values($this->aliases ?? []),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            // A city cannot be more available than its region.
            'serving_customers' => $this->status->servesCustomers() && $this->region->status->servesCustomers(),
            'launch_stage' => $this->launch_stage,
            'launched_at' => $this->launched_at?->toIso8601String(),
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
