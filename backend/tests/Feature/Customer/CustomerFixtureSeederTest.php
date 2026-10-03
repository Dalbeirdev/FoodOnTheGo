<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalCustomerFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The development customer-account fixtures (Module 25): Rahul's profile, favorites (open, closed, hidden),
 * saved journey locations (inside and outside coverage, abroad, without coordinates), payment-method references
 * (default, UPI, expired — no credential anywhere) and one explicit notification choice. Seeding again changes
 * nothing. Wednesday 2026-10-07 13:00 in India.
 */
class CustomerFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    private Customer $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);
        $this->rahul = Customer::query()->where('phone_e164', '+919876543210')->firstOrFail();
        $this->actingAsPrincipal($this->rahul);
    }

    public function test_rahul_has_a_profile_favorites_locations_payment_references_and_a_preference(): void
    {
        $profile = $this->getJson('/api/v1/customer/profile')->assertOk()->json();
        $this->assertSame(['Rahul Sharma', '1990-03-15', 'MALE', 20, 'en-IN'], [$profile['name'], $profile['date_of_birth'], $profile['gender'], $profile['search_radius_km'], $profile['preferred_locale']]);
        $this->assertSame(['burgers', 'healthy', 'north_indian'], collect($profile['favorite_cuisines'])->pluck('code')->sort()->values()->all());

        $favorites = $this->getJson('/api/v1/customer/favorites?page[size]=50')->assertOk()->json('data');
        $this->assertSame(['burger-hub', 'night-owl-kitchen', 'spice-nest', 'vadodara-expressway-grill'], collect($favorites)->pluck('slug')->sort()->values()->all());
        $byslug = collect($favorites)->keyBy('slug');
        $this->assertFalse($byslug['vadodara-expressway-grill']['available'], 'a favorite of a hidden restaurant is kept and marked unavailable');
        $this->assertNull($byslug['vadodara-expressway-grill']['restaurant']);
        $this->assertTrue($byslug['burger-hub']['available']);
        $this->assertNotNull($byslug['burger-hub']['restaurant']['availability']);

        $locations = collect($this->getJson('/api/v1/customer/saved-locations')->assertOk()->json())->keyBy('label');
        $this->assertSame(['Home', 'Work', 'Mumbai hotel', 'Dubai airport', "Grandma's place"], $locations->keys()->all());
        $this->assertTrue($locations['Home']['is_default']);
        $this->assertSame('supported', $locations['Home']['coverage']['status']);
        $this->assertSame(['unsupported', 'OUTSIDE_SERVICE_AREA', 'Noida'], [$locations['Work']['coverage']['status'], $locations['Work']['coverage']['reason'], $locations['Work']['coverage']['city']]); // Sector 142: in the city, outside the fixture service areas
        $this->assertSame('unsupported', $locations['Mumbai hotel']['coverage']['status']);
        $this->assertSame(['unsupported', 'MARKET_UNSUPPORTED', 'AE'], [$locations['Dubai airport']['coverage']['status'], $locations['Dubai airport']['coverage']['reason'], $locations['Dubai airport']['address']['country_code']]);
        $this->assertSame(['unknown', null], [$locations["Grandma's place"]['coverage']['status'], $locations["Grandma's place"]['location']]);

        $methods = $this->getJson('/api/v1/customer/payment-methods')->assertOk()->json();
        $this->assertSame(['Visa •••• 4242', 'UPI ra***@okaxis', 'Mastercard •••• 4444'], array_column($methods, 'display_label'));
        $this->assertSame([true, false, false], array_column($methods, 'is_default'));
        $this->assertSame(['ACTIVE', 'ACTIVE', 'EXPIRED'], array_column($methods, 'status'));
        $this->assertSame('development', $methods[0]['provider']);
        $this->assertStringNotContainsString('4242424242424242', json_encode(DB::table('customer_payment_methods')->get()));

        $matrix = $this->getJson('/api/v1/customer/notification-preferences')->assertOk()->json();
        $orderUpdates = collect($matrix['categories'])->firstWhere('category', 'ORDER_UPDATES');
        $this->assertFalse(collect($orderUpdates['channels'])->firstWhere('channel', 'EMAIL')['enabled']);
        $this->assertTrue(collect($orderUpdates['channels'])->firstWhere('channel', 'PUSH')['enabled']);
    }

    public function test_the_second_fixture_customer_sees_only_her_own_data(): void
    {
        $asha = Customer::query()->where('phone_e164', '+919876543212')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($asha);
        $this->assertSame(['pizza-point'], array_column($this->getJson('/api/v1/customer/favorites')->assertOk()->json('data'), 'slug'));
        $this->assertSame([], $this->getJson('/api/v1/customer/saved-locations')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/v1/customer/payment-methods')->assertOk()->json());
        $this->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('name', 'Asha Verma');
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $snapshot = fn (): array => [
            'customers' => DB::table('customers')->count(),
            'favorites' => DB::table('customer_favorite_locations')->count(),
            'locations' => DB::table('customer_saved_locations')->count(),
            'defaults' => DB::table('customer_saved_locations')->where('is_default', true)->count(),
            'methods' => DB::table('customer_payment_methods')->count(),
            'default_methods' => DB::table('customer_payment_methods')->where('is_default', true)->count(),
            'preferences' => DB::table('customer_notification_preferences')->count(),
            'version' => (int) $this->rahul->fresh()->version,
        ];
        $before = $snapshot();
        $this->seed(LocalCustomerFixtureSeeder::class);
        $this->assertSame($before, $snapshot());
        $this->assertSame(1, $before['defaults']);
        $this->assertSame(1, $before['default_methods']);
    }
}
