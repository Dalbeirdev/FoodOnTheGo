<?php

namespace Tests\Feature\Geo;

use App\Enums\MarketStatus;
use App\Enums\Permission as P;
use App\Models\Market;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGeography;
use Tests\TestCase;

class PublicMarketApiTest extends TestCase
{
    use BuildsGeography, RefreshDatabase;

    private const BOX = [77.30, 28.50, 77.40, 28.60];

    public function test_the_market_list_contains_only_markets_open_to_customers(): void
    {
        $this->india();
        Market::factory()->create(['country_code' => 'US']);
        Market::factory()->create(['country_code' => 'GB', 'status' => MarketStatus::Paused]);
        Market::factory()->create(['country_code' => 'FR', 'status' => MarketStatus::Closed]);
        Market::factory()->create(['country_code' => 'AE', 'status' => MarketStatus::Pilot, 'name' => 'United Arab Emirates']);

        $response = $this->getJson('/api/v1/markets')->assertOk();

        $this->assertSame(['IN', 'AE'], array_column($response->json('data'), 'country_code'));
        $response->assertJsonMissingPath('data.0.version')->assertJsonMissingPath('data.0.bounds')->assertJsonMissingPath('data.0.created_at');
    }

    public function test_coverage_exposes_only_what_customers_may_see(): void
    {
        $india = $this->india();
        $open = $this->region($india);
        $disabled = $this->region($india, 'IN-WB', 'DISABLED', 'West Bengal');
        $noida = $this->city($open);
        $this->city($open, 'Agra', 27.1767, 78.0081, 'PLANNED');
        $this->city($open, 'Hidden', 27.5, 78.5, 'UNAVAILABLE');
        $this->city($disabled, 'Kolkata', 22.5726, 88.3639, 'ACTIVE');

        $live = $this->area($noida, self::BOX, name: 'Live', attributes: ['priority' => 7, 'launch_stage' => 'internal note']);
        $this->area($noida, self::BOX, 'PAUSED', 'Paused');
        $this->area($noida, self::BOX, 'TESTING', 'Testing');
        $this->area($noida, self::BOX, 'PLANNED', 'Planned');
        $this->area($noida, self::BOX, 'DISABLED', 'Disabled');
        $this->area($noida, self::BOX, name: 'Not yet', attributes: ['effective_from' => now()->addWeek()]);

        $this->corridor($india, [[77.0, 28.55], [78.0, 28.55]], name: 'Live corridor');
        $this->corridor($india, [[77.0, 28.55], [78.0, 28.55]], 'TESTING', name: 'Testing corridor');

        $coverage = $this->getJson('/api/v1/markets/current/coverage')->assertOk()->assertJsonPath('country_code', 'IN')->json();

        $this->assertSame(['IN-UP'], array_column($coverage['regions'], 'code'));
        $this->assertSame(['Agra', 'Noida'], array_column($coverage['cities'], 'name'));
        $this->assertSame(['Live', 'Paused'], array_column($coverage['service_areas'], 'name'));
        $this->assertSame(['Live corridor'], array_column($coverage['route_corridors'], 'name'));

        $area = $coverage['service_areas'][0];
        $this->assertSame($live->public_id, $area['id']);
        $this->assertSame('MultiPolygon', $area['geometry']['type']);
        $this->assertSame([77.3, 28.5], $area['geometry']['coordinates'][0][0][0]);
        $this->assertSame(['id', 'city_id', 'name', 'slug', 'status', 'geometry'], array_keys($area), 'no priority, launch stage, version or dates');
        $this->assertArrayNotHasKey('launch_stage', $coverage['cities'][0]);
        $this->assertArrayNotHasKey('version', $coverage['regions'][0]);
        $this->assertStringNotContainsString('internal note', (string) json_encode($coverage));
    }

    public function test_coverage_of_a_market_that_does_not_serve_customers_is_not_available(): void
    {
        $this->india();
        Market::factory()->create(['country_code' => 'US']);

        $this->getJson('/api/v1/markets/current/coverage?country=US')->assertNotFound()->assertJsonPath('error.code', 'market_unavailable');
        $this->getJson('/api/v1/markets/current/coverage?country=usa')->assertUnprocessable();
    }

