<?php

namespace App\Services\Auth;

use App\Auth\AccessControl;
use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Enums\StaffStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\CredentialResetToken;
use App\Models\Market;
use App\Models\Role;
use App\Notifications\AdminInvitationNotification;
use App\Services\Audit\AuditRecorder;
use App\Services\Rbac\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Administration of administrator accounts: invitation, status and role. Every change is authorised by the
 * controller (permission) and constrained here by rules that no permission overrides:
 *
 *  - nobody changes their own status or role (no self-lockout, no self-promotion);
 *  - a role can only be granted by someone who holds every permission in it, platform-wide (no granting
 *    more than you have);
 *  - the platform must always keep one active administrator who can manage accounts and roles;
 *  - an account is never deleted — it is DISABLED, which is final, and its sessions end immediately;
 *  - an invited account has no password: the invited person sets one through a single-use, expiring link
 *    and only then becomes ACTIVE. The inviting administrator never sees or chooses a password.
 *
 * Every change writes an audit event (who, what, why) next to the security events the underlying
 * services already record.
 */
final class AdminUserService
{
    private const STATUS_TRANSITIONS = [
        'INVITED' => ['DISABLED'],
        'ACTIVE' => ['SUSPENDED', 'DISABLED'],
        'SUSPENDED' => ['ACTIVE', 'DISABLED'],
        'DISABLED' => [],
    ];

    public function __construct(
        private readonly RoleService $roles,
        private readonly AccountStatusService $statuses,
        private readonly AccessControl $access,
        private readonly AuditRecorder $audit,
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function invite(string $name, string $email, Role $role, ?Market $market, AdminUser $actor): AdminUser
    {
        $email = StaffLoginService::normaliseEmail($email);
        if (AdminUser::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['An administrator with this e-mail already exists.']]);
        }
        $this->assertMayGrant($actor, $role);

