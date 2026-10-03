<?php

namespace Tests\Feature\Restaurant;

use App\Enums\Permission;
use App\Models\RestaurantUser;
use App\Notifications\RestaurantStaffInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsRestaurants;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every restaurant endpoint answers with the shape documented in openapi/openapi.json — for customers, for
 * restaurant staff and for administrators. A property that is not documented fails the test.
 */
class RestaurantOpenApiContractTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase, ValidatesOpenApi;

    public function test_restaurant_responses_match_their_documented_schemas(): void
    {
        $this->setUpRestaurantWorld();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $organization = $this->organization('Riverside');
        $burger = $this->hours($this->location($organization, 'Burger Hub', ['slug' => 'burger-hub', 'price_level' => 2, 'phone_e164' => '+911204567890']), [[null, '09:00', '22:00']]);
        $this->cuisines($burger, ['burgers']);
        $cover = $this->image($burger);
        $gallery = $this->image($burger, 'GALLERY', '/images/food-pizza.jpg');
        $this->special($burger, '2026-10-08', note: 'Closed for Dussehra');
        $closed = $this->hours($this->location($organization, 'Brew & Bites', ['slug' => 'brew-bites', 'accepting_orders' => false, 'paused_at' => now(), 'pause_reason' => 'Busy']), [[null, '18:00', '23:00']]);
        $owner = $this->member($organization, 'OWNER');
        $manager = $this->member($organization, 'MANAGER', [$burger]);

        // ── Customers
        $this->assertMatchesSchema('PublicRestaurantPage', $this->getJson('/api/v1/restaurants?lat=28.55&lng=77.35')->assertOk()->assertJsonCount(2, 'data')->json());
        $this->assertMatchesSchema('PublicRestaurantPage', $this->getJson('/api/v1/restaurants?filter[open_now]=true')->assertOk()->assertJsonCount(1, 'data')->json());
        $this->assertMatchesSchema('PublicRestaurant', $this->getJson('/api/v1/restaurants/burger-hub')->assertOk()->json());
        $this->assertMatchesSchema('PublicRestaurant', $this->getJson('/api/v1/restaurants/brew-bites?lat=28.5&lng=77.3')->assertOk()->json());
        $this->assertMatchesSchema('CuisineList', $this->getJson('/api/v1/cuisines')->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/restaurants/no-such-restaurant')->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/restaurants?filter[status]=DRAFT')->assertUnprocessable()->json());

        // ── Restaurant staff
        $this->actingAsPrincipal($owner);
        $l = '/api/v1/restaurant/locations/'.$burger->public_id;
        $s = '/api/v1/restaurant/organizations/'.$organization->public_id.'/staff';

        $this->assertMatchesSchema('RestaurantContext', $this->getJson('/api/v1/restaurant/context')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantTaxonomy', $this->getJson('/api/v1/restaurant/taxonomy')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantRoleList', $this->getJson('/api/v1/restaurant/roles')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantLocation', $this->getJson($l)->assertOk()->json());
        $this->assertMatchesSchema('RestaurantLocation', $this->patchJson($l.'/profile', ['version' => 1, 'short_description' => 'Burgers and fries', 'cuisines' => ['burgers', 'fast_food'], 'features' => ['parking']])->assertOk()->json());
        $this->assertMatchesSchema('RestaurantLocation', $this->patchJson($l.'/availability', ['accepting_orders' => false, 'pause_reason' => 'Busy', 'paused_until' => now()->addHour()->toIso8601String()])->assertOk()->json());
        $this->assertMatchesSchema('RestaurantLocation', $this->patchJson($l.'/images/'.$cover->public_id, ['alt_text' => 'A burger'])->assertOk()->json());
        $this->assertMatchesSchema('RestaurantLocation', $this->deleteJson($l.'/images/'.$gallery->public_id)->assertOk()->json());
        $this->assertMatchesSchema('RestaurantHours', $this->getJson($l.'/hours')->assertOk()->json());
        $this->assertMatchesSchema('RestaurantHours', $this->putJson($l.'/hours', ['version' => 1, 'periods' => [['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00']]])->assertOk()->json());
        $special = $this->postJson($l.'/special-hours', ['date' => '2026-10-20', 'is_closed' => false, 'periods' => [['opens_at' => '10:00', 'closes_at' => '14:00']], 'public_note' => 'Short day'])->assertCreated();
        $this->assertMatchesSchema('SpecialHour', $special->json());
        $this->assertMatchesSchema('SpecialHour', $this->patchJson($l.'/special-hours/'.$special->json('id'), ['is_closed' => true])->assertOk()->json());
        $this->assertMatchesSchema('SpecialHourList', $this->getJson($l.'/special-hours')->assertOk()->assertJsonCount(2)->json());
        $this->deleteJson($l.'/special-hours/'.$special->json('id'))->assertNoContent();
        $this->assertMatchesSchema('PickupSettings', $this->getJson($l.'/pickup-settings')->assertOk()->json());
        $this->assertMatchesSchema('PickupSettings', $this->patchJson($l.'/pickup-settings', ['version' => 1, 'default_prep_minutes' => 20, 'capacity_per_slot' => 6, 'methods' => [['method' => 'COUNTER', 'enabled' => true, 'instructions' => 'Counter 2']]])->assertOk()->json());

        $this->assertMatchesSchema('RestaurantMemberPage', $this->getJson($s)->assertOk()->assertJsonCount(2, 'data')->json());
        $invited = $this->postJson($s, ['name' => 'Priya Nair', 'email' => 'priya@restaurant.example', 'role' => 'VIEWER', 'all_locations' => false, 'location_ids' => [$burger->public_id]])->assertCreated();
        $this->assertMatchesSchema('RestaurantMember', $invited->json());
        $this->assertMatchesSchema('Message', $this->postJson($s.'/'.$invited->json('id').'/invitation')->assertOk()->json());
        $managerMembership = $this->membershipOf($manager, $organization)->public_id;
        $this->assertMatchesSchema('RestaurantMember', $this->patchJson($s.'/'.$managerMembership, ['version' => 1, 'all_locations' => true])->assertOk()->json());
        $this->assertMatchesSchema('RestaurantMember', $this->deleteJson($s.'/'.$managerMembership, ['reason' => 'Left'])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->patchJson($l.'/profile', ['version' => 1, 'name' => 'Stale'])->assertConflict()->json());
        $this->assertMatchesSchema('Error', $this->patchJson($l.'/profile', ['version' => 2, 'status' => 'APPROVED'])->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/restaurant/locations/'.$this->location($this->organization('Other'), 'Other')->public_id)->assertNotFound()->json());

        $token = '';
        Notification::assertSentTo(RestaurantUser::query()->where('email', 'priya@restaurant.example')->firstOrFail(), RestaurantStaffInvitationNotification::class, function (RestaurantStaffInvitationNotification $n) use (&$token): bool {
            $token = substr($n->url(), strpos($n->url(), '#') + 1);

            return true;
        });
        $this->app['auth']->forgetGuards();
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/auth/restaurant/invitation/accept', ['token' => str_repeat('a', 64)])->assertUnprocessable()->json());
        $this->assertMatchesSchema('RestaurantInvitationAccepted', $this->postJson('/api/v1/auth/restaurant/invitation/accept', ['token' => $token, 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])->assertOk()->json());

        // ── Administrators
        $this->actingAsPrincipal($this->adminWith(array_values(array_filter(Permission::cases(), fn (Permission $p) => str_starts_with($p->value, 'admin.')))));
        $a = '/api/v1/admin';

        $this->assertMatchesSchema('AdminRestaurantPage', $this->getJson($a.'/restaurants')->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurant', $this->getJson($a.'/restaurants/'.$burger->public_id)->assertOk()->assertJsonCount(2, 'staff')->json());
        $this->assertMatchesSchema('AdminRestaurant', $this->getJson($a.'/restaurants/'.$closed->public_id)->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurant', $this->patchJson($a.'/restaurants/'.$closed->public_id, ['version' => 1, 'branch_label' => 'Sector 63', 'latitude' => 28.56, 'longitude' => 77.36])->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurant', $this->postJson($a.'/restaurants/'.$closed->public_id.'/status', ['status' => 'SUSPENDED', 'version' => 2, 'reason' => 'Contract test', 'public_reason' => 'Suspended.'])->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurantOrganizationPage', $this->getJson($a.'/restaurant-organizations')->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurantOrganizationDetail', $this->getJson($a.'/restaurant-organizations/'.$organization->public_id)->assertOk()->json());
        $created = $this->postJson($a.'/restaurant-organizations', ['market_id' => $this->market->public_id, 'legal_name' => 'New Kitchens Pvt. Ltd.', 'display_name' => 'New Kitchens'])->assertCreated();
        $this->assertMatchesSchema('AdminRestaurantOrganizationDetail', $created->json());
        $o = $a.'/restaurant-organizations/'.$created->json('id');
        $this->assertMatchesSchema('AdminRestaurantOrganizationDetail', $this->patchJson($o, ['version' => 1, 'display_name' => 'New Kitchens India'])->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurantOrganizationDetail', $this->postJson($o.'/status', ['status' => 'SUBMITTED', 'version' => 2])->assertOk()->json());
        $this->assertMatchesSchema('AdminRestaurantLocationCreated', $this->postJson($o.'/locations', ['name' => 'New Kitchen', 'latitude' => 28.55, 'longitude' => 77.35, 'formatted_address' => 'Sector 62, Noida'])->assertCreated()->json());
        $this->assertMatchesSchema('AdminRestaurantNote', $this->postJson($o.'/notes', ['note' => 'Contract test note'])->assertCreated()->json());
        $admitted = $this->postJson($o.'/staff', ['name' => 'First Owner', 'email' => 'first.owner@restaurant.example'])->assertCreated();
        $this->assertMatchesSchema('RestaurantMember', $admitted->json());
        $this->assertMatchesSchema('RestaurantMember', $this->deleteJson($o.'/staff/'.$admitted->json('id'), ['reason' => 'Contract test'])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson($o.'/status', ['status' => 'SUSPENDED', 'version' => 3, 'reason' => 'Not allowed from here'])->assertConflict()->json());
        $this->assertMatchesSchema('Error', $this->postJson($o.'/locations', ['name' => 'Far', 'latitude' => 51.5, 'longitude' => -0.12, 'formatted_address' => 'London somewhere'])->assertUnprocessable()->json());
    }
}
