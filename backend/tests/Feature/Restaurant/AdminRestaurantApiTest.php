<?php

namespace Tests\Feature\Restaurant;

use App\Enums\Permission as P;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Notifications\RestaurantStaffInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Restaurant administration: creating organizations and locations (placed by PostGIS, never by the client),
 * the list and the detail view, internal notes, the first owner, and who may see or change what.
 */
class AdminRestaurantApiTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $this->admin = $this->adminWith([P::AdminRestaurantsView, P::AdminRestaurantsApprove, P::AdminRestaurantsSuspend, P::AdminRestaurantsManage, P::AdminServiceAreasManage, P::AdminCitiesManage]);
        $this->actingAsPrincipal($this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrganization(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/restaurant-organizations', ['market_id' => $this->market->public_id, 'legal_name' => 'Riverside Hospitality Group Pvt. Ltd.', 'display_name' => 'Riverside Hospitality Group', ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLocation(RestaurantOrganization|string $organization, array $overrides = []): TestResponse
    {
        $id = $organization instanceof RestaurantOrganization ? $organization->public_id : $organization;

        return $this->postJson("/api/v1/admin/restaurant-organizations/{$id}/locations", [
            'name' => 'Burger Hub', 'latitude' => 28.55, 'longitude' => 77.35, 'formatted_address' => 'Sector 62, Noida, Uttar Pradesh 201309, India', ...$overrides,
        ]);
    }

    // ───────────────────────────── Creation ─────────────────────────────

    public function test_an_organization_is_created_as_a_draft(): void
    {
        $response = $this->createOrganization()->assertCreated();

        $response->assertJsonPath('legal_name', 'Riverside Hospitality Group Pvt. Ltd.')->assertJsonPath('display_name', 'Riverside Hospitality Group')
            ->assertJsonPath('slug', 'riverside-hospitality-group')->assertJsonPath('status', 'DRAFT')->assertJsonPath('market_scope', 'SINGLE_MARKET')
            ->assertJsonPath('primary_market.country_code', 'IN')->assertJsonPath('locations', [])->assertJsonPath('locations_count', 0)
            ->assertJsonPath('staff', [])->assertJsonPath('has_owner', false)->assertJsonPath('allowed_transitions', ['SUBMITTED', 'INACTIVE'])->assertJsonPath('version', 1);

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant.created', $event->action);
        $this->assertSame($response->json('id'), $event->target_public_id);
        $this->assertSame($this->market->id, $event->market_id);

        // The same name again gets its own slug; markup and unknown markets are refused.
        $this->createOrganization()->assertCreated()->assertJsonPath('slug', 'riverside-hospitality-group-2');
        $this->createOrganization(['display_name' => '<b>Bold</b> Brand'])->assertUnprocessable();
        $this->createOrganization(['market_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrorFor('market_id', 'error.details.fields');
        $this->createOrganization(['status' => 'APPROVED'])->assertCreated()->assertJsonPath('status', 'DRAFT');

        $this->patchJson('/api/v1/admin/restaurant-organizations/'.$response->json('id'), ['version' => 1, 'display_name' => 'Riverside Group'])->assertOk()
            ->assertJsonPath('display_name', 'Riverside Group')->assertJsonPath('slug', 'riverside-hospitality-group')->assertJsonPath('version', 2);
        $this->patchJson('/api/v1/admin/restaurant-organizations/'.$response->json('id'), ['version' => 1, 'display_name' => 'Stale'])->assertConflict();
    }

    public function test_a_location_is_placed_by_its_coordinates_never_by_what_the_client_claims(): void
    {
        $organization = $this->organization('Riverside', 'DRAFT');
        $elsewhere = $this->city($this->region($this->market, 'IN-GJ', name: 'Gujarat'), 'Surat', 21.1702, 72.8311);

        $created = $this->createLocation($organization, [
            'branch_label' => 'Sector 62 · Noida', 'postal_code' => '201309', 'phone' => '0120 456 7890',
            // None of these is read: geography comes from the coordinates, status from the lifecycle.
            'service_area_id' => 999, 'region_id' => $elsewhere->region_id, 'status' => 'APPROVED', 'slug' => 'my-own-slug', 'organization_id' => 999,
        ])->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('slug', 'burger-hub');

        $detail = $this->getJson('/api/v1/admin/restaurants/'.$created->json('id'))->assertOk();
        $detail->assertJsonPath('name', 'Burger Hub')->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('city.name', 'Noida')->assertJsonPath('region.code', 'IN-UP')
            ->assertJsonPath('service_area.id', $this->serviceArea->public_id)->assertJsonPath('service_area.name', 'Noida Central')
            ->assertJsonPath('market.country_code', 'IN')->assertJsonPath('timezone', 'Asia/Kolkata')->assertJsonPath('currency', 'INR')
            ->assertJsonPath('location', ['latitude' => 28.55, 'longitude' => 77.35])->assertJsonPath('phone', '+911204567890')
            ->assertJsonPath('organization.id', $organization->public_id)->assertJsonPath('accepting_orders', true)->assertJsonPath('operational_status', 'OPERATING')
            ->assertJsonPath('pickup.pickup_enabled', true)->assertJsonPath('pickup.asap_enabled', true)->assertJsonPath('pickup.scheduled_enabled', true)
            ->assertJsonPath('pickup.methods.0', ['method' => 'COUNTER', 'enabled' => true, 'instructions' => null, 'requires_vehicle_info' => false, 'available_in_market' => true])
            ->assertJsonPath('availability.reason', 'RESTAURANT_NOT_APPROVED');

        $row = DB::table('restaurant_locations')->where('public_id', $created->json('id'))->first();
        $this->assertSame($organization->id, $row->organization_id);
        $this->assertSame($this->noida->id, $row->city_id);
        $this->assertSame($this->serviceArea->id, $row->service_area_id);
        $this->assertSame('SRID=4326;POINT(77.35 28.55)', DB::selectOne('select ST_AsEWKT(location::geometry) as p from restaurant_locations where id = ?', [$row->id])->p, 'longitude first, WGS84');

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_location.created', $event->action);
        $this->assertEquals(['from' => null, 'to' => 'Noida Central'], $event->changes['service_area']);

        // Slugs are unique per market: the city qualifies the second one, a number the third.
        $this->createLocation($organization)->assertCreated()->assertJsonPath('slug', 'burger-hub-noida');
        $this->createLocation($organization)->assertCreated()->assertJsonPath('slug', 'burger-hub-noida-2');
    }

    public function test_coordinates_are_validated_against_the_market_and_the_city(): void
    {
        $organization = $this->organization('Riverside', 'DRAFT');
        $surat = $this->city($this->region($this->market, 'IN-GJ', name: 'Gujarat'), 'Surat', 21.1702, 72.8311);

        // London, and Noida with latitude and longitude swapped: neither is in the market.
        $this->createLocation($organization, ['latitude' => 51.5074, 'longitude' => -0.1278])->assertUnprocessable()->assertJsonPath('error.code', 'location_outside_market');
        $this->createLocation($organization, ['latitude' => 77.35, 'longitude' => 28.55])->assertUnprocessable()->assertJsonPath('error.code', 'location_outside_market');
        $this->createLocation($organization, ['latitude' => 91, 'longitude' => 77.35])->assertUnprocessable()->assertJsonValidationErrorFor('latitude', 'error.details.fields');
        $this->createLocation($organization, ['longitude' => null])->assertUnprocessable();

        // Coordinates in Noida's service area cannot be filed under another city.
        $this->createLocation($organization, ['city_id' => $surat->public_id])->assertUnprocessable()->assertJsonValidationErrorFor('city_id', 'error.details.fields');
        $this->createLocation($organization, ['city_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrorFor('city_id', 'error.details.fields');

        // In the middle of nowhere (central India, no known city nearby).
        $this->createLocation($organization, ['latitude' => 22.0, 'longitude' => 80.0])->assertUnprocessable()->assertJsonValidationErrorFor('latitude', 'error.details.fields');
        // Near Surat but told to belong to Noida: too far from that city.
        $this->createLocation($organization, ['latitude' => 21.17, 'longitude' => 72.83, 'city_id' => $this->noida->public_id])->assertUnprocessable()->assertJsonValidationErrorFor('latitude', 'error.details.fields');

        $this->createLocation($organization, ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrorFor('currency', 'error.details.fields');
        $this->createLocation($organization, ['timezone' => 'Mars/Olympus'])->assertUnprocessable()->assertJsonValidationErrorFor('timezone', 'error.details.fields');
        $this->createLocation($organization, ['phone' => 'call us'])->assertUnprocessable()->assertJsonValidationErrorFor('phone', 'error.details.fields');
        $this->createLocation($organization, ['name' => '<script>x</script>'])->assertUnprocessable();

        $this->assertSame(0, RestaurantLocation::query()->count());

        // Outside every service area but next to a known city: it can exist — and is simply not visible to customers.
        $outside = $this->createLocation($organization, ['name' => 'Surat Thali', 'latitude' => 21.17, 'longitude' => 72.83])->assertCreated()->json('id');
        $this->getJson('/api/v1/admin/restaurants/'.$outside)->assertOk()->assertJsonPath('city.name', 'Surat')->assertJsonPath('region.code', 'IN-GJ')->assertJsonPath('service_area', null)
            ->assertJsonPath('readiness.1', ['check' => 'inside_service_area', 'ok' => false]);
    }

    public function test_an_organization_of_one_market_cannot_get_a_location_in_another(): void
    {
        $other = tap(Market::factory()->active()->create(['country_code' => 'LK', 'default_currency' => 'LKR', 'supported_currencies' => ['LKR']]), fn (Market $m) => $m->setBounds(79.5, 5.9, 81.9, 9.9));
        $organization = $this->organization('Riverside', 'DRAFT');

        $this->createLocation($organization, ['market_id' => $other->public_id, 'latitude' => 6.9271, 'longitude' => 79.8612])->assertConflict()->assertJsonPath('error.code', 'market_not_allowed');

        $organization->forceFill(['market_scope' => 'MULTI_MARKET'])->save();
        // Allowed in principle now — but the other market has no city there yet.
        $this->createLocation($organization, ['market_id' => $other->public_id, 'latitude' => 6.9271, 'longitude' => 79.8612])->assertUnprocessable()->assertJsonValidationErrorFor('latitude', 'error.details.fields');
    }

    public function test_moving_a_location_resolves_its_geography_again(): void
    {
        $surat = $this->city($this->region($this->market, 'IN-GJ', name: 'Gujarat'), 'Surat', 21.1702, 72.8311);
        $suratArea = $this->area($surat, [72.78, 21.12, 72.88, 21.22], name: 'Surat NH48');
        $location = $this->location($this->organization(), 'Burger Hub');
        $url = '/api/v1/admin/restaurants/'.$location->public_id;

        $this->patchJson($url, ['version' => 1, 'latitude' => 21.17])->assertUnprocessable()->assertJsonValidationErrorFor('longitude', 'error.details.fields');
        $this->patchJson($url, ['version' => 1, 'latitude' => 51.5, 'longitude' => -0.12])->assertUnprocessable()->assertJsonPath('error.code', 'location_outside_market');

        $this->patchJson($url, ['version' => 1, 'latitude' => 21.17, 'longitude' => 72.83, 'formatted_address' => 'Ring Road, Surat, Gujarat 395002, India', 'name' => 'Burger Hub Surat'])->assertOk()
            ->assertJsonPath('city.name', 'Surat')->assertJsonPath('region.code', 'IN-GJ')->assertJsonPath('service_area.id', $suratArea->public_id)
            ->assertJsonPath('location', ['latitude' => 21.17, 'longitude' => 72.83])->assertJsonPath('name', 'Burger Hub Surat')->assertJsonPath('version', 2);

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_location.updated', $event->action);
        $this->assertEquals(['from' => 'Noida', 'to' => 'Surat'], $event->changes['city']);
        $this->assertEquals(['from' => 'Noida Central', 'to' => 'Surat NH48'], $event->changes['service_area']);
        $this->assertEquals(['from' => [28.55, 77.35], 'to' => [21.17, 72.83]], $event->changes['location']);
        $this->assertSame([], array_intersect(array_keys($event->changes), ['city_id', 'region_id', 'service_area_id']), 'internal ids stay out of the audit trail');

        $this->patchJson($url, ['version' => 1, 'name' => 'Stale'])->assertConflict()->assertJsonPath('error.code', 'stale_update');
        $this->patchJson($url, ['version' => 2, 'status' => 'SUSPENDED', 'accepting_orders' => false, 'slug' => 'x'])->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('version', 2);
    }

    public function test_redrawing_coverage_updates_which_service_area_a_location_is_filed_under(): void
    {
        $location = $this->location($this->organization(), 'Edge Kitchen', lat: 28.65, lng: 77.35);
        $detail = fn () => $this->getJson('/api/v1/admin/restaurants/'.$location->public_id)->assertOk();
        $detail()->assertJsonPath('service_area', null);

        // A new area is drawn around it (Module 22 API). Created PLANNED: filed under it, not yet visible to customers.
        $area = $this->postJson('/api/v1/admin/markets/'.$this->market->public_id.'/service-areas', ['city_id' => $this->noida->public_id, 'name' => 'Noida North', 'geometry' => $this->square([77.30, 28.60, 77.40, 28.70])])->assertCreated()->json();
        $detail()->assertJsonPath('service_area.id', $area['id'])->assertJsonPath('service_area.status', 'PLANNED')->assertJsonPath('availability.visible_to_customers', false);

        $this->patchJson('/api/v1/admin/service-areas/'.$area['id'], ['version' => 1, 'status' => 'ACTIVE'])->assertOk();
        $detail()->assertJsonPath('availability.visible_to_customers', true);

        // Redrawn away from the restaurant.
        $this->patchJson('/api/v1/admin/service-areas/'.$area['id'], ['version' => 2, 'geometry' => $this->square([77.50, 28.60, 77.60, 28.70])])->assertOk();
        $detail()->assertJsonPath('service_area', null)->assertJsonPath('availability.reason', 'OUTSIDE_SERVICE_AREA');
    }

    // ───────────────────────────── List and detail ─────────────────────────────

    public function test_the_list_filters_searches_sorts_and_counts(): void
    {
        $riverside = $this->organization('Riverside Hospitality');
        $second = $this->organization('Second Kitchen');
        $surat = $this->city($this->region($this->market, 'IN-GJ', name: 'Gujarat'), 'Surat', 21.1702, 72.8311);
        $this->location($riverside, 'Burger Hub');
        $this->location($riverside, 'Brew & Bites', ['status' => 'SUBMITTED', 'branch_label' => 'Sector 63']);
        $this->location($second, 'Pizza Point', ['status' => 'UNDER_REVIEW']);
        $this->location($second, 'Surat Thali', ['status' => 'SUSPENDED'], 21.17, 72.83, $surat);
        $names = fn (string $query = ''): array => $this->getJson('/api/v1/admin/restaurants'.$query)->assertOk()->json('data.*.name');

        $all = $this->getJson('/api/v1/admin/restaurants?sort=name')->assertOk();
        $this->assertSame(['Brew & Bites', 'Burger Hub', 'Pizza Point', 'Surat Thali'], $all->json('data.*.name'));
        $this->assertSame(['DRAFT' => 0, 'SUBMITTED' => 1, 'UNDER_REVIEW' => 1, 'APPROVED' => 1, 'REJECTED' => 0, 'SUSPENDED' => 1, 'INACTIVE' => 0], $all->json('counts'));
        $all->assertJsonPath('data.0.organization', ['id' => $riverside->public_id, 'name' => 'Riverside Hospitality', 'status' => 'APPROVED', 'locations' => 2])
            ->assertJsonPath('data.0.city.name', 'Noida')->assertJsonPath('data.0.in_service_area', true)->assertJsonPath('data.3.in_service_area', false)
            ->assertJsonPath('meta.total', 4);

        $this->assertEqualsCanonicalizing(['Brew & Bites', 'Pizza Point'], $names('?filter[stage]=PENDING'));
        $this->assertSame(['Surat Thali'], $names('?filter[stage]=SUSPENDED'));
        $this->assertSame(['Burger Hub'], $names('?filter[status]=APPROVED'));
        $this->assertSame(['Surat Thali'], $names('?filter[city]='.$surat->public_id));
        $this->assertEqualsCanonicalizing(['Pizza Point', 'Surat Thali'], $names('?filter[organization]='.$second->public_id));
        $this->assertCount(4, $names('?filter[market]='.$this->market->public_id));
        $this->assertSame(['Pizza Point'], $names('?filter[organization]='.$second->public_id.'&filter[stage]=PENDING'));

        $this->assertSame(['Burger Hub'], $names('?q=burger'));
        $this->assertSame(['Brew & Bites'], $names('?q=sector 63'));
        $this->assertEqualsCanonicalizing(['Pizza Point', 'Surat Thali'], $names('?q=second kitchen'), 'the organization name matches');
        $this->assertSame([], $names('?q=%25'));

        $this->assertSame(['Surat Thali', 'Pizza Point'], $names('?sort=-name&page[size]=2'));
        $this->assertSame(['Burger Hub', 'Brew & Bites'], $names('?sort=-name&page[size]=2&page[number]=2'));

        $this->getJson('/api/v1/admin/restaurants?filter[stage]=LIMBO')->assertUnprocessable();
        $this->getJson('/api/v1/admin/restaurants?filter[city]=noida')->assertUnprocessable();
        $this->getJson('/api/v1/admin/restaurants?filter[owner]=x')->assertUnprocessable();
        $this->getJson('/api/v1/admin/restaurants?sort=price_level')->assertUnprocessable();

        $organizations = $this->getJson('/api/v1/admin/restaurant-organizations')->assertOk();
        $this->assertSame(['Riverside Hospitality', 'Second Kitchen'], $organizations->json('data.*.display_name'));
        $this->assertSame([2, 2], $organizations->json('data.*.locations_count'));
        $this->assertSame(['Second Kitchen'], $this->getJson('/api/v1/admin/restaurant-organizations?q=second')->json('data.*.display_name'));
    }

    public function test_the_detail_shows_what_only_administrators_see(): void
    {
        $organization = $this->organization('Riverside');
        $location = $this->hours($this->location($organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '09:00', '22:00']]);
        $sibling = $this->location($organization, 'Brew & Bites', ['status' => 'DRAFT']);
        $this->cuisines($location, ['burgers']);
        $owner = $this->member($organization, 'OWNER');
        $this->member($organization, 'ORDER_STAFF', [$location]);

        $this->postJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/notes', ['note' => 'Owner prefers calls after 4 pm.'])->assertCreated()
            ->assertJsonPath('note', 'Owner prefers calls after 4 pm.')->assertJsonPath('author', $this->admin->name)->assertJsonPath('about', 'organization');
        $this->postJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/notes', ['note' => "Parking entrance is on the back road.\nGate 2.", 'location_id' => $location->public_id])->assertCreated()->assertJsonPath('about', 'location');
        $this->postJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/notes', ['note' => 'About the sibling', 'location_id' => $sibling->public_id])->assertCreated();
        $this->postJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/notes', ['note' => '<script>alert(1)</script>'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/notes', ['note' => 'Wrong owner', 'location_id' => $this->location($this->organization('Other'), 'Other Kitchen')->public_id])->assertUnprocessable();

        $detail = $this->getJson('/api/v1/admin/restaurants/'.$location->public_id)->assertOk();

        $detail->assertJsonPath('organization.legal_name', 'Riverside Pvt. Ltd.')->assertJsonPath('organization.status', 'APPROVED')
            ->assertJsonPath('organization_locations.*.name', ['Burger Hub', 'Brew & Bites'])
            ->assertJsonPath('staff.*.role.code', ['OWNER', 'ORDER_STAFF'])->assertJsonPath('staff.0.email', $owner->email)
            ->assertJsonPath('notes.*.note', ["Parking entrance is on the back road.\nGate 2.", 'Owner prefers calls after 4 pm.'])
            ->assertJsonPath('readiness', [
                ['check' => 'organization_approved', 'ok' => true], ['check' => 'inside_service_area', 'ok' => true], ['check' => 'opening_hours', 'ok' => true],
                ['check' => 'cuisine', 'ok' => true], ['check' => 'pickup_configured', 'ok' => true], ['check' => 'owner_account', 'ok' => true],
            ])
            ->assertJsonPath('availability.visible_to_customers', true)->assertJsonPath('availability.orderable', true)
            ->assertJsonPath('hours.weekly.1.periods', [['opens_at' => '09:00', 'closes_at' => '22:00']])
            ->assertJsonPath('history', []);

        // Internal notes never leave the administrator API.
        $this->actingAsPrincipal($owner);
        $this->assertStringNotContainsString('Parking entrance', $this->getJson('/api/v1/restaurant/locations/'.$location->public_id)->getContent().$this->getJson('/api/v1/restaurant/context')->getContent());
        $this->assertStringNotContainsString('Parking entrance', $this->getJson('/api/v1/restaurants/burger-hub')->getContent());

        // What the restaurant changes shows up in the administrator's history.
        $this->patchJson('/api/v1/restaurant/locations/'.$location->public_id.'/availability', ['accepting_orders' => false, 'pause_reason' => 'Short of staff'])->assertOk();
        $this->actingAsPrincipal($this->admin);
        $this->getJson('/api/v1/admin/restaurants/'.$location->public_id)->assertJsonPath('history.0.action', 'restaurant_location.accepting_orders_changed')
            ->assertJsonPath('history.0.actor_name', $owner->name)->assertJsonPath('history.0.actor_type', 'restaurant_user')->assertJsonPath('accepting_orders', false)->assertJsonPath('pause_reason', 'Short of staff');
    }

    // ───────────────────────────── Staff from the administrator side ─────────────────────────────

    public function test_an_administrator_invites_the_first_owner_and_cannot_leave_a_restaurant_without_one(): void
    {
        $organization = RestaurantOrganization::query()->where('public_id', $this->createOrganization()->json('id'))->firstOrFail();
        $staffUrl = '/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/staff';

        $invited = $this->postJson($staffUrl, ['name' => 'John Doe', 'email' => 'John@Riverside.example'])->assertCreated()
            ->assertJsonPath('role.code', 'OWNER')->assertJsonPath('status', 'INVITED')->assertJsonPath('all_locations', true)->assertJsonPath('email', 'john@riverside.example')->json();
        $john = RestaurantUser::query()->where('email', 'john@riverside.example')->firstOrFail();
        Notification::assertSentTo($john, RestaurantStaffInvitationNotification::class);
        $this->getJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id)->assertJsonPath('has_owner', false)->assertJsonPath('staff.0.status', 'INVITED');

        $this->postJson($staffUrl, ['name' => 'Eve', 'email' => 'eve@riverside.example', 'role' => 'SUPER_ADMIN'])->assertUnprocessable();
        $this->postJson($staffUrl, ['name' => 'John Again', 'email' => 'john@riverside.example'])->assertUnprocessable();

        // A mistaken invitation can be withdrawn while there is no owner yet.
        $this->deleteJson($staffUrl.'/'.$invited['id'], ['reason' => 'Wrong address'])->assertOk()->assertJsonPath('status', 'REVOKED');
        $this->deleteJson($staffUrl.'/'.$invited['id'])->assertUnprocessable();

        // Once somebody owns the restaurant, an administrator cannot remove the last owner.
        $owner = $this->member($organization, 'OWNER');
        $this->getJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id)->assertJsonPath('has_owner', true);
        $ownerMembership = $this->membershipOf($owner, $organization)->public_id;
        $this->deleteJson($staffUrl.'/'.$ownerMembership, ['reason' => 'Requested by e-mail'])->assertConflict()->assertJsonPath('error.code', 'last_owner');
        $this->assertSame('ACTIVE', DB::table('restaurant_memberships')->where('public_id', $ownerMembership)->value('status'));

        $second = $this->member($organization, 'OWNER');
        $this->deleteJson($staffUrl.'/'.$ownerMembership, ['reason' => 'Requested by e-mail'])->assertOk()->assertJsonPath('status', 'REVOKED');
        $this->assertSame('admin_user', AuditEvent::query()->where('action', 'restaurant_staff.revoked')->latest('id')->value('actor_type'));

        // A membership of another organization is not found under this one.
        $foreign = $this->organization('Other');
        $this->deleteJson($staffUrl.'/'.$this->membershipOf($this->member($foreign, 'OWNER'), $foreign)->public_id, ['reason' => 'Mix-up'])->assertNotFound();
        $this->assertNotNull($second);
    }

    // ───────────────────────────── Authorization ─────────────────────────────

    public function test_restaurant_administration_needs_an_administrator_with_the_right_permission(): void
    {
        $organization = $this->organization('Riverside');
        $location = $this->location($organization, 'Burger Hub');
        $base = '/api/v1/admin';
        $requests = [
            'list' => ['GET', "{$base}/restaurants", []],
            'detail' => ['GET', "{$base}/restaurants/{$location->public_id}", []],
            'organizations' => ['GET', "{$base}/restaurant-organizations", []],
            'organization' => ['GET', "{$base}/restaurant-organizations/{$organization->public_id}", []],
            'update location' => ['PATCH', "{$base}/restaurants/{$location->public_id}", ['version' => 1, 'name' => 'Changed']],
            'location status' => ['POST', "{$base}/restaurants/{$location->public_id}/status", ['status' => 'INACTIVE', 'version' => 1, 'reason' => 'Closed down']],
            'create organization' => ['POST', "{$base}/restaurant-organizations", ['market_id' => $this->market->public_id, 'legal_name' => 'New Co', 'display_name' => 'New Co']],
            'update organization' => ['PATCH', "{$base}/restaurant-organizations/{$organization->public_id}", ['version' => 1, 'display_name' => 'Changed']],
            'organization status' => ['POST', "{$base}/restaurant-organizations/{$organization->public_id}/status", ['status' => 'INACTIVE', 'version' => 1, 'reason' => 'Closed down']],
            'create location' => ['POST', "{$base}/restaurant-organizations/{$organization->public_id}/locations", ['name' => 'New', 'latitude' => 28.55, 'longitude' => 77.35, 'formatted_address' => 'Sector 62, Noida']],
            'add note' => ['POST', "{$base}/restaurant-organizations/{$organization->public_id}/notes", ['note' => 'A note']],
            'invite owner' => ['POST', "{$base}/restaurant-organizations/{$organization->public_id}/staff", ['name' => 'Owner', 'email' => 'owner@new.example']],
        ];
        $reads = ['list', 'detail', 'organizations', 'organization'];

        $send = fn (array $request, array $headers): int => $this->json($request[0], $request[1], $request[2], $headers)->status();

        foreach ($requests as $name => $request) {
            $this->app['auth']->forgetGuards();
            $this->assertSame(401, $send($request, []), "[{$name}] without a token");
            $this->assertSame(401, $send($request, $this->bearer($this->member($organization, 'OWNER'))), "[{$name}] with a restaurant owner's token");
            $this->assertSame(403, $send($request, $this->bearer($this->adminWith([P::AdminOrdersView, P::AdminMarketsManage]))), "[{$name}] without a restaurant permission");
            $this->assertSame(in_array($name, $reads, true) ? 200 : 403, $send($request, $this->bearer($this->adminWith([P::AdminRestaurantsView]))), "[{$name}] with the view permission only");
        }

        $this->assertSame('Burger Hub', DB::table('restaurant_locations')->where('id', $location->id)->value('name'));
        $this->assertSame(1, RestaurantOrganization::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'restaurant%')->count());
    }

    public function test_an_administrator_limited_to_one_market_neither_sees_nor_changes_a_restaurant_of_another(): void
    {
        $other = tap(Market::factory()->active()->create(['country_code' => 'LK', 'default_currency' => 'LKR', 'supported_currencies' => ['LKR']]), fn (Market $m) => $m->setBounds(79.5, 5.9, 81.9, 9.9));
        $colombo = $this->city($this->region($other, 'LK-1', name: 'Western Province'), 'Colombo', 6.9271, 79.8612);
        $this->area($colombo, [79.80, 6.88, 79.92, 6.98], name: 'Colombo Central');
        $lankan = tap((new RestaurantOrganization)->forceFill(['legal_name' => 'Lanka Foods', 'display_name' => 'Lanka Foods', 'slug' => 'lanka-foods', 'status' => 'APPROVED', 'primary_market_id' => $other->id]))->save();
        $kottu = $this->location($lankan, 'Kottu House', ['currency' => 'LKR', 'timezone' => 'Asia/Colombo'], 6.9271, 79.8612, $colombo);
        $indian = $this->location($this->organization('Riverside'), 'Burger Hub');

        $this->actingAsPrincipal($this->adminWith([P::AdminRestaurantsView, P::AdminRestaurantsManage, P::AdminRestaurantsSuspend, P::AdminRestaurantsApprove], $this->market));

        $this->assertSame(['Burger Hub'], $this->getJson('/api/v1/admin/restaurants')->assertOk()->json('data.*.name'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/restaurants')->json('counts.APPROVED'));
        $this->assertSame(['Riverside'], $this->getJson('/api/v1/admin/restaurant-organizations')->json('data.*.display_name'));
        $this->assertSame([], $this->getJson('/api/v1/admin/restaurants?filter[market]='.$other->public_id)->json('data'));
        $this->getJson('/api/v1/admin/restaurants/'.$indian->public_id)->assertOk();

        $this->getJson('/api/v1/admin/restaurants/'.$kottu->public_id)->assertForbidden();
        $this->patchJson('/api/v1/admin/restaurants/'.$kottu->public_id, ['version' => 1, 'name' => 'Taken'])->assertForbidden();
        $this->postJson('/api/v1/admin/restaurants/'.$kottu->public_id.'/status', ['status' => 'SUSPENDED', 'version' => 1, 'reason' => 'Out of scope'])->assertForbidden();
        $this->getJson('/api/v1/admin/restaurant-organizations/'.$lankan->public_id)->assertForbidden();
        $this->postJson('/api/v1/admin/restaurant-organizations/'.$lankan->public_id.'/notes', ['note' => 'Out of scope'])->assertForbidden();
        $this->createOrganization(['market_id' => $other->public_id])->assertForbidden();
        $this->createOrganization()->assertCreated();

        $this->assertSame('Kottu House', DB::table('restaurant_locations')->where('id', $kottu->id)->value('name'));
        $this->assertSame('APPROVED', DB::table('restaurant_locations')->where('id', $kottu->id)->value('status'));

        // A platform-wide administrator sees both markets.
        $this->actingAsPrincipal($this->admin);
        $this->assertEqualsCanonicalizing(['Burger Hub', 'Kottu House'], $this->getJson('/api/v1/admin/restaurants')->json('data.*.name'));
    }

    public function test_coordinates_of_a_city_must_be_sent_as_a_pair(): void
    {
        // Found while building this module: a lone latitude used to be accepted and silently ignored.
        $this->patchJson('/api/v1/admin/cities/'.$this->noida->public_id, ['version' => 1, 'latitude' => 28.6])->assertUnprocessable()->assertJsonValidationErrorFor('longitude', 'error.details.fields');
        $this->patchJson('/api/v1/admin/cities/'.$this->noida->public_id, ['version' => 1, 'latitude' => 28.6, 'longitude' => 77.4])->assertOk()->assertJsonPath('latitude', 28.6);
    }
}
