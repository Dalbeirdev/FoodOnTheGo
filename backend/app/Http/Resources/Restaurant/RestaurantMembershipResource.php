<?php

namespace App\Http\Resources\Restaurant;

use App\Enums\MembershipStatus;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member of a restaurant's staff, as the people who manage that staff (and administrators) see it.
 * Never a password, an MFA detail or an invitation token; the person's other restaurants are not shown.
 *
 * While an invitation is open the name is the one the inviter typed — the account behind an already
 * registered e-mail keeps its own name to itself until the person accepts.
 *
 * The controller loads `user`, `role` and `locations`; where a reader may only see some locations it has
 * already narrowed the relation.
 *
 * @mixin RestaurantMembership
 */
class RestaurantMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RestaurantMembership $membership */
        $membership = $this->resource;
        $invited = $membership->status === MembershipStatus::Invited;

        return [
            'id' => $membership->public_id,
            'name' => $invited ? ($membership->invited_name ?? $membership->user->name) : $membership->user->name,
            'email' => $membership->user->email,
            'role' => ['code' => $membership->role->code, 'name' => $membership->role->name],
            'status' => $membership->status->value,
            'all_locations' => $membership->all_locations,
            'locations' => $membership->locations->map(fn (RestaurantLocation $l): array => ['id' => $l->public_id, 'name' => $l->name, 'branch_label' => $l->branch_label])->values()->all(),
            'is_self' => $request->user() !== null && $request->user()->is($membership->user),
            'invited_at' => $membership->created_at?->toIso8601String(),
            'accepted_at' => $membership->accepted_at?->toIso8601String(),
            'revoked_at' => $membership->revoked_at?->toIso8601String(),
            'last_login_at' => $invited ? null : $membership->user->last_login_at?->toIso8601String(),
            'version' => (int) $membership->version,
        ];
    }
}
