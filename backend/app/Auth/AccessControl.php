<?php

namespace App\Auth;

use App\Enums\Permission;
use App\Enums\PrincipalType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Backend permission decisions. Wired into the Gate (AppServiceProvider), so controllers and policies use
 * `$principal->can('admin.refunds.issue')`, `Gate::authorize(...)` or the `can:` middleware — never role names.
 *
 * Rules:
 * - a permission can only be used by the principal type its prefix belongs to; customers hold none;
 * - permissions come from role assignments (role → permissions), each assignment optionally scoped;
 * - restaurant permissions are always scoped: the check must name an organization or location, and only an
 *   assignment for that location, or for the organization that owns it, satisfies it (tenant isolation);
 * - an admin assignment without a scope is platform-wide; one scoped to a market satisfies only checks for
 *   that market;
 * - nothing is implied: no role, no access.
 *
 * Effective grants are cached per principal under a global version. Any change to a role, its permissions
 * or an assignment bumps the version, so a stale entry can never be read afterwards.
 */
final class AccessControl
{
    private const VERSION_KEY = 'rbac:version';

    private const TTL_SECONDS = 300;

    public function allows(Principal $principal, Permission $permission, ?Scope $scope = null): bool
    {
        $type = $principal->principalType();

        if ($type !== $permission->principalType() || ! $principal->canAuthenticate()) {
            return false;
        }

        if ($type === PrincipalType::RestaurantUser && ($scope === null || $scope->type === 'market')) {
            return false;
        }

        foreach ($this->grants($principal) as $grant) {
            if ($grant['permission'] === $permission->value && $this->covers($grant, $type, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every (permission, scope) pair the principal holds.
     *
     * @return list<array{permission: string, scope_type: string|null, scope_id: string|null}>
     */
    public function grants(Principal $principal): array
    {
        $type = $principal->principalType();

        if ($type === PrincipalType::Customer) {
            return [];
        }

        $key = sprintf('rbac:%s:%s:%d', $this->version(), $type->morphAlias(), $principal->getAuthIdentifier());

        return Cache::remember($key, self::TTL_SECONDS, fn (): array => DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('role_assignments.principal_type', $type->morphAlias())
            ->where('role_assignments.principal_id', $principal->getAuthIdentifier())
            ->where('roles.principal_type', $type->value)
            ->orderBy('role_permissions.permission')
            ->get(['role_permissions.permission', 'role_assignments.scope_type', 'role_assignments.scope_id'])
            ->map(fn (object $row): array => ['permission' => $row->permission, 'scope_type' => $row->scope_type, 'scope_id' => $row->scope_id])
            ->all());
    }

    /**
     * Distinct permission codes held in any scope — for client navigation only, never a decision.
     *
     * @return list<string>
     */
    public function permissionCodes(Principal $principal): array
    {
        return array_values(array_unique(array_column($this->grants($principal), 'permission')));
    }

    /**
     * The permission codes the principal holds for exactly this resource — what a client may show for it.
     * Same rules as allows(); the backend still decides every action.
     *
     * @return list<string>
     */
    public function permissionsIn(Principal $principal, Scope $scope): array
    {
        $type = $principal->principalType();

        if (! $principal->canAuthenticate() || ($type === PrincipalType::RestaurantUser && $scope->type === 'market')) {
            return [];
        }

        $codes = [];
        foreach ($this->grants($principal) as $grant) {
            if ($this->covers($grant, $type, $scope)) {
                $codes[$grant['permission']] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Invalidates every cached grant. Called whenever roles, role permissions or assignments change.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
    }

    private function version(): string
    {
        return (string) Cache::rememberForever(self::VERSION_KEY, fn (): string => bin2hex(random_bytes(8)));
    }

    /**
     * @param  array{permission: string, scope_type: string|null, scope_id: string|null}  $grant
     */
    private function covers(array $grant, PrincipalType $type, ?Scope $scope): bool
    {
        if ($grant['scope_type'] === null) {
            return $type === PrincipalType::AdminUser;
        }

        if ($scope === null) {
            return false;
        }

        if ($grant['scope_type'] === $scope->type && $grant['scope_id'] === $scope->id) {
            return true;
        }

        return $grant['scope_type'] === 'organization' && $scope->type === 'location' && $grant['scope_id'] === $scope->organizationId;
    }
}
