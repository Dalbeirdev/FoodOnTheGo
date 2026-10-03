<?php

namespace App\Services\Restaurant;

use App\Auth\AccessControl;
use App\Auth\Principal;
use App\Auth\Scope;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Enums\StaffStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantStaffInvitation;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Notifications\RestaurantStaffInvitationNotification;
use App\Services\Audit\AuditRecorder;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Auth\StaffLoginService;
use App\Services\Auth\TokenIssuer;
use App\Services\Rbac\RoleService;
use App\Support\PlainText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Who works for which restaurant: memberships, invitations, roles and location access.
 *
 *   Restaurant User ── Membership (role, status) ── Organization
 *                          └─ all locations, or the listed ones
 *
 * A membership is the business fact; the role assignments of Module 21 are DERIVED from it (sync()). So
 * authorization is always "permission + resource scope", decided by AccessControl, and only an ACTIVE
 * membership can authorise anything: revoking or suspending one removes its assignments and invalidates the
 * permission cache at once.
 *
 * Rules that no permission overrides:
 *  - nobody changes or revokes their own membership (no self-promotion, no accidental self-lockout);
 *  - a role can only be given by someone who holds every permission in it for the whole organization, and
 *    nobody can alter the membership of someone who holds more than they do;
 *  - an organization always keeps one active member who can manage its staff across all locations
 *    ("the last owner" cannot be demoted, limited, suspended or revoked — transfer first);
 *  - locations given to a member must belong to the same organization;
 *  - an invitation creates no access: the link is random, single use, expiring, stored as a hash, and it can
 *    only ever grant the restaurant role it was sent for — never an administrator role.
 *
 * Authorization of the caller (restaurant.staff.manage for the whole organization) is checked before any of
 * these methods are reached.
 */
