<?php

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — what the reports cost: the number of queries of a report does not grow with the number of orders,
 * restaurants or sessions (no N+1), the aggregation and the reports stay inside an internal budget on a few thousand
 * orders, and the heavy queries use their indexes. With ANALYTICS_PERF_REPORT set to a file path the measurements and
 * the query plans are written there — numbers of THIS machine and THIS data set, not a claim about scale.
 */
class AnalyticsPerformanceTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const PERIOD = ['from' => '2026-08-09', 'to' => '2026-10-07'];

    private const REPORTS = ['overview', 'funnel', 'customers', 'orders', 'restaurants', 'menu', 'routes', 'pickup', 'payments', 'refunds', 'finance', 'support', 'reviews', 'notifications'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    /**
     * @return array<string, array{queries: int, ms: float}>
     */
    private function measure(mixed $admin, mixed $owner): array
    {
        $out = [];
        $run = function (string $name, \Closure $request) use (&$out): void {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            $request()->assertOk();
            $ms = (hrtime(true) - $started) / 1_000_000;
            // The sign-in of the test's actor is not the report's cost: only queries that touch the report's tables count.
            $queries = count(array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/personal_access_tokens|role_assignments|role_permissions|"roles"|"admin_users"|"restaurant_users"|restaurant_memberships|"cache"/', $q['query']) !== 1));
            DB::disableQueryLog();
            $out[$name] = ['queries' => $queries, 'ms' => round($ms, 1)];
        };
        foreach (self::REPORTS as $report) {
            $run('admin/'.$report, fn () => $this->adminAnalytics($admin, '/'.$report, self::PERIOD));
        }
        $run('admin/live', fn () => $this->adminAnalytics($admin, '/live'));
        foreach (['overview', 'orders', 'menu', 'operations', 'reviews', 'routes'] as $report) {
            $run('restaurant/'.$report, fn () => $this->restaurantAnalytics($owner, '/'.$report, self::PERIOD));
        }

        return $out;
    }

    public function test_reports_do_not_cost_more_queries_with_more_data_and_stay_inside_the_budget(): void
    {
        $source = $this->completedOrderWith();
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminFinanceView, Permission::AdminSupportView]);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');
        $small = $this->measure($admin, $owner);

        // 3,000 more orders over sixty days at five restaurants by three hundred customers, and 24,000 behavioural events.
        $locations = [$this->burgerHub->getKey()];
        foreach (['Brew & Bites', 'Healthy Bites', 'Curry Corner', 'Dosa Den'] as $i => $name) {
            $locations[] = $this->location($this->burgerHub->organization, $name, [], 28.551 + $i / 1000, 77.351 + $i / 1000)->getKey();
        }
        $customers = Customer::factory()->count(300)->create(['market_id' => $this->market->getKey(), 'preferred_locale' => 'en-IN'])->modelKeys();
        $columns = array_values(array_diff(DB::getSchemaBuilder()->getColumnListing('orders'), ['id']));
        $base = CarbonImmutable::parse('2026-08-09 00:00', 'Asia/Kolkata')->utc()->toDateTimeString();
        $placed = "(timestamp '{$base}' at time zone 'UTC' + (g % 60) * interval '1 day' + (g % 780) * interval '1 minute' + interval '9 hours')";
        $override = [
            'public_id' => 'gen_random_uuid()', 'order_number' => "'FOTG-20260809-' || translate(lpad(g::text, 6, 'Z'), '01', 'AB')", 'checkout_session_id' => 'null', 'checkout_public_id' => 'gen_random_uuid()', 'payment_attempt_id' => 'null',
            'payment_public_id' => 'gen_random_uuid()', 'pickup_reservation_id' => 'null', 'customer_id' => '(array['.implode(',', $customers).'])[1 + g % 300]', 'location_id' => '(array['.implode(',', $locations).'])[1 + g % 5]',
            'status' => "case when g % 20 = 0 then 'REJECTED' else 'COMPLETED' end", 'rejection_reason' => "case when g % 20 = 0 then 'KITCHEN_CAPACITY' end", 'rejected_at' => "case when g % 20 = 0 then {$placed} + interval '3 minutes' end",
            'placed_at' => $placed, 'accepted_at' => "case when g % 20 = 0 then null else {$placed} + (60 + g % 240) * interval '1 second' end", 'ready_at' => "case when g % 20 = 0 then null else {$placed} + (600 + g % 900) * interval '1 second' end",
            'picked_up_at' => "case when g % 20 = 0 then null else {$placed} + (900 + g % 1200) * interval '1 second' end", 'completed_at' => "case when g % 20 = 0 then null else {$placed} + (900 + g % 1200) * interval '1 second' end",
            'created_at' => $placed, 'updated_at' => $placed,
        ];
        $select = implode(', ', array_map(fn (string $c): string => ($override[$c] ?? 'o."'.$c.'"').' as "'.$c.'"', $columns));
        DB::statement('insert into orders ("'.implode('", "', $columns).'") select '.$select.' from orders o cross join generate_series(1, 3000) as g where o.id = ?', [$source->getKey()]);
        $names = "(array['journey_search_started','journey_results_viewed','restaurant_viewed','menu_viewed','item_added_to_cart','pickup_selected','checkout_started','payment_started'])";
        DB::statement(
            "insert into analytics_events (event_id, event_name, schema_version, source, platform, occurred_at, received_at, session_id, market_id, location_id, properties)
            select gen_random_uuid(), {$names}[1 + (g % 8)], 1, 'CLIENT', case when (g / 8) % 3 = 0 then 'ANDROID' else 'WEB' end,
                timestamp '{$base}' at time zone 'UTC' + ((g / 8) % 60) * interval '1 day' + ((g / 8) % 600) * interval '1 minute' + (g % 8) * interval '5 seconds', now(), md5((g / 8)::text)::uuid, ?, (array[".implode(',', $locations).'])[1 + (g / 8) % 5], \'{}\'
            from generate_series(0, 23999) as g where (g % 8) <= (g / 8) % 8',
            [$this->market->getKey()],
        );
        $events = (int) DB::table('analytics_events')->count();

        $started = hrtime(true);
        $run = $this->aggregate('BACKFILL');
        $aggregationMs = round((hrtime(true) - $started) / 1_000_000, 1);
        $this->assertSame(3001, $run['order_facts']);
        $started = hrtime(true);
        $this->aggregate('SCHEDULED');
        $incrementalMs = round((hrtime(true) - $started) / 1_000_000, 1);
        $large = $this->measure($admin, $owner);

        // The orders really are in the answers.
        $orders = $this->adminAnalytics($admin, '/orders', self::PERIOD)->assertOk()->json('metrics');
        $this->assertSame([3001, 2851, 150], [$orders['orders_placed']['value'], $orders['orders_completed']['value'], $orders['orders_rejected']['value']]);
        $this->assertCount(5, $this->adminAnalytics($admin, '/restaurants', self::PERIOD)->json('restaurants'));
        $this->assertSame(3000, $this->adminAnalytics($admin, '/funnel', self::PERIOD)->json('stages.0.sessions'));

        foreach ($large as $name => $cost) {
            // No N+1: three thousand orders, five restaurants and three thousand sessions later, the same number of queries.
            $this->assertSame($small[$name]['queries'], $cost['queries'], $name.' needs more queries with more data');
            // The internal budget of a report on this data set: at most 60 queries and two seconds.
            $this->assertLessThanOrEqual(60, $cost['queries'], $name);
            $this->assertLessThan(2000, $cost['ms'], $name);
        }
        $this->assertLessThan(60000, $aggregationMs);
        $this->assertLessThan(30000, $incrementalMs);

        // The heavy queries, as PostgreSQL plans and runs them.
        $in = (int) $this->market->getKey();
        $plans = [];
        foreach ([
            'daily_market_sums' => "select currency, sum(orders_placed), sum(gmv_minor) from analytics_daily_market where market_id = {$in} and local_date between '2026-08-09' and '2026-10-07' group by currency",
            'order_fact_percentiles' => "select percentile_cont(0.5) within group (order by accept_seconds), percentile_cont(0.9) within group (order by prep_seconds) from analytics_order_facts where market_id = {$in} and placed_at >= '2026-09-07 18:30+00' and placed_at < '2026-10-07 18:30+00'",
            'distinct_customers' => "select count(distinct customer_key) from analytics_order_facts where market_id = {$in} and status = 'COMPLETED' and completed_at >= '2026-09-07 18:30+00' and completed_at < '2026-10-07 18:30+00'",
            'restaurant_table' => "select location_id, sum(orders_placed), sum(completed_total_minor) from analytics_daily_restaurant where market_id = {$in} and local_date between '2026-08-09' and '2026-10-07' group by location_id",
            'funnel_stages' => "select stage, sum(sessions) from analytics_daily_funnel where market_id = {$in} and local_date between '2026-08-09' and '2026-10-07' group by stage",
            'session_events' => "select session_id, event_name, occurred_at from analytics_events where source = 'CLIENT' and session_id = md5('17')::uuid order by occurred_at",
            'events_of_a_restaurant' => 'select count(*) from analytics_events where location_id = '.(int) $locations[0]." and event_name = 'menu_viewed' and occurred_at >= '2026-09-07 18:30+00' and occurred_at < '2026-10-07 18:30+00'",
            'source_orders_changed_since' => "select id from orders where updated_at >= '2026-10-07 06:00+00'",
        ] as $name => $sql) {
            DB::statement('analyze');
            $plan = implode("\n", array_map(fn ($row): string => (string) array_values((array) $row)[0], DB::select('explain (analyze, buffers, costs off, timing on) '.$sql)));
            $plans[$name] = ['sql' => $sql, 'plan' => $plan];
            $this->assertMatchesRegularExpression('/Execution Time: [0-9.]+ ms/', $plan, $name);
        }
        // The selective reads of the one large table — a session's events, a restaurant's events — go through its indexes.
        // (A read model of sixty rows is rightly read whole: the plan of each query is in the report for a person to judge.)
        foreach (['session_events', 'events_of_a_restaurant'] as $indexed) {
            $this->assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap (Index|Heap) Scan/', $plans[$indexed]['plan'], $indexed);
        }

        $report = getenv('ANALYTICS_PERF_REPORT');
        if (is_string($report) && $report !== '') {
            file_put_contents($report, json_encode([
                'measured_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'note' => 'Measured by the test suite on the development machine against PostgreSQL (database foodonthego_test). Not a claim about scale.',
                'data_set' => ['orders' => 3001, 'restaurants' => 5, 'customers' => 301, 'behavioural_events' => $events, 'sessions' => 3000, 'days' => 60],
                'aggregation' => ['backfill_ms' => $aggregationMs, 'rows_written' => $run['rows'], 'incremental_run_ms' => $incrementalMs],
                'budget' => ['queries_per_report' => 60, 'ms_per_report' => 2000],
                'reports_small_data_set' => $small, 'reports_large_data_set' => $large, 'explain_analyze' => $plans,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
    }
}
