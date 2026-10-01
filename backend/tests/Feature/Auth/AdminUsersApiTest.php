<?php

namespace Tests\Feature\Auth;

use App\Auth\Scope;
use App\Enums\Permission as P;
use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Notifications\AdminInvitationNotification;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Administrator accounts managed through the API: invitation, status, role — and the rules no permission overrides.
 */
class AdminUsersApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-new-passphrase';

    private AdminUser $super;

    private RoleService $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->roles = app(RoleService::class);
        $this->super = $this->adminWithRole('SUPER_ADMIN', ['name' => 'Root Admin']);
        Notification::fake();
    }

    private function role(string $code): Role
    {
        return Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function adminWithRole(string $code, array $attributes = [], ?Scope $scope = null): AdminUser
    {
        $admin = AdminUser::factory()->create($attributes);
        $this->roles->assign($admin, $this->role($code), $scope);

        return $admin;
    }

    private function invitationToken(AdminUser $admin): string
    {
        $token = null;
        Notification::assertSentTo($admin, AdminInvitationNotification::class, function (AdminInvitationNotification $n) use (&$token): bool {
            $token = substr($n->url(), strpos($n->url(), '#') + 1);

            return true;
        });

        return (string) $token;
    }

    public function test_the_list_shows_accounts_with_roles_and_mfa_state_and_nothing_secret(): void
    {
        $india = Market::factory()->india()->create();
        $this->adminWithRole('OPERATIONS_ADMIN', ['name' => 'Nina Patel', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_enabled_at' => now(), 'last_login_at' => '2026-09-30 10:00:00'], Scope::market($india->public_id));
        AdminUser::factory()->status(StaffStatus::Suspended)->create(['name' => 'Sam Reyes']);
        $this->actingAsPrincipal($this->super);

        $response = $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(3, 'data');
        $users = collect($response->json('data'))->keyBy('name');

        $this->assertSame(['Nina Patel', 'Root Admin', 'Sam Reyes'], $users->keys()->all());
        $this->assertSame([['code' => 'OPERATIONS_ADMIN', 'name' => 'Operations admin', 'market_id' => $india->public_id, 'market' => 'IN']], $users['Nina Patel']['roles']);
        $this->assertSame([true, false, 'ACTIVE'], [$users['Nina Patel']['mfa_enabled'], $users['Nina Patel']['is_self'], $users['Nina Patel']['status']]);
        $this->assertSame([true, 'SUPER_ADMIN', null], [$users['Root Admin']['is_self'], $users['Root Admin']['roles'][0]['code'], $users['Root Admin']['roles'][0]['market']]);
        $this->assertSame(['SUSPENDED', []], [$users['Sam Reyes']['status'], $users['Sam Reyes']['roles']]);
        $this->assertStringStartsWith('2026-09-30T10:00:00', $users['Nina Patel']['last_login_at']);
        foreach (['password', 'mfa_secret', 'JBSWY3DPEHPK3PXP', 'recovery', '"id":'.$this->super->id.','] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent(), $secret);
        }

        $this->assertSame(['Sam Reyes'], array_column($this->getJson('/api/v1/admin/users?filter[status]=SUSPENDED')->json('data'), 'name'));
        $this->assertSame(['Nina Patel'], array_column($this->getJson('/api/v1/admin/users?q=nina')->json('data'), 'name'));
        $this->assertSame([], $this->getJson('/api/v1/admin/users?q='.urlencode('%'))->json('data'));

        $roles = collect($this->getJson('/api/v1/admin/roles')->assertOk()->json('data'))->keyBy('code');
        $this->assertCount(7, $roles);
        $this->assertContains('admin.users.manage', $roles['SUPER_ADMIN']['permissions']);
        $this->assertNotContains('admin.users.manage', $roles['SUPPORT_ADMIN']['permissions']);
    }

    public function test_an_invitation_creates_an_inactive_account_and_the_invited_person_sets_the_password(): void
    {
        $this->actingAsPrincipal($this->super);

        $invited = $this->postJson('/api/v1/admin/users', ['name' => 'Priya Nair', 'email' => 'Priya.Nair@FoodOnTheGo.example', 'role' => 'SUPPORT_ADMIN'])
            ->assertCreated()->assertJson(['name' => 'Priya Nair', 'email' => 'priya.nair@foodonthego.example', 'status' => 'INVITED', 'mfa_enabled' => false, 'roles' => [['code' => 'SUPPORT_ADMIN', 'market' => null]]])->json();
        $admin = AdminUser::query()->where('public_id', $invited['id'])->firstOrFail();
        $this->assertNull($admin->password);
        $token = $this->invitationToken($admin);
        $this->assertSame(64, strlen($token));
        $this->assertSame(0, DB::table('credential_reset_tokens')->where('token_hash', $token)->count(), 'only a hash of the link is stored');

        // No password, no sign-in — and the ordinary reset flow does not activate an invited account.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertUnauthorized();
        $this->postJson('/api/v1/auth/admin/password/reset', ['token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('error.code', 'reset_token_invalid');

        $accept = fn (string $t, string $p = self::PASSWORD) => $this->postJson('/api/v1/auth/admin/invitation/accept', ['token' => $t, 'password' => $p, 'password_confirmation' => $p]);
        $accept(str_repeat('a', 64))->assertUnprocessable()->assertJsonPath('error.code', 'invitation_invalid');
        $accept($token, 'short')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $accept($token)->assertOk();
        $accept($token)->assertUnprocessable()->assertJsonPath('error.code', 'invitation_invalid');   // single use

        $this->assertSame(StaffStatus::Active, $admin->refresh()->status);
        $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertSame(['admin_user.invited', 'admin_user.activated'], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(AuditEvent::query()->get()));
    }

    public function test_invitations_are_validated_expire_and_can_be_resent(): void
    {
        $this->actingAsPrincipal($this->super);
        $post = fn (array $body) => $this->postJson('/api/v1/admin/users', $body);

        $post(['name' => 'X', 'email' => 'not-an-email', 'role' => 'SUPPORT_ADMIN'])->assertUnprocessable();
        $post(['name' => 'Dup', 'email' => strtoupper($this->super->email), 'role' => 'SUPPORT_ADMIN'])->assertUnprocessable()->assertJsonPath('error.details.fields.email.0', 'An administrator with this e-mail already exists.');
        $post(['name' => 'Ghost', 'email' => 'ghost@foodonthego.example', 'role' => 'OWNER'])->assertUnprocessable();   // a restaurant role is not an admin role
        $post(['name' => 'Ghost', 'email' => 'ghost@foodonthego.example', 'role' => 'SUPPORT_ADMIN', 'market_id' => '0a000000-0000-4000-8000-00000000000a'])->assertUnprocessable();
        $this->assertSame(1, AdminUser::query()->count());

        $id = $post(['name' => 'Late Joiner', 'email' => 'late@foodonthego.example', 'role' => 'ANALYST'])->assertCreated()->json('id');
        $admin = AdminUser::query()->where('public_id', $id)->firstOrFail();
        $first = $this->invitationToken($admin);

        $this->travel(73)->hours();
        $this->postJson('/api/v1/auth/admin/invitation/accept', ['token' => $first, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('error.code', 'invitation_invalid');

        Notification::fake();
        $this->postJson("/api/v1/admin/users/{$id}/invitation")->assertOk();
        $second = $this->invitationToken($admin);
        $this->assertNotSame($first, $second);
        $this->postJson('/api/v1/auth/admin/invitation/accept', ['token' => $second, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->postJson("/api/v1/admin/users/{$id}/invitation")->assertConflict()->assertJsonPath('error.code', 'not_invited');
    }

    public function test_suspending_ends_access_at_once_and_status_changes_follow_the_rules(): void
    {
        $target = $this->adminWithRole('SUPPORT_ADMIN', ['name' => 'Lea Dubois']);
        $session = $this->bearer($target);
        $this->getJson('/api/v1/auth/me', $session)->assertOk();
        $url = "/api/v1/admin/users/{$target->public_id}/status";

        $this->actingAsPrincipal($this->super);
        $this->patchJson($url, ['status' => 'SUSPENDED'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'INVITED', 'reason' => 'Back to invited'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'SUSPENDED', 'reason' => 'Left the support team'])->assertOk()->assertJson(['status' => 'SUSPENDED']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $session)->assertUnauthorized();
        $this->assertSame(0, $target->tokens()->count());

        $this->actingAsPrincipal($this->super);
        $this->patchJson($url, ['status' => 'ACTIVE', 'reason' => 'Returned'])->assertOk()->assertJson(['status' => 'ACTIVE']);
        $this->patchJson($url, ['status' => 'DISABLED', 'reason' => 'Left the company'])->assertOk();
        $this->patchJson($url, ['status' => 'ACTIVE', 'reason' => 'Changed our mind'])->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');

        $events = AuditEvent::query()->where('action', 'admin_user.status_changed')->orderBy('id')->get();
        $this->assertSame([['ACTIVE', 'SUSPENDED'], ['SUSPENDED', 'ACTIVE'], ['ACTIVE', 'DISABLED']], $events->map(fn (AuditEvent $e) => [$e->changes['status']['from'], $e->changes['status']['to']])->all());
        $this->assertSame(['Left the support team', $this->super->public_id, $target->public_id, 'admin_users'], [$events[0]->reason, $events[0]->actor_public_id, $events[0]->target_public_id, $events[0]->target_type]);
        $this->assertSame('Lea Dubois', collect($this->getJson('/api/v1/admin/audit-events?filter[target_type]=admin_users')->json('data'))->first()['target_label']);
    }

    public function test_a_role_change_takes_effect_at_once_and_replaces_the_previous_role(): void
    {
        $india = Market::factory()->india()->create();
        $target = $this->adminWithRole('SUPPORT_ADMIN');
        $session = $this->bearer($target);
        $this->getJson('/api/v1/admin/markets', $session)->assertForbidden();

        $this->actingAsPrincipal($this->super);
        $url = "/api/v1/admin/users/{$target->public_id}/role";
        $this->putJson($url, ['role' => 'OPERATIONS_ADMIN', 'market_id' => $india->public_id])->assertUnprocessable();
        $this->putJson($url, ['role' => 'NOPE', 'reason' => 'Unknown role'])->assertUnprocessable();
        $this->putJson($url, ['role' => 'OPERATIONS_ADMIN', 'market_id' => $india->public_id, 'reason' => 'Moves to India operations'])
            ->assertOk()->assertJson(['roles' => [['code' => 'OPERATIONS_ADMIN', 'market' => 'IN']]])->assertJsonCount(1, 'roles');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/markets', $session)->assertOk()->assertJsonCount(1, 'data');   // same session, new permissions

        $event = AuditEvent::query()->where('action', 'admin_user.role_changed')->sole();
        $this->assertSame([['SUPPORT_ADMIN'], ['OPERATIONS_ADMIN (IN)'], 'Moves to India operations'], [$event->changes['role']['from'], $event->changes['role']['to'], $event->reason]);

        // Sending the same role again changes nothing and writes nothing.
        $this->actingAsPrincipal($this->super);
        $this->putJson($url, ['role' => 'OPERATIONS_ADMIN', 'market_id' => $india->public_id, 'reason' => 'Again'])->assertOk();
        $this->assertSame(1, AuditEvent::query()->where('action', 'admin_user.role_changed')->count());
    }

    public function test_nobody_changes_their_own_account_or_removes_the_last_full_administrator(): void
    {
        $this->actingAsPrincipal($this->super);
        $own = "/api/v1/admin/users/{$this->super->public_id}";
        $this->patchJson($own.'/status', ['status' => 'SUSPENDED', 'reason' => 'Self'])->assertConflict()->assertJsonPath('error.code', 'cannot_change_own_account');
        $this->putJson($own.'/role', ['role' => 'ANALYST', 'reason' => 'Self'])->assertConflict()->assertJsonPath('error.code', 'cannot_change_own_account');

        // An account manager who cannot manage roles must not be able to remove the only full administrator.
        $manager = AdminUser::factory()->create();
        $this->roles->assign($manager, $this->roles->define(PrincipalType::AdminUser, 'ACCOUNT_MANAGER', 'Account manager', [P::AdminUsersView, P::AdminUsersManage]));
        $this->actingAsPrincipal($manager);
        $this->patchJson($own.'/status', ['status' => 'SUSPENDED', 'reason' => 'Coup'])->assertConflict()->assertJsonPath('error.code', 'last_administrator');
        $this->patchJson($own.'/status', ['status' => 'DISABLED', 'reason' => 'Coup'])->assertConflict()->assertJsonPath('error.code', 'last_administrator');
        $this->assertSame(StaffStatus::Active, $this->super->refresh()->status);

        // With a second full administrator the first one can be suspended.
        $this->adminWithRole('SUPER_ADMIN');
        $this->patchJson($own.'/status', ['status' => 'SUSPENDED', 'reason' => 'Handover done'])->assertOk();
    }

    public function test_a_role_cannot_be_granted_by_someone_who_does_not_hold_its_permissions(): void
    {
        $delegate = AdminUser::factory()->create();
        $this->roles->assign($delegate, $this->roles->define(PrincipalType::AdminUser, 'DELEGATE', 'Delegate', [P::AdminUsersView, P::AdminUsersManage, P::AdminRolesView, P::AdminRolesManage, P::AdminSupportView, P::AdminAnalyticsView]));
        $this->roles->define(PrincipalType::AdminUser, 'HELPDESK', 'Helpdesk', [P::AdminSupportView]);
        $target = $this->adminWithRole('ANALYST');
        $this->actingAsPrincipal($delegate);

        $this->postJson('/api/v1/admin/users', ['name' => 'Sneaky', 'email' => 'sneaky@foodonthego.example', 'role' => 'SUPER_ADMIN'])->assertForbidden()->assertJsonPath('error.code', 'cannot_grant_beyond_own_permissions');
        $this->putJson("/api/v1/admin/users/{$target->public_id}/role", ['role' => 'SUPER_ADMIN', 'reason' => 'Promote'])->assertForbidden()->assertJsonPath('error.code', 'cannot_grant_beyond_own_permissions');
        $this->putJson("/api/v1/admin/users/{$target->public_id}/role", ['role' => 'FINANCE_ADMIN', 'reason' => 'Promote'])->assertForbidden();
        $this->assertSame(0, AdminUser::query()->where('email', 'sneaky@foodonthego.example')->count());
        $this->assertSame(['ANALYST'], $target->roleAssignments()->with('role')->get()->pluck('role.code')->all());

        $this->putJson("/api/v1/admin/users/{$target->public_id}/role", ['role' => 'HELPDESK', 'reason' => 'Within my own permissions'])->assertOk();
        $this->postJson('/api/v1/admin/users', ['name' => 'Helper', 'email' => 'helper@foodonthego.example', 'role' => 'HELPDESK'])->assertCreated();
    }

    public function test_permissions_are_required_platform_wide_on_every_endpoint(): void
    {
        $india = Market::factory()->india()->create();
        $target = $this->adminWithRole('ANALYST');
        $u = "/api/v1/admin/users/{$target->public_id}";
        $endpoints = [
            ['GET', '/api/v1/admin/users', []], ['GET', '/api/v1/admin/roles', []],
            ['POST', '/api/v1/admin/users', ['name' => 'New', 'email' => 'new@foodonthego.example', 'role' => 'ANALYST']],
            ['PATCH', $u.'/status', ['status' => 'SUSPENDED', 'reason' => 'Test']], ['PUT', $u.'/role', ['role' => 'ANALYST', 'reason' => 'Test']], ['POST', $u.'/invitation', []],
        ];

        foreach ($endpoints as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertUnauthorized();
        }
        foreach ([Customer::factory()->create(), RestaurantUser::factory()->create()] as $principal) {
            $headers = $this->bearer($principal);
            foreach ($endpoints as [$method, $url, $body]) {
                $this->json($method, $url, $body, $headers)->assertUnauthorized();
            }
        }

        $viewer = AdminUser::factory()->create();
        $this->roles->assign($viewer, $this->roles->define(PrincipalType::AdminUser, 'USER_VIEWER', 'User viewer', [P::AdminUsersView, P::AdminRolesView]));
        $scoped = AdminUser::factory()->create();
        $this->roles->assign($scoped, $this->role('SUPER_ADMIN'), Scope::market($india->public_id));

        $this->actingAsPrincipal($viewer);
        foreach ($endpoints as [$method, $url, $body]) {
            $method === 'GET' ? $this->json($method, $url, $body)->assertOk() : $this->json($method, $url, $body)->assertForbidden();
        }
        // Even every permission, held for one market only, opens nothing here.
        $this->actingAsPrincipal($scoped);
        foreach ($endpoints as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden();
        }

        $this->assertSame(StaffStatus::Active, $target->refresh()->status);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->getJson('/api/v1/admin/users/'.$target->id.'/status')->assertNotFound();
    }
}
