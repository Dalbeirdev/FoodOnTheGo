<?php

namespace Tests\Feature\Journey;

use App\Enums\ProviderFailure;
use App\Models\Journey;
use App\Models\RestaurantLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * GET /journeys/{journey}/restaurants — the discovery pipeline on real PostGIS rows and the scripted development
 * routing provider. The clock is Monday 2026-10-05 12:00 in India; the standard route runs west → east along
 * latitude 28.55 through the Noida Central service area.
 */
class RouteDiscoveryApiTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    public function test_candidates_come_from_the_postgis_corridor_with_distance_and_progress_along_the_route(): void
    {
        $a = $this->eligible('Along A', 28.552, 77.33);
        $b = $this->eligible('Along B', 28.56, 77.36);
        $this->eligible('Far C', 28.59, 77.38);                                   // ~4.4 km north: outside the default 3 km corridor
        $this->eligible('Suspended', 28.551, 77.34, ['status' => 'SUSPENDED']);
        $this->eligible('No Menu', 28.551, 77.35, menu: false);
        $this->eligible('Under Review', 28.551, 77.37, ['status' => 'UNDER_REVIEW']);
        $this->eligible('Banned Brand', 28.551, 77.38, organization: $this->organization('Banned', 'SUSPENDED'));

        $plan = $this->planGuest();
        $response = $this->restaurantsOf($plan['id'], $plan['headers'])->assertOk();

        $this->assertSame(['Along A', 'Along B'], $this->namesOf($response));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.counts.corridor_rows'));
        $this->assertSame(2, $response->json('meta.counts.detour_requested'));
        $this->assertFalse($response->json('meta.cached'));
        $this->assertSame(['name' => 'fake', 'mode' => 'development'], $response->json('meta.provider'));
        $this->assertSame(3000, $response->json('meta.search.corridor_width_meters'));

        $ctxA = $response->json('data.0.route_context');
        $ctxB = $response->json('data.1.route_context');
        $this->assertSame($a->public_id, $response->json('data.0.restaurant.id'));
        $this->assertSame($b->public_id, $response->json('data.1.restaurant.id'));
        $this->assertLessThan(700, $ctxA['distance_to_route_meters']);
        $this->assertGreaterThan(700, $ctxB['distance_to_route_meters']);
        $this->assertLessThan(1600, $ctxB['distance_to_route_meters']);
        $this->assertEqualsWithDelta(0.3, $ctxA['route_progress'], 0.08);
        $this->assertEqualsWithDelta(0.6, $ctxB['route_progress'], 0.08);
        $this->assertTrue($ctxA['arrival']['is_estimate']);
        $this->assertSame('Asia/Kolkata', $ctxA['arrival']['timezone']);
        $this->assertTrue($ctxA['open_at_arrival']);
        $this->assertTrue($ctxA['orderable_at_arrival']);
        $this->assertGreaterThan(0, $ctxA['detour']['seconds']);
        $this->assertGreaterThan(0, $ctxA['arrival']['leg_seconds']);
        $this->assertSame(now()->addSeconds($ctxA['arrival']['leg_seconds'])->utc()->toIso8601String(), $ctxA['arrival']['estimated_at']);
        $this->assertArrayNotHasKey('menu', $response->json('data.0.restaurant'));

        // Widening the corridor is a new candidate set: Far C appears, nothing is served from the earlier cache.
        $wider = $this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 6000, 'max_detour_minutes' => 60])->assertOk();
        $this->assertSame(['Along A', 'Along B', 'Far C'], $this->namesOf($wider));
        $this->assertFalse($wider->json('meta.cached'));
    }

    public function test_only_the_bounded_shortlist_ever_reaches_the_routing_provider(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->eligible('Candidate '.$i, 28.551 + ($i % 5) * 0.001, 77.301 + $i * 0.0008);
        }
        $this->assertSame(120, RestaurantLocation::query()->count());

        $plan = $this->planGuest();
        $response = $this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 25]])->assertOk();

        $this->assertSame(120, $response->json('meta.counts.corridor_rows'));
        $this->assertSame(40, $response->json('meta.counts.shortlist'));
        $this->assertSame(40, $response->json('meta.counts.detour_requested'));
        $this->assertSame(40, $response->json('meta.total'));
        $this->assertCount(25, $response->json('data'));
        $this->assertSame(2, $response->json('meta.last_page'));

        $calls = $this->fakeRouting()->detourCalls();
        $this->assertSame(2, count($calls), 'two batches of at most 25');
        $this->assertSame(40, array_sum(array_column($calls, 'waypoints')));
        $this->assertLessThanOrEqual(25, max(array_column($calls, 'waypoints')));
        $this->assertSame(40, Journey::query()->where('public_id', $plan['id'])->value('detour_candidates_count'));
    }

    public function test_the_detour_filter_uses_the_providers_actual_detours(): void
    {
        $a = $this->eligible('Detour A', 28.552, 77.32);
        $b = $this->eligible('Detour B', 28.552, 77.35);
        $c = $this->eligible('Detour C', 28.552, 77.38);
        $this->fakeRouting()->scriptDetour($a->public_id, 120);
        $this->fakeRouting()->scriptDetour($b->public_id, 480);
        $this->fakeRouting()->scriptDetour($c->public_id, 1200);

        $plan = $this->planGuest();
        $ten = $this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 10])->assertOk();
        $this->assertSame(['Detour A', 'Detour B'], $this->namesOf($ten));
        $this->assertSame([2, 8], $ten->json('data.*.route_context.detour.minutes'));
        $this->assertSame([120, 480], $ten->json('data.*.route_context.detour.seconds'));

        $this->assertSame(['Detour A', 'Detour B'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'])->assertOk()), 'the market default is 15 minutes');
        $this->assertSame(['Detour A', 'Detour B', 'Detour C'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 30, 'sort' => 'lowest_detour'])->assertOk()));
        $this->assertSame(['Detour A'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 2])->assertOk()));
        $this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 0])->assertUnprocessable();
        $this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 61])->assertUnprocessable();
    }

    public function test_opposite_carriageways_and_barriers_are_decided_by_the_provider_not_by_distance(): void
    {
        $this->eligible('Right Side', 28.5515, 77.33);
        $wrongSide = $this->eligible('Wrong Side', 28.5485, 77.34);   // 170 m from the line — but a long way round
        $island = $this->eligible('Behind Barrier', 28.5515, 77.36);
        $this->fakeRouting()->scriptDetour($wrongSide->public_id, 25 * 60);
        $this->fakeRouting()->scriptDetour($island->public_id, null);  // NO_ROUTE

        $plan = $this->planGuest();
        $response = $this->restaurantsOf($plan['id'], $plan['headers'])->assertOk();

        $this->assertSame(['Right Side'], $this->namesOf($response));
        $this->assertSame(1, $response->json('meta.counts.unreachable'));
        $this->assertSame(3, $response->json('meta.counts.detour_requested'));
        $this->assertSame(['Right Side', 'Wrong Side'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['max_detour_minutes' => 30, 'sort' => 'route_order'])->assertOk()));
    }

    public function test_open_at_the_expected_arrival_is_decided_from_the_hours_in_the_restaurants_time_zone(): void
    {
        $this->eligible('All Day', 28.552, 77.33);
        $this->eligible('Evening Only', 28.552, 77.34, hours: [[null, '18:00', '22:00']]);
        $closedToday = $this->eligible('Closed Today', 28.552, 77.35);
        $this->special($closedToday, '2026-10-05', [], 'Staff training');
        $this->eligible('Night Owl', 28.552, 77.36, hours: [[null, '23:00', '04:00']]);

        $plan = $this->planGuest();
        $noon = $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order'])->assertOk();
        $this->assertSame(['All Day', 'Evening Only', 'Closed Today', 'Night Owl'], $this->namesOf($noon));
        $this->assertSame([true, false, false, false], $noon->json('data.*.route_context.open_at_arrival'));
        $this->assertSame([true, false, false, false], $noon->json('data.*.route_context.orderable_at_arrival'));
        $this->assertSame('CLOSED_NOW', $noon->json('data.1.route_context.availability_at_arrival.reason'));
        $this->assertSame('2026-10-05T12:30:00+00:00', $noon->json('data.1.route_context.availability_at_arrival.opens_next_at'));
        $this->assertSame('CLOSED_NOW', $noon->json('data.2.route_context.availability_at_arrival.reason'));
        $this->assertSame('2026-10-06T03:30:00+00:00', $noon->json('data.2.route_context.availability_at_arrival.opens_next_at'), 'closed all of today by special hours: opens tomorrow');

        $this->assertSame(['All Day'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['open_at_arrival' => 'true'])->assertOk()));
        $this->assertSame(1, $this->restaurantsOf($plan['id'], $plan['headers'], ['open_at_arrival' => 'true'])->json('meta.total'));

        // Leaving at 17:55: the first leg takes long enough for Evening Only to have opened on arrival.
        $evening = $this->planGuest(['departure_at' => '2026-10-05T17:55:00+05:30']);
        $atArrival = $this->restaurantsOf($evening['id'], $evening['headers'], ['sort' => 'route_order'])->assertOk();
        $this->assertTrue($atArrival->json('data.1.route_context.open_at_arrival'), 'Evening Only is open when the traveller gets there');
        $this->assertSame('18:0', substr($atArrival->json('data.1.route_context.arrival.local_time'), 0, 4));
        $this->assertSame('2026-10-05T12:25:00+00:00', $atArrival->json('meta.departure_at'));

        // Leaving at 23:30 the night place is open (overnight period), the all-day one has closed.
        $night = $this->planGuest(['departure_at' => '2026-10-05T23:30:00+05:30']);
        $late = $this->restaurantsOf($night['id'], $night['headers'], ['sort' => 'route_order'])->assertOk();
        $this->assertSame([false, false, false, true], $late->json('data.*.route_context.open_at_arrival'));
        $this->assertSame('2026-10-05', $late->json('data.3.route_context.arrival.local_date'));
    }

    public function test_recommended_ranking_is_a_deterministic_rule_orderable_then_detour_then_route_order(): void
    {
        $x = $this->eligible('X open 6', 28.552, 77.32);
        $y = $this->eligible('Y closed 2', 28.552, 77.34, hours: [[null, '18:00', '22:00']]);
        $z = $this->eligible('Z open 3', 28.552, 77.36);
        $w = $this->eligible('W paused 1', 28.552, 77.38, ['accepting_orders' => false, 'pause_reason' => 'Short of staff', 'paused_at' => now()]);
        $this->fakeRouting()->scriptDetour($x->public_id, 360);
        $this->fakeRouting()->scriptDetour($y->public_id, 120);
        $this->fakeRouting()->scriptDetour($z->public_id, 180);
        $this->fakeRouting()->scriptDetour($w->public_id, 60);

        $plan = $this->planGuest();
        $this->assertSame(['Z open 3', 'X open 6', 'W paused 1', 'Y closed 2'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'])->assertOk()), 'orderable first (W is open but paused), then the smaller detour, closed last');
        $this->assertSame(['W paused 1', 'Y closed 2', 'Z open 3', 'X open 6'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'lowest_detour'])->assertOk()));
        $this->assertSame(['X open 6', 'Y closed 2', 'Z open 3', 'W paused 1'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order'])->assertOk()));
        $this->assertSame('NOT_ACCEPTING_ORDERS', $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'lowest_detour'])->json('data.0.route_context.availability_at_arrival.reason'));
        $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'by_rating'])->assertUnprocessable();
    }

    public function test_long_routes_are_segmented_so_every_part_of_the_route_is_represented(): void
    {
        $agra = $this->city($this->region($this->market, 'IN-UP-2', name: 'Uttar Pradesh South'), 'Agra', 27.18, 78.0);
        $this->area($agra, [77.95, 27.13, 78.05, 27.23], name: 'Agra Centre');
        for ($i = 0; $i < 5; $i++) {
            $this->eligible('Near Noida '.$i, 28.551 + $i * 0.0005, 77.305 + $i * 0.002);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->eligible('Near Agra '.$i, 27.18 + $i * 0.001, 77.99 + $i * 0.002, city: $agra);
        }
        config(['journey.candidates.shortlist_cap' => 4, 'journey.candidates.per_segment_cap' => 2]);

        $plan = $this->planGuest(['destination' => ['lat' => 27.18, 'lng' => 78.0, 'label' => 'Agra']]);
        $this->assertGreaterThan(150000, $plan['response']->json('route.distance_meters'));
        $response = $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order', 'max_detour_minutes' => 60])->assertOk();

        $names = $this->namesOf($response);
        $this->assertCount(4, $names);
        $this->assertCount(2, array_filter($names, fn (string $n): bool => str_starts_with($n, 'Near Noida')));
        $this->assertCount(2, array_filter($names, fn (string $n): bool => str_starts_with($n, 'Near Agra')));
        $this->assertSame(8, $response->json('meta.counts.corridor_rows'));
        $this->assertSame(4, $response->json('meta.counts.shortlist'));
        $this->assertGreaterThan($response->json('data.1.route_context.segment'), $response->json('data.2.route_context.segment'));
        $this->assertSame(4, array_sum(array_column($this->fakeRouting()->detourCalls(), 'waypoints')));
    }

    public function test_pages_come_from_the_cached_candidate_set_without_new_provider_calls(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->eligible('Page '.$i, 28.552, 77.305 + $i * 0.008);
        }
        $plan = $this->planGuest();

        $first = $this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 5]])->assertOk();
        $this->assertCount(5, $first->json('data'));
        $this->assertSame(12, $first->json('meta.total'));
        $this->assertSame(3, $first->json('meta.last_page'));
        $this->assertFalse($first->json('meta.cached'));
        $this->assertStringContainsString('page%5Bnumber%5D=2', $first->json('links.next'));
        $calls = count($this->fakeRouting()->detourCalls());

        $second = $this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 5, 'number' => 2]])->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertTrue($second->json('meta.cached'));
        $this->assertSame($calls, count($this->fakeRouting()->detourCalls()), 'paging never recalculates detours');
        $this->assertEmpty(array_intersect($first->json('data.*.restaurant.id'), $second->json('data.*.restaurant.id')));

        $third = $this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 5, 'number' => 3]])->assertOk();
        $this->assertCount(2, $third->json('data'));
        $this->assertNull($third->json('links.next'));

        // A different sort or detour limit is served from the same candidate set; a different corridor is not.
        $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order', 'max_detour_minutes' => 5])->assertOk()->assertJsonPath('meta.cached', true);
        $wider = $this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 4000])->assertOk()->assertJsonPath('meta.cached', false);
        $this->assertSame(12, $wider->json('meta.counts.shortlist'));
        $this->assertSame(0, $wider->json('meta.counts.detour_requested'), 'the same restaurants: their detours for this journey are already known');
        $this->assertSame($calls, count($this->fakeRouting()->detourCalls()));
    }

    public function test_the_cache_never_keeps_a_suspended_or_paused_restaurant_orderable(): void
    {
        $a = $this->eligible('Stays', 28.552, 77.33);
        $b = $this->eligible('Gets Suspended', 28.552, 77.35);
        $c = $this->eligible('Gets Paused', 28.552, 77.37);
        $plan = $this->planGuest();
        $this->assertSame(['Stays', 'Gets Suspended', 'Gets Paused'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order'])->assertOk()));

        $b->forceFill(['status' => 'SUSPENDED'])->save();
        $c->forceFill(['accepting_orders' => false, 'pause_reason' => 'Sold out', 'paused_at' => now()])->save();

        $again = $this->restaurantsOf($plan['id'], $plan['headers'], ['sort' => 'route_order'])->assertOk();
        $this->assertTrue($again->json('meta.cached'));
        $this->assertSame(['Stays', 'Gets Paused'], $this->namesOf($again));
        $this->assertSame(1, $again->json('meta.counts.dropped_since_calculation'));
        $this->assertFalse($again->json('data.1.route_context.orderable_at_arrival'));
        $this->assertSame('NOT_ACCEPTING_ORDERS', $again->json('data.1.route_context.availability_at_arrival.reason'));
        $this->assertFalse($again->json('data.1.route_context.accepting_orders_now'));
        $this->assertTrue($again->json('data.0.route_context.orderable_at_arrival'));
        $this->assertSame($a->public_id, $again->json('data.0.restaurant.id'));
    }

    public function test_cheap_filters_narrow_the_shortlist_before_any_detour_is_requested(): void
    {
        $this->eligible('Burger Stop', 28.552, 77.33, cuisines: ['burgers']);
        $this->eligible('Pizza Stop', 28.552, 77.35, cuisines: ['pizza']);
        $plan = $this->planGuest();

        $pizza = $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'pizza'])->assertOk();
        $this->assertSame(['Pizza Stop'], $this->namesOf($pizza));
        $this->assertSame(1, $pizza->json('meta.counts.detour_requested'));
        $this->assertSame(1, array_sum(array_column($this->fakeRouting()->detourCalls(), 'waypoints')));

        // Widening the filter only asks the provider for the restaurant it has not calculated for this journey yet.
        $both = $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'pizza,burgers', 'sort' => 'route_order'])->assertOk();
        $this->assertSame(['Burger Stop', 'Pizza Stop'], $this->namesOf($both));
        $this->assertSame(1, $both->json('meta.counts.detour_requested'));
        $burger = $this->restaurantsOf($plan['id'], $plan['headers'], ['q' => 'burger'])->assertOk();
        $this->assertSame(['Burger Stop'], $this->namesOf($burger));
        $this->assertSame(0, $burger->json('meta.counts.detour_requested'));
        $this->assertSame(2, array_sum(array_column($this->fakeRouting()->detourCalls(), 'waypoints')), 'two restaurants, two detours — whatever the number of searches');

        $none = $this->restaurantsOf($plan['id'], $plan['headers'], ['pickup_method' => 'CURBSIDE'])->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertSame('FILTERS', $none->json('meta.empty_reason'));
        $this->assertSame(0, $none->json('meta.counts.detour_requested'));

        $this->restaurantsOf($plan['id'], $plan['headers'], ['pickup_method' => 'DRONE'])->assertUnprocessable();
        $this->restaurantsOf($plan['id'], $plan['headers'], ['cuisine' => 'pizza; drop table'])->assertUnprocessable();
        $this->restaurantsOf($plan['id'], $plan['headers'], ['page' => ['size' => 99]])->assertUnprocessable();
        $this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 100])->assertUnprocessable();
    }

    public function test_an_empty_corridor_and_a_provider_outage_are_told_apart(): void
    {
        $plan = $this->planGuest();
        $empty = $this->restaurantsOf($plan['id'], $plan['headers'])->assertOk();
        $this->assertSame('NO_RESTAURANTS_IN_CORRIDOR', $empty->json('meta.empty_reason'));
        $this->assertSame('COVERED', $empty->json('meta.coverage.along_route'));
        $this->assertSame(0, count($this->fakeRouting()->detourCalls()), 'no candidates, no provider call');

        $this->eligible('Only One', 28.552, 77.33);
        $this->fakeRouting()->failNext('detours', ProviderFailure::Timeout, 2);
        $this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 3500])->assertStatus(503)->assertJsonPath('error.code', 'routing_temporarily_unavailable');

        // Nothing was cached for the failed computation: the next request computes again and succeeds.
        $this->assertSame(['Only One'], $this->namesOf($this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 3500])->assertOk()->assertJsonPath('meta.cached', false)));
    }

    public function test_query_count_stays_bounded_and_the_corridor_query_is_parameterized(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->eligible('Q '.$i, 28.552, 77.305 + $i * 0.003);
        }
        $plan = $this->planGuest();

        $queries = function (array $query) use ($plan): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->restaurantsOf($plan['id'], $plan['headers'], $query)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // Computing the candidates of thirty restaurants costs as many queries as computing those of five would:
        // the corridor, the eligibility, one load of the shortlist with its relations — never a query per restaurant.
        $compute = $queries(['page' => ['size' => 25]]);
        $computeNarrow = $queries(['page' => ['size' => 25], 'q' => 'Q 1']);          // 11 restaurants match ("Q 1", "Q 10" … "Q 19")
        $this->assertSame($compute, $computeNarrow, "computing 30 candidates ran {$compute} queries, computing 11 ran {$computeNarrow}");
        $this->assertLessThan(40, $compute, "computing a journey's candidates ran {$compute} queries");

        // A cached page of twenty-five costs as many queries as a cached page of five.
        $pageOf25 = $queries(['page' => ['size' => 25]]);
        $pageOf5 = $queries(['page' => ['size' => 25, 'number' => 2]]);
        $this->assertSame($pageOf25, $pageOf5, "a page of 25 ran {$pageOf25} queries, a page of 5 ran {$pageOf5}");
        $this->assertLessThan($compute, $pageOf25, 'a cached page skips the corridor and eligibility queries');

        // The corridor query is the per-piece PostGIS query with bound parameters only. That it is served by the GiST index
        // is asserted on a realistic table size, without planner hints, in RouteDiscoveryScaleTest (on thirty rows the
        // planner rightly reads the table).
        $captured = null;
        DB::listen(function (QueryExecuted $query) use (&$captured): void {
            if (str_contains($query->sql, 'ST_Subdivide')) {
                $captured = [$query->sql, $query->bindings];
            }
        });
        $this->restaurantsOf($plan['id'], $plan['headers'], ['corridor_width_meters' => 3500])->assertOk();
        $this->assertNotNull($captured);
        $this->assertStringContainsString('pieces as materialized', $captured[0]);
        $this->assertStringContainsString('ST_DWithin(l.location, p.piece, ?)', $captured[0]);
        $this->assertSame(3500, $captured[1][3]);
        $this->assertStringNotContainsString('77.3', $captured[0], 'no coordinate is ever concatenated into the SQL');
    }
}
