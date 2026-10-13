<?php

namespace App\Services\Analytics;

use App\Http\Controllers\Api\Restaurant\FinanceController;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\RestaurantEarningsService;
use Illuminate\Support\Facades\DB;

/**
 * Financial analytics (Module 38) — the FINANCE module's own numbers, shown over time.
 *
 * The totals of a period are read from the financial ledger and the allocations by Module 36's own report service;
 * the per-day series is the analytics copy of the same ledger lines (a data-quality check compares the two). A
 * restaurant's earnings are Module 36's earnings service, unchanged — there is no second way to add up money.
 *
 * GMV is shown next to the ledger figures and never called revenue. Platform revenue is commission + the platform's
 * own fees. Nothing here is read to authorize a payout or a settlement.
 */
final class FinanceAnalyticsService
{
    public function __construct(private readonly FinanceReportService $reports, private readonly RestaurantEarningsService $earnings, private readonly ReadModel $read, private readonly OrderAnalyticsService $orders) {}

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        // Ledger lines without a market (a provider's fee, a provider's settlement) belong to a report of EVERY market only.
        $markets = $scope->includesUnassigned ? null : $scope->marketIds;
        $now = $this->reports->overview($markets, $period->startUtc(), $period->endUtc());
        $previous = $this->reports->overview($markets, $period->previous()->startUtc(), $period->previous()->endUtc());
        $of = fn (array $overview, string $part, string $key): array => collect($overview['currencies'])->mapWithKeys(fn (array $c): array => [$c['currency'] => (int) $c[$part][$key]])->all();
        $money = fn (string $metric, string $key): array => Metric::money($metric, $of($now, 'period', $key), $of($previous, 'period', $key), $scope->currency);
        $gmv = fn (AnalyticsPeriod $p): array => array_map(fn (array $t): int => $t['gmv_minor'], $this->orders->totals($scope, $p));

