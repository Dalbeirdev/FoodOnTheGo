<?php

namespace Tests\Feature\Restaurant;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Models\AccessToken;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Auth\AccountStatusService;
use App\Services\Rbac\RoleService;
use App\Services\Restaurant\RestaurantStaffService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Who may do what, and to which restaurant: permission + resource scope on every restaurant endpoint.
 * Real bearer tokens are used throughout, so a change of membership is seen on the very next request.
 */
class RestaurantAuthorizationTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private const READS = ['view location', 'view hours', 'view special hours', 'view pickup settings'];

    private const WRITES = ['update profile', 'replace hours', 'add special hours', 'update pickup settings', 'pause orders'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    /**
     * Every location endpoint: name => [method, path, body].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function actions(RestaurantLocation $location): array
    {
        $base = '/api/v1/restaurant/locations/'.$location->public_id;

        return [
            'view location' => ['GET', $base, []],
            'view hours' => ['GET', $base.'/hours', []],
            'view special hours' => ['GET', $base.'/special-hours', []],
            'view pickup settings' => ['GET', $base.'/pickup-settings', []],
            'update profile' => ['PATCH', $base.'/profile', ['version' => 1, 'short_description' => 'Changed by '.Str::random(4)]],
            'replace hours' => ['PUT', $base.'/hours', ['version' => 1, 'periods' => [['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00']]]],
            'add special hours' => ['POST', $base.'/special-hours', ['date' => '2026-10-20', 'is_closed' => true]],
            'update pickup settings' => ['PATCH', $base.'/pickup-settings', ['version' => 1, 'default_prep_minutes' => 30]],
            'pause orders' => ['PATCH', $base.'/availability', ['accepting_orders' => false]],
        ];
    }

    /**
     * @param  array{0: string, 1: string, 2: array<string, mixed>}  $action
     * @param  array<string, string>  $headers
     */
    private function send(array $action, array $headers): TestResponse
    {
        return $this->json($action[0], $action[1], $action[2], $headers);
    }

    /**
     * The same token on a NEW request. Inside one test the application keeps the signed-in user between
     * requests; a real request starts without one. Needed wherever the token itself was revoked or swapped.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function again(array $headers): array
    {
        $this->app['auth']->forgetGuards();

        return $headers;
    }

    /**
     * @return array{0: RestaurantOrganization, 1: RestaurantLocation, 2: RestaurantUser}
     */
    private function restaurantWith(string $role, string $name = 'Kitchen'): array
    {
        $organization = $this->organization($name.' '.Str::random(4));
        $location = $this->location($organization, $name);

        return [$organization, $location, $this->member($organization, $role)];
    }

    public function test_each_role_can_do_exactly_what_its_permissions_allow(): void
    {
        $expected = [
            'OWNER' => [...self::READS, ...self::WRITES, 'list staff', 'invite staff'],
            'MANAGER' => [...self::READS, ...self::WRITES, 'list staff'],
            'ORDER_STAFF' => self::READS,
            'MENU_MANAGER' => self::READS,
            'VIEWER' => self::READS,
        ];

        foreach ($expected as $role => $allowed) {
            [$organization, $location, $user] = $this->restaurantWith($role);
            $token = $this->bearer($user);
            $staff = '/api/v1/restaurant/organizations/'.$organization->public_id.'/staff';
            $events = AuditEvent::query()->count();

            $actions = [
                ...$this->actions($location),
                'list staff' => ['GET', $staff, []],
                'invite staff' => ['POST', $staff, ['name' => 'New Person', 'email' => 'new.'.Str::lower(Str::random(6)).'@restaurant.example', 'role' => 'VIEWER', 'all_locations' => true]],
            ];

            foreach ($actions as $name => $action) {
                $response = $this->send($action, $token);

                if (in_array($name, $allowed, true)) {
                    $this->assertTrue($response->isSuccessful(), "{$role} should be allowed to [{$name}] but got {$response->status()}: ".$response->getContent());
                } else {
                    $this->assertSame(403, $response->status(), "{$role} must not [{$name}]");
                    $this->assertSame('forbidden', $response->json('error.code'));
                }
            }

            $writes = count(array_intersect($allowed, [...self::WRITES, 'invite staff']));
            $this->assertSame($events + $writes, AuditEvent::query()->count(), "{$role}: exactly the allowed writes were made");
        }
    }

    public function test_the_role_bundles_are_the_documented_ones(): void
    {
        $permissions = fn (string $code): array => Role::query()->where('code', $code)->firstOrFail()->permissions()->orderBy('permission')->pluck('permission')->all();

        $this->assertContains('restaurant.staff.manage', $permissions('OWNER'));
        $this->assertNotContains('restaurant.staff.manage', $permissions('MANAGER'));
        foreach (['restaurant.profile.manage', 'restaurant.hours.manage', 'restaurant.pickup_settings.manage', 'restaurant.settings.manage', 'restaurant.staff.view'] as $permission) {
            $this->assertContains($permission, $permissions('MANAGER'));
            foreach (['ORDER_STAFF', 'MENU_MANAGER', 'VIEWER'] as $role) {
                $this->assertNotContains($permission, $permissions($role), "{$role} must not hold {$permission}");
            }
        }
    }

    public function test_one_restaurant_cannot_reach_another_restaurant_by_changing_an_id(): void
    {
        [$orgA, $locationA, $ownerA] = $this->restaurantWith('OWNER', 'Riverside');
        [$orgB, $locationB, $ownerB] = $this->restaurantWith('OWNER', 'Second Kitchen');
        $this->hours($locationA, [[null, '09:00', '22:00']]);
        $specialA = $this->special($locationA, '2026-10-25');
        $imageA = $this->image($locationA);
        $memberA = $this->membershipOf($ownerA, $orgA);
        $tokenB = $this->bearer($ownerB);
        $before = (array) DB::table('restaurant_locations')->where('id', $locationA->id)->first();

        $attempts = [
            ...$this->actions($locationA),
            'update special hours' => ['PATCH', '/api/v1/restaurant/locations/'.$locationA->public_id.'/special-hours/'.$specialA->public_id, ['is_closed' => true]],
            'delete special hours' => ['DELETE', '/api/v1/restaurant/locations/'.$locationA->public_id.'/special-hours/'.$specialA->public_id, []],
            'update image' => ['PATCH', '/api/v1/restaurant/locations/'.$locationA->public_id.'/images/'.$imageA->public_id, ['alt_text' => 'Ours now']],
            'archive image' => ['DELETE', '/api/v1/restaurant/locations/'.$locationA->public_id.'/images/'.$imageA->public_id, []],
            'list staff' => ['GET', '/api/v1/restaurant/organizations/'.$orgA->public_id.'/staff', []],
            'invite staff' => ['POST', '/api/v1/restaurant/organizations/'.$orgA->public_id.'/staff', ['name' => 'Mole', 'email' => 'mole@restaurant.example', 'role' => 'OWNER', 'all_locations' => true]],
            'change staff' => ['PATCH', '/api/v1/restaurant/organizations/'.$orgA->public_id.'/staff/'.$memberA->public_id, ['version' => 1, 'role' => 'VIEWER']],
            'revoke staff' => ['DELETE', '/api/v1/restaurant/organizations/'.$orgA->public_id.'/staff/'.$memberA->public_id, []],
        ];

        foreach ($attempts as $name => $action) {
            $response = $this->send($action, $tokenB);
            $this->assertSame(404, $response->status(), "[{$name}] on another restaurant must look like it does not exist");
            $this->assertSame('not_found', $response->json('error.code'), $name);
        }

        // The same answer as for an id nobody was ever given.
        $this->getJson('/api/v1/restaurant/locations/'.Str::uuid(), $tokenB)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/restaurant/organizations/'.Str::uuid().'/staff', $tokenB)->assertNotFound();

        // Their own restaurant's URL with the other restaurant's records inside it.
        $this->patchJson('/api/v1/restaurant/locations/'.$locationB->public_id.'/special-hours/'.$specialA->public_id, ['is_closed' => true], $tokenB)->assertNotFound();
        $this->deleteJson('/api/v1/restaurant/locations/'.$locationB->public_id.'/images/'.$imageA->public_id, [], $tokenB)->assertNotFound();
        $this->patchJson('/api/v1/restaurant/organizations/'.$orgB->public_id.'/staff/'.$memberA->public_id, ['version' => 1, 'role' => 'VIEWER'], $tokenB)->assertNotFound();
        $this->deleteJson('/api/v1/restaurant/organizations/'.$orgB->public_id.'/staff/'.$memberA->public_id, [], $tokenB)->assertNotFound();
        $this->postJson('/api/v1/restaurant/organizations/'.$orgB->public_id.'/staff', ['name' => 'Spy', 'email' => 'spy@restaurant.example', 'role' => 'VIEWER', 'all_locations' => false, 'location_ids' => [$locationA->public_id]], $tokenB)
            ->assertUnprocessable()->assertJsonPath('error.details.fields.location_ids.0', 'Every location must belong to this restaurant.');

        // Nothing sent by the client can claim the other restaurant.
        $this->getJson('/api/v1/restaurant/locations/'.$locationA->public_id.'?organization_id='.$orgA->public_id, $tokenB + ['X-Organization-Id' => $orgA->public_id, 'X-Restaurant-Id' => $locationA->public_id])->assertNotFound();
        $this->patchJson('/api/v1/restaurant/locations/'.$locationA->public_id.'/profile', ['version' => 1, 'name' => 'Taken over', 'organization' => $orgB->public_id, 'scope_id' => $orgB->public_id], $tokenB)->assertNotFound();

        $this->assertSame($before, (array) DB::table('restaurant_locations')->where('id', $locationA->id)->first());
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(['Second Kitchen'], $this->getJson('/api/v1/restaurant/context', $tokenB)->json('locations.*.name'));
        $this->assertSame(['Riverside'], $this->getJson('/api/v1/restaurant/context', $this->bearer($ownerA))->json('locations.*.name'));
    }

    public function test_a_member_limited_to_one_location_cannot_open_a_sibling_location(): void
    {
        $organization = $this->organization('Riverside');
        $burger = $this->location($organization, 'Burger Hub');
        $brew = $this->location($organization, 'Brew & Bites');
        $this->member($organization, 'OWNER');
        $manager = $this->member($organization, 'MANAGER', [$burger]);
        $token = $this->bearer($manager);

        foreach ($this->actions($burger) as $name => $action) {
            $this->assertTrue($this->send($action, $token)->isSuccessful(), "the manager of Burger Hub may [{$name}] there");
        }
        foreach ($this->actions($brew) as $name => $action) {
            $this->assertSame(404, $this->send($action, $token)->status(), "[{$name}] at a location outside the membership");
        }

        $context = $this->getJson('/api/v1/restaurant/context', $token)->assertOk();
        $this->assertSame(['Burger Hub'], $context->json('locations.*.name'));
        $this->assertSame($burger->public_id, $context->json('default_location_id'));
        $this->assertSame([], $context->json('organizations.0.permissions'));
        $this->assertFalse($context->json('organizations.0.membership.all_locations'));

        $this->assertNull(DB::table('restaurant_locations')->where('id', $brew->id)->value('short_description'));
        $this->assertTrue((bool) DB::table('restaurant_locations')->where('id', $brew->id)->value('accepting_orders'));
    }

    public function test_access_follows_the_membership_on_the_very_next_request(): void
    {
        [$organization, $location, $owner] = $this->restaurantWith('OWNER');
        $manager = $this->member($organization, 'MANAGER');
        $token = $this->bearer($manager);
        $membership = $this->membershipOf($manager, $organization);
        $url = '/api/v1/restaurant/locations/'.$location->public_id;
        $staff = app(RestaurantStaffService::class);

        $this->getJson($url, $token)->assertOk();
        $this->patchJson($url.'/availability', ['accepting_orders' => false], $token)->assertOk();

        // Demoted: still reads, no longer writes.
        $staff->update($membership, ['version' => 1, 'role' => Role::query()->where('code', 'VIEWER')->firstOrFail()], $owner);
        $this->getJson($url, $token)->assertOk()->assertJsonMissing(['restaurant.profile.manage']);
        $this->patchJson($url.'/availability', ['accepting_orders' => true], $token)->assertForbidden();

        // Suspended: the restaurant no longer exists for them.
        $staff->update($membership->refresh(), ['version' => 2, 'status' => MembershipStatus::Suspended], $owner);
        $this->getJson($url, $token)->assertNotFound();
        $this->getJson('/api/v1/restaurant/context', $token)->assertOk()->assertExactJson(['organizations' => [], 'locations' => [], 'default_location_id' => null]);

        // Reactivated, then revoked — which also ends the session, because it was their only restaurant.
        $staff->update($membership->refresh(), ['version' => 3, 'status' => MembershipStatus::Active], $owner);
        $this->getJson($url, $token)->assertOk();

        $staff->revoke($membership->refresh(), $owner, 'Left the company');
        $this->getJson($url, $this->again($token))->assertUnauthorized();
        $this->getJson($url, $this->bearer($manager))->assertNotFound();
        $this->assertSame(0, DB::table('role_assignments')->where('principal_type', 'restaurant_user')->where('principal_id', $manager->id)->count());
    }

    public function test_an_invited_membership_authorises_nothing(): void
    {
        [$organization, $location] = $this->restaurantWith('OWNER');
        $invited = $this->member($organization, 'MANAGER', status: MembershipStatus::Invited);
        $token = $this->bearer($invited);

        $this->getJson('/api/v1/restaurant/locations/'.$location->public_id, $token)->assertNotFound();
        $this->getJson('/api/v1/restaurant/context', $token)->assertOk()->assertJsonPath('locations', []);
        $this->assertSame(0, DB::table('role_assignments')->where('principal_id', $invited->id)->where('principal_type', 'restaurant_user')->count());
    }

    public function test_a_role_assignment_without_a_membership_does_not_open_the_dashboard(): void
    {
        [$organization, $location] = $this->restaurantWith('OWNER');
        $stray = RestaurantUser::factory()->create();
        app(RoleService::class)->assign($stray, Role::query()->where('code', 'OWNER')->firstOrFail(), $organization->scope());

        $this->getJson('/api/v1/restaurant/locations/'.$location->public_id, $this->bearer($stray))->assertNotFound();
    }

    public function test_only_an_active_restaurant_account_gets_in(): void
    {
        [$organization, $location, $owner] = $this->restaurantWith('OWNER');
        $url = '/api/v1/restaurant/locations/'.$location->public_id;
        $token = $this->bearer($owner);

        $this->getJson($url)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson($url, ['Authorization' => 'Bearer not-a-token'])->assertUnauthorized();
        $this->getJson($url, $this->bearer(Customer::factory()->create()))->assertUnauthorized();
        $this->patchJson($url.'/profile', ['version' => 1, 'name' => 'By a customer'], $this->bearer(Customer::factory()->create()))->assertUnauthorized();

        $admin = $this->adminWith(Permission::for(PrincipalType::AdminUser));
        $this->getJson($url, $this->bearer($admin))->assertUnauthorized();
        $this->getJson('/api/v1/restaurant/context', $this->bearer($admin))->assertUnauthorized();
        $this->getJson('/api/v1/restaurant/organizations/'.$organization->public_id.'/staff', $this->bearer(AdminUser::factory()->create()))->assertUnauthorized();

        // A token that may only be used to enrol in MFA cannot use the dashboard.
        $this->getJson($url, $this->bearer($owner, abilities: [AccessToken::ENROLL]))->assertForbidden()->assertJsonPath('error.code', 'mfa_enrollment_required');

        $this->getJson($url, $this->again($token))->assertOk();
        app(AccountStatusService::class)->change($owner, StaffStatus::Suspended);
        $this->getJson($url, $this->again($token))->assertUnauthorized();
        $this->getJson($url, $this->bearer($owner->refresh()))->assertForbidden()->assertJsonPath('error.code', 'account_not_active');
    }

    public function test_the_staff_list_of_a_location_manager_shows_only_people_who_work_there(): void
    {
        $organization = $this->organization('Riverside');
        $burger = $this->location($organization, 'Burger Hub');
        $brew = $this->location($organization, 'Brew & Bites');
        $owner = $this->member($organization, 'OWNER');
        $manager = $this->member($organization, 'MANAGER', [$burger]);
        $both = $this->member($organization, 'ORDER_STAFF', [$burger, $brew]);
        $elsewhere = $this->member($organization, 'ORDER_STAFF', [$brew]);
        $url = '/api/v1/restaurant/organizations/'.$organization->public_id.'/staff';

        $all = $this->getJson($url, $this->bearer($owner))->assertOk();
        $this->assertEqualsCanonicalizing([$owner->email, $manager->email, $both->email, $elsewhere->email], $all->json('data.*.email'));

        $seen = collect($this->getJson($url, $this->bearer($manager))->assertOk()->json('data'))->keyBy('email');
        $this->assertEqualsCanonicalizing([$owner->email, $manager->email, $both->email], $seen->keys()->all());
        $this->assertSame(['Burger Hub'], array_column($seen[$both->email]['locations'], 'name'), 'the other location of a shared colleague is not revealed');
        $this->assertTrue($seen[$manager->email]['is_self']);
        $this->assertTrue($seen[$owner->email]['all_locations']);

        // A manager can look, not change.
        $this->postJson($url, ['name' => 'Friend', 'email' => 'friend@restaurant.example', 'role' => 'VIEWER', 'all_locations' => false, 'location_ids' => [$burger->public_id]], $this->bearer($manager))->assertForbidden();
        $this->deleteJson($url.'/'.$this->membershipOf($both, $organization)->public_id, [], $this->bearer($manager))->assertForbidden();

        // Order staff cannot see the staff list at all.
        $this->getJson($url, $this->bearer($both))->assertForbidden();
    }
}
