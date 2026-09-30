<?php

namespace Tests\Feature\Geo;

use App\Enums\AvailabilityReason;
use App\Models\Market;
use App\Services\Market\Availability;
use App\Services\Market\MarketAvailabilityService;
use App\Support\Geo\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGeography;
use Tests\TestCase;

/**
 * Location availability, decided by PostGIS against real rows. The test area is the square
 * 77.30..77.40 E, 28.50..28.60 N around Noida.
 */
class AvailabilityTest extends TestCase
{
    use BuildsGeography, RefreshDatabase;

    private const BOX = [77.30, 28.50, 77.40, 28.60];

    private function check(float $lat, float $lng, ?string $country = null): Availability
    {
        return app(MarketAvailabilityService::class)->check(new Location($lat, $lng, $country));
    }

    public function test_a_point_inside_an_active_area_of_an_open_city_region_and_market_is_supported(): void
    {
        $city = $this->city($this->region($this->india()));
        $area = $this->area($city, self::BOX);

        $result = $this->check(28.55, 77.35);

        $this->assertTrue($result->supported);
        $this->assertNull($result->reason);
        $this->assertTrue($result->serviceArea->is($area));
        $this->assertSame('Noida', $result->city->name);
        $this->assertSame('IN-UP', $result->region->code);
        $this->assertSame('IN', $result->market->country_code);
    }

    public function test_a_point_outside_every_area_is_outside_the_service_area_even_though_the_city_is_active(): void
    {
        $this->area($this->city($this->region($this->india())), self::BOX);

        $result = $this->check(28.65, 77.35);

        $this->assertFalse($result->supported);
        $this->assertSame(AvailabilityReason::OutsideServiceArea, $result->reason);
        $this->assertSame('Noida', $result->city->name, 'the nearest open city is still named');
    }

    public function test_a_point_exactly_on_the_boundary_counts_as_inside(): void
    {
        $this->area($this->city($this->region($this->india())), self::BOX);

        $this->assertTrue($this->check(28.55, 77.40)->supported, 'edge');
        $this->assertTrue($this->check(28.60, 77.40)->supported, 'corner');
        $this->assertFalse($this->check(28.55, 77.4001)->supported, 'just outside');
    }

    public function test_overlapping_areas_resolve_by_priority_then_by_the_smaller_area(): void
    {
        $city = $this->city($this->region($this->india()));
        $large = $this->area($city, self::BOX, name: 'Large');
        $small = $this->area($city, [77.34, 28.54, 77.36, 28.56], name: 'Small');

        $this->assertTrue($this->check(28.55, 77.35)->serviceArea->is($small), 'same priority: the smaller, more specific area');

        DB::table('service_areas')->where('id', $large->id)->update(['priority' => 10]);
        $this->assertTrue($this->check(28.55, 77.35)->serviceArea->is($large), 'higher priority wins');
    }

    public function test_an_overlapping_active_area_still_serves_when_the_preferred_one_is_paused(): void
    {
        $city = $this->city($this->region($this->india()));
        $this->area($city, [77.34, 28.54, 77.36, 28.56], 'PAUSED', 'Small paused');
        $large = $this->area($city, self::BOX, name: 'Large');

        $result = $this->check(28.55, 77.35);

        $this->assertTrue($result->supported);
        $this->assertTrue($result->serviceArea->is($large));
    }

    public function test_only_an_active_area_serves_customers(): void
    {
        $city = $this->city($this->region($this->india()));

        foreach (['PLANNED', 'TESTING', 'PAUSED', 'DISABLED'] as $status) {
            DB::table('service_areas')->delete();
            $this->area($city, self::BOX, $status);

            $result = $this->check(28.55, 77.35);
            $this->assertFalse($result->supported, $status);
            $this->assertSame(AvailabilityReason::ServiceAreaPaused, $result->reason, $status);
        }
    }

    public function test_effective_dates_are_enforced_with_the_server_clock(): void
    {
        $city = $this->city($this->region($this->india()));
        $this->area($city, self::BOX, attributes: ['effective_from' => now()->addDay()]);
        $this->assertFalse($this->check(28.55, 77.35)->supported, 'not started yet');

        $this->travel(2)->days();
        $this->assertTrue($this->check(28.55, 77.35)->supported, 'started');

        DB::table('service_areas')->update(['effective_until' => now()->subHour()]);
        $this->assertFalse($this->check(28.55, 77.35)->supported, 'ended');
    }

    public function test_a_child_is_never_more_available_than_its_parent(): void
    {
        $india = $this->india();
        $region = $this->region($india);
        $city = $this->city($region);
        $this->area($city, self::BOX);
        $this->assertTrue($this->check(28.55, 77.35)->supported);

        DB::table('cities')->update(['status' => 'PAUSED']);
        $paused = $this->check(28.55, 77.35);
        $this->assertSame(AvailabilityReason::CityUnavailable, $paused->reason);
        $this->assertNull($paused->city, 'a city that is not open is not described');

        DB::table('cities')->update(['status' => 'ACTIVE']);
        DB::table('market_regions')->update(['status' => 'DISABLED']);
        $this->assertSame(AvailabilityReason::RegionUnavailable, $this->check(28.55, 77.35)->reason);

        DB::table('market_regions')->update(['status' => 'ACTIVE']);
        DB::table('markets')->update(['status' => 'PAUSED']);
        $this->assertSame(AvailabilityReason::MarketPaused, $this->check(28.55, 77.35)->reason);
    }

