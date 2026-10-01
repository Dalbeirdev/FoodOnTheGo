<?php

namespace App\Http\Resources;

use App\Models\AdminUser;
use App\Models\Market;
use App\Models\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An administrator account as other administrators may see it. Never the password, the MFA secret or
 * recovery codes — only whether MFA is on.
 *
 * @mixin AdminUser
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scoped = $this->roleAssignments->where('scope_type', 'market')->pluck('scope_id')->all();
        $markets = $scoped === [] ? collect() : Market::query()->whereIn('public_id', $scoped)->pluck('country_code', 'public_id');

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'mfa_enabled' => $this->hasMfaEnabled(),
            'is_self' => $request->user()?->is($this->resource) ?? false,
            'roles' => $this->roleAssignments->map(fn (RoleAssignment $a): array => [
                'code' => $a->role->code,
                'name' => $a->role->name,
                'market_id' => $a->scope_type === 'market' ? $a->scope_id : null,
                'market' => $a->scope_type === 'market' ? ($markets[$a->scope_id] ?? null) : null,
            ])->values()->all(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
