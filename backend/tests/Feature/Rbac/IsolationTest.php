<?php

namespace Tests\Feature\Rbac;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Insecure-direct-object-reference patterns. The business resources arrive in later modules, so protected
 * stand-in routes are registered here; they are authorised exactly the way real ones must be:
 *
 *   restaurant: Gate::authorize(<permission>, Scope::location($location, $organization))
 *   customer:   Gate::authorize('own', $record)
 *   admin:      can:<permission>
 */
class IsolationTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_A = '0a000000-0000-4000-8000-00000000000a';

    private const ORG_B = '0b000000-0000-4000-8000-00000000000b';

    private const LOC_A1 = '0a000000-0000-4000-8000-0000000000a1';

    private const LOC_A2 = '0a000000-0000-4000-8000-0000000000a2';

    private const LOC_B1 = '0b000000-0000-4000-8000-0000000000b1';

    /** Which organization owns which location — the lookup the restaurant module will do in the database. */
    private const OWNERSHIP = [self::LOC_A1 => self::ORG_A, self::LOC_A2 => self::ORG_A, self::LOC_B1 => self::ORG_B];

    private RoleService $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->roles = app(RoleService::class);

        Route::middleware(['api', 'auth:restaurant', 'active'])->prefix('api/v1/_test')->group(function (): void {
            Route::get('/locations/{location}/orders', function (string $location) {
                Gate::authorize(Permission::RestaurantOrdersView->value, Scope::location($location, self::OWNERSHIP[$location] ?? null));

                return ['location' => $location, 'orders' => []];
            });
            Route::post('/locations/{location}/menu', function (string $location) {
                Gate::authorize(Permission::RestaurantMenuManage->value, Scope::location($location, self::OWNERSHIP[$location] ?? null));

                return response()->json(['location' => $location], 201);
            });
        });

        Route::middleware(['api', 'auth:customer', 'active'])->get('/api/v1/_test/orders/{owner}', function (int $owner) {
            $order = new class extends Model
            {
                protected $guarded = [];
            };
            $order->customer_id = $owner;
            Gate::authorize('own', $order);

            return ['order' => 'FOTG-TEST', 'customer_id' => $owner];
        });

        Route::middleware(['api', 'auth:admin', 'active'])->post('/api/v1/_test/refunds', fn () => response()->json(['refund' => 'issued'], 201))
            ->middleware('can:'.Permission::AdminRefundsIssue->value);
    }

    private function staff(string $role, Scope $scope): RestaurantUser
    {
        $user = RestaurantUser::factory()->create();
        $this->roles->assign($user, Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', $role)->firstOrFail(), $scope);

        return $user;
    }

    public function test_restaurant_a_staff_cannot_read_restaurant_b_by_changing_the_id_in_the_url(): void
    {
        $ownerA = $this->bearer($this->staff('OWNER', Scope::organization(self::ORG_A)));

        $this->getJson('/api/v1/_test/locations/'.self::LOC_A1.'/orders', $ownerA)->assertOk();
        $this->getJson('/api/v1/_test/locations/'.self::LOC_A2.'/orders', $ownerA)->assertOk();

        $this->getJson('/api/v1/_test/locations/'.self::LOC_B1.'/orders', $ownerA)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->postJson('/api/v1/_test/locations/'.self::LOC_B1.'/menu', [], $ownerA)->assertForbidden();
        $this->getJson('/api/v1/_test/locations/'.Str::uuid().'/orders', $ownerA)->assertForbidden();
    }

    public function test_staff_limited_to_one_location_cannot_open_a_sibling_location(): void
    {
        $orderStaff = $this->bearer($this->staff('ORDER_STAFF', Scope::location(self::LOC_A1)));

        $this->getJson('/api/v1/_test/locations/'.self::LOC_A1.'/orders', $orderStaff)->assertOk();
        $this->getJson('/api/v1/_test/locations/'.self::LOC_A2.'/orders', $orderStaff)->assertForbidden();
        $this->getJson('/api/v1/_test/locations/'.self::LOC_B1.'/orders', $orderStaff)->assertForbidden();
    }

    public function test_being_in_the_right_restaurant_is_not_enough_without_the_permission(): void
    {
        $orderStaff = $this->bearer($this->staff('ORDER_STAFF', Scope::location(self::LOC_A1)));

        $this->postJson('/api/v1/_test/locations/'.self::LOC_A1.'/menu', [], $orderStaff)->assertForbidden();

        $menuManager = $this->bearer($this->staff('MENU_MANAGER', Scope::organization(self::ORG_A)));
        $this->postJson('/api/v1/_test/locations/'.self::LOC_A1.'/menu', [], $menuManager)->assertCreated();
    }

    public function test_scope_sent_by_the_client_is_ignored(): void
    {
        $ownerA = $this->bearer($this->staff('OWNER', Scope::organization(self::ORG_A)));

        $this->getJson('/api/v1/_test/locations/'.self::LOC_B1.'/orders?organization_id='.self::ORG_A.'&restaurant_id='.self::LOC_A1, $ownerA + ['X-Organization-Id' => self::ORG_A])
            ->assertForbidden();
        $this->postJson('/api/v1/_test/locations/'.self::LOC_B1.'/menu', ['organization_id' => self::ORG_A, 'scope_id' => self::ORG_A, 'role' => 'OWNER', 'permissions' => ['restaurant.menu.manage']], $ownerA)
            ->assertForbidden();
    }

    public function test_customers_and_admins_cannot_use_restaurant_routes(): void
    {
        $admin = AdminUser::factory()->create();
        $this->roles->assign($admin, Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->getJson('/api/v1/_test/locations/'.self::LOC_A1.'/orders', $this->bearer($admin))->assertUnauthorized();
        $this->getJson('/api/v1/_test/locations/'.self::LOC_A1.'/orders', $this->bearer(Customer::factory()->create()))->assertUnauthorized();
        $this->getJson('/api/v1/_test/locations/'.self::LOC_A1.'/orders')->assertUnauthorized();
    }

    public function test_customer_a_cannot_read_customer_b_resources(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        $this->getJson('/api/v1/_test/orders/'.$a->id, $this->bearer($a))->assertOk();
        $this->getJson('/api/v1/_test/orders/'.$b->id, $this->bearer($a))->assertForbidden();

        $staff = $this->staff('OWNER', Scope::organization(self::ORG_A));
        $this->getJson('/api/v1/_test/orders/'.$a->id, $this->bearer($staff))->assertUnauthorized();
    }

    public function test_an_admin_without_the_finance_permission_cannot_issue_a_refund(): void
    {
        $support = AdminUser::factory()->create();
        $this->roles->assign($support, Role::query()->where('code', 'SUPPORT_ADMIN')->firstOrFail());
        $finance = AdminUser::factory()->create();
        $this->roles->assign($finance, Role::query()->where('code', 'FINANCE_ADMIN')->firstOrFail());

        $this->postJson('/api/v1/_test/refunds', ['permissions' => ['admin.refunds.issue'], 'role' => 'FINANCE_ADMIN', 'is_admin' => true], $this->bearer($support))->assertForbidden();
        $this->postJson('/api/v1/_test/refunds', [], $this->bearer($finance))->assertCreated();
    }
}
