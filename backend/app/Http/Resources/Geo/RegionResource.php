<?php

namespace App\Http\Resources\Geo;

use App\Models\MarketRegion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketRegion
 */
class RegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
