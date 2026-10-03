<?php

namespace Tests\Feature\Restaurant;

use App\Models\AuditEvent;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * The restaurant dashboard API as an owner uses it: context, profile, accepting orders, pickup settings and
 * image metadata. Who may do what is RestaurantAuthorizationTest; hours are RestaurantHoursApiTest.
 * The clock is Monday 2026-10-05 12:00 in India.
 */
class RestaurantDashboardApiTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    private RestaurantLocation $brew;

    private RestaurantUser $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $this->organization = $this->organization('Riverside');
        $this->burger = $this->hours($this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '09:00', '22:00']]);
        $this->brew = $this->location($this->organization, 'Brew & Bites');
        $this->owner = $this->member($this->organization, 'OWNER');
        $this->actingAsPrincipal($this->owner);
    }

    private function url(string $path = '', ?RestaurantLocation $location = null): string
    {
        return '/api/v1/restaurant/locations/'.($location ?? $this->burger)->public_id.$path;
    }

    // ───────────────────────────── Context ─────────────────────────────

    public function test_context_lists_the_organizations_and_locations_of_the_signed_in_user(): void
    {
        $response = $this->getJson('/api/v1/restaurant/context')->assertOk();

        $response->assertJsonCount(1, 'organizations')->assertJsonCount(2, 'locations')
            ->assertJsonPath('organizations.0.id', $this->organization->public_id)
            ->assertJsonPath('organizations.0.name', 'Riverside')
            ->assertJsonPath('organizations.0.status', 'APPROVED')
            ->assertJsonPath('organizations.0.membership.role', ['code' => 'OWNER', 'name' => 'Owner'])
            ->assertJsonPath('organizations.0.membership.all_locations', true)
            ->assertJsonPath('locations.*.name', ['Burger Hub', 'Brew & Bites'])
            ->assertJsonPath('default_location_id', $this->burger->public_id);

        $this->assertContains('restaurant.staff.manage', $response->json('organizations.0.permissions'));
        $this->assertContains('restaurant.hours.manage', $response->json('locations.0.permissions'));
        $this->assertSame('OPEN', $response->json('locations.0.availability.open_state'));
        $this->assertSame('CLOSED_NOW', $response->json('locations.1.availability.reason'), 'no hours yet');
    }

    public function test_the_default_location_is_the_first_approved_one_then_the_oldest(): void
    {
        $this->getJson('/api/v1/restaurant/context')->assertJsonPath('default_location_id', $this->burger->public_id);

        DB::table('restaurant_locations')->where('id', $this->burger->id)->update(['status' => 'SUSPENDED']);
        $this->getJson('/api/v1/restaurant/context')->assertJsonPath('default_location_id', $this->brew->public_id)->assertJsonPath('locations.*.name', ['Brew & Bites', 'Burger Hub']);

        DB::table('restaurant_locations')->where('id', $this->brew->id)->update(['status' => 'DRAFT']);
        $this->getJson('/api/v1/restaurant/context')->assertJsonPath('default_location_id', $this->burger->public_id, 'nothing approved: the oldest');
    }

    public function test_context_spans_every_organization_the_user_belongs_to_and_nothing_else(): void
    {
        $second = $this->organization('Second Kitchen');
        $pizza = $this->location($second, 'Pizza Point');
        $this->member($second, 'VIEWER', [$pizza], user: $this->owner);
        $foreign = $this->location($this->organization('Somebody Else'), 'Foreign Kitchen');

        $response = $this->getJson('/api/v1/restaurant/context')->assertOk();

        $this->assertSame(['Riverside', 'Second Kitchen'], $response->json('organizations.*.name'));
        $this->assertSame(['Burger Hub', 'Brew & Bites', 'Pizza Point'], $response->json('locations.*.name'));
        $this->assertSame([], $response->json('organizations.1.permissions'), 'a membership limited to locations holds nothing organization-wide');
        $this->assertNotContains('restaurant.profile.manage', $response->json('locations.2.permissions'));
        $this->assertContains('restaurant.profile.view', $response->json('locations.2.permissions'));
        $this->assertStringNotContainsString($foreign->public_id, $response->getContent());
    }

    public function test_a_user_without_any_restaurant_gets_an_empty_context(): void
    {
        $this->actingAsPrincipal(RestaurantUser::factory()->create());

        $this->getJson('/api/v1/restaurant/context')->assertOk()->assertExactJson(['organizations' => [], 'locations' => [], 'default_location_id' => null]);
    }

    public function test_taxonomy_offers_the_active_cuisines_and_features(): void
    {
        DB::table('cuisines')->where('code', 'bengali')->update(['status' => 'INACTIVE']);

        $response = $this->getJson('/api/v1/restaurant/taxonomy')->assertOk();

        $this->assertContains(['code' => 'pizza', 'name' => 'Pizza'], $response->json('cuisines'));
        $this->assertNotContains('bengali', $response->json('cuisines.*.code'));
        $this->assertContains(['code' => 'pure_veg', 'name' => 'Pure Veg', 'category' => 'DIETARY'], $response->json('features'));
        $this->assertSame(5, $response->json('limits.cuisines'));
    }

    // ───────────────────────────── Location and profile ─────────────────────────────

    public function test_staff_see_their_location_with_its_status_and_the_exact_availability_reason(): void
    {
        DB::table('restaurant_locations')->where('id', $this->burger->id)->update(['status' => 'SUSPENDED', 'status_note' => 'Suspended while a complaint is reviewed.']);
        DB::table('restaurant_admin_notes')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'note' => 'INTERNAL: inspector visit on Friday', 'created_at' => now()]);

        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('status', 'SUSPENDED')
            ->assertJsonPath('status_note', 'Suspended while a complaint is reviewed.')
            ->assertJsonPath('organization', ['id' => $this->organization->public_id, 'name' => 'Riverside', 'status' => 'APPROVED', 'status_note' => null])
            ->assertJsonPath('availability.visible_to_customers', false)
            ->assertJsonPath('availability.reason', 'LOCATION_SUSPENDED')
            ->assertJsonPath('market', 'IN')
            ->assertJsonPath('hours.version', 1)
            ->assertJsonPath('version', 1);

        $this->assertStringNotContainsString('INTERNAL', $response->getContent(), 'administrator notes stay with administrators');
    }

    public function test_the_profile_is_updated_validated_normalised_and_audited(): void
    {
        $response = $this->patchJson($this->url('/profile'), [
            'version' => 1,
            'name' => '  Burger   Hub Deluxe ',
            'branch_label' => 'Sector 62 · Noida',
            'short_description' => 'Burgers & fries 🍔',
            'description' => "Line one\r\n\r\n\r\n\r\nLine two with बर्गर",
            'pickup_instructions' => 'Counter 2, show your code.',
            'phone' => '0120 456-7890',
            'email' => 'Hello@BurgerHub.Example',
            'website' => 'https://burgerhub.example/menu',
            'price_level' => 3,
            'cuisines' => ['fast_food', 'burgers'],
            'features' => ['parking', 'quick_pickup'],
        ])->assertOk();

        $response->assertJsonPath('name', 'Burger Hub Deluxe')
            ->assertJsonPath('description', "Line one\n\nLine two with बर्गर")
            ->assertJsonPath('short_description', 'Burgers & fries 🍔')
            ->assertJsonPath('phone', '+911204567890')
            ->assertJsonPath('email', 'hello@burgerhub.example')
            ->assertJsonPath('price_level', 3)
            ->assertJsonPath('cuisines.*.code', ['fast_food', 'burgers'])
            ->assertJsonPath('features.*.code', ['quick_pickup', 'parking'])
            ->assertJsonPath('slug', 'burger-hub')
            ->assertJsonPath('version', 2);

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_location.profile_changed', $event->action);
        $this->assertSame('restaurant_user', $event->actor_type);
        $this->assertSame($this->owner->public_id, $event->actor_public_id);
        $this->assertSame($this->burger->public_id, $event->target_public_id);
        $this->assertSame('restaurant_locations', $event->target_type);
        $this->assertEquals(['from' => 'Burger Hub', 'to' => 'Burger Hub Deluxe'], $event->changes['name']);
        $this->assertEquals(['from' => [], 'to' => ['fast_food', 'burgers']], $event->changes['cuisines']);
        $this->assertEquals(['from' => null, 'to' => '+911204567890'], $event->changes['phone_e164']);

        // Customers see the change at once.
        $this->getJson('/api/v1/restaurants/burger-hub')->assertOk()->assertJsonPath('name', 'Burger Hub Deluxe')->assertJsonPath('cuisines.0.name', 'Fast Food');
    }

    public function test_a_stale_version_writes_nothing_and_a_change_of_nothing_is_not_a_change(): void
    {
        $this->patchJson($this->url('/profile'), ['version' => 1, 'name' => 'First'])->assertOk()->assertJsonPath('version', 2);

        $this->patchJson($this->url('/profile'), ['version' => 1, 'name' => 'Overwritten'])
            ->assertConflict()->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 2);
        $this->assertSame('First', DB::table('restaurant_locations')->where('id', $this->burger->id)->value('name'));

        $this->patchJson($this->url('/profile'), ['version' => 2, 'name' => 'First', 'cuisines' => []])->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(1, AuditEvent::query()->count());

        $this->patchJson($this->url('/profile'), ['name' => 'No version'])->assertUnprocessable();
    }

    public function test_fields_a_restaurant_must_not_decide_are_refused_and_never_written(): void
    {
        $before = (array) DB::table('restaurant_locations')->where('id', $this->burger->id)->first();

        foreach ([
            'status' => 'APPROVED', 'slug' => 'better-slug', 'organization_id' => 1, 'market_id' => 1, 'city_id' => 1, 'service_area_id' => 1,
            'latitude' => 19.07, 'longitude' => 72.87, 'timezone' => 'America/New_York', 'currency' => 'USD', 'formatted_address' => 'Somewhere else',
            'accepting_orders' => false, 'operational_status' => 'TEMPORARILY_CLOSED', 'status_note' => 'All fine', 'approved_at' => '2020-01-01',
        ] as $field => $value) {
            $this->patchJson($this->url('/profile'), ['version' => 1, 'name' => 'Sneaky', $field => $value])
                ->assertUnprocessable()->assertJsonPath("error.details.fields.{$field}.0", 'This field cannot be changed here.');
        }

        // A field the API has never heard of is simply not read.
        $this->patchJson($this->url('/profile'), ['version' => 1, 'is_featured' => true, 'commission_rate' => 0, 'hours_version' => 99])->assertOk()->assertJsonPath('version', 1);

        $this->assertSame($before, (array) DB::table('restaurant_locations')->where('id', $this->burger->id)->first());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_restaurant_text_is_plain_text_markup_is_refused(): void
    {
        foreach ([
            'name' => '<b>Burger</b> Hub',
            'description' => 'Best burgers <script>alert(1)</script>',
            'short_description' => '<img src=x onerror=alert(1)>',
            'pickup_instructions' => 'Counter <a href="https://evil.example">here</a>',
            'branch_label' => '</div><iframe>',
        ] as $field => $value) {
            $this->patchJson($this->url('/profile'), ['version' => 1, $field => $value])
                ->assertUnprocessable()->assertJsonPath("error.details.fields.{$field}.0", 'Formatting and HTML are not allowed here — plain text only.');
        }

        // Harmless text that merely contains the characters is fine, and control characters are dropped.
        $this->patchJson($this->url('/profile'), ['version' => 1, 'description' => "2 < 3 and 5 > 4 & \"quotes\"\u{0007} \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 'short_description' => "Tab\there"])->assertOk()
            ->assertJsonPath('description', "2 < 3 and 5 > 4 & \"quotes\" \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}")
            ->assertJsonPath('short_description', 'Tab here');

        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_profile_values_are_validated(): void
    {
        $invalid = fn (array $input, string $field) => $this->patchJson($this->url('/profile'), ['version' => 1, ...$input])->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details.fields');

        $invalid(['name' => ''], 'name');
        $invalid(['name' => 'X'], 'name');
        $invalid(['name' => str_repeat('a', 161)], 'name');
        $invalid(['description' => str_repeat('a', 2001)], 'description');
        $invalid(['phone' => 'call me'], 'phone');
        $invalid(['phone' => '+1 415 555 0100'], 'phone');
        $invalid(['email' => 'not-an-email'], 'email');
        $invalid(['website' => 'javascript:alert(1)'], 'website');
        $invalid(['website' => 'ftp://burgerhub.example'], 'website');
        $invalid(['price_level' => 5], 'price_level');
        $invalid(['cuisines' => ['pizza', 'sushi_fusion']], 'cuisines.1');
        $invalid(['cuisines' => ['pizza', 'pizza']], 'cuisines.0');
        $invalid(['cuisines' => ['pizza', 'burgers', 'cafe', 'thali', 'grill', 'asian']], 'cuisines');
        $invalid(['cuisines' => 'pizza'], 'cuisines');
        $invalid(['features' => ['teleporter']], 'features.0');

        DB::table('cuisines')->where('code', 'bengali')->update(['status' => 'INACTIVE']);
        $invalid(['cuisines' => ['bengali']], 'cuisines.0');

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(1, (int) DB::table('restaurant_locations')->where('id', $this->burger->id)->value('version'));
    }

    // ───────────────────────────── Accepting orders ─────────────────────────────

    public function test_orders_can_be_paused_with_a_reason_and_an_end_time_and_resumed(): void
    {
        $until = now()->addMinutes(45)->toIso8601String();

        $this->patchJson($this->url('/availability'), ['accepting_orders' => false, 'pause_reason' => 'Kitchen at capacity', 'paused_until' => $until])->assertOk()
            ->assertJsonPath('accepting_orders', false)
            ->assertJsonPath('pause_reason', 'Kitchen at capacity')
            ->assertJsonPath('paused_until', '2026-10-05T07:15:00+00:00')
            ->assertJsonPath('availability.open_state', 'OPEN')
            ->assertJsonPath('availability.accepting_orders', false)
            ->assertJsonPath('availability.reason', 'NOT_ACCEPTING_ORDERS')
            ->assertJsonPath('status', 'APPROVED');

        $this->getJson('/api/v1/restaurants/burger-hub')->assertOk()->assertJsonPath('availability.orderable', false)->assertJsonPath('availability.reason', 'NOT_ACCEPTING_ORDERS');

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_location.accepting_orders_changed', $event->action);
        $this->assertEquals(['from' => true, 'to' => false], $event->changes['accepting_orders']);
        $this->assertEquals(['from' => null, 'to' => 'Kitchen at capacity'], $event->changes['pause_reason']);

        // The pause runs out by itself …
        Carbon::setTestNow(now()->addMinutes(46));
        $this->getJson('/api/v1/restaurants/burger-hub')->assertJsonPath('availability.orderable', true);
        $this->getJson($this->url())->assertJsonPath('availability.accepting_orders', true);

        // … or is ended by the restaurant, which clears every trace of it.
        Carbon::setTestNow(now()->subMinutes(30));
        $this->patchJson($this->url('/availability'), ['accepting_orders' => true])->assertOk()
            ->assertJsonPath('accepting_orders', true)->assertJsonPath('pause_reason', null)->assertJsonPath('paused_until', null)->assertJsonPath('paused_at', null);
        $this->assertSame(2, AuditEvent::query()->count());

        $this->patchJson($this->url('/availability'), ['accepting_orders' => true])->assertOk();
        $this->assertSame(2, AuditEvent::query()->count(), 'switching on what is on is not an event');
    }

    public function test_a_pause_is_validated(): void
    {
        $this->patchJson($this->url('/availability'), [])->assertUnprocessable();
        $this->patchJson($this->url('/availability'), ['accepting_orders' => 'maybe'])->assertUnprocessable();
        $this->patchJson($this->url('/availability'), ['accepting_orders' => false, 'paused_until' => now()->subMinute()->toIso8601String()])->assertUnprocessable()->assertJsonPath('error.details.fields.paused_until.0', 'The end of a pause must be in the future.');
        $this->patchJson($this->url('/availability'), ['accepting_orders' => false, 'paused_until' => now()->addDays(8)->toIso8601String()])->assertUnprocessable();
        $this->patchJson($this->url('/availability'), ['accepting_orders' => false, 'paused_until' => 'next week sometime'])->assertUnprocessable();
        $this->patchJson($this->url('/availability'), ['accepting_orders' => false, 'pause_reason' => '<b>busy</b>'])->assertUnprocessable();
        $this->patchJson($this->url('/availability'), ['operational_status' => 'OPERATING', 'pause_reason' => 'no pause to explain'])->assertUnprocessable();
        $this->patchJson($this->url('/availability'), ['operational_status' => 'DEMOLISHED'])->assertUnprocessable();
        // The approval status is not reachable through this endpoint.
        $this->patchJson($this->url('/availability'), ['accepting_orders' => true, 'status' => 'APPROVED'])->assertOk()->assertJsonPath('version', 1);

        $this->assertTrue((bool) DB::table('restaurant_locations')->where('id', $this->burger->id)->value('accepting_orders'));
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_the_outlet_can_be_marked_temporarily_closed_regardless_of_its_hours(): void
    {
        $this->patchJson($this->url('/availability'), ['operational_status' => 'TEMPORARILY_CLOSED'])->assertOk()
            ->assertJsonPath('operational_status', 'TEMPORARILY_CLOSED')->assertJsonPath('availability.open_state', 'TEMPORARILY_CLOSED')->assertJsonPath('accepting_orders', true);
        $this->assertSame('restaurant_location.operational_status_changed', AuditEvent::query()->sole()->action);

        $this->getJson('/api/v1/restaurants/burger-hub')->assertOk()->assertJsonPath('availability.reason', 'TEMPORARILY_CLOSED');

        $this->patchJson($this->url('/availability'), ['operational_status' => 'OPERATING'])->assertOk()->assertJsonPath('availability.orderable', true);
    }

    // ───────────────────────────── Pickup settings ─────────────────────────────

    public function test_pickup_settings_show_what_is_saved_what_the_market_allows_and_the_limits(): void
    {
        $response = $this->getJson($this->url('/pickup-settings'))->assertOk();
        $this->assertNotNull($response->json('updated_at'));

        $this->assertEquals([
            'location_id' => $this->burger->public_id,
            'pickup_enabled' => true, 'asap_enabled' => true, 'scheduled_enabled' => false,
            'default_prep_minutes' => 15, 'minimum_lead_minutes' => 15, 'buffer_minutes' => 0, 'schedule_horizon_minutes' => 1440,
            'slot_interval_minutes' => 15, 'order_cutoff_minutes' => 0, 'capacity_per_slot' => null,
            'methods' => [
                ['method' => 'COUNTER', 'enabled' => true, 'instructions' => null, 'requires_vehicle_info' => false, 'available_in_market' => true],
                ['method' => 'CURBSIDE', 'enabled' => false, 'instructions' => null, 'requires_vehicle_info' => true, 'available_in_market' => false],
                ['method' => 'DRIVE_THROUGH', 'enabled' => false, 'instructions' => null, 'requires_vehicle_info' => true, 'available_in_market' => false],
            ],
            'market' => ['asap_allowed' => true, 'scheduled_allowed' => true],
            'limits' => [
                'default_prep_minutes' => [1, 240], 'minimum_lead_minutes' => [0, 720], 'buffer_minutes' => [0, 120], 'order_cutoff_minutes' => [0, 240],
                'schedule_horizon_minutes' => [0, 10080], 'slot_interval_minutes' => [5, 10, 15, 20, 30, 60], 'capacity_per_slot' => [1, 500],
            ],
            'version' => 1,
        ], array_diff_key($response->json(), ['updated_at' => 1]));
    }

    public function test_pickup_settings_are_updated_and_audited(): void
    {
        $this->patchJson($this->url('/pickup-settings'), [
            'version' => 1, 'default_prep_minutes' => 20, 'minimum_lead_minutes' => 25, 'scheduled_enabled' => true, 'schedule_horizon_minutes' => 2880,
            'slot_interval_minutes' => 30, 'capacity_per_slot' => 8, 'buffer_minutes' => 5, 'order_cutoff_minutes' => 30,
            'methods' => [['method' => 'COUNTER', 'enabled' => true, 'instructions' => 'Counter 2, ground floor']],
        ])->assertOk()
            ->assertJsonPath('default_prep_minutes', 20)->assertJsonPath('scheduled_enabled', true)->assertJsonPath('capacity_per_slot', 8)
            ->assertJsonPath('methods.0.instructions', 'Counter 2, ground floor')->assertJsonPath('version', 2);

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_location.pickup_settings_changed', $event->action);
        $this->assertEquals(['from' => 15, 'to' => 20], $event->changes['default_prep_minutes']);
        $this->assertEquals(['from' => ['COUNTER:on'], 'to' => ['COUNTER:on (Counter 2, ground floor)']], $event->changes['methods']);
        $this->assertSame($this->burger->public_id, $event->target_public_id);

        $this->getJson('/api/v1/restaurants/burger-hub')->assertJsonPath('pickup.scheduled', true)->assertJsonPath('pickup.default_prep_minutes', 20)
            ->assertJsonPath('pickup.methods.0.instructions', 'Counter 2, ground floor');

        $this->patchJson($this->url('/pickup-settings'), ['version' => 2, 'capacity_per_slot' => null])->assertOk()->assertJsonPath('capacity_per_slot', null)->assertJsonPath('version', 3);
        $this->patchJson($this->url('/pickup-settings'), ['version' => 2, 'default_prep_minutes' => 5])->assertConflict()->assertJsonPath('error.code', 'stale_update');
        $this->patchJson($this->url('/pickup-settings'), ['version' => 3, 'default_prep_minutes' => 20])->assertOk()->assertJsonPath('version', 3);
        $this->assertSame(2, AuditEvent::query()->count());
    }

    public function test_pickup_settings_keep_their_invariants(): void
    {
        $refused = fn (array $input, string $field) => $this->patchJson($this->url('/pickup-settings'), ['version' => 1, ...$input])->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details.fields');

        $refused(['default_prep_minutes' => 0], 'default_prep_minutes');
        $refused(['default_prep_minutes' => 241], 'default_prep_minutes');
        $refused(['minimum_lead_minutes' => 721], 'minimum_lead_minutes');
        $refused(['slot_interval_minutes' => 7], 'slot_interval_minutes');
        $refused(['capacity_per_slot' => 0], 'capacity_per_slot');
        $refused(['schedule_horizon_minutes' => 20000], 'schedule_horizon_minutes');
        $refused(['asap_enabled' => false], 'asap_enabled');
        $refused(['methods' => [['method' => 'COUNTER', 'enabled' => false]]], 'methods');
        $refused(['methods' => [['method' => 'TELEPORT', 'enabled' => true]]], 'methods.0.method');
        $refused(['methods' => [['method' => 'COUNTER', 'enabled' => true], ['method' => 'COUNTER', 'enabled' => false]]], 'methods.0.method');
        $refused(['methods' => [['method' => 'COUNTER', 'enabled' => true, 'instructions' => '<b>bold</b>']]], 'methods.0.instructions');
        $refused(['scheduled_enabled' => true, 'minimum_lead_minutes' => 120, 'schedule_horizon_minutes' => 60], 'schedule_horizon_minutes');
        $refused(['scheduled_enabled' => true, 'minimum_lead_minutes' => 0, 'schedule_horizon_minutes' => 10, 'slot_interval_minutes' => 30], 'schedule_horizon_minutes');

        // With pickup switched off altogether, nothing else has to be consistent.
        $this->patchJson($this->url('/pickup-settings'), ['version' => 1, 'pickup_enabled' => false, 'asap_enabled' => false])->assertOk()->assertJsonPath('pickup_enabled', false);
        $this->getJson('/api/v1/restaurants/burger-hub')->assertJsonPath('availability.reason', 'PICKUP_UNAVAILABLE');
    }

    public function test_a_location_cannot_switch_on_what_its_market_has_not_enabled(): void
    {
        $this->patchJson($this->url('/pickup-settings'), ['version' => 1, 'methods' => [['method' => 'CURBSIDE', 'enabled' => true]]])
            ->assertConflict()->assertJsonPath('error.code', 'pickup_method_not_available')->assertJsonPath('error.details.method', 'CURBSIDE');

        $this->market->forceFill(['features' => ['asap_pickup' => true, 'scheduled_pickup' => false, 'curbside_pickup' => true]])->save();

        $this->patchJson($this->url('/pickup-settings'), ['version' => 1, 'scheduled_enabled' => true])
            ->assertConflict()->assertJsonPath('error.code', 'pickup_mode_not_available')->assertJsonPath('error.details.mode', 'scheduled');
        $this->patchJson($this->url('/pickup-settings'), ['version' => 1, 'methods' => [['method' => 'CURBSIDE', 'enabled' => true, 'instructions' => 'Bay 3']]])->assertOk()
            ->assertJsonPath('methods.1', ['method' => 'CURBSIDE', 'enabled' => true, 'instructions' => 'Bay 3', 'requires_vehicle_info' => true, 'available_in_market' => true]);

        $this->assertSame(1, AuditEvent::query()->count());
    }

    // ───────────────────────────── Images ─────────────────────────────

    public function test_image_metadata_belongs_to_its_location(): void
    {
        $cover = $this->image($this->burger);
        $elsewhere = $this->image($this->brew);
        $foreign = $this->image($this->location($this->organization('Somebody Else'), 'Foreign Kitchen'));

        $this->patchJson($this->url('/images/'.$cover->public_id), ['alt_text' => 'A double cheeseburger', 'display_order' => 3])->assertOk()
            ->assertJsonPath('images.0', ['id' => $cover->public_id, 'type' => 'COVER', 'url' => '/images/food-burger.jpg', 'alt_text' => 'A double cheeseburger', 'display_order' => 3, 'width' => null, 'height' => null]);
        $this->assertSame('restaurant_location.image_changed', AuditEvent::query()->sole()->action);

        $this->patchJson($this->url('/images/'.$cover->public_id), ['alt_text' => '<svg onload=alert(1)>'])->assertUnprocessable();
        $this->patchJson($this->url('/images/'.$cover->public_id), ['path' => 'https://evil.example/x.png', 'status' => 'ACTIVE', 'location_id' => $this->brew->id])->assertOk();
        $this->assertSame('/images/food-burger.jpg', DB::table('restaurant_images')->where('id', $cover->id)->value('path'), 'the file reference is not editable');

        // An image of another location — same organization or not — is not found under this one.
        $this->patchJson($this->url('/images/'.$elsewhere->public_id), ['alt_text' => 'Mine now'])->assertNotFound();
        $this->patchJson($this->url('/images/'.$foreign->public_id), ['alt_text' => 'Mine now'])->assertNotFound();
        $this->deleteJson($this->url('/images/'.$foreign->public_id))->assertNotFound();
        $this->assertSame('ACTIVE', DB::table('restaurant_images')->where('id', $foreign->id)->value('status'));

        $this->deleteJson($this->url('/images/'.$cover->public_id))->assertOk()->assertJsonPath('images', []);
        $this->assertSame('ARCHIVED', DB::table('restaurant_images')->where('id', $cover->id)->value('status'), 'archived, not deleted');
        $this->getJson('/api/v1/restaurants/burger-hub')->assertJsonPath('images.cover', null);
    }

    public function test_every_response_is_built_with_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 6) as $n) {
            $this->hours($this->location($this->organization, 'Outlet '.$n), [[null, '09:00', '22:00']]);
        }

        // Warm-up: the caller's permissions are read once and cached.
        $this->getJson('/api/v1/restaurant/context')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/restaurant/context')->assertOk()->assertJsonCount(8, 'locations');
        $eight = array_column(DB::getQueryLog(), 'query');

        foreach (range(7, 20) as $n) {
            $this->hours($this->location($this->organization, 'Outlet '.$n), [[null, '09:00', '22:00']]);
        }
        DB::flushQueryLog();
        $this->getJson('/api/v1/restaurant/context')->assertOk()->assertJsonCount(22, 'locations');

        // The same statements, whatever the number of locations (only the size of the IN lists differs).
        $shape = fn (array $queries): array => array_map(fn (string $sql): string => preg_replace('/ in \([^)]*\)/', ' in (…)', $sql), $queries);
        $this->assertSame($shape($eight), $shape(array_column(DB::getQueryLog(), 'query')), 'no query per location');
        $this->assertLessThanOrEqual(20, count($eight));
    }
}
