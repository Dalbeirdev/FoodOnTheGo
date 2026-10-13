<?php

namespace App\Services\Analytics;

use App\Services\Finance\FinanceReportService;
use Illuminate\Support\Facades\DB;

/**
 * Order analytics (Module 38): counts, rates and durations of orders — all read from the copies of the ORDER domain.
 * A report for administrators reads the market's days; a restaurant's report reads that restaurant's own days.
 *
 * An order that is still in progress is never a completed one: the completion rate is taken over the orders that
 * reached a final state, so a recent period is not made to look worse by the orders still on their way.
 */
final class OrderAnalyticsService
{
    public function __construct(private readonly ReadModel $read) {}

    /**
     * The order figures of a period per currency, in one shape for both grains.
     *
     * @return array<string, array<string, int>> currency => figures
     */
    public function totals(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $columns = ['orders_placed', 'placed_total_minor', 'cohort_completed', 'cohort_rejected', 'cohort_cancelled', 'cohort_active', 'cohort_refunded', 'cohort_accepted', 'orders_completed',
            'accept_seconds_sum', 'accept_count', 'prep_seconds_sum', 'prep_count', 'pickup_wait_seconds_sum', 'pickup_wait_count'];
        if ($scope->forLocations()) {
            $rows = $this->read->sumsByCurrency('analytics_daily_restaurant', $scope, $period, [...$columns, 'completed_total_minor', 'refunded_minor', 'journey_orders']);

            return array_map(fn (array $r): array => $r + ['gmv_minor' => $r['completed_total_minor'], 'total_seconds_sum' => 0, 'total_count' => 0], $rows);
        }

        return $this->read->sumsByCurrency('analytics_daily_market', $scope, $period, [...$columns, 'gmv_minor', 'total_seconds_sum', 'total_count']);
    }

    /**
     * @param  array<string, array<string, int>>  $byCurrency
     * @return array<string, int>
     */
    public static function flat(array $byCurrency): array
    {
        $flat = [];
        foreach ($byCurrency as $figures) {
            foreach ($figures as $key => $value) {
                $flat[$key] = ($flat[$key] ?? 0) + $value;
            }
        }

        return $flat;
    }

