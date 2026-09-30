<?php

namespace Tests\Feature\Foundation;

use App\Support\Geo\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spatial smoke tests. They use a temporary table that disappears with the test transaction —
 * no restaurant or route rows are created.
 */
class PostgisTest extends TestCase
{
    use RefreshDatabase;

    private const NOIDA = [28.6280, 77.3649];

    private const CHANDIGARH = [30.7333, 76.7794];

    private const KARNAL = [29.6857, 76.9905];

    private const JAIPUR = [26.9124, 75.7873];

    public function test_postgis_extension_is_enabled_and_wgs84_is_known(): void
    {
        $this->assertNotNull(DB::selectOne("select extversion from pg_extension where extname = 'postgis'"));
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', DB::selectOne('select postgis_lib_version() as v')->v);
        $this->assertSame(1, (int) DB::selectOne('select count(*) as n from spatial_ref_sys where srid = ?', [Geo::SRID])->n);
    }

    public function test_geography_distance_between_two_points_is_returned_in_metres(): void
    {
        [$from, $fromBindings] = Geo::pointSql(...self::NOIDA);
        [$to, $toBindings] = Geo::pointSql(...self::CHANDIGARH);

        $metres = (float) DB::selectOne("select ST_Distance({$from}, {$to}) as m", [...$fromBindings, ...$toBindings])->m;

        // Great-circle distance Noida → Chandigarh is about 240 km.
        $this->assertEqualsWithDelta(240_000, $metres, 5_000);
    }

    public function test_points_near_a_route_line_are_found_with_a_gist_index_available(): void
    {
        DB::statement('create temporary table spatial_smoke (name text primary key, location geography(Point, 4326) not null) on commit drop');
        DB::statement('create index spatial_smoke_location_gist on spatial_smoke using gist (location)');

        foreach (['karnal' => self::KARNAL, 'jaipur' => self::JAIPUR] as $name => $point) {
            [$sql, $bindings] = Geo::pointSql(...$point);
            DB::insert("insert into spatial_smoke (name, location) values (?, {$sql})", [$name, ...$bindings]);
        }

        // Straight line Noida → Chandigarh as geometry(LineString, 4326); 40 km corridor.
        $route = 'ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326)::geography';
        $near = DB::select(
            "select name from spatial_smoke where ST_DWithin(location, {$route}, ?)",
            [self::NOIDA[1], self::NOIDA[0], self::CHANDIGARH[1], self::CHANDIGARH[0], 40_000],
        );

        $this->assertSame(['karnal'], array_column($near, 'name'));
        $this->assertSame('gist', DB::selectOne(
            "select am.amname from pg_class c join pg_am am on am.oid = c.relam where c.relname = 'spatial_smoke_location_gist'"
        )->amname);
    }
}
