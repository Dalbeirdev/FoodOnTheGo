<?php

namespace App\Services\Analytics\Aggregation;

use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Finance\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs the aggregation (Module 38): copies what changed into the fact tables, finds the local days that changed and
 * recomputes exactly those days in every daily read model.
 *
 *   SCHEDULED  every few minutes: what changed since the last run + the most recent days.
 *   REBUILD    a range somebody named, again — same result as the first time.
 *   BACKFILL   everything the source domains still hold.
 *
 * The run READS orders, payments, refunds, the ledger, cases, reviews and notifications and writes only analytics_*
 * tables. It never repairs a source: an inconsistency is for the data-quality checks to report. A failure leaves the
 * transactional system untouched and the next run does the same days again.
 */
final class AggregationService
{
    public function __construct(
        private readonly FactBuilder $facts,
        private readonly DailyAggregator $daily,
        private readonly AnalyticsMetrics $metrics,
        private readonly AnalyticsCache $cache,
    ) {}

    /**
     * @param  list<string>|null  $groups  null = every group
     * @param  list<int>|null  $marketIds  null = every market
     * @return array<string, mixed>
     */
    public function run(string $trigger = 'SCHEDULED', ?array $groups = null, ?string $from = null, ?string $to = null, ?array $marketIds = null, bool $dryRun = false): array
    {
        $known = (array) config('analytics.aggregation.groups');
        $groups = $groups === null ? $known : array_values(array_intersect($known, $groups));
        $lock = Cache::lock('analytics:aggregation', 900);
        if (! $lock->get()) {
            return ['status' => 'SKIPPED', 'reason' => 'another_run_is_active'];
        }
        $started = CarbonImmutable::now('UTC');
        $timer = hrtime(true);
        $runId = DB::table('analytics_runs')->insertGetId([
            'public_id' => (string) Str::uuid(), 'trigger' => $trigger, 'dry_run' => $dryRun, 'status' => 'RUNNING', 'groups' => json_encode($groups), 'from_date' => $from, 'to_date' => $to, 'started_at' => $started,
        ]);

        try {
            DB::beginTransaction();
            $result = $this->work($trigger, $groups, $from, $to, $marketIds, $started);
            $dryRun ? DB::rollBack() : DB::commit();
            $finish = ['status' => 'OK', 'result' => json_encode($result), 'rows_written' => $dryRun ? 0 : $result['rows'], 'watermark_at' => $dryRun || $trigger !== 'SCHEDULED' ? null : $started];
            if (! $dryRun) {
                // Every cached answer was computed from the read models as they were: start a new generation.
                $this->cache->flush();
            }
            $this->metrics->record($trigger === 'SCHEDULED' ? 'aggregation_ok' : 'rebuild');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('analytics.aggregation_failed', ['run' => $runId, 'error' => $e::class, 'message' => Str::limit($e->getMessage(), 300, '')]);
            $this->metrics->record('aggregation_failed');
            $result = ['error' => $e::class];
            $finish = ['status' => 'FAILED', 'result' => json_encode($result), 'error' => Str::limit($e::class.': '.$e->getMessage(), 300, '')];
        } finally {
            $lock->release();
        }
        DB::table('analytics_runs')->where('id', $runId)->update($finish + ['duration_ms' => (int) round((hrtime(true) - $timer) / 1_000_000), 'finished_at' => CarbonImmutable::now('UTC')]);

        return ['status' => $finish['status'], 'trigger' => $trigger, 'dry_run' => $dryRun] + $result;
    }

