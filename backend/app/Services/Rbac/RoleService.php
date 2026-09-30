<?php

namespace App\Services\Rbac;

use App\Auth\AccessControl;
use App\Auth\Principal;
use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Events\RoleAssignmentChanged;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Services\Auth\SecurityEventRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way roles and assignments change. Every change invalidates the permission cache, records a
 * security event and raises the audit hook.
 */
final class RoleService
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly SecurityEventRecorder $events,
    ) {}

    /**
     * Creates or updates a role and makes its permission set exactly the given one.
     *
     * @param  list<Permission>  $permissions
     */
    public function define(PrincipalType $type, string $code, string $name, array $permissions, bool $system = false): Role
    {
        foreach ($permissions as $permission) {
            if ($permission->principalType() !== $type) {
                throw new InvalidArgumentException("Permission [{$permission->value}] cannot belong to a {$type->value} role.");
            }
        }

        $role = DB::transaction(function () use ($type, $code, $name, $permissions, $system): Role {
            $role = Role::query()->updateOrCreate(['principal_type' => $type->value, 'code' => $code], ['name' => $name, 'is_system' => $system]);
            $codes = array_map(fn (Permission $p): string => $p->value, $permissions);

            $role->permissions()->whereNotIn('permission', $codes)->delete();
            foreach (array_diff($codes, $role->permissions()->pluck('permission')->all()) as $missing) {
                $role->permissions()->create(['permission' => $missing]);
            }

            return $role;
        });

        $this->access->flush();

        return $role;
    }

    public function assign(AdminUser|RestaurantUser $account, Role $role, ?Scope $scope = null, ?AdminUser $grantedBy = null, ?Principal $actor = null): RoleAssignment
    {
        $this->assertAssignable($account, $role, $scope);

        $assignment = RoleAssignment::query()->firstOrCreate([
            'role_id' => $role->getKey(),
            'principal_type' => $account->principalType()->morphAlias(),
            'principal_id' => $account->getKey(),
            'scope_type' => $scope?->type,
            'scope_id' => $scope?->id,
        ], ['granted_by_admin_id' => $grantedBy?->getKey()]);

        $this->changed($account, $role, $scope, true, $actor ?? $grantedBy);

        return $assignment;
    }

    public function revoke(AdminUser|RestaurantUser $account, Role $role, ?Scope $scope = null, ?Principal $actor = null): void
    {
        RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('principal_type', $account->principalType()->morphAlias())
            ->where('principal_id', $account->getKey())
            ->where('scope_type', $scope?->type)
            ->where('scope_id', $scope?->id)
            ->delete();

        $this->changed($account, $role, $scope, false, $actor);
    }

    private function assertAssignable(AdminUser|RestaurantUser $account, Role $role, ?Scope $scope): void
    {
        if ($role->principal_type !== $account->principalType()) {
            throw new InvalidArgumentException('A role can only be assigned to its own principal type.');
        }

        if ($account instanceof RestaurantUser && ($scope === null || $scope->type === 'market')) {
            throw new InvalidArgumentException('A restaurant role must be scoped to an organization or a location.');
        }

        if ($account instanceof AdminUser && $scope !== null && $scope->type !== 'market') {
            throw new InvalidArgumentException('An admin role is platform-wide or scoped to a market.');
        }
    }

    private function changed(AdminUser|RestaurantUser $account, Role $role, ?Scope $scope, bool $assigned, ?Principal $actor): void
    {
        $this->access->flush();

        $this->events->record(SecurityEventType::PermissionChanged, $account, [
            'role' => $role->code,
            'change' => $assigned ? 'assigned' : 'revoked',
            'scope_type' => $scope?->type,
            'scope_id' => $scope?->id,
            'actor' => $actor?->public_id,
        ]);
        RoleAssignmentChanged::dispatch($account, $role->code, $scope, $assigned, $actor);
    }
}