    public function test_public_market_data_is_cached_and_every_administrative_change_invalidates_it(): void
    {
        $india = $this->india();
        $area = $this->area($this->city($this->region($india)), self::BOX);

        $this->getJson('/api/v1/markets/current')->assertJsonPath('features.reviews', true);
        $this->getJson('/api/v1/markets/current/coverage')->assertJsonCount(1, 'service_areas');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/markets/current')->assertOk();
        $cachedQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "markets"'))->count();
        DB::disableQueryLog();
        $this->assertSame(0, $cachedQueries, 'the second read of the market payload is served from the cache');

        // A change behind the cache's back is not visible …
        DB::table('service_areas')->update(['status' => 'PAUSED']);
        $this->getJson('/api/v1/markets/current/coverage')->assertJsonPath('service_areas.0.status', 'ACTIVE');
        // … but the availability decision never reads the cache.
        $this->postJson('/api/v1/availability/location', ['lat' => 28.55, 'lng' => 77.35])->assertJson(['supported' => false]);
        DB::table('service_areas')->update(['status' => 'ACTIVE']);

        // … and a change made through the API is visible at once.
        $this->actingAsPrincipal($this->adminWith([P::AdminServiceAreasManage, P::AdminMarketFeaturesManage]));
        $this->patchJson('/api/v1/admin/service-areas/'.$area->public_id, ['version' => 1, 'status' => 'PAUSED', 'reason' => 'Cache test'])->assertOk();
        $this->getJson('/api/v1/markets/current/coverage')->assertJsonPath('service_areas.0.status', 'PAUSED');

        $this->patchJson('/api/v1/admin/markets/'.$india->public_id.'/features', ['version' => 1, 'features' => ['reviews' => false], 'reason' => 'Cache test'])->assertOk();
        $this->getJson('/api/v1/markets/current')->assertJsonPath('features.reviews', false);
    }

    public function test_the_seeded_local_fixtures_answer_like_the_prototype_did(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);   // idempotent

        $this->assertSame([36, 23, 15, 7], [DB::table('market_regions')->count(), DB::table('cities')->count(), DB::table('service_areas')->count(), DB::table('route_corridors')->count()]);
        $this->assertSame(27, DB::table('market_regions')->where('status', 'PLANNED')->count(), 'a region in the table is reference geography, not an operating claim');

        $check = fn (float $lat, float $lng) => $this->postJson('/api/v1/availability/location', ['lat' => $lat, 'lng' => $lng])->assertOk();

        $check(28.6139, 77.209)->assertJson(['supported' => true, 'city' => ['name' => 'Delhi'], 'region' => ['code' => 'IN-DL']])->assertJsonCount(2, 'route_corridors');
        $check(28.4595, 77.0266)->assertJson(['supported' => true, 'city' => ['name' => 'Gurugram', 'status' => 'PILOT']]);
        $check(20.3893, 72.9106)->assertJson(['supported' => false, 'reason' => 'CITY_UNAVAILABLE']);          // Vapi: paused city
        $check(19.076, 72.8777)->assertJson(['supported' => false, 'reason' => 'REGION_UNAVAILABLE']);          // Mumbai: planned state
        $check(22.5726, 88.3639)->assertJson(['supported' => false, 'reason' => 'REGION_UNAVAILABLE']);         // Kolkata: disabled state
        $check(30.901, 75.8573)->assertJson(['supported' => false, 'reason' => 'CITY_UNAVAILABLE']);            // Ludhiana: planned city, pilot state
        $check(28.9, 77.6)->assertJson(['supported' => false, 'reason' => 'OUTSIDE_SERVICE_AREA']);             // between cities
        $check(51.5072, -0.1276)->assertJson(['supported' => false, 'reason' => 'MARKET_UNSUPPORTED']);         // London

        $coverage = $this->getJson('/api/v1/markets/current/coverage')->assertOk()->json();
        $this->assertNotContains('Kolkata', array_column($coverage['cities'], 'name'));
        $this->assertCount(3, $coverage['route_corridors']);
        $this->assertSame(33, count($coverage['service_areas'][0]['geometry']['coordinates'][0][0]), 'fixture circles are 32-gons');

        $config = DB::table('market_configurations')->sole();
        $this->assertSame(['cash_at_pickup', 'cross_border_ordering'], json_decode($config->locked_features, true));
    }
}