    /**
     * @param  list<string>  $groups
     * @return array<string, mixed>
     */
    private function work(string $trigger, array $groups, ?string $from, ?string $to, ?array $marketIds, CarbonImmutable $now): array
    {
        $since = $trigger === 'SCHEDULED' ? $this->since() : null;
        $rows = $this->facts->orders($since, $now);
        $orderFacts = $rows;
        $sessionFacts = $this->facts->sessions($since, $now);
        $rows += $sessionFacts;

        $markets = DB::table('markets')->orderBy('id')->when($marketIds !== null, fn ($q) => $q->whereIn('id', $marketIds))->get(['id', 'default_timezone', 'default_currency']);
        $defaultId = (int) (DB::table('markets')->where('country_code', (string) config('market.default_country'))->value('id') ?? DB::table('markets')->min('id'));
        $perMarket = [];
        $perGroup = array_fill_keys($groups, 0);
        foreach ($markets as $row) {
            $timezone = in_array((string) $row->default_timezone, timezone_identifiers_list(), true) ? (string) $row->default_timezone : 'UTC';
            $market = (object) ['id' => (int) $row->id, 'timezone' => $timezone, 'currency' => (string) $row->default_currency, 'is_default' => (int) $row->id === $defaultId];
            $dates = $trigger === 'SCHEDULED' ? $this->changedDays($market, $since, $now) : $this->rangeDays($market, $from, $to, $now);
            if ($dates === []) {
                continue;
            }
            foreach (array_chunk($dates, 62) as $chunk) {
                foreach ($groups as $group) {
                    $written = $this->daily->run($group, $market, $chunk, $now);
                    $perGroup[$group] += $written;
                    $rows += $written;
                }
            }
            DB::table('analytics_dirty_days')->where('market_id', $market->id)->delete();
            $perMarket[] = ['market' => $market->id, 'days' => count($dates), 'from' => $dates[0], 'to' => $dates[count($dates) - 1]];
        }

        return ['rows' => $rows, 'order_facts' => $orderFacts, 'session_facts' => $sessionFacts, 'groups' => $perGroup, 'markets' => $perMarket, 'since' => $since?->toIso8601String()];
    }

    /**
     * Source rows changed at or after this instant are looked at by a scheduled run; null = there was no run yet.
     */
    private function since(): ?CarbonImmutable
    {
        $last = DB::table('analytics_runs')->where('status', 'OK')->whereNotNull('watermark_at')->max('watermark_at');

        return $last === null ? null : CarbonImmutable::parse((string) $last)->utc()->subSeconds((int) config('analytics.aggregation.overlap_seconds'));
    }

