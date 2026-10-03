<?php

namespace App\Http\Resources\Restaurant;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Restaurant\RestaurantLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A restaurant organization as administrators see it. `locations` is included when the controller loaded
 * them (detail view); the list carries the count only.
 *
 * @mixin RestaurantOrganization
 */
class AdminRestaurantOrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantOrganization $organization */
        $organization = $this->resource;
        $scope = Scope::market($organization->primaryMarket->public_id);
        $can = fn (Permission $p): bool => Gate::allows($p->value, $scope);

        return [
            'id' => $organization->public_id,
            'legal_name' => $organization->legal_name,
            'display_name' => $organization->display_name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
            'status_note' => $organization->status_note,
            'rejection_category' => $organization->rejection_category?->value,
            'market_scope' => $organization->market_scope,
            'primary_market' => ['id' => $organization->primaryMarket->public_id, 'country_code' => $organization->primaryMarket->country_code, 'name' => $organization->primaryMarket->name],
            'locations_count' => (int) ($organization->getAttribute('locations_count') ?? ($organization->relationLoaded('locations') ? $organization->locations->count() : 0)),
            $this->mergeWhen($organization->relationLoaded('locations'), fn (): array => ['locations' => $organization->locations->map(fn (RestaurantLocation $l): array => [
                'id' => $l->public_id, 'name' => $l->name, 'branch_label' => $l->branch_label, 'slug' => $l->slug, 'status' => $l->status->value,
                'operational_status' => $l->operational_status->value, 'accepting_orders' => $l->accepting_orders, 'city' => $l->city->name, 'version' => (int) $l->version,
            ])->values()->all()]),
            'allowed_transitions' => app(RestaurantLifecycleService::class)->allowedTransitions($organization->status, $can),
            'can_manage' => $can(Permission::AdminRestaurantsManage),
            'submitted_at' => $organization->submitted_at?->toIso8601String(),
            'approved_at' => $organization->approved_at?->toIso8601String(),
            'suspended_at' => $organization->suspended_at?->toIso8601String(),
            'created_at' => $organization->created_at?->toIso8601String(),
            'updated_at' => $organization->updated_at?->toIso8601String(),
            'version' => (int) $organization->version,
        ];
    }
}
