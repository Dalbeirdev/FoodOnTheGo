<?php

namespace App\Http\Resources\Restaurant;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Models\Cuisine;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One row of the administrators' restaurant list: a location with the organization it belongs to and where
 * it stands in the approval lifecycle. `allowed_transitions` are the status changes THIS administrator may
 * make from here (the backend checks again when one is requested).
 *
 * @mixin RestaurantLocation
 */
class AdminRestaurantSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantLocation $location */
        $location = $this->resource;
        $scope = Scope::market($location->market->public_id);

        return [
            'id' => $location->public_id,
            'slug' => $location->slug,
            'name' => $location->name,
            'branch_label' => $location->branch_label,
            'status' => $location->status->value,
            'operational_status' => $location->operational_status->value,
            'accepting_orders' => $location->accepting_orders,
            'organization' => [
                'id' => $location->organization->public_id,
                'name' => $location->organization->display_name,
                'status' => $location->organization->status->value,
                'locations' => (int) ($location->getAttribute('organization_locations') ?? 1),
            ],
            'market' => ['id' => $location->market->public_id, 'country_code' => $location->market->country_code],
            'city' => ['id' => $location->city->public_id, 'name' => $location->city->name],
            'region_code' => $location->region->code,
            'in_service_area' => $location->service_area_id !== null,
            'cuisines' => $location->cuisines->map(fn (Cuisine $c): string => $c->name)->values()->all(),
            'allowed_transitions' => app(RestaurantLifecycleService::class)->allowedTransitions($location->status, fn (Permission $p): bool => Gate::allows($p->value, $scope)),
            'submitted_at' => $location->submitted_at?->toIso8601String(),
            'approved_at' => $location->approved_at?->toIso8601String(),
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
            'version' => (int) $location->version,
        ];
    }
}