    public function test_pilot_regions_and_cities_serve_customers(): void
    {
        $this->area($this->city($this->region($this->india(), status: 'PILOT'), status: 'PILOT'), self::BOX);

        $this->assertTrue($this->check(28.55, 77.35)->supported);
    }

    public function test_a_point_near_a_planned_city_reports_the_city_as_unavailable(): void
    {
        $this->city($this->region($this->india(), 'IN-MH', 'ACTIVE', 'Maharashtra'), 'Mumbai', 19.076, 72.8777, 'PLANNED');

        $result = $this->check(19.08, 72.88);

        $this->assertSame(AvailabilityReason::CityUnavailable, $result->reason);
        $this->assertNull($result->city);
    }

    public function test_a_point_far_from_any_known_city_is_outside_the_service_area(): void
    {
        $this->city($this->region($this->india()));

        $result = $this->check(15.0, 78.0);

        $this->assertSame(AvailabilityReason::OutsideServiceArea, $result->reason);
        $this->assertNull($result->city);
        $this->assertSame('IN', $result->market->country_code);
    }

    public function test_a_point_outside_every_market_or_in_a_draft_market_is_unsupported_without_naming_the_market(): void
    {
        $this->india();
        $us = Market::factory()->create(['country_code' => 'US']);
        $us->setBounds(-125.0, 24.0, -66.0, 49.5);

        foreach ([[51.5, -0.12], [40.71, -74.0]] as [$lat, $lng]) {
            $result = $this->check($lat, $lng);
            $this->assertSame(AvailabilityReason::MarketUnsupported, $result->reason);
            $this->assertNull($result->market, 'a draft market is never revealed');
        }
    }

    public function test_a_contradicting_country_hint_is_not_served_by_the_market_whose_envelope_contains_the_point(): void
    {
        $this->area($this->city($this->region($this->india())), self::BOX);

        $this->assertTrue($this->check(28.55, 77.35, 'IN')->supported);
        $this->assertSame(AvailabilityReason::MarketUnsupported, $this->check(28.55, 77.35, 'NP')->reason);
    }

    public function test_the_public_endpoint_answers_with_machine_readable_reasons_and_no_internal_fields(): void
    {
        $this->freezeTime();
        $area = $this->area($this->city($this->region($this->india())), self::BOX);

        $this->postJson('/api/v1/availability/location', ['lat' => 28.55, 'lng' => 77.35])
            ->assertOk()
            ->assertJson(['supported' => true, 'reason' => null, 'market' => ['country_code' => 'IN'], 'city' => ['name' => 'Noida'], 'service_area' => ['id' => $area->public_id], 'route_corridors' => []])
            ->assertJsonMissingPath('service_area.priority')
            ->assertJsonMissingPath('service_area.geometry')
            ->assertJsonMissingPath('city.launch_stage');

        $this->postJson('/api/v1/availability/location', ['lat' => 28.75, 'lng' => 77.35])
            ->assertOk()
            ->assertJson(['supported' => false, 'reason' => 'OUTSIDE_SERVICE_AREA', 'service_area' => null]);

        $this->postJson('/api/v1/availability/location', ['lat' => 48.85, 'lng' => 2.35])
            ->assertOk()
            ->assertExactJson(['supported' => false, 'reason' => 'MARKET_UNSUPPORTED', 'market' => null, 'region' => null, 'city' => null, 'service_area' => null, 'route_corridors' => [], 'checked_at' => now()->toIso8601String()]);
    }

    public function test_the_public_endpoint_validates_coordinates_and_is_rate_limited(): void
    {
        $this->india();

        foreach ([[], ['lat' => 91, 'lng' => 77], ['lat' => 28, 'lng' => 181], ['lat' => 'abc', 'lng' => 77], ['lat' => 28, 'lng' => 77, 'country_code' => 'india']] as $body) {
            $this->postJson('/api/v1/availability/location', $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/v1/availability/location', ['lat' => 28.55, 'lng' => 77.35])->assertOk();
        }
        $this->postJson('/api/v1/availability/location', ['lat' => 28.55, 'lng' => 77.35])->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_coordinates_are_bound_parameters_never_sql(): void
    {
        $this->india();

        $this->postJson('/api/v1/availability/location', ['lat' => '28.55); drop table markets; --', 'lng' => 77.35])->assertUnprocessable();
        $this->assertSame(1, Market::query()->count());
    }

    public function test_containment_uses_the_spatial_index(): void
    {
        $city = $this->city($this->region($this->india()));
        $this->area($city, self::BOX);

        DB::statement('set local enable_seqscan = off');
        $plan = collect(DB::select('explain select id from service_areas where ST_Covers(geometry, ST_SetSRID(ST_MakePoint(77.35, 28.55), 4326))'))->pluck('QUERY PLAN')->implode("\n");

        $this->assertStringContainsString('service_areas_geometry_gist', $plan);
    }
}
