<?php

namespace App\Http\Resources\Restaurant;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Http\Resources\Restaurant\Concerns\PresentsRestaurant;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantAvailabilityService;
use App\Services\Restaurant\RestaurantLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A location as ADMINISTRATORS see it: everything the restaurant manages, plus what only the platform
 * decides or knows — the organization, the market geography it was placed in, the lifecycle timestamps, the
 * exact availability reason and the status changes this administrator may make.
 *
 * Internal notes, the staff summary and the history are added by the controller for the detail view.
 *
 * @mixin RestaurantLocation
 */
class AdminRestaurantResource extends JsonResource
{
    use PresentsRestaurant;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantLocation $location */
        $location = $this->resource;
        $organization = $location->organization;
        $scope = Scope::market($location->market->public_id);
        $can = fn (Permission $p): bool => Gate::allows($p->value, $scope);
        $lifecycle = app(RestaurantLifecycleService::class);

        return [
            ...$this->managedProfile($location),
            'organization' => [
                'id' => $organization->public_id,
                'name' => $organization->display_name,
                'legal_name' => $organization->legal_name,
                'slug' => $organization->slug,
                'status' => $organization->status->value,
                'status_note' => $organization->status_note,
                'market_scope' => $organization->market_scope,
                'allowed_transitions' => $lifecycle->allowedTransitions($organization->status, $can),
                'version' => (int) $organization->version,
            ],
            'market' => ['id' => $location->market->public_id, 'country_code' => $location->market->country_code, 'name' => $location->market->name],
            'city' => ['id' => $location->city->public_id, 'name' => $location->city->name, 'status' => $location->city->status->value],
            'region' => ['code' => $location->region->code, 'name' => $location->region->name, 'status' => $location->region->status->value],
            // The stored association (covering area with the highest priority). Availability is decided from the geometry.
            'service_area' => $location->serviceArea === null ? null : [
                'id' => $location->serviceArea->public_id, 'name' => $location->serviceArea->name, 'status' => $location->serviceArea->status->value,
            ],
            'service_area_resolved_at' => $location->service_area_resolved_at?->toIso8601String(),
            'pickup' => (new PickupSettingsResource($location))->resolve($request),
            'availability' => app(RestaurantAvailabilityService::class)->evaluate($location)->forStaff(),
            'allowed_transitions' => $lifecycle->allowedTransitions($location->status, $can),
            'can_manage' => $can(Permission::AdminRestaurantsManage),
            'submitted_at' => $location->submitted_at?->toIso8601String(),
            'approved_at' => $location->approved_at?->toIso8601String(),
            'suspended_at' => $location->suspended_at?->toIso8601String(),
            'created_at' => $location->created_at?->toIso8601String(),
        ];
    }
}
