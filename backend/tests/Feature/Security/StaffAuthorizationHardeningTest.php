<?php

namespace Tests\Feature\Security;

use App\Auth\AccessControl;
use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\Market;
use App\Services\Rbac\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 39 — the staff authorization findings, each pinned: an administrator cannot hand a live restaurant to a new
 * OWNER; an order's event stream needs the order permission; negotiated commission rates only for the markets where
 * the caller holds the commission permission; cached grants are invalidated only once the change is committed.
 */
class StaffAuthorizationHardeningTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_an_administrator_cannot_invite_a_second_owner_into_an_organization_that_has_one(): void
    {
        $this->member($this->organization, 'OWNER');
        $this->asActor($this->adminWith([Permission::AdminRestaurantsView, Permission::AdminRestaurantsManage]));
        $this->adminPost('/restaurant-organizations/'.$this->organization->public_id.'/staff', ['name' => 'Mallory', 'email' => 'mallory@attacker.example'])
            ->assertStatus(409)->assertJsonPath('error.code', 'organization_has_owner');
        $this->assertSame(0, DB::table('restaurant_users')->where('email', 'mallory@attacker.example')->count());
    }

    public function test_the_event_stream_of_an_order_needs_the_order_permission_at_its_location(): void
    {
        [, , , , $order] = $this->paidOrder();
        $roles = app(RoleService::class);
        $roles->define(PrincipalType::RestaurantUser, 'T_MENU_ONLY', 'Menu only', [Permission::RestaurantMenuView]);
        $this->asActor($this->member($this->organization, 'T_MENU_ONLY'));
        $this->restaurantEvents($order['order_number'])->assertForbidden();

        $this->orderStaff();
        $this->restaurantEvents($order['order_number'])->assertOk();
    }

    public function test_commission_rates_by_plan_are_shown_only_for_markets_where_the_caller_may_see_them(): void
    {
        $this->commissionPlan(1000);
        $this->completedOrderWith();

        // Finance view everywhere, the commission permission only for another market: the plan grouping opens, empty.
        $admin = $this->adminWith([Permission::AdminFinanceView]);
        $roles = app(RoleService::class);
        $other = Market::factory()->create();
        $roles->assign($admin, $roles->define(PrincipalType::AdminUser, 'T_COMMISSION_OTHER', 'Commission elsewhere', [Permission::AdminFinanceCommissionView]), Scope::market($other->public_id));
        $this->asActor($admin);
        $this->assertSame([], $this->financeGet('/reports/commission?group_by=plan')->assertOk()->json('rows'));

        // With the commission permission for this market, its plan is shown.
        $roles->assign($admin, $roles->define(PrincipalType::AdminUser, 'T_COMMISSION_HERE', 'Commission here', [Permission::AdminFinanceCommissionView]), Scope::market($this->market->public_id));
        $this->asActor($admin->fresh());
        $this->assertSame(1000, $this->financeGet('/reports/commission?group_by=plan')->assertOk()->json('rows.0.rate_bps'));
    }

    public function test_cached_grants_are_invalidated_when_the_change_commits_and_not_before(): void
    {
        $read = fn (): ?string => Cache::get('rbac:version');
        $access = app(AccessControl::class);
        $before = $read() ?? 'unset';
        Cache::forever('rbac:version', $before);

        DB::transaction(function () use ($access, $read, $before): void {
            $access->flush();
            $this->assertSame($before, $read(), 'inside the transaction the old version stays: no request can cache old grants under a new version');
        });
        $after = $read();
        $this->assertNotSame($before, $after, 'the commit invalidates');

        try {
            DB::transaction(function () use ($access): void {
                $access->flush();
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($after, $read(), 'a change that is rolled back invalidates nothing');
    }
}
