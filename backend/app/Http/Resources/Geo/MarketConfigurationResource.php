<?php

namespace App\Http\Resources\Geo;

use App\Models\MarketConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketConfiguration
 */
class MarketConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'payment' => (object) $this->payment,
            'tax' => (object) $this->tax,
            'legal' => (object) $this->legal,
            'address' => (object) $this->address,
            'ordering' => (object) $this->ordering,
            // Module 26: corridor width / detour limit / candidate cap overrides, within the bounds of config/journey.php.
            'journey' => (object) ($this->journey ?? []),
            'locked_features' => array_values($this->locked_features ?? []),
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
