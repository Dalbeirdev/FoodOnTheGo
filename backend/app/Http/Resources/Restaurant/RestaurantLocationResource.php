<?php

namespace App\Http\Resources\Restaurant;

use App\Http\Resources\Restaurant\Concerns\PresentsRestaurant;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Restaurant\RestaurantAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A location as its own STAFF see it: the profile they manage, its approval status with the explanation
 * meant for them, the exact availability reason — and nothing administrators keep to themselves (internal
 * notes, internal reasons, other organizations).
 *
 * `permissions` lists what the signed-in person may do at THIS location, for navigation only.
 *
 * @mixin RestaurantLocation
 */
class RestaurantLocationResource extends JsonResource
{
    use PresentsRestaurant;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantLocation $location */
        $location = $this->resource;
        $user = $request->user();

        return [
            ...$this->managedProfile($location),
            'organization' => [
                'id' => $location->organization->public_id,
                'name' => $location->organization->display_name,
                'status' => $location->organization->status->value,
                'status_note' => $location->organization->status_note,
            ],
            'market' => $location->market->country_code,
            // What customers are offered for pickup right now (the location's settings limited by its market).
            'pickup' => $this->pickupForCustomers($location),
            'availability' => app(RestaurantAvailabilityService::class)->evaluate($location)->forStaff(),
            'permissions' => $user instanceof RestaurantUser ? app(RestaurantAccess::class)->permissions($user, $location) : [],
        ];
    }
}
