<?php

namespace Tests\Feature\Geo;

use App\Models\Market;
use App\Services\Geo\DistanceService;
use App\Services\Geo\RouteCorridorQuery;
use App\Services\Market\JourneyCoverage;
use App\Support\Geo\GeoJsonGeometry;
use App\Support\Geo\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGeography;
use Tests\TestCase;

/**
 * Corridor = centreline + width in metres. The test corridor runs west → east along latitude 29.0 N from
 * 77.0 E to 78.0 E, 5 km wide. At that latitude 0.01° of latitude ≈ 1.11 km.
 */
class RouteCorridorTest extends TestCase
{
    use BuildsGeography, RefreshDatabase;

    private const LINE = [[77.0, 29.0], [78.0, 29.0]];

    private function line(array $coordinates): GeoJsonGeometry
    {
        return GeoJsonGeometry::fromInput(['type' => 'LineString', 'coordinates' => $coordinates], ['LineString']);
    }

    public function test_a_point_is_in_a_corridor_when_it_is_within_the_corridor_width_of_the_centreline(): void
    {
        $india = $this->india();
        $corridor = $this->corridor($india, self::LINE);
        $query = app(RouteCorridorQuery::class);

        $on = $query->containing(new Location(29.0, 77.5), $india);
        $this->assertTrue($on->first()->is($corridor));
        // Distances are geodesic: the shortest path between the two ends bows ≈ 100 m north of the parallel.
        $this->assertEqualsWithDelta(102, $on->first()->distance_m, 10);

        $near = $query->containing(new Location(29.04, 77.5), $india);   // ≈ 4.4 km north
        $this->assertCount(1, $near);
        $this->assertEqualsWithDelta(4330, $near->first()->distance_m, 60);

        $this->assertCount(0, $query->containing(new Location(29.05, 77.5), $india), '≈ 5.5 km: outside a 5 km corridor');
        $this->assertCount(0, $query->containing(new Location(29.0, 78.1), $india), 'beyond the end of the line');
    }

    public function test_the_width_is_per_corridor(): void
    {
        $india = $this->india();
        $this->corridor($india, self::LINE, width: 10000);

        $this->assertCount(1, app(RouteCorridorQuery::class)->containing(new Location(29.05, 77.5), $india));
    }

    public function test_only_active_corridors_in_effect_are_found(): void
    {
        $india = $this->india();
        $query = app(RouteCorridorQuery::class);

        foreach (['PLANNED', 'TESTING', 'PAUSED', 'DISABLED'] as $status) {
            $this->corridor($india, self::LINE, $status, name: $status);
        }
        $this->corridor($india, self::LINE, name: 'Future', attributes: ['effective_from' => now()->addWeek()]);
        $this->corridor($india, self::LINE, name: 'Ended', attributes: ['effective_until' => now()->subDay()]);
        $this->assertCount(0, $query->containing(new Location(29.0, 77.5), $india));

        $active = $this->corridor($india, self::LINE, name: 'Live');
        $this->assertSame([$active->id], $query->containing(new Location(29.0, 77.5), $india)->modelKeys());
    }

    public function test_intersecting_is_topology_and_within_is_proximity(): void
    {
        $india = $this->india();
        $this->corridor($india, self::LINE);
        $query = app(RouteCorridorQuery::class);

        $crossing = $this->line([[77.5, 28.9], [77.5, 29.1]]);                 // crosses the centreline
        $parallel = $this->line([[77.2, 29.02], [77.8, 29.02]]);               // ≈ 2.2 km north, never touches
        $far = $this->line([[77.2, 29.2], [77.8, 29.2]]);                      // ≈ 22 km north

        $this->assertCount(1, $query->intersecting($crossing, $india));
        $this->assertCount(1, $query->within($crossing, $india));

        $this->assertCount(0, $query->intersecting($parallel, $india), 'near is not intersecting');
        $this->assertCount(1, $query->within($parallel, $india), 'but it is inside the corridor band');

        $this->assertCount(0, $query->intersecting($far, $india));
        $this->assertCount(0, $query->within($far, $india));
    }

    public function test_corridors_of_another_market_are_never_returned(): void
    {
        $india = $this->india();
        $other = Market::factory()->active()->create(['country_code' => 'NP']);
        $this->corridor($other, self::LINE);

        $this->assertCount(0, app(RouteCorridorQuery::class)->containing(new Location(29.0, 77.5), $india));
    }

    public function test_distance_is_geodesic_and_in_metres(): void
    {
        // Delhi → Chandigarh, city centres: ≈ 238.5 km on the WGS84 spheroid.
        $distance = app(DistanceService::class)->between(new Location(28.6139, 77.209), new Location(30.7333, 76.7794));

        $this->assertEqualsWithDelta(238_500, $distance->metres, 1_500);
        $this->assertEqualsWithDelta(0, app(DistanceService::class)->between(new Location(28.6, 77.2), new Location(28.6, 77.2))->metres, 0.001);
    }

    public function test_corridor_geometry_is_a_wgs84_linestring_with_a_gist_index(): void
    {
        $this->corridor($this->india(), self::LINE);

        $row = DB::selectOne('select ST_SRID(centerline) as srid, GeometryType(centerline) as type, ST_Length(centerline::geography) as m from route_corridors');
        $this->assertSame(4326, (int) $row->srid);
        $this->assertSame('LINESTRING', $row->type);
        $this->assertEqualsWithDelta(97_400, (float) $row->m, 600, '1° of longitude at 29° N');

        $this->assertNotNull(DB::selectOne("select 1 as ok from pg_indexes where tablename = 'route_corridors' and indexname = 'route_corridors_centerline_gist' and indexdef like '%gist%'"));
    }

    public function test_journey_coverage_reports_both_ends_and_the_corridors_along_a_route(): void
    {
        $india = $this->india();
        $region = $this->region($india);
        $this->area($this->city($region, 'West', 29.0, 77.0), [76.95, 28.95, 77.05, 29.05], name: 'West');
        $this->area($this->city($region, 'East', 29.0, 78.0), [77.95, 28.95, 78.05, 29.05], name: 'East');
        $corridor = $this->corridor($india, self::LINE);

        $journey = app(JourneyCoverage::class);
        $ends = $journey->endpoints(new Location(29.0, 77.0), new Location(29.0, 78.0));

        $this->assertTrue($ends['origin']->supported);
        $this->assertTrue($ends['destination']->supported);
        $this->assertTrue($ends['same_market']);
        $this->assertSame([$corridor->id], $journey->corridorsAlong($this->line([[77.0, 29.01], [78.0, 29.01]]), $ends['origin'])->modelKeys());

        $outside = $journey->endpoints(new Location(29.0, 77.0), new Location(48.85, 2.35));
        $this->assertFalse($outside['destination']->supported);
        $this->assertFalse($outside['same_market']);
    }
}
