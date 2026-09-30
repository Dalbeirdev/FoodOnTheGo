<?php

namespace Tests\Feature\Foundation;

use App\Auth\AccessControl;
use App\Auth\Scope;
use App\Enums\Permission;
use App\Models\Market;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The pattern every protected endpoint is tested with: unauthenticated (401), wrong security context (403),
 * right context without the permission (403), permitted (200).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private AccessControl $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = app(AccessControl::class);
        Market::factory()->india()->create();
        Market::factory()->count(3)->create();
    }

    public function test_unauthenticated_request_is_401(): void
    {
        $this->getJson('/api/v1/admin/markets')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_customer_and_restaurant_tokens_cannot_enter_the_admin_context(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/markets')->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        Sanctum::actingAs(User::factory()->restaurantUser()->create());
        $this->getJson('/api/v1/admin/markets')->assertForbidden();
    }

    public function test_an_admin_without_the_permission_is_forbidden_admins_are_not_super_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $this->access->grant($admin, Permission::AdminReviewsModerate);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/markets')->assertForbidden();
    }

    public function test_an_admin_holding_the_permission_is_allowed_and_sees_every_market_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->access->grant($admin, Permission::AdminMarketsView);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/markets')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_a_permission_cannot_be_used_outside_its_principal_type_even_if_a_grant_row_exists(): void
    {
        $customer = User::factory()->create();
        $this->access->grant($customer, Permission::AdminMarketsManage);

        $this->assertFalse($customer->can(Permission::AdminMarketsManage->value));
    }

    public function test_restaurant_permissions_are_isolated_to_the_granted_organization_or_location(): void
    {
        $staff = User::factory()->restaurantUser()->create();
        $ownLocation = Scope::location((string) Str::uuid());
        $otherLocation = Scope::location((string) Str::uuid());
        $this->access->grant($staff, Permission::RestaurantOrdersView, $ownLocation);

        $this->assertTrue($staff->can(Permission::RestaurantOrdersView->value, $ownLocation));
        $this->assertFalse($staff->can(Permission::RestaurantOrdersView->value, $otherLocation));
        $this->assertFalse($staff->can(Permission::RestaurantOrdersView->value), 'a check without a scope must be denied');
        $this->assertFalse($staff->can(Permission::RestaurantMenuManage->value, $ownLocation));
    }

    public function test_an_unscoped_grant_never_gives_a_restaurant_user_platform_wide_access(): void
    {
        $staff = User::factory()->restaurantUser()->create();
        $this->access->grant($staff, Permission::RestaurantOrdersView);

        $this->assertFalse($staff->can(Permission::RestaurantOrdersView->value, Scope::location((string) Str::uuid())));
    }

    public function test_admin_permissions_can_be_limited_to_a_market(): void
    {
        $india = Scope::market(Market::query()->where('country_code', 'IN')->value('public_id'));
        $other = Scope::market((string) Str::uuid());
        $admin = User::factory()->admin()->create();
        $this->access->grant($admin, Permission::AdminRefundsIssue, $india);

        $this->assertTrue($admin->can(Permission::AdminRefundsIssue->value, $india));
        $this->assertFalse($admin->can(Permission::AdminRefundsIssue->value, $other));
        $this->assertFalse($admin->can(Permission::AdminRefundsIssue->value));
    }

    public function test_principal_type_cannot_be_mass_assigned(): void
    {
        $user = User::query()->create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'Secret123', 'principal_type' => 'ADMIN_USER']);

        $this->assertTrue($user->fresh()->isCustomer());
    }

    public function test_collection_endpoints_share_one_pagination_filter_and_sort_convention(): void
    {
        $admin = User::factory()->admin()->create();
        $this->access->grant($admin, Permission::AdminMarketsView);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/markets?page[size]=2&page[number]=2&sort=-country_code')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'country_code', 'status']], 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2);

        $this->getJson('/api/v1/admin/markets?filter[status]=ACTIVE')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.country_code', 'IN');

        $this->getJson('/api/v1/admin/markets?page[size]=100000')->assertOk()->assertJsonPath('meta.per_page', config('api.pagination.max_size'));

        $this->getJson('/api/v1/admin/markets?filter[features]=x')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->getJson('/api/v1/admin/markets?sort=public_id')->assertUnprocessable();
    }
}
