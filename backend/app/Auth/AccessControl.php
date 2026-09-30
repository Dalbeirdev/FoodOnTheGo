<?php

namespace App\Auth;

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\PermissionGrant;
use App\Models\User;

/**
 * Backend permission decisions. Wired into the Gate (AppServiceProvider), so controllers and policies use
 * `$user->can('admin.markets.manage')`, `Gate::authorize(...)` or the `can:` middleware — never role checks.
 *
 * Rules:
 * - a permission can only be held by the principal type its prefix belongs to;
 * - restaurant permissions are always scoped: a grant must name the organization or location, and a check
 *   without a scope is denied (tenant isolation);
 * - admin permissions may be platform-wide (null scope) or limited to a market;
 * - nothing is implied: an admin without a grant has no access.
 */
final class AccessControl
{
    public function allows(User $user, Permission $permission, ?Scope $scope = null): bool
    {
        if ($user->principal_type !== $permission->principalType()) {
            return false;
        }

        if ($user->principal_type === PrincipalType::RestaurantUser && $scope === null) {
            return false;
        }

        return $user->loadMissing('permissionGrants')->permissionGrants
            ->where('permission', $permission->value)
            ->contains(fn (PermissionGrant $grant): bool => $this->covers($grant, $user, $scope));
    }

    public function grant(User $user, Permission $permission, ?Scope $scope = null, ?User $grantedBy = null): PermissionGrant
    {
        $grant = PermissionGrant::query()->firstOrCreate([
            'user_id' => $user->getKey(),
            'permission' => $permission->value,
            'scope_type' => $scope?->type,
            'scope_id' => $scope?->id,
        ], ['granted_by' => $grantedBy?->getKey()]);

        $user->unsetRelation('permissionGrants');

        return $grant;
    }

    private function covers(PermissionGrant $grant, User $user, ?Scope $scope): bool
    {
        if ($grant->scope_type === null) {
            return $user->principal_type === PrincipalType::AdminUser;
        }

        return $scope !== null && $grant->scope_type === $scope->type && $grant->scope_id === $scope->id;
    }
}