    /**
     * The handful of order figures the overview shows, and the trend — without the percentiles, the distributions and
     * the peak times of the full report.
     *
     * @return array{metrics: array<string, array<string, mixed>>, series: array<string, mixed>}
     */
    public function headline(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->totals($scope, $period);
        $before = $this->totals($scope, $period->previous());
        $keys = ['orders_placed', 'cohort_completed', 'cohort_rejected', 'cohort_cancelled', 'cohort_refunded', 'orders_completed', 'accept_seconds_sum', 'accept_count', 'prep_seconds_sum', 'prep_count', 'pickup_wait_seconds_sum', 'pickup_wait_count'];
        $n = self::flat($now) + array_fill_keys($keys, 0);
        $p = self::flat($before) + array_fill_keys($keys, 0);
        $final = fn (array $t): int => $t['cohort_completed'] + $t['cohort_rejected'] + $t['cohort_cancelled'];
        $money = $scope->audience === 'RESTAURANT' ? 'completed_order_total' : 'gmv';

        return [
            'metrics' => [
                'orders_placed' => Metric::count('orders_placed', $n['orders_placed'], $p['orders_placed']),
                'orders_completed' => Metric::count('orders_completed', $n['orders_completed'], $p['orders_completed']),
                'orders_refunded' => Metric::count('orders_refunded', $n['cohort_refunded'], $p['cohort_refunded']),
                'order_completion_rate' => Metric::ratio('order_completion_rate', $n['cohort_completed'], $final($n), [$p['cohort_completed'], $final($p)]),
                'order_rejection_rate' => Metric::ratio('order_rejection_rate', $n['cohort_rejected'], $n['orders_placed'], [$p['cohort_rejected'], $p['orders_placed']]),
                // The same sum under the name its reader knows: GMV for the platform, sales of completed orders for a restaurant.
                $money => Metric::money($money, array_map(fn (array $t): int => $t['gmv_minor'], $now), array_map(fn (array $t): int => $t['gmv_minor'], $before), $scope->currency),
                'acceptance_time' => Metric::duration('acceptance_time', $n['accept_seconds_sum'], $n['accept_count']),
                'preparation_time' => Metric::duration('preparation_time', $n['prep_seconds_sum'], $n['prep_count']),
                'pickup_wait_time' => Metric::duration('pickup_wait_time', $n['pickup_wait_seconds_sum'], $n['pickup_wait_count']),
            ],
            'series' => $this->series($scope, $period, array_keys($now)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->totals($scope, $period);
        $before = $this->totals($scope, $period->previous());
        $n = self::flat($now) + array_fill_keys(['orders_placed', 'cohort_completed', 'cohort_rejected', 'cohort_cancelled', 'cohort_active', 'cohort_refunded', 'cohort_accepted', 'orders_completed', 'accept_seconds_sum', 'accept_count', 'prep_seconds_sum', 'prep_count', 'pickup_wait_seconds_sum', 'pickup_wait_count', 'total_seconds_sum', 'total_count'], 0);
        $p = self::flat($before) + array_fill_keys(array_keys($n), 0);
        $final = fn (array $t): int => $t['cohort_completed'] + $t['cohort_rejected'] + $t['cohort_cancelled'];
        $money = $scope->audience === 'RESTAURANT' ? 'completed_order_total' : 'gmv';
        $percentiles = $this->percentiles($scope, $period);

        return [
            'metrics' => [
                'orders_placed' => Metric::count('orders_placed', $n['orders_placed'], $p['orders_placed']),
                'orders_completed' => Metric::count('orders_completed', $n['orders_completed'], $p['orders_completed']),
                'orders_rejected' => Metric::count('orders_rejected', $n['cohort_rejected'], $p['cohort_rejected']),
                'orders_cancelled' => Metric::count('orders_cancelled', $n['cohort_cancelled'], $p['cohort_cancelled']),
                'orders_in_progress' => Metric::count('orders_in_progress', $n['cohort_active']),
                'orders_refunded' => Metric::count('orders_refunded', $n['cohort_refunded'], $p['cohort_refunded']),
                'orders_refund_required' => Metric::count('orders_refund_required', $this->refundRequired($scope)),
                'order_completion_rate' => Metric::ratio('order_completion_rate', $n['cohort_completed'], $final($n), [$p['cohort_completed'], $final($p)]),
                'order_rejection_rate' => Metric::ratio('order_rejection_rate', $n['cohort_rejected'], $n['orders_placed'], [$p['cohort_rejected'], $p['orders_placed']]),
                // The same sum under the name its reader knows: GMV for the platform, sales of completed orders for a restaurant.
                $money => Metric::money($money, array_map(fn (array $t): int => $t['gmv_minor'], $now), array_map(fn (array $t): int => $t['gmv_minor'], $before), $scope->currency),
                'average_order_value' => Metric::moneyAverage('average_order_value', array_map(fn (array $t): array => [$t['gmv_minor'], $t['orders_completed']], $now), $scope->currency),
                'median_order_value' => $this->medianValue($scope, $period),
                'acceptance_time' => Metric::duration('acceptance_time', $n['accept_seconds_sum'], $n['accept_count'], $percentiles['accept'][0], $percentiles['accept'][1], [$p['accept_seconds_sum'], $p['accept_count']]),
                'preparation_time' => Metric::duration('preparation_time', $n['prep_seconds_sum'], $n['prep_count'], $percentiles['prep'][0], $percentiles['prep'][1], [$p['prep_seconds_sum'], $p['prep_count']]),
                'pickup_wait_time' => Metric::duration('pickup_wait_time', $n['pickup_wait_seconds_sum'], $n['pickup_wait_count'], $percentiles['wait'][0], $percentiles['wait'][1], [$p['pickup_wait_seconds_sum'], $p['pickup_wait_count']]),
                'completion_time' => Metric::duration('completion_time', $percentiles['total_sum'], $percentiles['total_count'], $percentiles['total'][0], $percentiles['total'][1]),
            ],
            'series' => $this->series($scope, $period, array_keys($now)),
            'status_distribution' => $this->statuses($scope, $period),
            'rejection_reasons' => $scope->forLocations() ? $this->factCounts($scope, $period, 'rejection_reason', "f.status = 'REJECTED'") : ReadModel::rows($this->read->breakdown('orders', 'rejection_reason', $scope, $period)),
            'peak_times' => $this->peakTimes($scope, $period),
            'estimated_preparation' => $this->estimate($scope, $period),
        ];
    }

    /**
     * Orders placed and completed and GMV per bucket — by hour for a day or two, else by day, week or month.
     *
     * @param  list<string>|null  $currencies  the currencies of the period when the caller already knows them
     * @return array<string, mixed>
     */
    public function series(AnalyticsScope $scope, AnalyticsPeriod $period, ?array $currencies = null): array
    {
        $currencies ??= array_keys($this->totals($scope, $period));
        $bucket = $period->bucket();
        $points = array_fill_keys($period->buckets(), ['orders_placed' => 0, 'orders_completed' => 0, 'gmv_minor' => 0]);
        if ($bucket === 'hour') {
            // Hours are cut from the facts, in the report's time zone, as REAL hours: the hour that happens twice when the
            // clocks go back is two buckets.
            $zone = FinanceReportService::zone($period->timezone);
            $names = $period->hourBuckets();
            foreach ([['placed_at', 'orders_placed', '1'], ['completed_at', 'orders_completed', '1'], ['completed_at', 'gmv_minor', 'f.total_minor']] as [$column, $key, $value]) {
                $rows = $this->read->facts($scope, $period, $column)->when($column === 'completed_at', fn ($q) => $q->where('f.status', 'COMPLETED'))
                    ->selectRaw("to_char(date_trunc('hour', f.{$column}, {$zone}) at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as b, coalesce(sum({$value}), 0) as n")->groupByRaw('1')->get();
                foreach ($rows as $row) {
                    $name = $names[(string) $row->b] ?? null;
                    if ($name !== null) {
                        $points[$name][$key] = (int) $row->n;
                    }
                }
            }
        } else {
            $table = $scope->forLocations() ? 'analytics_daily_restaurant' : 'analytics_daily_market';
            $money = $scope->forLocations() ? 'completed_total_minor' : 'gmv_minor';
            foreach ($this->read->series($table, $scope, $period, ['orders_placed', 'orders_completed', $money]) as $key => $row) {
                $points[$key] = ['orders_placed' => $row['orders_placed'], 'orders_completed' => $row['orders_completed'], 'gmv_minor' => $row[$money]];
            }
        }

        // A money line of several currencies would be a sum that means nothing: its points carry no amount, and it says so.
        $oneCurrency = count($currencies) <= 1;

        return [
            'bucket' => $bucket, 'timezone' => $period->timezone, 'currency' => $oneCurrency ? ($currencies[0] ?? $scope->currency) : null,
            'money_series_available' => $oneCurrency,
            'points' => array_map(fn (string $key, array $row): array => ['bucket' => $key] + ($oneCurrency ? $row : ['gmv_minor' => null] + $row), array_keys($points), array_values($points)),
        ];
    }

    /**
     * Median and 90th percentile of the durations, from the facts of the orders placed in the period.
     *
     * @return array<string, mixed>
     */
    private function percentiles(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $pair = fn (string $column): string => "percentile_cont(0.5) within group (order by f.{$column}) as {$column}_p50, percentile_cont(0.9) within group (order by f.{$column}) as {$column}_p90";
        $row = $this->read->facts($scope, $period)->selectRaw($pair('accept_seconds').', '.$pair('prep_seconds').', '.$pair('pickup_wait_seconds').', '.$pair('total_seconds').', coalesce(sum(f.total_seconds), 0) as total_sum, count(f.total_seconds) as total_count')->first();
        $both = fn (string $column): array => [$row?->{$column.'_p50'} === null ? null : (float) $row->{$column.'_p50'}, $row?->{$column.'_p90'} === null ? null : (float) $row->{$column.'_p90'}];

        return ['accept' => $both('accept_seconds'), 'prep' => $both('prep_seconds'), 'wait' => $both('pickup_wait_seconds'), 'total' => $both('total_seconds'), 'total_sum' => (int) ($row->total_sum ?? 0), 'total_count' => (int) ($row->total_count ?? 0)];
    }

    /**
     * @return array<string, mixed>
     */
    private function medianValue(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $rows = $this->read->facts($scope, $period, 'completed_at')->where('f.status', 'COMPLETED')->selectRaw('f.currency, percentile_cont(0.5) within group (order by f.total_minor) as median, count(*) as n')->groupBy('f.currency')->get();
        $by = [];
        foreach ($rows as $row) {
            $by[(string) $row->currency] = [(int) round((float) $row->median) * (int) $row->n, (int) $row->n];
        }

        return Metric::moneyAverage('median_order_value', $by, $scope->currency);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statuses(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        return $scope->forLocations() ? $this->factCounts($scope, $period, 'status') : ReadModel::rows($this->read->breakdown('orders', 'status', $scope, $period));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factCounts(AnalyticsScope $scope, AnalyticsPeriod $period, string $column, ?string $where = null): array
    {
        $rows = $this->read->facts($scope, $period)->when($where !== null, fn ($q) => $q->whereRaw($where))->selectRaw("coalesce(f.{$column}, 'UNKNOWN') as value, count(*) as n")->groupByRaw('1')->get();

        return ReadModel::rows($rows->mapWithKeys(fn ($r): array => [(string) $r->value => ['count' => (int) $r->n]])->all());
    }

    /**
     * Orders by day of the week (1 = Monday) and hour, in each restaurant's own time zone.
     *
     * @return array<string, mixed>
     */
    private function peakTimes(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $cells = [];
        foreach ($this->read->breakdown('orders', 'dow_hour', $scope, $period) as $value => $figures) {
            [$day, $hour] = array_map('intval', explode(':', $value));
            $cells[] = ['day_of_week' => $day, 'hour' => $hour, 'orders' => $figures['count']];
        }
        usort($cells, fn (array $a, array $b): int => [$b['orders'], $a['day_of_week'], $a['hour']] <=> [$a['orders'], $b['day_of_week'], $b['hour']]);

        return ['timezone_note' => 'Each restaurant\'s own time zone', 'week_starts' => 'MONDAY', 'cells' => $cells];
    }

    /**
     * What customers were told (estimated preparation) next to what happened (accepted → ready). Operational only.
     *
     * @return array<string, mixed>
     */
    private function estimate(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $row = $this->read->facts($scope, $period)->whereNotNull('f.prep_seconds')->whereNotNull('f.estimated_prep_minutes')
            ->selectRaw('count(*) as n, coalesce(avg(f.estimated_prep_minutes * 60), 0) as estimated, coalesce(avg(f.prep_seconds), 0) as actual, count(*) filter (where f.prep_seconds <= f.estimated_prep_minutes * 60) as within')->first();

        return ['orders' => (int) ($row->n ?? 0), 'estimated_seconds_average' => (int) round((float) ($row->estimated ?? 0)), 'actual_seconds_average' => (int) round((float) ($row->actual ?? 0)), 'ready_within_estimate' => (int) ($row->within ?? 0),
            'note' => 'An operational comparison of two times. It says nothing about the food.'];
    }

    private function refundRequired(AnalyticsScope $scope): int
    {
        return (int) DB::table('analytics_order_facts as f')->whereIn('f.market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)
            ->when($scope->forLocations(), fn ($q) => $q->whereIn('f.location_id', $scope->locationIds === [] ? [0] : $scope->locationIds))->where('f.refund_status', 'REQUIRED')->count();
    }
}
