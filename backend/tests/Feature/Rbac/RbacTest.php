<?php

namespace Tests\Feature\Rbac;

use App\Auth\AccessControl;
use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Events\RoleAssignmentChanged;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private RoleService $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->roles = app(RoleService::class);
        Market::factory()->india()->create();
    }

    private function adminRole(string $code): Role
    {
        return Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail();
    }

    private function restaurantRole(string $code): Role
    {
        return Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', $code)->firstOrFail();
    }

    public function test_roles_are_permission_bundles_and_system_roles_exist_for_both_staff_contexts(): void
    {
        $this->assertEqualsCanonicalizing(['OWNER', 'MANAGER', 'ORDER_STAFF', 'MENU_MANAGER', 'VIEWER'], Role::query()->where('principal_type', 'RESTAURANT_USER')->pluck('code')->all());
        $this->assertEqualsCanonicalizing(
            ['SUPER_ADMIN', 'OPERATIONS_ADMIN', 'RESTAURANT_ONBOARDING', 'FINANCE_ADMIN', 'SUPPORT_ADMIN', 'MODERATION_ADMIN', 'ANALYST'],
            Role::query()->where('principal_type', 'ADMIN_USER')->pluck('code')->all(),
        );

        // Every stored permission is a catalogued one of the role's own principal type.
        foreach (Role::query()->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $this->assertSame($role->principal_type, Permission::from($permission->permission)->principalType());
            }
        }

        $this->assertFalse($this->restaurantRole('MANAGER')->permissions->contains('permission', Permission::RestaurantStaffManage->value));
    }

    public function test_a_permission_is_granted_only_through_a_role_and_absent_otherwise(): void
    {
        $finance = AdminUser::factory()->create();
        $moderation = AdminUser::factory()->create();
        $nobody = AdminUser::factory()->create();

        $this->roles->assign($finance, $this->adminRole('FINANCE_ADMIN'));
        $this->roles->assign($moderation, $this->adminRole('MODERATION_ADMIN'));

        $this->assertTrue($finance->can(Permission::AdminRefundsIssue->value));
        $this->assertFalse($finance->can(Permission::AdminReviewsModerate->value));

        $this->assertTrue($moderation->can(Permission::AdminReviewsModerate->value));
        $this->assertFalse($moderation->can(Permission::AdminRefundsIssue->value), 'moderation must not inherit finance permissions');

        $this->assertFalse($nobody->can(Permission::AdminRestaurantsView->value), 'an admin without a role has no access');
        $this->assertSame([], app(AccessControl::class)->permissionCodes($nobody));
    }

    public function test_super_admin_is_a_role_like_any_other_not_a_default(): void
    {
        $super = AdminUser::factory()->create();
        $this->roles->assign($super, $this->adminRole('SUPER_ADMIN'));

        foreach (Permission::for(PrincipalType::AdminUser) as $permission) {
            $this->assertTrue($super->can($permission->value));
        }
        $this->assertFalse($super->can(Permission::RestaurantMenuManage->value, Scope::organization((string) Str::uuid())), 'admin roles never carry restaurant permissions');
    }

    public function test_role_changes_take_effect_immediately_even_with_the_permission_cache_warm(): void
    {
        $admin = AdminUser::factory()->create();
        $support = $this->adminRole('SUPPORT_ADMIN');
        $finance = $this->adminRole('FINANCE_ADMIN');

        $this->roles->assign($admin, $support);
        $this->assertFalse($admin->can(Permission::AdminRefundsIssue->value));
        $this->assertTrue($admin->can(Permission::AdminSupportManage->value));

        $this->roles->assign($admin, $finance);
        $this->assertTrue($admin->can(Permission::AdminRefundsIssue->value), 'new role must apply without waiting for the cache');

        $this->roles->revoke($admin, $finance);
        $this->assertFalse($admin->can(Permission::AdminRefundsIssue->value), 'a revoked role must not linger in the cache');

        // Removing a permission from the role itself.
        $this->roles->define(PrincipalType::AdminUser, 'SUPPORT_ADMIN', 'Support admin', [Permission::AdminSupportView], system: true);
        $this->assertFalse($admin->can(Permission::AdminSupportManage->value));
        $this->assertTrue($admin->can(Permission::AdminSupportView->value));
    }

    public function test_changes_made_outside_the_service_also_invalidate_the_cache(): void
    {
        $admin = AdminUser::factory()->create();
        $role = $this->adminRole('ANALYST');
        $this->roles->assign($admin, $role);
        $this->assertTrue($admin->can(Permission::AdminAnalyticsView->value));

        $role->permissions()->where('permission', Permission::AdminAnalyticsView->value)->first()->delete();
        $this->assertFalse($admin->can(Permission::AdminAnalyticsView->value));

        $role->delete();
        $this->assertSame([], app(AccessControl::class)->permissionCodes($admin));
    }

    public function test_the_permission_cache_works_on_redis_and_is_invalidated_there_too(): void
    {
        config(['cache.default' => 'redis']);
        Cache::store('redis')->flush();
        $admin = AdminUser::factory()->create();

        $this->roles->assign($admin, $this->adminRole('ANALYST'));
        $this->assertTrue($admin->can(Permission::AdminAnalyticsView->value));
        $this->assertTrue($admin->can(Permission::AdminAnalyticsView->value));

        $this->roles->revoke($admin, $this->adminRole('ANALYST'));
        $this->assertFalse($admin->can(Permission::AdminAnalyticsView->value));
        Cache::store('redis')->flush();
    }

    public function test_an_admin_role_can_be_limited_to_a_market(): void
    {
        $india = Scope::market(Market::query()->where('country_code', 'IN')->value('public_id'));
        $future = Scope::market(Market::factory()->create(['country_code' => 'AE'])->public_id);
        $admin = AdminUser::factory()->create();

        $this->roles->assign($admin, $this->adminRole('OPERATIONS_ADMIN'), $india);

        $this->assertTrue($admin->can(Permission::AdminOrdersOverride->value, $india));
        $this->assertFalse($admin->can(Permission::AdminOrdersOverride->value, $future), 'an India-scoped admin cannot operate another market');
        $this->assertFalse($admin->can(Permission::AdminOrdersOverride->value), 'a market-scoped role is not platform-wide');

        $global = AdminUser::factory()->create();
        $this->roles->assign($global, $this->adminRole('OPERATIONS_ADMIN'));
        $this->assertTrue($global->can(Permission::AdminOrdersOverride->value, $future));
    }

    public function test_restaurant_roles_must_be_scoped_and_admin_roles_cannot_take_a_restaurant_scope(): void
    {
        $staff = RestaurantUser::factory()->create();
        $admin = AdminUser::factory()->create();

        foreach ([
            fn () => $this->roles->assign($staff, $this->restaurantRole('OWNER')),
            fn () => $this->roles->assign($staff, $this->restaurantRole('OWNER'), Scope::market((string) Str::uuid())),
            fn () => $this->roles->assign($staff, $this->adminRole('SUPER_ADMIN'), Scope::organization((string) Str::uuid())),
            fn () => $this->roles->assign($admin, $this->restaurantRole('OWNER'), Scope::organization((string) Str::uuid())),
            fn () => $this->roles->assign($admin, $this->adminRole('SUPER_ADMIN'), Scope::organization((string) Str::uuid())),
            fn () => $this->roles->define(PrincipalType::RestaurantUser, 'ROGUE', 'Rogue', [Permission::AdminUsersManage]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An invalid role assignment was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // The database refuses the same thing if application code is bypassed.
        $this->expectException(QueryException::class);
        DB::table('role_assignments')->insert(['role_id' => $this->restaurantRole('OWNER')->id, 'principal_type' => 'restaurant_user', 'principal_id' => $staff->id]);
    }

    public function test_customers_hold_no_permissions_and_cannot_be_given_roles(): void
    {
        $customer = Customer::factory()->create();

        $this->assertFalse($customer->can(Permission::AdminMarketsView->value));
        $this->assertFalse($customer->can(Permission::RestaurantOrdersView->value, Scope::organization((string) Str::uuid())));

        $this->expectException(QueryException::class);
        DB::table('role_assignments')->insert(['role_id' => $this->adminRole('SUPER_ADMIN')->id, 'principal_type' => 'customer', 'principal_id' => $customer->id]);
    }

    public function test_a_suspended_account_has_no_permissions_whatever_its_roles(): void
    {
        $admin = AdminUser::factory()->create();
        $this->roles->assign($admin, $this->adminRole('SUPER_ADMIN'));

        $admin->forceFill(['status' => 'SUSPENDED'])->save();

        $this->assertFalse($admin->fresh()->can(Permission::AdminUsersManage->value));
    }

    public function test_role_changes_are_recorded_and_raise_the_audit_hook(): void
    {
        Event::fake([RoleAssignmentChanged::class]);
        $actor = AdminUser::factory()->create();
        $admin = AdminUser::factory()->create();

        $this->roles->assign($admin, $this->adminRole('FINANCE_ADMIN'), grantedBy: $actor);
        $this->roles->revoke($admin, $this->adminRole('FINANCE_ADMIN'), actor: $actor);

        $events = SecurityEvent::query()->where('event', SecurityEventType::PermissionChanged)->orderBy('id')->get();
        $this->assertSame(['assigned', 'revoked'], $events->pluck('metadata.change')->all());
        $this->assertSame($actor->public_id, $events[0]->metadata['actor']);
        Event::assertDispatchedTimes(RoleAssignmentChanged::class, 2);
    }

    public function test_the_identity_endpoint_lists_roles_and_permissions_for_navigation(): void
    {
        $admin = AdminUser::factory()->create();
        $this->roles->assign($admin, $this->adminRole('MODERATION_ADMIN'));

        $this->getJson('/api/v1/auth/me', $this->bearer($admin))->assertOk()
            ->assertJsonPath('roles.0.code', 'MODERATION_ADMIN')
            ->assertJsonPath('roles.0.scope', null)
            ->assertJsonFragment(['permissions' => app(AccessControl::class)->permissionCodes($admin)]);

        $this->assertContains('admin.reviews.moderate', app(AccessControl::class)->permissionCodes($admin));
        $this->assertNotContains('admin.refunds.issue', app(AccessControl::class)->permissionCodes($admin));
    }

    public function test_protected_admin_endpoint_follows_the_401_403_200_pattern(): void
    {
        Market::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/markets')->assertUnauthorized();
        $this->getJson('/api/v1/admin/markets', $this->bearer(Customer::factory()->create()))->assertUnauthorized();
        $this->getJson('/api/v1/admin/markets', $this->bearer(RestaurantUser::factory()->create()))->assertUnauthorized();

        $moderator = AdminUser::factory()->create();
        $this->roles->assign($moderator, $this->adminRole('MODERATION_ADMIN'));
        $this->getJson('/api/v1/admin/markets', $this->bearer($moderator))->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $analyst = AdminUser::factory()->create();
        $this->roles->assign($analyst, $this->adminRole('ANALYST'));
        $this->getJson('/api/v1/admin/markets?page[size]=2&sort=-country_code&filter[status]=DRAFT', $this->bearer($analyst))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/markets?filter[features]=x', $this->bearer($analyst))->assertUnprocessable();
    }
}