        return DB::transaction(function () use ($name, $email, $role, $market, $actor): AdminUser {
            $admin = (new AdminUser)->forceFill(['name' => $name, 'email' => $email, 'password' => null, 'status' => StaffStatus::Invited]);
            $admin->save();
            $this->roles->assign($admin, $role, $market === null ? null : Scope::market($market->public_id), $actor, $actor);
            $this->audit->record('admin_user.invited', $admin, $actor, ['status' => ['from' => null, 'to' => 'INVITED'], 'role' => ['from' => null, 'to' => $this->describe($role, $market)]], null, $market?->getKey());
            $this->sendInvitation($admin);

            return $admin;
        });
    }

    public function resendInvitation(AdminUser $admin): void
    {
        if ($admin->status !== StaffStatus::Invited) {
            throw ApiException::conflict('not_invited', 'This account has already been activated or closed.');
        }

        $this->sendInvitation($admin);
    }

    /**
     * The invited person sets their password. The link is single use; a wrong, used or expired one says the
     * same thing.
     */
    public function acceptInvitation(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($token, $password): void {
            $record = CredentialResetToken::query()->where('token_hash', hash('sha256', $token))->where('principal_type', PrincipalType::AdminUser->morphAlias())->lockForUpdate()->first();
            $admin = $record === null ? null : AdminUser::query()->find($record->principal_id);

            if ($record === null || $record->used_at !== null || $record->expires_at->isPast() || $admin === null || $admin->status !== StaffStatus::Invited) {
                throw new ApiException(422, 'invitation_invalid', 'This invitation link is invalid or has expired. Ask an administrator to send a new one.');
            }

            CredentialResetToken::query()->where('principal_type', $record->principal_type)->where('principal_id', $record->principal_id)->whereNull('used_at')->update(['used_at' => now()]);
            $admin->forceFill(['password' => $password, 'password_changed_at' => now(), 'email_verified_at' => now()])->save();
            $this->events->record(SecurityEventType::PasswordChanged, $admin, ['via' => 'invitation']);
            $this->statuses->change($admin, StaffStatus::Active, $admin, 'Invitation accepted');
            $this->audit->record('admin_user.activated', $admin, $admin, ['status' => ['from' => 'INVITED', 'to' => 'ACTIVE']], 'Invitation accepted');
        });
    }

    public function changeStatus(AdminUser $admin, StaffStatus $status, string $reason, AdminUser $actor): AdminUser
    {
        $this->assertNotSelf($admin, $actor);

        return DB::transaction(function () use ($admin, $status, $reason, $actor): AdminUser {
            $locked = AdminUser::query()->whereKey($admin->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $status) {
                return $locked;
            }
            if (! in_array($status->value, self::STATUS_TRANSITIONS[$from->value], true)) {
                throw ApiException::conflict('invalid_status_transition', "An account that is {$from->value} cannot become {$status->value}.", ['from' => $from->value, 'allowed' => self::STATUS_TRANSITIONS[$from->value]]);
            }
            if (! $status->canAuthenticate()) {
                $this->assertNotLastAdministrator($locked);
            }

            $this->statuses->change($locked, $status, $actor, $reason);
            $this->audit->record('admin_user.status_changed', $locked, $actor, ['status' => ['from' => $from->value, 'to' => $status->value]], $reason);

            return $locked;
        });
    }

    /**
     * Makes the given role (platform-wide, or for one market) the account's only role.
     */
    public function changeRole(AdminUser $admin, Role $role, ?Market $market, string $reason, AdminUser $actor): AdminUser
    {
        $this->assertNotSelf($admin, $actor);
        $this->assertMayGrant($actor, $role);

        return DB::transaction(function () use ($admin, $role, $market, $reason, $actor): AdminUser {
            $current = $admin->roleAssignments()->with('role')->get();
            $before = $current->map(fn ($a): string => $a->role->code.($a->scope_type === null ? '' : " ({$a->scope_type})"))->sort()->values()->all();
            $target = $market === null ? null : Scope::market($market->public_id);

            if ($current->count() === 1 && $current[0]->role_id === $role->getKey() && $current[0]->scope_type === $target?->type && $current[0]->scope_id === $target?->id) {
                return $admin;
            }

            // Would this take account / role management away from the last administrator who has it?
            $keeps = $target === null && $role->permissions()->whereIn('permission', [Permission::AdminUsersManage->value, Permission::AdminRolesManage->value])->count() === 2;
            if (! $keeps && $admin->canAuthenticate()) {
                $this->assertNotLastAdministrator($admin);
            }

            foreach ($current as $assignment) {
                $this->roles->revoke($admin, $assignment->role, $assignment->scope_type === null ? null : new Scope($assignment->scope_type, $assignment->scope_id), $actor);
            }
            $this->roles->assign($admin, $role, $target, $actor, $actor);
            // A changed role takes effect at once; sessions stay, permissions are re-read on every request.
            $this->audit->record('admin_user.role_changed', $admin, $actor, ['role' => ['from' => $before, 'to' => [$this->describe($role, $market)]]], $reason, $market?->getKey());

            return $admin;
        });
    }

    private function sendInvitation(AdminUser $admin): void
    {
        $token = Str::random(64);
        $hours = (int) config('auth_security.invitation_ttl_hours');

        CredentialResetToken::query()->where('principal_type', PrincipalType::AdminUser->morphAlias())->where('principal_id', $admin->getKey())->whereNull('used_at')->update(['used_at' => now()]);
        CredentialResetToken::query()->create([
            'principal_type' => PrincipalType::AdminUser->morphAlias(), 'principal_id' => $admin->getKey(),
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours($hours), 'created_at' => now(),
        ]);

        $admin->notify(new AdminInvitationNotification(rtrim((string) config('app.frontend_url'), '/').'/admin/accept-invitation#'.$token, $hours));
    }

    private function describe(Role $role, ?Market $market): string
    {
        return $role->code.($market === null ? '' : ' ('.$market->country_code.')');
    }

    private function assertNotSelf(AdminUser $admin, AdminUser $actor): void
    {
        if ($admin->is($actor)) {
            throw ApiException::conflict('cannot_change_own_account', 'You cannot change your own status or role. Ask another administrator.');
        }
    }

    private function assertMayGrant(AdminUser $actor, Role $role): void
    {
        if ($role->principal_type !== PrincipalType::AdminUser) {
            throw ValidationException::withMessages(['role' => ['This is not an administrator role.']]);
        }

        foreach ($role->permissions()->pluck('permission') as $code) {
            $permission = Permission::tryFrom($code);
            if ($permission === null || ! $this->access->allows($actor, $permission)) {
                throw new ApiException(403, 'cannot_grant_beyond_own_permissions', 'You cannot grant a role that includes permissions you do not hold yourself.');
            }
        }
    }

    /**
     * Refuses to take away the last active administrator who can manage accounts and roles platform-wide.
     */
    private function assertNotLastAdministrator(AdminUser $leaving): void
    {
        $others = AdminUser::query()->where('status', StaffStatus::Active->value)->whereKeyNot($leaving->getKey())->get()
            ->filter(fn (AdminUser $a): bool => $this->access->allows($a, Permission::AdminUsersManage) && $this->access->allows($a, Permission::AdminRolesManage));
        $isOne = $leaving->canAuthenticate() && $this->access->allows($leaving, Permission::AdminUsersManage) && $this->access->allows($leaving, Permission::AdminRolesManage);

        if ($isOne && $others->isEmpty()) {
            throw ApiException::conflict('last_administrator', 'This is the last active administrator who can manage accounts and roles. Give that role to another administrator first.');
        }
    }
}
