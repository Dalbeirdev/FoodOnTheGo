<?php

namespace Tests\Feature\Geo;

use App\Auth\Scope;
use App\Enums\Permission as P;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGeography;
use Tests\TestCase;

/**
 * Who may read and change market geography. Permission AND market scope are both required, on every
 * endpoint, decided by the backend.
 */
class MarketScopeTest extends TestCase
{
    use BuildsGeography, RefreshDatabase;

    private const BOX = [77.30, 28.50, 77.40, 28.60];

    private const VIEW = [P::AdminMarketsView, P::AdminCitiesView, P::AdminServiceAreasView, P::AdminMarketConfigurationView, P::AdminAuditView];

    private const MANAGE = [P::AdminMarketsManage, P::AdminCitiesManage, P::AdminServiceAreasManage, P::AdminMarketConfigurationManage, P::AdminMarketFeaturesManage];

    /**
     * Every admin geography endpoint, with a body that would succeed for a fully authorised administrator.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function endpoints(Market $market): array
    {
        $region = $this->region($market, 'R-'.$market->country_code, 'ACTIVE', 'Region '.$market->country_code);
        $city = $this->city($region, 'City '.$market->country_code);
        $area = $this->area($city, self::BOX, name: 'Area '.$market->country_code);
        $corridor = $this->corridor($market, [[77.0, 28.55], [78.0, 28.55]], name: 'Corridor '.$market->country_code);
        $m = '/api/v1/admin/markets/'.$market->public_id;

        return [
            ['GET', $m, []],
            ['GET', $m.'/configuration', []],
            ['GET', $m.'/regions', []],
            ['GET', $m.'/cities', []],
            ['GET', $m.'/service-areas', []],
            ['GET', $m.'/route-corridors', []],
            ['GET', $m.'/map', []],
            ['POST', $m.'/availability-check', ['lat' => 28.55, 'lng' => 77.35]],
            ['GET', '/api/v1/admin/cities/'.$city->public_id, []],
            ['GET', '/api/v1/admin/service-areas/'.$area->public_id, []],
            ['GET', '/api/v1/admin/route-corridors/'.$corridor->public_id, []],
            ['PATCH', $m, ['version' => 1, 'status' => 'PAUSED', 'reason' => 'Scope test']],
            ['PATCH', $m.'/features', ['version' => 2, 'features' => ['reviews' => false], 'reason' => 'Scope test']],
            ['PATCH', $m.'/configuration', ['version' => 1, 'reason' => 'Scope test', 'ordering' => ['x' => 1]]],
            ['POST', $m.'/regions', ['code' => 'NEW', 'name' => 'New region', 'type' => 'STATE']],
            ['PATCH', '/api/v1/admin/regions/'.$region->public_id, ['version' => 1, 'name' => 'Renamed']],
            ['POST', $m.'/cities', ['region_id' => $region->public_id, 'name' => 'New city', 'latitude' => 28.0, 'longitude' => 77.0]],
            ['PATCH', '/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'name' => 'Renamed']],
            ['POST', $m.'/service-areas', ['city_id' => $city->public_id, 'name' => 'New area', 'geometry' => $this->square(self::BOX)]],
            ['PATCH', '/api/v1/admin/service-areas/'.$area->public_id, ['version' => 1, 'name' => 'Renamed']],
            ['POST', $m.'/route-corridors', ['name' => 'New corridor', 'geometry' => ['type' => 'LineString', 'coordinates' => [[77.0, 28.0], [77.5, 28.5]]], 'corridor_width_meters' => 5000]],
            ['PATCH', '/api/v1/admin/route-corridors/'.$corridor->public_id, ['version' => 1, 'name' => 'Renamed']],
        ];
    }

    private function second(): Market
    {
        return tap(Market::factory()->active()->create(['country_code' => 'LK', 'features' => ['reviews' => true]]), fn (Market $m) => $m->setBounds(...Market::INDIA_BOUNDS));
    }

    public function test_every_endpoint_requires_an_admin_token(): void
    {
        $endpoints = [...$this->endpoints($this->india()), ['GET', '/api/v1/admin/markets', []], ['GET', '/api/v1/admin/audit-events', []]];

        foreach ($endpoints as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertUnauthorized();
        }

        $this->seed(RoleSeeder::class);
        $owner = RestaurantUser::factory()->create();
        app(RoleService::class)->assign($owner, Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', 'OWNER')->firstOrFail(), Scope::organization('0a000000-0000-4000-8000-00000000000a'));

        foreach ([Customer::factory()->create(), $owner] as $principal) {
            $headers = $this->bearer($principal);
            foreach ($endpoints as [$method, $url, $body]) {
                $this->json($method, $url, $body, $headers)->assertUnauthorized();
            }
        }

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_an_administrator_without_market_permissions_is_forbidden_everywhere(): void
    {
        $endpoints = $this->endpoints($this->india());
        $this->actingAsPrincipal($this->adminWith([P::AdminCustomersView, P::AdminOrdersView]));

        foreach ([...$endpoints, ['GET', '/api/v1/admin/markets', []], ['GET', '/api/v1/admin/audit-events', []]] as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }
    }

    public function test_view_permissions_allow_reading_and_never_changing(): void
    {
        $endpoints = $this->endpoints($this->india());
        $this->actingAsPrincipal($this->adminWith(self::VIEW));

        foreach ($endpoints as [$method, $url, $body]) {
            $response = $this->json($method, $url, $body);
            str_contains($url, 'availability-check') || $method === 'GET' ? $response->assertOk() : $response->assertForbidden();
        }

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame('ACTIVE', DB::table('markets')->where('country_code', 'IN')->value('status'));
    }

    public function test_a_grant_for_one_market_never_opens_another(): void
    {
        $india = $this->india();
        $other = $this->second();
        $own = $this->endpoints($india);
        $foreign = $this->endpoints($other);

        $this->actingAsPrincipal($this->adminWith([...self::VIEW, ...self::MANAGE], $india));

        foreach ($foreign as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden();
        }
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame('ACTIVE', $other->refresh()->status->value);
        $this->assertSame(1, DB::table('cities')->where('market_id', $other->id)->count());

        foreach ($own as [$method, $url, $body]) {
            $status = $this->json($method, $url, $body)->status();
            $this->assertContains($status, [200, 201], "{$method} {$url}");
        }
    }

    public function test_a_record_cannot_be_moved_into_or_attached_across_markets(): void
    {
        $india = $this->india();
        $other = $this->second();
        $foreignRegion = $this->region($other, 'LK-1', 'ACTIVE', 'Western');
        $foreignCity = $this->city($foreignRegion, 'Colombo', 6.93, 79.85);
        $this->actingAsPrincipal($this->adminWith([...self::VIEW, ...self::MANAGE]));
        $m = '/api/v1/admin/markets/'.$india->public_id;

        $this->postJson($m.'/cities', ['region_id' => $foreignRegion->public_id, 'name' => 'Smuggled', 'latitude' => 28.0, 'longitude' => 77.0])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.region_id.0', 'The region does not belong to this market.');
        $this->postJson($m.'/service-areas', ['city_id' => $foreignCity->public_id, 'name' => 'Smuggled', 'geometry' => $this->square(self::BOX)])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.city_id.0', 'The city does not belong to this market.');

        $this->assertSame(0, DB::table('cities')->where('market_id', $india->id)->count());
    }

    public function test_market_and_audit_lists_are_limited_to_the_markets_in_scope(): void
    {
        $india = $this->india();
        $other = $this->second();
        $super = $this->adminWith([...self::VIEW, ...self::MANAGE]);
        $scoped = $this->adminWith(self::VIEW, $india);

        $this->actingAsPrincipal($super);
        $this->patchJson('/api/v1/admin/markets/'.$india->public_id.'/features', ['version' => 1, 'features' => ['reviews' => false], 'reason' => 'India change'])->assertOk();
        $this->patchJson('/api/v1/admin/markets/'.$other->public_id.'/features', ['version' => 1, 'features' => ['reviews' => false], 'reason' => 'Other change'])->assertOk();
        $this->getJson('/api/v1/admin/markets')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/audit-events')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAsPrincipal($scoped);
        $this->getJson('/api/v1/admin/markets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.country_code', 'IN');
        $this->getJson('/api/v1/admin/audit-events')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'India change');
    }

    public function test_the_seeded_roles_give_operations_read_access_and_only_the_super_admin_write_access(): void
    {
        $this->seed(RoleSeeder::class);
        $india = $this->india();
        $roles = app(RoleService::class);
        $role = fn (string $code) => Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail();
        $url = '/api/v1/admin/markets/'.$india->public_id;
        $pause = ['version' => 1, 'status' => 'PAUSED', 'reason' => 'Role test'];

        $operations = AdminUser::factory()->create();
        $roles->assign($operations, $role('OPERATIONS_ADMIN'), Scope::market($india->public_id));
        $this->actingAsPrincipal($operations);
        $this->getJson($url.'/map')->assertOk();
        $this->patchJson($url, $pause)->assertForbidden();

        $support = AdminUser::factory()->create();
        $roles->assign($support, $role('SUPPORT_ADMIN'));
        $this->actingAsPrincipal($support);
        $this->getJson($url)->assertForbidden();

        $super = AdminUser::factory()->create();
        $roles->assign($super, $role('SUPER_ADMIN'));
        $this->actingAsPrincipal($super);
        $this->patchJson($url, $pause)->assertOk();
    }

    public function test_a_suspended_administrator_loses_access_immediately(): void
    {
        $india = $this->india();
        $admin = $this->adminWith(self::VIEW);
        $headers = $this->bearer($admin);

        $this->getJson('/api/v1/admin/markets/'.$india->public_id, $headers)->assertOk();

        $admin->forceFill(['status' => 'SUSPENDED'])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/markets/'.$india->public_id, $headers)->assertStatus(403);
    }
}
