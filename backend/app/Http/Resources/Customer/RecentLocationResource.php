<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerRecentLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recently chosen place of the customer (Module 25) — bounded, deduplicated, clearable.
 *
 * @mixin CustomerRecentLocation
 */
class RecentLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerRecentLocation $recent */
        $recent = $this->resource;

        return [
            'id' => $recent->public_id,
            'label' => $recent->label,
            'formatted_address' => $recent->formatted_address,
            'location' => ['latitude' => (float) $recent->latitude, 'longitude' => (float) $recent->longitude],
            'place' => $recent->place_id === null ? null : ['provider' => $recent->place_provider, 'id' => $recent->place_id],
            'country_code' => $recent->country_code,
            'timezone' => $recent->timezone,
            'times_used' => (int) $recent->times_used,
            'last_used_at' => $recent->last_used_at?->toIso8601String(),
        ];
    }
}
