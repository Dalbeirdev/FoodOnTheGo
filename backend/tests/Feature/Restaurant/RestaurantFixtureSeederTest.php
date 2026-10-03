<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RestaurantTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The development fixtures: they must cover every availability outcome, place every outlet through PostGIS,
 * and derive the fixture accounts' access from memberships. Wednesday 2026-10-07 13:00 in India.
 */
class RestaurantFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_taxonomy_contains_the_india_launch_cuisines(): void
    {
        $names = DB::table('cuisines')->orderBy('display_order')->pluck('name')->all();

        $this->assertSame([
            'North Indian', 'South Indian', 'Punjabi', 'Biryani', 'Chinese', 'Street Food', 'Pizza', 'Burgers', 'Cafe', 'Desserts', 'Beverages',
            'Gujarati', 'Rajasthani', 'Bengali', 'Maharashtrian', 'Kerala', 'Andhra / Telangana', 'Healthy', 'Continental',
        ], array_slice($names, 0, 19));
        $this->assertCount(count(RestaurantTaxonomySeeder::CUISINES), $names);
        $this->assertSame(['DIETARY', 'FACILITY', 'SERVICE'], DB::table('restaurant_features')->distinct()->orderBy('category')->pluck('category')->all());
    }

    public function test_the_fixtures_cover_every_availability_outcome(): void
    {
        $this->assertSame(17, DB::table('restaurant_organizations')->count());
        $this->assertSame(19, DB::table('restaurant_locations')->count());

        $reasons = collect($this->getJson('/api/v1/restaurants?page[size]=100')->assertOk()->json('data'))->mapWithKeys(fn (array $r): array => [$r['slug'] => $r['availability']['reason']]);

        // Visible to customers: 14 of the 19. The rest is unapproved, suspended or outside every service area.
        $this->assertEqualsCanonicalizing([
            'burger-hub', 'brew-bites', 'healthy-bites', 'pizza-point', 'spice-nest', 'wok-express', 'dhaba-junction-ropar', 'hoshiarpur-sweets-and-snacks',
            'pathankot-punjabi-rasoi', 'ambala-chai-point', 'jaipur-rajwada-thali', 'ahmedabad-gujarati-bhojan', 'vapi-highway-coffee', 'night-owl-kitchen',
        ], $reasons->keys()->all());

        $this->assertNull($reasons['burger-hub'], 'approved, open, accepting');
        $this->assertNull($reasons['dhaba-junction-ropar'], '24 hours');
        $this->assertNull($reasons['spice-nest'], '13:00 is inside its first period');
        $this->assertSame('CLOSED_NOW', $reasons['night-owl-kitchen'], 'approved but closed by day');
        $this->assertSame('NOT_ACCEPTING_ORDERS', $reasons['hoshiarpur-sweets-and-snacks'], 'approved, open, paused');
        $this->assertSame('TEMPORARILY_CLOSED', $reasons['wok-express']);
        $this->assertSame('AREA_UNAVAILABLE', $reasons['vapi-highway-coffee'], 'its city and service area are paused');

        foreach (['vadodara-expressway-grill' => 'organization suspended', 'surat-dhokla-house' => 'under review', 'udaipur-lakeside-cafe' => 'outside every service area', 'tandoori-trails-karnal' => 'draft', 'curry-leaf-gurugram' => 'rejected'] as $slug => $why) {
            $this->getJson('/api/v1/restaurants/'.$slug)->assertNotFound();
            $this->assertFalse($reasons->has($slug), $why);
        }

        // Spice Nest closes between its two periods; the overnight restaurant is open after midnight.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 16:00', 'Asia/Kolkata'));
        $this->getJson('/api/v1/restaurants/spice-nest')->assertJsonPath('availability.reason', 'CLOSED_NOW')->assertJsonPath('availability.opens_next_at', '2026-10-07T12:30:00+00:00');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 01:30', 'Asia/Kolkata'));
        $this->getJson('/api/v1/restaurants/pathankot-punjabi-rasoi')->assertJsonPath('availability.open_now', true)->assertJsonPath('availability.closes_at', '2026-10-07T20:30:00+00:00');
        $this->getJson('/api/v1/restaurants/night-owl-kitchen')->assertJsonPath('availability.orderable', true);

        // The special closure of the day after seeding, and a closed weekday (Monday the 12th).
        $this->getJson('/api/v1/restaurants/ambala-chai-point')->assertJsonPath('hours.special.0', ['date' => '2026-10-08', 'is_closed' => true, 'periods' => [], 'note' => 'Closed for staff training']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 12:00', 'Asia/Kolkata'));
        $this->getJson('/api/v1/restaurants/ambala-chai-point')->assertJsonPath('availability.reason', 'CLOSED_NOW');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 12:00', 'Asia/Kolkata'));
        $this->getJson('/api/v1/restaurants/ahmedabad-gujarati-bhojan')->assertJsonPath('availability.reason', 'CLOSED_NOW')->assertJsonPath('hours.weekly.1.periods', []);
    }

    public function test_every_fixture_outlet_was_placed_by_its_coordinates(): void
    {
        $placed = DB::table('restaurant_locations as l')->join('cities as c', 'c.id', '=', 'l.city_id')->leftJoin('service_areas as sa', 'sa.id', '=', 'l.service_area_id')
            ->orderBy('l.slug')->get(['l.slug', 'c.name as city', 'sa.slug as area'])->mapWithKeys(fn (object $r): array => [$r->slug => [$r->city, $r->area]]);

        $this->assertSame(['Noida', 'noida-62'], $placed['burger-hub']);
        $this->assertSame(['Noida', 'noida-expressway'], $placed['pizza-point']);
        $this->assertSame(['Delhi', 'delhi-central'], $placed['night-owl-kitchen']);
        $this->assertSame(['Rupnagar', 'rupnagar-nh205'], $placed['dhaba-junction-ropar']);
        $this->assertSame(['Udaipur', null], $placed['udaipur-lakeside-cafe'], 'a known city, but no service area there');
        $this->assertSame(['Vapi', 'vapi-nh48'], $placed['vapi-highway-coffee']);

        // The stored association equals what PostGIS says today for every one of them.
        $mismatches = DB::select('select l.slug from restaurant_locations l where l.service_area_id is distinct from ('
            .'select sa.id from service_areas sa where sa.market_id = l.market_id and ST_Covers(sa.geometry, l.location::geometry) order by sa.priority desc, ST_Area(sa.geometry), sa.id limit 1)');
        $this->assertSame([], $mismatches);
    }

    public function test_fixture_accounts_get_their_access_from_memberships(): void
    {
        $assignments = fn (string $email): array => DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.principal_type', 'restaurant_user')->where('role_assignments.principal_id', RestaurantUser::query()->where('email', $email)->value('id'))
            ->orderBy('role_assignments.scope_type')->get(['roles.code', 'role_assignments.scope_type'])->map(fn (object $a): string => $a->code.' '.$a->scope_type)->all();

        $this->assertSame(['OWNER organization'], $assignments('john@riverside.example'));
        $this->assertSame(['MANAGER location', 'MANAGER location'], $assignments('sarah@riverside.example'));
        $this->assertSame(['ORDER_STAFF location'], $assignments('mike@riverside.example'));
        $this->assertSame(['MENU_MANAGER organization'], $assignments('emily@riverside.example'));
        $this->assertSame([], $assignments('yuki@riverside.example'), 'invited: nothing yet');
        $this->assertSame([], $assignments('suspended@riverside.example'));
        $this->assertSame(['OWNER organization'], $assignments('owner@second-kitchen.example'));

        $context = fn (string $email): array => $this->actingAsPrincipal(RestaurantUser::query()->where('email', $email)->firstOrFail())->getJson('/api/v1/restaurant/context')->assertOk()->json();

        $john = $context('john@riverside.example');
        $this->assertSame(['Burger Hub', 'Brew & Bites', 'Healthy Bites'], array_column($john['locations'], 'name'));
        $this->assertSame('Riverside Hospitality Group', $john['organizations'][0]['name']);
        $this->assertSame(RestaurantLocation::query()->where('slug', 'burger-hub')->value('public_id'), $john['default_location_id']);

        $this->assertSame(['Burger Hub', 'Brew & Bites'], array_column($context('sarah@riverside.example')['locations'], 'name'));
        $this->assertSame(['Burger Hub'], array_column($context('mike@riverside.example')['locations'], 'name'));
        $this->assertSame(['Pizza Point'], array_column($context('owner@second-kitchen.example')['locations'], 'name'));
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $snapshot = fn (): array => [
            DB::table('restaurant_organizations')->count(), DB::table('restaurant_locations')->count(), DB::table('restaurant_location_hours')->count(),
            DB::table('restaurant_special_hours')->count(), DB::table('restaurant_memberships')->count(), DB::table('restaurant_images')->count(),
            DB::table('restaurant_admin_notes')->count(), DB::table('cuisines')->count(), DB::table('restaurant_pickup_settings')->count(),
            DB::table('role_assignments')->where('principal_type', 'restaurant_user')->count(),
        ];
        $before = $snapshot();

        // A local change survives a re-seed.
        DB::table('restaurant_locations')->where('slug', 'burger-hub')->update(['name' => 'Burger Hub (renamed locally)']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $snapshot());
        $this->assertSame('Burger Hub (renamed locally)', DB::table('restaurant_locations')->where('slug', 'burger-hub')->value('name'));
    }
}
