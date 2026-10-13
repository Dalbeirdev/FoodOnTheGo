<?php

namespace App\Services\Analytics;

use App\Services\Finance\FinanceReportService;
use Illuminate\Support\Facades\DB;

/**
 * Customer analytics for administrators (Module 38) — AGGREGATES only. A customer is a keyed hash here: no report
 * lists customers, and no figure names one. "New account", "first-time ordering" and "repeat" are three different
 * things and are named separately. There is no lifetime value: no formula was approved.
 */
final class CustomerAnalyticsService
{
    public function __construct(private readonly ReadModel $read, private readonly OrderAnalyticsService $orders) {}

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->figures($scope, $period);
        $before = $this->figures($scope, $period->previous());
        $totals = $this->orders->totals($scope, $period);
        $completed = OrderAnalyticsService::flat($totals)['orders_completed'] ?? 0;

        return [
            'metrics' => [
                'new_accounts' => Metric::count('new_accounts', $now['new_accounts'], $before['new_accounts']),
                'first_time_ordering_customers' => Metric::count('first_time_ordering_customers', $now['first_time'], $before['first_time']),
                'ordering_customers' => Metric::count('ordering_customers', $now['ordering'], $before['ordering']),
                'repeat_customers' => Metric::count('repeat_customers', $now['repeat'], $before['repeat']),
                'repeat_order_rate' => Metric::ratio('repeat_order_rate', $now['repeat'], $now['ordering'], [$before['repeat'], $before['ordering']]),
                'active_customers' => Metric::count('active_customers', $now['active'], $before['active']),
                'orders_per_customer' => Metric::quotient('orders_per_customer', $completed, $now['ordering']),
                'average_order_value' => Metric::moneyAverage('average_order_value', array_map(fn (array $t): array => [$t['gmv_minor'], $t['orders_completed']], $totals), $scope->currency),
            ],
            'segments' => [
                // Two plain segments by ordering behaviour. Nothing is inferred about who a person is.
                ['key' => 'FIRST_TIME', 'label' => 'First completed order in the period', 'customers' => $now['first_time']],
                ['key' => 'RETURNING', 'label' => 'Had completed an order before the period', 'customers' => max(0, $now['ordering'] - $now['first_time'])],
            ],
            'series' => $this->series($scope, $period),
            'retention' => $this->retention($scope, $period),
            'not_reported' => ['lifetime_value' => 'Not defined: no formula or business purpose was approved.', 'acquisition_source' => 'Not tracked: no marketing attribution exists.'],
        ];
    }

    /**
     * Distinct customers of a period. Distinct counts cannot be added up from days, so they are counted over the facts.
     *
     * @return array{new_accounts: int, first_time: int, ordering: int, repeat: int, active: int}
     */
    public function figures(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $markets = $scope->marketIds === [] ? [0] : $scope->marketIds;
        $in = implode(',', array_map('intval', $markets));
        $row = DB::selectOne(
            "with done as (select customer_key, completed_at from analytics_order_facts where market_id in ({$in}) and status = 'COMPLETED'),
                per as (select customer_key, min(completed_at) as first_at, count(*) filter (where completed_at < ?) as upto, count(*) filter (where completed_at >= ? and completed_at < ?) as inside from done group by customer_key)
            select count(*) filter (where inside > 0) as ordering, count(*) filter (where first_at >= ? and first_at < ?) as first_time, count(*) filter (where inside > 0 and upto >= 2) as repeat from per",
            [$period->endUtc(), $period->startUtc(), $period->endUtc(), $period->startUtc(), $period->endUtc()],
        );
        $active = DB::selectOne(
            "select count(*) as n from (
                select customer_key from analytics_order_facts where market_id in ({$in}) and placed_at >= ? and placed_at < ?
                union select customer_key from analytics_events where market_id in ({$in}) and customer_key is not null and occurred_at >= ? and occurred_at < ?
            ) x",
            [$period->startUtc(), $period->endUtc(), $period->startUtc(), $period->endUtc()],
        );
        $accounts = $this->read->sums('analytics_daily_customers', $scope, $period, ['new_accounts']);

        return ['new_accounts' => $accounts['new_accounts'], 'first_time' => (int) ($row->first_time ?? 0), 'ordering' => (int) ($row->ordering ?? 0), 'repeat' => (int) ($row->repeat ?? 0), 'active' => (int) ($active->n ?? 0)];
    }

    /**
     * @return array<string, mixed>
     */
    private function series(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $rows = $this->read->series('analytics_daily_customers', $scope, $period, ['new_accounts', 'first_time_ordering']);

        return ['bucket' => $period->bucket() === 'hour' ? 'day' : $period->bucket(), 'timezone' => $period->timezone,
            'points' => array_map(fn (string $key, array $row): array => ['bucket' => $key] + $row, array_keys($rows), array_values($rows)),
            'note' => 'Counts of a day can be added up. Ordering and active customers are distinct people and are not shown per bucket.'];
    }

    /**
     * A basic cohort table: customers by the month of their first completed order, and how many of them completed
     * an order in each later month. The last six cohorts up to the end of the period.
     *
     * @return array<string, mixed>
     */
    private function retention(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $in = implode(',', array_map('intval', $scope->marketIds === [] ? [0] : $scope->marketIds));
        $zone = FinanceReportService::zone($period->timezone);
        $rows = DB::select(
            "with done as (select customer_key, date_trunc('month', completed_at at time zone {$zone})::date as m from analytics_order_facts where market_id in ({$in}) and status = 'COMPLETED' and completed_at < ?),
                firsts as (select customer_key, min(m) as cohort from done group by customer_key)
            select f.cohort, (extract(year from age(d.m, f.cohort)) * 12 + extract(month from age(d.m, f.cohort)))::int as month_number, count(distinct d.customer_key) as customers
            from firsts f join done d on d.customer_key = f.customer_key where f.cohort >= (date_trunc('month', ?::timestamptz at time zone {$zone}) - interval '5 months')::date group by 1, 2 order by 1, 2",
            [$period->endUtc(), $period->endUtc()->subSecond()],
        );
        $cohorts = [];
        foreach ($rows as $row) {
            $key = substr((string) $row->cohort, 0, 7);
            $cohorts[$key] ??= ['cohort' => $key, 'customers' => 0, 'months' => []];
            if ((int) $row->month_number === 0) {
                $cohorts[$key]['customers'] = (int) $row->customers;
            } else {
                $cohorts[$key]['months'][] = ['month_number' => (int) $row->month_number, 'customers' => (int) $row->customers];
            }
        }
        $min = max(1, (int) config('analytics.small_sample.min_denominator'));

        return ['definition' => 'Customers by the month of their first completed order; a later month counts those who completed an order in it.', 'minimum_cohort' => $min,
            'cohorts' => array_map(fn (array $c): array => $c + ['insufficient' => $c['customers'] < $min], array_values($cohorts))];
    }
}
