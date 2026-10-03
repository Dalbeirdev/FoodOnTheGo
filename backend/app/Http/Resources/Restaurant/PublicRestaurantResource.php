<?php

namespace App\Http\Resources\Restaurant;

use App\Http\Resources\Restaurant\Concerns\PresentsRestaurant;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A restaurant as CUSTOMERS see it. Only ever built for a location that passed the customer-visibility rule
 * (RestaurantVisibility), and only from fields a customer is meant to read:
 *
 *   never  the approval status or its explanation, the organization's legal name, staff, internal notes,
 *          the pause reason, versions, internal ids, audit data.
 *
 * `availability` is calculated for this request (RestaurantAvailabilityService) — it is the backend's answer,
 * not something a client works out from the hours. All text fields are plain text.
 *
 * @mixin RestaurantLocation
 */
class PublicRestaurantResource extends JsonResource
{
    use PresentsRestaurant;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantLocation $location */
        $location = $this->resource;
        $distance = $location->getAttribute('distance');

        return [
            'id' => $location->public_id,
            'slug' => $location->slug,
            'name' => $location->name,
            'branch_label' => $location->branch_label,
            'short_description' => $location->short_description,
            'description' => $location->description,
            'cuisines' => $this->cuisines($location),
            'features' => $this->features($location),
            'price_level' => $location->price_level,
            'phone' => $location->phone_e164,
            'email' => $location->public_email,
            'website' => $location->website,
            'address' => $this->address($location),
            'location' => ['latitude' => $location->latitude, 'longitude' => $location->longitude],
            'timezone' => $location->timezone,
            'currency' => $location->currency,
            'images' => $this->imagesForCustomers($location),
            'hours' => ['timezone' => $location->timezone, 'weekly' => $this->weeklyHours($location), 'special' => $this->specialHours($location, internal: false)],
            'pickup' => $this->pickupForCustomers($location),
            'availability' => app(RestaurantAvailabilityService::class)->evaluate($location)->forCustomers(),
            // Straight-line distance from the point the client sent. Route distance and detour belong to route discovery.
            $this->mergeWhen($distance !== null, fn (): array => ['distance_meters' => (int) round((float) $distance)]),
        ];
    }
}
