<?php

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Services\Analytics\MetricCatalog;
use App\Services\Finance\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — the administrators' reports: every figure is one the catalog defines, orders and money say what their
 * domains say, finance and support figures need their own permission, and a market that is not the caller's is not
 * in any answer. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsAdminApiTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const PERIOD = ['from' => '2026-10-01', 'to' => '2026-10-07'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    /**
     * Every `key` in an answer, wherever it is nested.
     *
     * @return list<string>
     */
    private function metricKeys(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        $keys = isset($node['key'], $node['unit']) && is_string($node['key']) ? [$node['key']] : [];
        foreach ($node as $child) {
            $keys = [...$keys, ...$this->metricKeys($child)];
        }

        return $keys;
    }

    public function test_every_report_answers_and_every_figure_in_it_is_defined_in_the_catalog(): void
    {
        $this->feeRule();
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        [$order] = $this->sessionOrder(true, $journey['id']);
        $this->completedOrderWith();
        [, , , , $open] = $this->paidOrder($this->nextPhone(), '15:00');
        [$refunded] = $this->refundedCompletedOrder(10000);
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminFinanceView, Permission::AdminSupportView]);

        $seen = [];
        foreach (['overview', 'funnel', 'customers', 'orders', 'restaurants', 'menu', 'routes', 'pickup', 'payments', 'refunds', 'finance', 'support', 'reviews', 'notifications'] as $report) {
            $answer = $this->adminAnalytics($admin, '/'.$report, self::PERIOD)->assertOk()->json();
            $this->assertSame(['custom', '2026-10-01', '2026-10-07', 7, 'Asia/Kolkata'], [$answer['meta']['period']['preset'], $answer['meta']['period']['from'], $answer['meta']['period']['to'], $answer['meta']['period']['days'], $answer['meta']['timezone']], $report);
            $this->assertSame('india', $answer['meta']['scope']['markets'][0]['slug'], $report);
            $this->assertSame('INR', $answer['meta']['currency'], $report);
            $this->assertArrayHasKey('mode', $answer['meta']['freshness'], $report);
            $seen = [...$seen, ...$this->metricKeys($answer)];
            $this->sample('admin-'.$report, $answer);
        }
        $live = $this->adminAnalytics($admin, '/live')->assertOk()->json();
        $this->sample('admin-live', $live);
        $seen = [...$seen, ...$this->metricKeys($live)];
        $this->assertSame([], array_values(array_diff(array_unique($seen), MetricCatalog::keys())), 'A figure without a definition was returned.');
        $this->assertGreaterThan(60, count(array_unique($seen)));

        // The catalog itself: every metric says what it is, where it comes from and how fresh it is.
        $catalog = $this->adminAnalytics($admin, '/catalog')->assertOk()->json();
        $this->sample('admin-catalog', $catalog);
        foreach ($catalog['metrics'] as $metric) {
            foreach (['key', 'name', 'definition', 'source', 'filters', 'timezone', 'currency', 'exclusions', 'aggregation', 'freshness', 'owner'] as $field) {
                $this->assertNotEmpty($metric[$field], $metric['key'].'.'.$field);
            }
            if ($metric['unit'] === 'percent') {
                $this->assertNotEmpty($metric['numerator'], $metric['key']);
                $this->assertNotEmpty($metric['denominator'], $metric['key']);
            }
        }
        $names = array_column($catalog['events'], 'name');
        $this->assertContains('journey_search_started', $names);
        // No client can report a fact of the payment, order or refund domains: there is no such event.
        $this->assertSame([], array_values(array_intersect($names, ['payment_verified', 'order_created', 'order_completed', 'refund_completed'])));
        $this->assertSame(11, count($catalog['funnel']['stages']));
        $this->assertFalse($catalog['privacy']['advertising_trackers']);
        $this->assertNotNull($order);
        $this->assertNotNull($open);
        $this->assertNotNull($refunded);
    }

    public function test_orders_and_money_say_what_the_order_and_finance_domains_say(): void
    {
        $this->feeRule();
        $this->completedOrderWith();
        $this->completedOrderWith();
        [, , , , $open] = $this->paidOrder($this->nextPhone(), '15:00');
        $this->refundedCompletedOrder(10000);
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminFinanceView, Permission::AdminSupportView]);

        $orders = $this->adminAnalytics($admin, '/orders', self::PERIOD)->assertOk()->json('metrics');
        // Four placed, three completed, one still in progress — and the one in progress is in neither side of the rate.
        $this->assertSame([4, 3, 0, 0, 1, 1], [$orders['orders_placed']['value'], $orders['orders_completed']['value'], $orders['orders_rejected']['value'], $orders['orders_cancelled']['value'], $orders['orders_in_progress']['value'], $orders['orders_refunded']['value']]);
        $this->assertEquals([100.0, 3, 3], [$orders['order_completion_rate']['value'], $orders['order_completion_rate']['numerator'], $orders['order_completion_rate']['denominator']]);
        $this->assertSame(['amount' => 51500 * 3, 'currency' => 'INR'], $orders['gmv']['value']);
        $this->assertSame(['amount' => 51500, 'currency' => 'INR'], $orders['average_order_value']['value']);
        $this->assertSame(['amount' => 51500, 'currency' => 'INR'], $orders['median_order_value']['value']);
        $this->assertSame(3, $orders['acceptance_time']['count']);
        // Nothing before this period: the comparison says so instead of "+∞ %".
        $this->assertSame(['previous' => 0, 'change_pct' => null, 'state' => 'NEW'], $orders['orders_placed']['comparison']);

        // Finance: the ledger's figures, the same the finance report returns — and GMV is not among the revenue.
        $finance = $this->adminAnalytics($admin, '/finance', self::PERIOD)->assertOk()->json();
        $ledger = app(FinanceReportService::class)->overview([(int) $this->market->getKey()], CarbonImmutable::parse('2026-10-01', 'Asia/Kolkata')->utc(), CarbonImmutable::parse('2026-10-08', 'Asia/Kolkata')->utc())['currencies'][0]['period'];
        $m = $finance['metrics'];
        $this->assertSame([$ledger['platform_commission_minor'], $ledger['platform_fee_revenue_minor'], $ledger['platform_revenue_minor'], $ledger['restaurant_earnings_minor'], $ledger['gross_marketplace_sales_minor'], $ledger['refunds_minor']],
            [$m['platform_commission']['value']['amount'], $m['platform_fee_revenue']['value']['amount'], $m['platform_revenue']['value']['amount'], $m['restaurant_net_earnings']['value']['amount'], $m['gross_marketplace_sales']['value']['amount'], $m['ledger_refunds']['value']['amount']]);
        $this->assertSame([15000, 4500, 19500, 135000, 150000, 10000], [$m['platform_commission']['value']['amount'], $m['platform_fee_revenue']['value']['amount'], $m['platform_revenue']['value']['amount'], $m['restaurant_net_earnings']['value']['amount'], $m['gross_marketplace_sales']['value']['amount'], $m['ledger_refunds']['value']['amount']]);
        $this->assertSame($m['platform_revenue']['value']['amount'], $m['platform_commission']['value']['amount'] + $m['platform_fee_revenue']['value']['amount']);
        $this->assertSame(51500 * 3, $m['gmv']['value']['amount']);
        $this->assertStringContainsString('NOT FoodOnTheGo revenue', $finance['terms']['gmv']);
        $this->assertSame('LEDGER', $finance['meta']['freshness']['mode']);
        // The per-day series adds up to the period's commission.
        $this->assertSame(15000, array_sum(array_column($finance['series']['by_currency'][0]['points'], 'platform_commission_minor')));
        $this->assertSame(135000, $m['restaurant_payable']['value']['amount']);

        // The restaurant table: the finance module's earnings per restaurant, the order domain's counts.
        $row = $this->adminAnalytics($admin, '/restaurants', self::PERIOD)->assertOk()->json('restaurants.0');
        $this->assertSame(['Burger Hub', 4, 3, 150000, 135000, 51500 * 3], [$row['restaurant']['name'], $row['orders_placed'], $row['orders_completed'], $row['gross_sales']['amount'], $row['net_earnings']['amount'], $row['completed_order_total']['amount']]);
        $this->assertEquals([100.0, 25.0], [$row['acceptance_rate']['value'], $row['refund_rate']['value']]);

        // Payments and refunds from their own domains.
        $payments = $this->adminAnalytics($admin, '/payments', self::PERIOD)->assertOk()->json();
        $this->assertEquals([4, 4, 0, 100.0, 51500 * 4], [$payments['metrics']['payment_attempts']['value'], $payments['metrics']['payments_verified']['value'], $payments['metrics']['payments_pending']['value'], $payments['metrics']['payment_success_rate']['value'], $payments['metrics']['payments_verified_amount']['value']['amount']]);
        $this->assertSame('UPI', $payments['methods'][0]['key']);
        $refunds = $this->adminAnalytics($admin, '/refunds', self::PERIOD)->assertOk()->json('metrics');
        $this->assertEquals([1, 10000, 0, 1, 25.0], [$refunds['refunds_completed']['value'], $refunds['refund_amount']['value']['amount'], $refunds['refunds_full']['value'], $refunds['refunds_partial']['value'], $refunds['refund_rate_orders']['value']]);
        $this->assertSame([10000, 51500 * 4], [$refunds['refund_rate_amount']['numerator'], $refunds['refund_rate_amount']['denominator']]);

        // Live: counts of this moment, read from the orders — never cached, labelled as such.
        $live = $this->adminAnalytics($admin, '/live')->assertOk()->json();
        $this->assertSame(['OPERATIONAL', false, 1, 1], [$live['meta']['freshness']['mode'], $live['meta']['freshness']['cached'], $live['metrics']['live_active_orders']['value'], $live['metrics']['live_waiting_for_restaurant']['value']]);
        $this->assertNotNull($open);

        // A period with nothing in it: zeros, "no data" — not an error and not NaN.
        $empty = $this->adminAnalytics($admin, '/orders', ['from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk()->json('metrics');
        $this->assertSame([0, null, true, 'NO_DATA'], [$empty['orders_placed']['value'], $empty['order_completion_rate']['value'], $empty['order_completion_rate']['no_data'], $empty['orders_placed']['comparison']['state']]);
        $this->assertSame(['amount' => 0, 'currency' => 'INR'], $empty['gmv']['value']);
    }

    public function test_finance_and_support_figures_need_their_own_permission_and_analytics_needs_its_own(): void
    {
        $this->completedOrderWith();
        $this->aggregate();
        $plain = $this->analyst();

        // An analyst without finance or support access: those reports are refused, the overview says "restricted".
        $this->adminAnalytics($plain, '/finance', self::PERIOD)->assertStatus(403);
        $this->adminAnalytics($plain, '/support', self::PERIOD)->assertStatus(403);
        $overview = $this->adminAnalytics($plain, '/overview', self::PERIOD)->assertOk()->json();
        $this->assertSame([true, 'admin.finance.view', null], [$overview['metrics']['platform_commission']['restricted'], $overview['metrics']['platform_commission']['requires'], $overview['metrics']['platform_commission']['value']]);
        $this->assertSame([true, true], [$overview['finance_summary']['restricted'], $overview['support_health']['restricted']]);
        $this->assertSame(['finance' => false, 'support' => false], $overview['meta']['permissions']);
        $this->assertSame(1, $overview['metrics']['orders_completed']['value']);
        $row = $this->adminAnalytics($plain, '/restaurants', self::PERIOD)->assertOk()->json('restaurants.0');
        $this->assertSame([null, null, true], [$row['gross_sales'], $row['net_earnings'], $row['finance_restricted']]);

        // Finance access without analytics access is not analytics access.
        $finance = $this->adminWith([Permission::AdminFinanceView, Permission::AdminSupportView]);
        foreach (['overview', 'orders', 'finance', 'support', 'catalog', 'live'] as $report) {
            $this->adminAnalytics($finance, '/'.$report)->assertStatus(403);
        }
        // And nobody reads a report without signing in.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/analytics/overview')->assertStatus(401);
        $this->assertSame(1, DB::table('analytics_order_facts')->count());
    }
}
