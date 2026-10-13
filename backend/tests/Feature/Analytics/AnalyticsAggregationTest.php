<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Aggregation\AggregationService;
use App\Services\Finance\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 38 — the aggregation: fact tables and daily read models are copies of what the domains say, a rerun never
 * counts twice, and a correction of the past changes the past. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsAggregationTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    private function aggregate(string $trigger = 'BACKFILL', ?string $from = null, ?string $to = null, bool $dryRun = false): array
    {
        return app(AggregationService::class)->run($trigger, null, $from, $to, null, $dryRun);
    }

    private function snapshot(): array
    {
        $tables = ['analytics_order_facts', 'analytics_daily_market', 'analytics_daily_customers', 'analytics_daily_restaurant', 'analytics_daily_items', 'analytics_daily_funnel', 'analytics_daily_routes', 'analytics_daily_finance', 'analytics_daily_support', 'analytics_daily_breakdowns'];
        $all = [];
        foreach ($tables as $table) {
            $all[$table] = DB::table($table)->get()->map(function ($row): array {
                $row = (array) $row;
                unset($row['id'], $row['computed_at']);
                ksort($row);

                return $row;
            })->sortBy(fn (array $r): string => json_encode($r))->values()->all();
        }

        return $all;
    }

    public function test_orders_money_refunds_and_the_ledger_arrive_in_the_read_models_exactly_as_the_domains_have_them(): void
    {
        $this->feeRule();
        $completed = $this->completedOrderWith();
        $this->completedOrderWith();
        [, , , , $open] = $this->paidOrder($this->nextPhone(), '15:00');
        [$refunded] = $this->refundedCompletedOrder(10000);

        $run = $this->aggregate();
        $this->assertSame('OK', $run['status'], json_encode($run));
        $this->assertSame(4, $run['order_facts']);

        // The fact of an order is the order: status, amounts, the allocation's commission and net, the refund's amount.
        $fact = DB::table('analytics_order_facts')->where('order_id', $completed->getKey())->first();
        $allocation = $this->allocationOf($completed);
        $this->assertSame(['COMPLETED', (int) $completed->total_minor, 'RECOGNIZED', (int) $allocation->commission_minor, (int) $allocation->restaurant_net_minor, 0],
            [$fact->status, (int) $fact->total_minor, $fact->allocation_status, (int) $fact->commission_minor, (int) $fact->restaurant_net_minor, (int) $fact->refunded_minor]);
        $this->assertNotNull($fact->accept_seconds);
        $this->assertSame(32, strlen($fact->customer_key));
        $this->assertNotSame((string) $completed->customer_id, $fact->customer_key);
        $this->assertSame([10000, 1], array_map('intval', array_values((array) DB::table('analytics_order_facts')->where('order_id', $refunded->getKey())->first(['refunded_minor', 'refunds_completed']))));

        // The day: four orders placed, three completed, one still active — an active order is not a completed one.
        $day = DB::table('analytics_daily_market')->where('local_date', '2026-10-07')->first();
        $this->assertSame(['INR', 'Asia/Kolkata', 4, 3, 0, 0, 1, 1, 3], [$day->currency, $day->timezone, $day->orders_placed, $day->cohort_completed, $day->cohort_rejected, $day->cohort_cancelled, $day->cohort_active, $day->cohort_refunded, $day->orders_completed]);
        $this->assertSame(51500 * 3, (int) $day->gmv_minor);
        $this->assertSame(51500 * 4, (int) $day->placed_total_minor);

        // The ledger per day says what the finance report says for the period — to the paisa.
        $overview = app(FinanceReportService::class)->overview(null, CarbonImmutable::parse('2026-10-01', 'Asia/Kolkata')->utc(), CarbonImmutable::parse('2026-10-08', 'Asia/Kolkata')->utc())['currencies'][0]['period'];
        $daily = DB::table('analytics_daily_finance')->where('currency', 'INR')->selectRaw('measure, sum(amount_minor) as amount')->groupBy('measure')->pluck('amount', 'measure')->map(fn ($v): int => (int) $v);
        $this->assertSame(
            [$overview['platform_commission_minor'], $overview['platform_fee_revenue_minor'], $overview['customer_payments_minor'], $overview['refunds_minor'], $overview['gross_marketplace_sales_minor'], $overview['restaurant_earnings_minor']],
            [$daily['PLATFORM_COMMISSION_REVENUE'], $daily['PLATFORM_FEE_REVENUE'], $daily['CUSTOMER_PAYMENTS'], $daily['REFUNDS'], $daily['GROSS_MARKETPLACE_SALES'], $daily['RESTAURANT_EARNINGS']],
        );
        $this->assertGreaterThan(0, $overview['platform_commission_minor']);

        // The restaurant's day, its items (what was SOLD), the payments and the refunds.
        $restaurant = DB::table('analytics_daily_restaurant')->where('local_date', '2026-10-07')->first();
        $this->assertSame([4, 3, 3, 10000, 3, 1], [$restaurant->orders_placed, $restaurant->cohort_completed, $restaurant->orders_completed, (int) $restaurant->refunded_minor, $restaurant->cohort_accepted, $restaurant->cohort_active]);
        $item = DB::table('analytics_daily_items')->where('local_date', '2026-10-07')->first();
        $this->assertSame([6, 3], [(int) $item->units_sold, $item->orders]);
        $payments = DB::table('analytics_daily_breakdowns')->where('grp', 'payments')->where('dimension', 'status')->pluck('count', 'value')->all();
        $this->assertSame(['VERIFIED' => 4], $payments);
        $refunds = DB::table('analytics_daily_breakdowns')->where('grp', 'refunds')->where('dimension', 'completed')->first();
        $this->assertSame(['PARTIAL', 1, 10000], [$refunds->value, $refunds->count, (int) $refunds->amount_minor]);
        $this->assertNotNull($open);
    }

    public function test_running_the_same_days_again_changes_nothing_and_a_dry_run_writes_nothing(): void
    {
        $this->completedOrderWith();
        $this->completedOrderWith();

        $dry = $this->aggregate('REBUILD', '2026-10-06', '2026-10-08', dryRun: true);
        $this->assertSame(['OK', true], [$dry['status'], $dry['dry_run']]);
        $this->assertGreaterThan(0, $dry['rows']);
        $this->assertSame(0, DB::table('analytics_order_facts')->count() + DB::table('analytics_daily_market')->count());

        $this->aggregate();
        $first = $this->snapshot();
        $this->assertSame(2, (int) $first['analytics_daily_market'][0]['orders_placed']);
        $this->aggregate('REBUILD', '2026-10-06', '2026-10-08');
        $this->aggregate('SCHEDULED');
        $this->aggregate('SCHEDULED');
        $this->assertSame($first, $this->snapshot());
        $this->assertSame(4, DB::table('analytics_runs')->where('status', 'OK')->where('dry_run', false)->count());
    }
}