    /**
     * The local days of a market that a scheduled run must write again: the most recent days, the days somebody
     * marked, and the days of every source row that changed since the last run (a late event, a refund of an old
     * order, a case resolved today that was opened last week).
     *
     * @return list<string>
     */
    private function changedDays(object $market, ?CarbonImmutable $since, CarbonImmutable $now): array
    {
        if ($since === null) {
            return $this->rangeDays($market, null, null, $now);
        }
        $z = FinanceReportService::zone($market->timezone);
        $today = $now->setTimezone($market->timezone)->startOfDay();
        $days = [];
        for ($i = 0; $i < max(1, (int) config('analytics.aggregation.trailing_days')); $i++) {
            $days[$today->subDays($i)->toDateString()] = true;
        }
        foreach (DB::table('analytics_dirty_days')->where('market_id', $market->id)->pluck('local_date') as $day) {
            $days[substr((string) $day, 0, 10)] = true;
        }
        $day = fn (string $column): string => "({$column} at time zone {$z})::date";
        $mine = $market->is_default ? '(market_id = ? or market_id is null)' : 'market_id = ?';
        $cases = $market->is_default ? '(c.market_id = ? or c.market_id is null)' : 'c.market_id = ?';
        $sources = [
            // The facts this very run wrote: the days their orders were placed and completed.
            ["select {$day('placed_at')} as d from analytics_order_facts where market_id = ? and computed_at >= ?", [$market->id, $now]],
            ["select {$day('completed_at')} from analytics_order_facts where market_id = ? and computed_at >= ? and completed_at is not null", [$market->id, $now]],
            ["select {$day('occurred_at')} from analytics_events where market_id = ? and received_at >= ?", [$market->id, $since]],
            ['select local_date from analytics_session_facts where market_id = ? and computed_at >= ?', [$market->id, $now]],
            ["select {$day('created_at')} from payment_attempts where market_id = ? and updated_at >= ?", [$market->id, $since]],
            ["select {$day('coalesce(requested_at, created_at)')} from refunds where market_id = ? and updated_at >= ?", [$market->id, $since]],
            ["select {$day('completed_at')} from refunds where market_id = ? and updated_at >= ? and completed_at is not null", [$market->id, $since]],
            ["select {$day('occurred_at')} from ledger_entries where {$mine} and created_at >= ?", [$market->id, $since]],
            ["select {$day('recognized_at')} from order_financial_allocations where market_id = ? and updated_at >= ? and recognized_at is not null", [$market->id, $since]],
            ["select {$day('v.d')} from support_cases c cross join lateral (values (c.created_at), (c.resolved_at), (c.closed_at), (c.reopened_at), (c.escalated_at), (c.sla_breached_at)) as v(d) where {$cases} and c.updated_at >= ? and v.d is not null", [$market->id, $since]],
            ["select {$day('published_at')} from restaurant_reviews where market_id = ? and updated_at >= ? and published_at is not null", [$market->id, $since]],
            ["select {$day('n.created_at')} from notification_deliveries dl join notifications n on n.id = dl.notification_id join customers cu on cu.id = n.customer_id where (cu.market_id = ? or (cu.market_id is null and ?)) and dl.updated_at >= ?", [$market->id, $market->is_default, $since]],
            ["select {$day('created_at')} from customers where (market_id = ? or (market_id is null and ?)) and created_at >= ?", [$market->id, $market->is_default, $since]],
        ];
        foreach ($sources as [$sql, $bindings]) {
            foreach (DB::select($sql, $bindings) as $row) {
                $value = array_values((array) $row)[0];
                if ($value !== null) {
                    $days[substr((string) $value, 0, 10)] = true;
                }
            }
        }
        ksort($days);

        return array_keys($days);
    }

    /**
     * Every local day from … to (both included). Without a start: from the first thing any source domain still holds.
     *
     * @return list<string>
     */
    private function rangeDays(object $market, ?string $from, ?string $to, CarbonImmutable $now): array
    {
        $today = $now->setTimezone($market->timezone)->startOfDay();
        $end = $to === null ? $today : CarbonImmutable::createFromFormat('!Y-m-d', $to, $market->timezone);
        if ($from === null) {
            $first = collect([
                DB::table('orders')->where('market_id', $market->id)->min('placed_at'), DB::table('analytics_events')->where('market_id', $market->id)->min('occurred_at'),
                DB::table('payment_attempts')->where('market_id', $market->id)->min('created_at'), DB::table('refunds')->where('market_id', $market->id)->min('created_at'),
                DB::table('ledger_entries')->where('market_id', $market->id)->min('occurred_at'), DB::table('support_cases')->where('market_id', $market->id)->min('created_at'),
                DB::table('restaurant_reviews')->where('market_id', $market->id)->min('published_at'),
                $market->is_default ? DB::table('notifications')->min('created_at') : null, $market->is_default ? DB::table('customers')->min('created_at') : null,
                $market->is_default ? DB::table('support_cases')->whereNull('market_id')->min('created_at') : null, $market->is_default ? DB::table('ledger_entries')->whereNull('market_id')->min('occurred_at') : null,
            ])->filter()->map(fn ($v): CarbonImmutable => CarbonImmutable::parse((string) $v))->min();
            if ($first === null) {
                return [];
            }
            $start = $first->setTimezone($market->timezone)->startOfDay()->subDay();
        } else {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $market->timezone);
        }
        $days = [];
        for ($day = $start; $day->lessThanOrEqualTo($end) && count($days) < 4000; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * When the read models were last written successfully.
     */
    public function lastSuccess(): ?CarbonImmutable
    {
        $last = DB::table('analytics_runs')->where('status', 'OK')->where('dry_run', false)->max('finished_at');

        return $last === null ? null : CarbonImmutable::parse((string) $last)->utc();
    }
}
