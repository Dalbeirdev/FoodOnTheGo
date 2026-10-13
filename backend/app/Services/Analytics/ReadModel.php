<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads the analytics read models for a scope and a period (Module 38). Every query here is narrowed by the scope —
 * the markets, and the locations when the report is a restaurant's — so a service cannot forget to.
 *
 * The daily tables hold LOCAL days, so a period is a range of days; nothing here touches an order, a payment or the
 * ledger.
 */
final class ReadModel
{
    /**
     * A query on a daily table for the scope's markets (and locations) and the period's days.
     */
    public function daily(string $table, AnalyticsScope $scope, AnalyticsPeriod $period, string $alias = 't', bool $byMarket = true): Builder
    {
        $query = DB::table($table.' as '.$alias)->whereBetween($alias.'.local_date', [$period->fromDate(), $period->toDate()]);
        if ($byMarket) {
            $this->narrow($query, $scope, $alias);
        }
        if ($scope->forLocations()) {
            $query->whereIn($alias.'.location_id', $scope->locationIds === [] ? [0] : $scope->locationIds);
        }

        return $query;
    }

    /**
     * Narrows by market: the scope's markets, and rows without a market only for a scope that includes them.
     */
    public function narrow(Builder $query, AnalyticsScope $scope, string $alias = 't', string $column = 'market_id'): Builder
    {
        return $query->where(function (Builder $q) use ($scope, $alias, $column): void {
            $q->whereIn($alias.'.'.$column, $scope->marketIds === [] ? [0] : $scope->marketIds);
            if ($scope->includesUnassigned && ! $scope->forLocations()) {
                $q->orWhereNull($alias.'.'.$column);
            }
        });
    }

    /**
     * Sums of columns per currency: currency => [column => int].
     *
     * @param  list<string>  $columns
     * @return array<string, array<string, int>>
     */
    public function sumsByCurrency(string $table, AnalyticsScope $scope, AnalyticsPeriod $period, array $columns): array
    {
        $select = implode(', ', array_map(fn (string $c): string => "coalesce(sum(t.{$c}), 0) as {$c}", $columns));
        $out = [];
        foreach ($this->daily($table, $scope, $period)->selectRaw('t.currency, '.$select)->groupBy('t.currency')->orderBy('t.currency')->get() as $row) {
            $out[(string) $row->currency] = array_map('intval', array_diff_key((array) $row, ['currency' => true]));
        }

        return $out;
    }

    /**
     * Sums of columns over everything in scope (for counts — never for money of several currencies).
     *
     * @param  list<string>  $columns
     * @return array<string, int>
     */
    public function sums(string $table, AnalyticsScope $scope, AnalyticsPeriod $period, array $columns): array
    {
        $select = implode(', ', array_map(fn (string $c): string => "coalesce(sum(t.{$c}), 0) as {$c}", $columns));
        $row = (array) $this->daily($table, $scope, $period)->selectRaw($select)->first();

        return array_map('intval', $row + array_fill_keys($columns, 0));
    }

    /**
     * Columns per bucket of the period, every bucket present: [bucket => [column => int]].
     *
     * @param  list<string>  $columns
     * @return array<string, array<string, int>>
     */
    public function series(string $table, AnalyticsScope $scope, AnalyticsPeriod $period, array $columns, ?\Closure $where = null): array
    {
        $bucket = $period->bucket() === 'hour' ? 'day' : $period->bucket();
        $select = implode(', ', array_map(fn (string $c): string => "coalesce(sum(t.{$c}), 0) as {$c}", $columns));
        $query = $this->daily($table, $scope, $period)->selectRaw('t.local_date, '.$select)->groupBy('t.local_date');
        if ($where !== null) {
            $where($query);
        }
        $out = array_fill_keys($period->buckets($bucket), array_fill_keys($columns, 0));
        foreach ($query->get() as $row) {
            $key = $period->bucketOf(substr((string) $row->local_date, 0, 10), $bucket);
            foreach ($columns as $column) {
                $out[$key][$column] = ($out[$key][$column] ?? 0) + (int) $row->{$column};
            }
        }

        return $out;
    }

    /**
     * A breakdown of the period: value => [count, amount_minor, seconds_sum, seconds_count] (and per currency when asked).
     *
     * @return array<string, array{count: int, amount_minor: int, seconds_sum: int, seconds_count: int}>
     */
    public function breakdown(string $group, string $dimension, AnalyticsScope $scope, AnalyticsPeriod $period, ?string $currency = null): array
    {
        $query = $this->daily('analytics_daily_breakdowns', $scope, $period)->where('t.grp', $group)->where('t.dimension', $dimension)
            ->when($currency !== null, fn (Builder $q) => $q->where('t.currency', $currency))
            ->selectRaw('t.value, coalesce(sum(t.count), 0) as n, coalesce(sum(t.amount_minor), 0) as amount, coalesce(sum(t.seconds_sum), 0) as seconds_sum, coalesce(sum(t.seconds_count), 0) as seconds_count')
            ->groupBy('t.value')->orderBy('t.value');
        $out = [];
        foreach ($query->get() as $row) {
            $out[(string) $row->value] = ['count' => (int) $row->n, 'amount_minor' => (int) $row->amount, 'seconds_sum' => (int) $row->seconds_sum, 'seconds_count' => (int) $row->seconds_count];
        }

        return $out;
    }

    /**
     * The amounts of a breakdown per currency: currency => value => amount.
     *
     * @return array<string, array<string, int>>
     */
    public function breakdownAmounts(string $group, string $dimension, AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $out = [];
        $rows = $this->daily('analytics_daily_breakdowns', $scope, $period)->where('t.grp', $group)->where('t.dimension', $dimension)->where('t.currency', '!=', '')
            ->selectRaw('t.currency, t.value, coalesce(sum(t.amount_minor), 0) as amount')->groupBy('t.currency', 't.value')->get();
        foreach ($rows as $row) {
            $out[(string) $row->currency][(string) $row->value] = (int) $row->amount;
        }

        return $out;
    }

    /**
     * A query on the order facts placed in the period, in scope.
     */
    public function facts(AnalyticsScope $scope, AnalyticsPeriod $period, string $by = 'placed_at'): Builder
    {
        $query = DB::table('analytics_order_facts as f')->where('f.'.$by, '>=', $period->startUtc())->where('f.'.$by, '<', $period->endUtc())->whereIn('f.market_id', $scope->marketIds === [] ? [0] : $scope->marketIds);
        if ($scope->forLocations()) {
            $query->whereIn('f.location_id', $scope->locationIds === [] ? [0] : $scope->locationIds);
        }

        return $query;
    }

    /**
     * Rows of a list as [{key, label, count, …}] sorted by count, largest first.
     *
     * @param  array<string, array<string, int>>  $breakdown
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    public static function rows(array $breakdown, array $labels = []): array
    {
        $rows = [];
        foreach ($breakdown as $value => $figures) {
            $rows[] = ['key' => (string) $value, 'label' => $labels[$value] ?? self::words((string) $value), 'count' => $figures['count']];
        }
        usort($rows, fn (array $a, array $b): int => [$b['count'], $a['key']] <=> [$a['count'], $b['key']]);

        return $rows;
    }

    /** "NET_BANKING" → "Net banking". */
    public static function words(string $code): string
    {
        return ucfirst(strtolower(str_replace(['_', ':'], [' ', ' · '], $code)));
    }
}
