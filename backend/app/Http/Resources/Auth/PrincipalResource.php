<?php

namespace App\Http\Resources\Auth;

use App\Auth\AccessControl;
use App\Auth\Principal;
use App\Models\Customer;
use App\Support\NoticeLocales;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated identity as the client may see it. `permissions` and `roles` exist so a client can
 * decide what to show; every protected action is still decided by the backend.
 *
 * @mixin Principal
 */
class PrincipalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource instanceof Customer ? $this->customer($this->resource) : $this->staff();
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Customer $customer): array
    {
        $customer->loadMissing('market');

        return [
            'principal_type' => $customer->principalType()->value,
            'id' => $customer->public_id,
            'status' => $customer->status->value,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone_e164,
            'phone_masked' => PhoneNumber::mask($customer->phone_e164, $customer->market?->phone_country_code),
            'phone_verified' => $customer->phone_verified_at !== null,
            'profile_complete' => $customer->profileComplete(),
            'preferred_locale' => $customer->preferred_locale,
            'market' => $customer->market?->country_code,
            'member_since' => $customer->created_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staff(): array
    {
        $this->resource->loadMissing('roleAssignments.role');

        return [
            'principal_type' => $this->principalType()->value,
            'id' => $this->public_id,
            'status' => $this->status->value,
            'name' => $this->name,
            'email' => $this->email,
            'mfa_enabled' => $this->hasMfaEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'preferred_locale' => $this->preferred_locale,
            'notice_locales' => NoticeLocales::available(),
            'roles' => $this->roleAssignments->map(fn ($assignment): array => [
                'code' => $assignment->role->code,
                'name' => $assignment->role->name,
                'scope' => $assignment->scope_type === null ? null : ['type' => $assignment->scope_type, 'id' => $assignment->scope_id],
            ])->values()->all(),
            'permissions' => app(AccessControl::class)->permissionCodes($this->resource),
        ];
    }
}