final class RestaurantStaffService
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly AccessControl $access,
        private readonly AuditRecorder $audit,
        private readonly SecurityEventRecorder $events,
        private readonly AccountStatusService $statuses,
        private readonly TokenIssuer $tokens,
    ) {}

    /**
     * @param  Collection<int, RestaurantLocation>  $locations  ignored when $allLocations is true
     */
    public function invite(RestaurantOrganization $organization, string $name, string $email, Role $role, bool $allLocations, Collection $locations, RestaurantUser|AdminUser $actor): RestaurantMembership
    {
        $email = StaffLoginService::normaliseEmail($email);
        $this->assertRestaurantRole($role);
        $this->assertMayGrant($actor, $organization, $role);
        $this->assertLocations($organization, $allLocations, $locations);

        return DB::transaction(function () use ($organization, $name, $email, $role, $allLocations, $locations, $actor): RestaurantMembership {
            $user = RestaurantUser::query()->where('email', $email)->lockForUpdate()->first();

            if ($user !== null && $user->status === StaffStatus::Disabled) {
                throw ValidationException::withMessages(['email' => ['This account has been closed and cannot be invited.']]);
            }
            if ($user === null) {
                $user = (new RestaurantUser)->forceFill(['name' => PlainText::clean($name, 'name'), 'email' => $email, 'password' => null, 'status' => StaffStatus::Invited]);
                $user->save();
            }

            $membership = RestaurantMembership::query()->where('restaurant_user_id', $user->getKey())->where('organization_id', $organization->getKey())->lockForUpdate()->first();
            if ($membership !== null && $membership->status !== MembershipStatus::Revoked) {
                throw ValidationException::withMessages(['email' => ['This person is already a member of the restaurant or has an open invitation.']]);
            }

            $membership ??= (new RestaurantMembership)->forceFill(['restaurant_user_id' => $user->getKey(), 'organization_id' => $organization->getKey()]);
            $membership->forceFill([
                'role_id' => $role->getKey(),
                'status' => MembershipStatus::Invited,
                'all_locations' => $allLocations,
                'invited_name' => PlainText::clean($name, 'name'),
                'invited_by_restaurant_user_id' => $actor instanceof RestaurantUser ? $actor->getKey() : null,
                'invited_by_admin_id' => $actor instanceof AdminUser ? $actor->getKey() : null,
                'accepted_at' => null,
                'revoked_at' => null,
                'version' => (int) ($membership->version ?? 0) + 1,
            ])->save();
            $membership->locations()->sync($allLocations ? [] : $this->keys($locations));

            $this->audit->record('restaurant_staff.invited', $membership, $actor, [
                'member' => ['from' => null, 'to' => $membership->invited_name],
                'role' => ['from' => null, 'to' => $role->code],
                'locations' => ['from' => null, 'to' => $allLocations ? 'ALL' : $locations->pluck('name')->all()],
            ], null, (int) $organization->primary_market_id);
            $this->sendInvitation($membership, $user);

            return $membership;
        });
    }

    public function resendInvitation(RestaurantMembership $membership): void
    {
        if ($membership->status !== MembershipStatus::Invited) {
            throw ApiException::conflict('not_invited', 'This invitation has already been accepted or withdrawn.');
        }

        $membership->loadMissing('user');
        $this->sendInvitation($membership, $membership->user);
    }

    /**
     * The invited person accepts. A new account chooses its password here; an existing account just joins.
     * A wrong, used or expired link — or one for a membership that is no longer waiting — says the same thing.
     */
    public function acceptInvitation(#[SensitiveParameter] string $token, #[SensitiveParameter] ?string $password): RestaurantMembership
    {
        return DB::transaction(function () use ($token, $password): RestaurantMembership {
            $invitation = RestaurantStaffInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $membership = $invitation === null ? null : RestaurantMembership::query()->with(['user', 'organization', 'role'])->whereKey($invitation->membership_id)->lockForUpdate()->first();
            $user = $membership?->user;

            if ($invitation === null || $invitation->used_at !== null || $invitation->expires_at->isPast() || $membership === null
                || $membership->status !== MembershipStatus::Invited || ! in_array($user->status, [StaffStatus::Invited, StaffStatus::Active], true)) {
                throw new ApiException(422, 'invitation_invalid', 'This invitation link is invalid or has expired. Ask the restaurant to send a new one.');
            }

            if ($user->status === StaffStatus::Invited) {
                if ($password === null || $password === '') {
                    throw ValidationException::withMessages(['password' => ['Choose a password to activate your account.']]);
                }
                $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'email_verified_at' => now()])->save();
                $this->events->record(SecurityEventType::PasswordChanged, $user, ['via' => 'invitation']);
                $this->statuses->change($user, StaffStatus::Active, $user, 'Invitation accepted');
            }

            RestaurantStaffInvitation::query()->where('membership_id', $membership->getKey())->whereNull('used_at')->update(['used_at' => now()]);
            $membership->forceFill(['status' => MembershipStatus::Active, 'accepted_at' => now(), 'version' => (int) $membership->version + 1])->save();
            $this->sync($membership);

            $this->audit->record('restaurant_staff.activated', $membership, $user, ['status' => ['from' => 'INVITED', 'to' => 'ACTIVE']], 'Invitation accepted', (int) $membership->organization->primary_market_id);

            return $membership;
        });
    }

    /**
     * Role, location access and status of a member, changed together or not at all.
     *
     * @param  array{version: int, role?: Role, all_locations?: bool, locations?: Collection<int, RestaurantLocation>, status?: MembershipStatus, reason?: string|null}  $input
     */
    public function update(RestaurantMembership $membership, array $input, RestaurantUser $actor): RestaurantMembership
    {
        $membership->loadMissing(['organization', 'role', 'user']);
        $organization = $membership->organization;
        $this->assertNotSelf($membership, $actor);
        $this->assertMayGrant($actor, $organization, $membership->role);

        if (isset($input['role'])) {
            $this->assertRestaurantRole($input['role']);
            $this->assertMayGrant($actor, $organization, $input['role']);
        }
        if (isset($input['status']) && ! in_array($input['status'], [MembershipStatus::Active, MembershipStatus::Suspended], true)) {
            throw ValidationException::withMessages(['status' => ['A membership can be suspended or reactivated here. Use revoke to remove it.']]);
        }

        return DB::transaction(function () use ($membership, $organization, $input, $actor): RestaurantMembership {
            $this->serialise($organization);
            $locked = RestaurantMembership::query()->with(['role', 'locations', 'user'])->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);
            $hadOwner = $this->hasOwner($organization);

            if ($locked->status === MembershipStatus::Revoked) {
                throw ApiException::conflict('membership_revoked', 'This membership has been revoked. Invite the person again to give access back.');
            }

            $changes = [];
            $role = $input['role'] ?? $locked->role;
            if (! $role->is($locked->role)) {
                $changes['role'] = ['from' => $locked->role->code, 'to' => $role->code];
            }

            $all = $input['all_locations'] ?? $locked->all_locations;
            $locations = $all ? collect() : ($input['locations'] ?? $locked->locations);
            if (array_key_exists('all_locations', $input) || array_key_exists('locations', $input)) {
                $this->assertLocations($organization, $all, $locations);
                $before = $locked->all_locations ? 'ALL' : $locked->locations->pluck('name')->sort()->values()->all();
                $after = $all ? 'ALL' : $locations->pluck('name')->sort()->values()->all();
                if ($before !== $after) {
                    $changes['locations'] = ['from' => $before, 'to' => $after];
                }
            }

            $status = $input['status'] ?? $locked->status;
            if ($status !== $locked->status) {
                if ($locked->status === MembershipStatus::Invited) {
                    throw ApiException::conflict('not_accepted', 'This invitation has not been accepted yet. Withdraw it by revoking the membership.');
                }
                $changes['status'] = ['from' => $locked->status->value, 'to' => $status->value];
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->forceFill(['role_id' => $role->getKey(), 'all_locations' => $all, 'status' => $status, 'version' => (int) $locked->version + 1])->save();
            $locked->locations()->sync($all ? [] : $this->keys($locations));
            $locked->setRelation('role', $role)->unsetRelation('locations');

            $this->assertOwnerRemains($organization, $hadOwner);
            $this->sync($locked);

            $action = match (true) {
                isset($changes['role']) => 'restaurant_staff.role_changed',
                isset($changes['status']) => $status === MembershipStatus::Suspended ? 'restaurant_staff.suspended' : 'restaurant_staff.reactivated',
                default => 'restaurant_staff.access_changed',
            };
            $this->audit->record($action, $locked, $actor, $changes, $input['reason'] ?? null, (int) $organization->primary_market_id);

            return $locked;
        });
    }

    /**
     * Removes a member's access to the organization. Their role assignments go at once; when it was their only
     * restaurant, their open sessions are ended as well. The row stays for history.
     */
    public function revoke(RestaurantMembership $membership, RestaurantUser|AdminUser $actor, ?string $reason = null): RestaurantMembership
    {
        $membership->loadMissing(['organization', 'role', 'user']);
        $organization = $membership->organization;

        if ($actor instanceof RestaurantUser) {
            $this->assertNotSelf($membership, $actor);
            $this->assertMayGrant($actor, $organization, $membership->role);
        }

        return DB::transaction(function () use ($membership, $organization, $actor, $reason): RestaurantMembership {
            $this->serialise($organization);
            $locked = RestaurantMembership::query()->with(['role', 'user'])->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === MembershipStatus::Revoked) {
                return $locked;
            }

            $from = $locked->status;
            $hadOwner = $this->hasOwner($organization);
            $locked->forceFill(['status' => MembershipStatus::Revoked, 'revoked_at' => now(), 'version' => (int) $locked->version + 1])->save();
            RestaurantStaffInvitation::query()->where('membership_id', $locked->getKey())->whereNull('used_at')->update(['used_at' => now()]);

            $this->assertOwnerRemains($organization, $hadOwner);
            $this->sync($locked);

            $user = $locked->user;
            $other = RestaurantMembership::query()->where('restaurant_user_id', $user->getKey())->where('status', MembershipStatus::Active->value)->exists();
            $sessions = $other ? 0 : $this->tokens->revokeAll($user);
            if ($sessions > 0) {
                $this->events->record(SecurityEventType::SessionRevoked, $user, ['reason' => 'membership_revoked', 'sessions_revoked' => $sessions]);
            }

            $this->audit->record('restaurant_staff.revoked', $locked, $actor, ['status' => ['from' => $from->value, 'to' => 'REVOKED']], $reason, (int) $organization->primary_market_id);

            return $locked;
        });
    }

    /**
     * Makes the member's role assignments exactly what the membership says: nothing unless it is ACTIVE, the
     * organization scope for "all locations", otherwise one assignment per listed location. Changing them
     * through RoleService invalidates the permission cache and records the security events.
     */
    public function sync(RestaurantMembership $membership): void
    {
        $membership->loadMissing(['organization', 'user', 'role']);
        $organization = $membership->organization;
        $user = $membership->user;
        $locationIds = RestaurantLocation::query()->where('restaurant_locations.organization_id', $organization->getKey())->pluck('public_id', 'id');

        $desired = [];
        if ($membership->status->grantsAccess()) {
            if ($membership->all_locations) {
                $desired['organization:'.$organization->public_id] = Scope::organization($organization->public_id);
            } else {
                foreach ($membership->locations()->pluck('restaurant_locations.id') as $id) {
                    $desired['location:'.$locationIds[$id]] = Scope::location($locationIds[$id], $organization->public_id);
                }
            }
        }

        $existing = RoleAssignment::query()->with('role')
            ->where('principal_type', PrincipalType::RestaurantUser->morphAlias())->where('principal_id', $user->getKey())
            ->where(fn ($scope) => $scope
                ->where(fn ($org) => $org->where('scope_type', 'organization')->where('scope_id', $organization->public_id))
                ->orWhere(fn ($loc) => $loc->where('scope_type', 'location')->whereIn('scope_id', $locationIds->values()->all())))
            ->get();

        foreach ($existing as $assignment) {
            $key = $assignment->scope_type.':'.$assignment->scope_id;
            if (isset($desired[$key]) && (int) $assignment->role_id === (int) $membership->role_id) {
                unset($desired[$key]);

                continue;
            }
            $this->roles->revoke($user, $assignment->role, new Scope($assignment->scope_type, $assignment->scope_id));
        }

        foreach ($desired as $scope) {
            $this->roles->assign($user, $membership->role, $scope);
        }
    }

    /**
     * Whether the organization still has an active member who can manage its staff across all locations.
     */
    public function hasOwner(RestaurantOrganization $organization): bool
    {
        return RestaurantMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->where('all_locations', true)
            ->whereExists(fn ($user) => $user->selectRaw('1')->from('restaurant_users')->whereColumn('restaurant_users.id', 'restaurant_memberships.restaurant_user_id')->where('restaurant_users.status', StaffStatus::Active->value))
            ->whereExists(fn ($permission) => $permission->selectRaw('1')->from('role_permissions')->whereColumn('role_permissions.role_id', 'restaurant_memberships.role_id')->where('role_permissions.permission', Permission::RestaurantStaffManage->value))
            ->exists();
    }

    private function sendInvitation(RestaurantMembership $membership, RestaurantUser $user): void
    {
        $token = Str::random(64);
        $hours = (int) config('auth_security.invitation_ttl_hours');

        RestaurantStaffInvitation::query()->where('membership_id', $membership->getKey())->whereNull('used_at')->update(['used_at' => now()]);
        RestaurantStaffInvitation::query()->create(['membership_id' => $membership->getKey(), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours($hours), 'created_at' => now()]);

        $membership->loadMissing('organization');
        $user->notify(new RestaurantStaffInvitationNotification(
            rtrim((string) config('app.frontend_url'), '/').'/restaurant-dashboard/accept-invitation#'.$token, $hours, $membership->organization->display_name, $user->status === StaffStatus::Invited,
        ));
    }

    private function assertRestaurantRole(Role $role): void
    {
        if ($role->principal_type !== PrincipalType::RestaurantUser) {
            throw ValidationException::withMessages(['role' => ['This is not a restaurant role.']]);
        }
    }

    /**
     * A restaurant user may only hand out (or take charge of) a role whose every permission they hold
     * themselves for the whole organization. Administrators bootstrap the first owner and are not bound by it.
     */
    private function assertMayGrant(RestaurantUser|AdminUser $actor, RestaurantOrganization $organization, Role $role): void
    {
        if ($actor instanceof AdminUser) {
            return;
        }

        foreach ($role->permissions()->pluck('permission') as $code) {
            $permission = Permission::tryFrom($code);
            if ($permission === null || ! $this->access->allows($actor, $permission, $organization->scope())) {
                throw new ApiException(403, 'cannot_grant_beyond_own_permissions', 'You cannot give or change a role that includes permissions you do not hold yourself.');
            }
        }
    }

    private function assertNotSelf(RestaurantMembership $membership, Principal $actor): void
    {
        if ($actor instanceof RestaurantUser && (int) $membership->restaurant_user_id === (int) $actor->getKey()) {
            throw ApiException::conflict('cannot_change_own_membership', 'You cannot change your own role, access or membership. Ask another owner.');
        }
    }

    /**
     * @param  Collection<int, RestaurantLocation>  $locations
     */
    private function assertLocations(RestaurantOrganization $organization, bool $allLocations, Collection $locations): void
    {
        if ($allLocations) {
            return;
        }
        if ($locations->isEmpty()) {
            throw ValidationException::withMessages(['location_ids' => ['Choose at least one location, or give access to all locations.']]);
        }
        if ($locations->contains(fn (RestaurantLocation $l): bool => (int) $l->organization_id !== (int) $organization->getKey())) {
            throw ValidationException::withMessages(['location_ids' => ['Every location must belong to this restaurant.']]);
        }
    }

    /**
     * Staff changes of one organization happen one at a time (row lock on the organization), so two owners
     * demoting each other at the same moment cannot both pass the last-owner check.
     */
    private function serialise(RestaurantOrganization $organization): void
    {
        RestaurantOrganization::query()->whereKey($organization->getKey())->lockForUpdate()->first();
    }

    /**
     * A change must not take the last owner away. An organization that had none before the change (a new one
     * whose first owner has not accepted yet) is not held to it.
     */
    private function assertOwnerRemains(RestaurantOrganization $organization, bool $hadOwner): void
    {
        if ($hadOwner && ! $this->hasOwner($organization)) {
            throw ApiException::conflict('last_owner', 'This restaurant must keep one active member who can manage its staff for all locations. Give that role to someone else first.');
        }
    }

    /**
     * @param  Collection<int, RestaurantLocation>  $locations
     * @return list<int>
     */
    private function keys(Collection $locations): array
    {
        return $locations->map(fn (RestaurantLocation $location): int => (int) $location->getKey())->values()->all();
    }
}
