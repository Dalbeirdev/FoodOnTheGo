<?php

namespace App\Http\Resources\Geo;

use App\Services\Market\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Availability $resource
 */
class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'supported' => $a->supported,
            'reason' => $a->reason?->value,
            'market' => $a->market === null ? null : ['id' => $a->market->public_id, 'country_code' => $a->market->country_code, 'name' => $a->market->name, 'status' => $a->market->status->value],
            'region' => $a->region === null ? null : ['id' => $a->region->public_id, 'code' => $a->region->code, 'name' => $a->region->name],
            'city' => $a->city === null ? null : ['id' => $a->city->public_id, 'name' => $a->city->name, 'slug' => $a->city->slug, 'timezone' => $a->city->timezone, 'status' => $a->city->status->value],
            'service_area' => $a->serviceArea === null ? null : ['id' => $a->serviceArea->public_id, 'name' => $a->serviceArea->name],
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