        return [
            'metrics' => [
                'gmv' => Metric::money('gmv', $gmv($period), $gmv($period->previous()), $scope->currency),
                'gross_marketplace_sales' => $money('gross_marketplace_sales', 'gross_marketplace_sales_minor'),
                'platform_commission' => $money('platform_commission', 'platform_commission_minor'),
                'platform_fee_revenue' => $money('platform_fee_revenue', 'platform_fee_revenue_minor'),
                'platform_revenue' => $money('platform_revenue', 'platform_revenue_minor'),
                'restaurant_net_earnings' => $money('restaurant_net_earnings', 'restaurant_earnings_minor'),
                'customer_payments' => $money('customer_payments', 'customer_payments_minor'),
                'ledger_refunds' => $money('ledger_refunds', 'refunds_minor'),
                'provider_fees' => $money('provider_fees', 'provider_fees_minor'),
                'promotion_expense' => $money('promotion_expense', 'promotion_expense_minor'),
                'restaurant_paid_out' => $money('restaurant_paid_out', 'restaurant_payouts_minor'),
                'restaurant_payable' => Metric::money('restaurant_payable', $of($now, 'standing', 'restaurant_payables_minor'), null, $scope->currency),
                'reconciliation_differences_open' => Metric::count('reconciliation_differences_open', (int) $now['queues']['differences_open']['total']),
            ],
            'terms' => [
                'gmv' => 'What customers paid for completed orders. NOT FoodOnTheGo revenue.',
                'gross_marketplace_sales' => 'The merchandise restaurants sold through the platform. NOT FoodOnTheGo revenue.',
                'platform_revenue' => 'Commission plus the platform\'s own fees — the only revenue.',
                'four_restaurant_figures' => 'Gross sales, net earnings, what is owed now and what was paid out are four different figures.',
            ],
            'series' => $this->series($scope, $period),
            'commission_by_restaurant' => array_map(fn (array $r): array => [
                'restaurant' => ['id' => $r['key'], 'name' => $r['label']], 'currency' => $r['currency'], 'orders' => $r['orders'],
                'gross_sales' => ['amount' => $r['gross_marketplace_sales_minor'], 'currency' => $r['currency']], 'commission' => ['amount' => $r['commission_minor'], 'currency' => $r['currency']],
                'net_earnings' => ['amount' => $r['restaurant_earnings_minor'], 'currency' => $r['currency']],
            ], array_slice($this->reports->commission($scope->marketIds, $period->startUtc(), $period->endUtc(), 'location', $period->timezone), 0, 100)),
            'settlements' => $this->settlements($scope, $period),
            'provider_fees' => $now['provider_fees'],
            'reconciliation' => ['open_differences' => (int) $now['queues']['differences_open']['total'], 'by_severity' => $now['queues']['differences_open']['by_severity']],
            'payouts_note' => 'LIVE RESTAURANT PAYOUTS ARE DISABLED. Payout figures on this machine come from the fake payout provider and are not production payouts.',
            'commission_note' => 'Every commission plan on this machine is a TEST FIXTURE.',
            'source' => 'Financial ledger and allocations (Module 36), read at the moment of the request.',
        ];
    }

    /**
     * The finance figures the overview shows — read from the ledger by the finance module's own report, for the period
     * only (the full finance report has the comparison with the period before).
     *
     * @return array<string, mixed>
     */
    public function headline(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->reports->overview($scope->includesUnassigned ? null : $scope->marketIds, $period->startUtc(), $period->endUtc());
        $of = fn (string $part, string $key): array => collect($now['currencies'])->mapWithKeys(fn (array $c): array => [$c['currency'] => (int) $c[$part][$key]])->all();

        return [
            'platform_commission' => Metric::money('platform_commission', $of('period', 'platform_commission_minor'), null, $scope->currency),
            'gross_marketplace_sales' => Metric::money('gross_marketplace_sales', $of('period', 'gross_marketplace_sales_minor'), null, $scope->currency),
            'platform_revenue' => Metric::money('platform_revenue', $of('period', 'platform_revenue_minor'), null, $scope->currency),
            'restaurant_net_earnings' => Metric::money('restaurant_net_earnings', $of('period', 'restaurant_earnings_minor'), null, $scope->currency),
            'ledger_refunds' => Metric::money('ledger_refunds', $of('period', 'refunds_minor'), null, $scope->currency),
            'restaurant_payable' => Metric::money('restaurant_payable', $of('standing', 'restaurant_payables_minor'), null, $scope->currency),
            'terms' => ['gmv' => 'What customers paid for completed orders. NOT FoodOnTheGo revenue.', 'platform_revenue' => 'Commission plus the platform\'s own fees — the only revenue.'],
        ];
    }

    /**
     * Commission, platform fees and gross marketplace sales per bucket, from the per-day copy of the ledger.
     *
     * @return array<string, mixed>
     */
    private function series(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $bucket = $period->bucket() === 'hour' ? 'day' : $period->bucket();
        $measures = ['PLATFORM_COMMISSION_REVENUE' => 'platform_commission_minor', 'PLATFORM_FEE_REVENUE' => 'platform_fee_revenue_minor', 'GROSS_MARKETPLACE_SALES' => 'gross_marketplace_sales_minor', 'RESTAURANT_EARNINGS' => 'restaurant_net_earnings_minor'];
        $rows = $this->read->daily('analytics_daily_finance', $scope, $period)->whereIn('t.measure', array_keys($measures))->selectRaw('t.currency, t.local_date, t.measure, coalesce(sum(t.amount_minor), 0) as amount')->groupBy('t.currency', 't.local_date', 't.measure')->get();
        $currencies = $rows->pluck('currency')->unique()->values()->all();
        $out = [];
        foreach ($currencies ?: [$scope->currency] as $currency) {
            $points = array_fill_keys($period->buckets($bucket), array_fill_keys(array_values($measures), 0));
            foreach ($rows->where('currency', $currency) as $row) {
                $points[$period->bucketOf(substr((string) $row->local_date, 0, 10), $bucket)][$measures[(string) $row->measure]] += (int) $row->amount;
            }
            $out[] = ['currency' => (string) $currency, 'points' => array_map(fn (string $key, array $row): array => ['bucket' => $key] + $row, array_keys($points), array_values($points))];
        }

        return ['bucket' => $bucket, 'timezone' => $period->timezone, 'by_currency' => $out, 'source' => 'Per-day copy of the ledger (aggregated); the totals above are read from the ledger itself.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function settlements(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $rows = DB::table('restaurant_settlements')->whereIn('market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)->where('generated_at', '>=', $period->startUtc())->where('generated_at', '<', $period->endUtc())
            ->selectRaw('currency, status, count(*) as n, coalesce(sum(net_payable_minor), 0) as amount, coalesce(sum(extract(epoch from (paid_at - generated_at))) filter (where paid_at is not null), 0) as paid_s, count(paid_at) as paid_n')->groupBy('currency', 'status')->get();
        $pending = ['CALCULATED', 'APPROVED', 'PAYMENT_PENDING'];
        $review = ['REVIEW_REQUIRED', 'FAILED'];
        $sum = fn (array $statuses): int => (int) $rows->whereIn('status', $statuses)->sum('n');

        return [
            'metrics' => [
                'settlements_generated' => Metric::count('settlements_generated', (int) $rows->sum('n')),
                'settlement_time_to_payout' => Metric::duration('settlement_time_to_payout', (int) round((float) $rows->sum('paid_s')), (int) $rows->sum('paid_n')),
            ],
            'pending' => $sum($pending), 'paid' => $sum(['PAID', 'CLOSED']), 'failed_or_review' => $sum($review), 'cancelled' => $sum(['CANCELLED']),
            'amounts' => $rows->groupBy('currency')->map(fn ($group, $currency): array => ['currency' => (string) $currency, 'net_payable' => ['amount' => (int) $group->sum('amount'), 'currency' => (string) $currency]])->values()->all(),
            'by_status' => $rows->groupBy('status')->map(fn ($group, $status): array => ['key' => (string) $status, 'label' => ReadModel::words((string) $status), 'count' => (int) $group->sum('n')])->values()->all(),
        ];
    }

    /**
     * A restaurant's earnings — Module 36's earnings service, unchanged.
     *
     * @param  list<int>  $locationIds
     * @return array<string, mixed>
     */
    public function restaurant(array $locationIds, string $timezone, AnalyticsPeriod $period): array
    {
        return [
            'summary' => array_map(fn (array $row): array => FinanceController::moneyRow($row), $this->earnings->summary($locationIds, $timezone)),
            'series' => array_map(fn (array $row): array => FinanceController::moneyRow($row), $this->earnings->series($locationIds, $timezone, min(92, max(1, $period->days())))),
            'definitions' => RestaurantEarningsService::DEFINITIONS,
            'source' => 'The same figures as the Earnings page: the finance module (Module 36). Analytics adds nothing to them.',
            'series_note' => 'The earnings series covers the most recent days up to today (at most 92), as on the Earnings page.',
        ];
    }
}
