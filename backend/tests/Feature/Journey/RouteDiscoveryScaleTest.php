<?php

namespace Tests\Feature\Journey;

use App\Models\Journey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * Route discovery on a large synthetic dataset: thousands of eligible restaurants spread over a wide service
 * area. The assertions are about BEHAVIOUR (the corridor search uses the spatial index, the provider only ever
 * sees the bounded shortlist, pages stay bounded); the timings are measured, not asserted — when
 * JOURNEY_PERF_REPORT names a file they are written there as evidence.
 */
class RouteDiscoveryScaleTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    private const RESTAURANTS = 20000;

    /** [west, south, east, north] — about 195 km × 220 km */
    private const BOX = [76.5, 27.0, 78.5, 29.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    public function test_discovery_stays_bounded_and_indexed_with_twenty_thousand_restaurants(): void
    {
        $this->area($this->noida, self::BOX, name: 'North India Wide');
        $organization = $this->organization('Synthetic Kitchens');
        $seedMs = $this->timed(fn () => $this->seedSynthetic($organization->id));
        // Statistics after a bulk load, as autovacuum provides them in a running database.
        DB::statement('analyze restaurant_locations');
        DB::statement('analyze menus');
        $this->assertSame(self::RESTAURANTS, (int) DB::table('restaurant_locations')->count());

        // A throwaway journey first, so the timings below are those of a WARM process (classes loaded, connections open) —
        // the very first request of a fresh PHP process pays for loading the framework, which a running server does not.
        $coldMs = $this->timed(function (): void {
            $warm = $this->planGuest(['origin' => ['lat' => 28.60, 'lng' => 76.90, 'label' => 'Warm-up start'], 'destination' => ['lat' => 28.75, 'lng' => 77.05, 'label' => 'Warm-up end']]);
            $this->restaurantsOf($warm['id'], $warm['headers'])->assertOk();
        });
        $this->fakeRouting()->reset();

        // Noida → Agra: about 200 km diagonally across the box.
        $shortlistSql = null;
        $eligibilityMs = null;
        DB::listen(function (QueryExecuted $query) use (&$shortlistSql, &$eligibilityMs): void {
            if (str_contains($query->sql, 'ST_Subdivide') && ! str_contains($query->sql, 'explain')) {
                $shortlistSql = [$query->sql, $query->bindings, $query->time];
            }
            if ($eligibilityMs === null && str_contains($query->sql, 'ST_Covers') && str_contains($query->sql, '"menus"') && str_contains($query->sql, '"restaurant_locations"."id" in')) {
                $eligibilityMs = $query->time;
            }
        });

        $planMs = $this->timed(function () use (&$plan): void {
            $plan = $this->planGuest(['origin' => ['lat' => 28.55, 'lng' => 77.35, 'label' => 'Noida'], 'destination' => ['lat' => 27.18, 'lng' => 78.0, 'label' => 'Agra']]);
        });
        $firstMs = $this->timed(function () use (&$first, &$plan): void {
            $first = $this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 60, 'page' => ['size' => 25]])->assertOk();
        });
        $pageMs = $this->timed(function () use (&$second, &$plan): void {
            $second = $this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 60, 'page' => ['size' => 25, 'number' => 2]])->assertOk();
        });

        // Bounded: hundreds of restaurants lie in the corridor, 40 reach the provider, a page holds at most 25.
        $counts = $first->json('meta.counts');
        $this->assertGreaterThan(200, $counts['corridor_rows']);
        $this->assertSame(40, $counts['shortlist']);
        $this->assertSame(40, $counts['detour_requested']);
        $this->assertSame(40, array_sum(array_column($this->fakeRouting()->detourCalls(), 'waypoints')));
        $this->assertLessThanOrEqual(25, count($first->json('data')));
        $this->assertTrue($second->json('meta.cached'));
        $this->assertCount(2, $this->fakeRouting()->detourCalls(), 'page two did not go back to the provider');

        // The whole route is represented: candidates come from many segments, not from the dense start only.
        $all = [...$first->json('data'), ...$second->json('data')];
        $segments = array_unique(array_map(fn (array $row): int => $row['route_context']['segment'], $all));
        $this->assertGreaterThanOrEqual(5, count($segments));

        // The corridor search uses the spatial index without any planner hint, and reads a fraction of the table.
        $this->assertNotNull($shortlistSql);
        [$sql, $bindings, $shortlistMs] = $shortlistSql;
        $explain = DB::select('explain (analyze, buffers, format json) '.$sql, $bindings);
        $planText = array_column(DB::select('explain (analyze, buffers) '.$sql, $bindings), 'QUERY PLAN');
        $planJson = json_decode($explain[0]->{'QUERY PLAN'}, true)[0];
        $text = json_encode($planJson);
        $this->assertStringContainsString('restaurant_locations_location_gist', $text, 'the corridor query must use the GiST index on restaurant_locations.location');

        $report = [
            'measured_at' => now()->toIso8601String(),
            'dataset' => ['restaurants' => self::RESTAURANTS, 'area_box' => self::BOX, 'route' => 'Noida → Agra (development routing model)', 'route_distance_m' => $plan['response']->json('route.distance_meters'), 'search_points' => Journey::query()->where('public_id', $plan['id'])->value('search_points')],
            'counts' => $counts,
            'segments_represented' => count($segments),
            'timings_ms' => [
                'seed_dataset' => $seedMs,
                'cold_first_plan_and_search_of_the_process' => $coldMs,
                'plan_journey_request' => $planMs,
                'first_discovery_request' => $firstMs,
                'cached_page_request' => $pageMs,
                'corridor_sql_as_executed' => round((float) $shortlistMs, 2),
                'corridor_sql_explain_analyze_execution' => $planJson['Execution Time'] ?? null,
                'corridor_sql_explain_analyze_planning' => $planJson['Planning Time'] ?? null,
                'eligibility_sql_as_executed' => $eligibilityMs === null ? null : round((float) $eligibilityMs, 2),
            ],
            'plan_uses_index' => 'restaurant_locations_location_gist',
            'plan' => $planText,
            'note' => 'Local machine, PostgreSQL test database inside one transaction, PHP CLI without OPcache, development routing provider (no network). The plan / discovery / page timings are those of a warm process (a throwaway journey ran first; its time is reported as cold_first_plan_and_search_of_the_process). measured_at is the fixed clock of the test. Timings are measurements, not targets.',
        ];
        if (($path = (string) env('JOURNEY_PERF_REPORT')) !== '') {
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->assertIsArray($report['timings_ms']);
    }

    /**
     * 20,000 APPROVED locations with an ACTIVE menu, uniformly spread over the box, in two statements.
     */
    private function seedSynthetic(int $organizationId): void
    {
        [$west, $south, $east, $north] = self::BOX;
        $prefix = Str::lower(Str::random(6));

        DB::statement(
            'insert into restaurant_locations (public_id, organization_id, market_id, region_id, city_id, name, slug, status, operational_status, accepting_orders, timezone, currency, locale, formatted_address, location, created_at, updated_at) '
            ."select gen_random_uuid(), ?, ?, ?, ?, 'Synthetic ' || g, ? || '-' || g, 'APPROVED', 'OPERATING', true, 'Asia/Kolkata', 'INR', 'en-IN', 'Synthetic address ' || g, "
            .'ST_SetSRID(ST_MakePoint(? + random() * ?, ? + random() * ?), 4326)::geography, now(), now() from generate_series(1, ?) g',
            [$organizationId, $this->market->id, $this->noida->region_id, $this->noida->id, 'syn-'.$prefix, $west, $east - $west, $south, $north - $south, self::RESTAURANTS],
        );
        DB::statement(
            "insert into menus (public_id, location_id, name, status, currency, created_at, updated_at) select gen_random_uuid(), l.id, 'Menu', 'ACTIVE', 'INR', now(), now() from restaurant_locations l where l.slug like ?",
            ['syn-'.$prefix.'-%'],
        );
    }

    private function timed(callable $work): float
    {
        $started = hrtime(true);
        $work();

        return round((hrtime(true) - $started) / 1_000_000, 1);
    }
}
