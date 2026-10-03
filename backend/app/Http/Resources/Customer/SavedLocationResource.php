<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerSavedLocation;
use App\Services\Customer\SavedLocationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved journey location as its owner sees it (Module 25). Coordinates are private customer data and
 * appear only here. `coverage` is decided per request from the geometry and the current statuses.
 *
 * @mixin CustomerSavedLocation
 */
class SavedLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerSavedLocation $location */
        $location = $this->resource;

        return [
            'id' => $location->public_id,
            'kind' => $location->kind->value,
            'label' => $location->label,
            'address' => [
                'line1' => $location->line1, 'line2' => $location->line2, 'locality' => $location->locality, 'city' => $location->city, 'region' => $location->region,
                'postal_code' => $location->postal_code, 'country_code' => $location->country_code, 'formatted' => $location->formatted_address,
            ],
            'location' => $location->hasPoint() ? ['latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude] : null,
            'place' => $location->place_id === null ? null : ['provider' => $location->place_provider, 'id' => $location->place_id],
            'timezone' => $location->timezone,
            'is_default' => (bool) $location->is_default,
            'coverage' => app(SavedLocationService::class)->coverage($location),
            'version' => (int) $location->version,
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
