<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Aggregation\AggregationService;
use App\Services\Finance\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Integrity checks of the analytics layer (Module 38): HEALTHY, WARNING or ERROR — each from an actual comparison.
 *
 * A check compares an analytics table with the domain it copies, or looks for something that cannot be. It REPORTS.
 * It never changes an order, a payment, a refund or a ledger line: an inconsistency in a source is for the people
 * of that domain, and an inconsistency in a copy is fixed by building the copy again (`analytics:rebuild`).
 */
final class DataQualityService
{
    public function __construct(private readonly AggregationService $aggregation, private readonly FinanceReportService $finance) {}

    /**
     * Runs every check, stores the results and returns them.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $now = CarbonImmutable::now('UTC');
        $results = [];
        foreach ([
            'order_facts_cover_orders' => fn (): array => $this->orderCoverage(),
            'order_totals_match' => fn (): array => $this->orderTotals(),
            'facts_have_dimensions' => fn (): array => $this->dimensions(),
            'verified_payments_have_orders' => fn (): array => $this->paymentsWithoutOrders($now),
            'events_have_a_market' => fn (): array => $this->unknownMarkets(),
            'no_negative_counts' => fn (): array => $this->negatives(),
            'funnel_transitions' => fn (): array => $this->transitions(),
            'event_ids_unique' => fn (): array => $this->duplicateEvents(),
            'finance_daily_matches_ledger' => fn (): array => $this->ledger($now),
            'aggregation_is_fresh' => fn (): array => $this->freshness($now),
        ] as $key => $check) {
            try {
                [$status, $message, $observed] = $check();
            } catch (\Throwable $e) {
                [$status, $message, $observed] = ['ERROR', 'The check itself failed to run.', ['exception' => $e::class]];
            }
            $results[] = ['check' => $key, 'status' => $status, 'message' => $message, 'observed' => $observed];
            DB::table('analytics_quality_results')->insert(['check_key' => $key, 'status' => $status, 'message' => mb_substr($message, 0, 300), 'observed' => json_encode((object) $observed), 'checked_at' => $now]);
        }
        DB::table('analytics_quality_results')->where('checked_at', '<', $now->subDays((int) config('analytics.retention.quality_days')))->delete();

        return $this->latest();
    }

    /**
     * The result of the last run of every check, and the worst of them as the overall status.
     *
     * @return array<string, mixed>
     */
    public function latest(): array
    {
        $rows = DB::select('select distinct on (check_key) check_key, status, message, observed, checked_at from analytics_quality_results order by check_key, checked_at desc, id desc');
        $rank = ['HEALTHY' => 0, 'WARNING' => 1, 'ERROR' => 2];
        $worst = 'HEALTHY';
        $checks = [];
        foreach ($rows as $row) {
            $worst = $rank[$row->status] > $rank[$worst] ? $row->status : $worst;
            $checks[] = ['check' => (string) $row->check_key, 'status' => (string) $row->status, 'message' => (string) $row->message, 'observed' => json_decode((string) $row->observed, true) ?: (object) [],
                'checked_at' => CarbonImmutable::parse((string) $row->checked_at)->toIso8601ZuluString()];
        }

        return [
            'status' => $checks === [] ? 'NEVER_CHECKED' : $worst,
            'checked_at' => $checks === [] ? null : max(array_column($checks, 'checked_at')),
            'checks' => $checks,
            'counts' => ['healthy' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'HEALTHY')), 'warning' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'WARNING')), 'error' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'ERROR'))],
            'repair' => 'The checks report. A copy that is wrong is built again (analytics:rebuild); a source that is wrong is not touched by analytics.',
        ];
    }

    /** Every order has a fact row — after the aggregation has had time to see it. */
    private function orderCoverage(): array
    {
        $grace = CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.freshness.stale_after_minutes'));
        $missing = (int) DB::table('orders as o')->leftJoin('analytics_order_facts as f', 'f.order_id', '=', 'o.id')->whereNull('f.order_id')->where('o.created_at', '<', $grace)->count();
        $orphans = (int) DB::table('analytics_order_facts as f')->leftJoin('orders as o', 'o.id', '=', 'f.order_id')->whereNull('o.id')->count();

        return [$missing + $orphans === 0 ? 'HEALTHY' : 'ERROR', $missing + $orphans === 0 ? 'Every order has its fact.' : 'Orders and their facts do not match one to one.', ['orders_without_fact' => $missing, 'facts_without_order' => $orphans]];
    }

    /** The facts say the same status and total as the orders they copy. */
    private function orderTotals(): array
    {
        $row = DB::selectOne("select count(*) filter (where f.total_minor <> o.total_minor) as totals, count(*) filter (where f.status <> o.status and o.updated_at < now() - interval '30 minutes') as statuses,
            coalesce(sum(o.total_minor), 0) as orders_total, coalesce(sum(f.total_minor), 0) as facts_total from analytics_order_facts f join orders o on o.id = f.order_id");
        $bad = (int) $row->totals + (int) $row->statuses;

        return [$bad === 0 ? 'HEALTHY' : 'ERROR', $bad === 0 ? 'Order facts agree with the orders.' : 'Some order facts differ from their orders.', ['different_totals' => (int) $row->totals, 'stale_statuses' => (int) $row->statuses, 'orders_total_minor' => (int) $row->orders_total, 'facts_total_minor' => (int) $row->facts_total]];
    }

    /** A fact without a currency, or whose market is not its restaurant's, cannot be reported correctly. */
    private function dimensions(): array
    {
        $row = DB::selectOne("select count(*) filter (where f.currency is null or btrim(f.currency) = '') as no_currency, count(*) filter (where l.market_id <> f.market_id) as market_mismatch,
            count(*) filter (where f.status = 'COMPLETED' and f.completed_at is null) as completed_without_time from analytics_order_facts f join restaurant_locations l on l.id = f.location_id");
        $errors = (int) $row->no_currency;
        $warnings = (int) $row->market_mismatch + (int) $row->completed_without_time;
        $status = $errors > 0 ? 'ERROR' : ($warnings > 0 ? 'WARNING' : 'HEALTHY');

        return [$status, $status === 'HEALTHY' ? 'Every fact has its currency, market and times.' : 'Some facts lack a dimension — the source order is inconsistent.', ['missing_currency' => $errors, 'market_differs_from_restaurant' => (int) $row->market_mismatch, 'completed_without_completion_time' => (int) $row->completed_without_time]];
    }

    /** A verified payment that never became an order: the funnel's "payment verified" has no "order created". */
    private function paymentsWithoutOrders(CarbonImmutable $now): array
    {
        $count = (int) DB::table('payment_attempts')->where('status', 'VERIFIED')->whereIn('fulfillment_status', ['ORDER_PENDING', 'RECOVERY_REQUIRED'])
            ->where('verified_at', '<', $now->subMinutes((int) config('analytics.quality.payment_without_order_minutes')))->count();

        return [$count === 0 ? 'HEALTHY' : 'WARNING', $count === 0 ? 'Every verified payment has its order.' : 'Verified payments without an order exist — a matter for the payment and order domains.', ['verified_payments_without_order' => $count]];
    }

    private function unknownMarkets(): array
    {
        $row = DB::selectOne('select count(*) as total, count(*) filter (where market_id is null) as unknown from analytics_events');
        $share = (int) $row->total === 0 ? 0.0 : (int) $row->unknown / (int) $row->total;

        return [$share > (float) config('analytics.quality.unknown_market_share') ? 'WARNING' : 'HEALTHY', (int) $row->unknown === 0 ? 'Every behavioural event has a market.' : 'Some behavioural events have no market.', ['events' => (int) $row->total, 'without_market' => (int) $row->unknown]];
    }

    /** A count below zero cannot be: it would mean a read model was written wrongly. */
    private function negatives(): array
    {
        $found = [];
        foreach ([
            'analytics_daily_market' => 'orders_placed < 0 or orders_completed < 0 or gmv_minor < 0 or placed_total_minor < 0',
            'analytics_daily_restaurant' => 'orders_placed < 0 or orders_completed < 0 or completed_total_minor < 0 or refunded_minor < 0',
            'analytics_daily_items' => 'revenue_minor < 0',
            'analytics_daily_breakdowns' => 'seconds_sum < 0',
        ] as $table => $condition) {
            $n = (int) DB::table($table)->whereRaw($condition)->count();
            if ($n > 0) {
                $found[$table] = $n;
            }
        }

        return [$found === [] ? 'HEALTHY' : 'ERROR', $found === [] ? 'No impossible negative figure.' : 'A read model holds a negative figure that cannot be.', $found];
    }

    /** Sessions with a later stage and without the one before it — clients that skipped an event. Not counted in the funnel. */
    private function transitions(): array
    {
        $row = DB::selectOne('select count(*) as total, count(*) filter (where out_of_order) as broken from analytics_session_facts');
        $share = (int) $row->total === 0 ? 0.0 : (int) $row->broken / (int) $row->total;

        return [$share > (float) config('analytics.quality.invalid_transition_share') ? 'WARNING' : 'HEALTHY', (int) $row->broken === 0 ? 'Every session reached its stages in order.' : 'Some sessions have a stage without the one before it; they are counted only as far as they came in order.',
            ['sessions' => (int) $row->total, 'out_of_order' => (int) $row->broken]];
    }

    private function duplicateEvents(): array
    {
        $duplicates = (int) (DB::selectOne('select count(*) as n from (select event_id from analytics_events group by event_id having count(*) > 1) d')->n ?? 0);
        $unique = DB::selectOne("select count(*) as n from pg_indexes where tablename = 'analytics_events' and indexdef ilike '%unique%' and indexdef ilike '%event_id%'");

        return [$duplicates === 0 && (int) $unique->n > 0 ? 'HEALTHY' : 'ERROR', $duplicates === 0 ? 'No event id is stored twice.' : 'An event id is stored more than once.', ['duplicate_event_ids' => $duplicates, 'unique_index' => (int) $unique->n > 0]];
    }

    /** The per-day copy of the ledger says what the ledger says — for the last 35 days, per currency. */
    private function ledger(CarbonImmutable $now): array
    {
        $from = $now->subDays(35)->startOfDay();
        $overview = $this->finance->overview(null, $from, $now->addDay());
        $differences = [];
        foreach ($overview['currencies'] as $currency) {
            $copy = DB::table('analytics_daily_finance as d')->where('d.currency', $currency['currency'])->whereIn('d.measure', ['PLATFORM_COMMISSION_REVENUE', 'GROSS_MARKETPLACE_SALES'])
                ->where('d.local_date', '>=', $from->subDay()->toDateString())
                ->selectRaw('d.measure, coalesce(sum(d.amount_minor), 0) as amount')->groupBy('d.measure')->pluck('amount', 'measure');
            // The copy is cut by local days and the ledger by instants: compare over a window both contain entirely.
            $ledger = ['PLATFORM_COMMISSION_REVENUE' => (int) $currency['period']['platform_commission_minor'], 'GROSS_MARKETPLACE_SALES' => (int) $currency['period']['gross_marketplace_sales_minor']];
            foreach ($ledger as $measure => $amount) {
                if ($amount > (int) ($copy[$measure] ?? 0)) {
                    $differences[] = ['currency' => $currency['currency'], 'measure' => $measure, 'ledger_minor' => $amount, 'copy_minor' => (int) ($copy[$measure] ?? 0)];
                }
            }
        }

        return [$differences === [] ? 'HEALTHY' : 'ERROR', $differences === [] ? 'The per-day finance figures cover the ledger.' : 'The per-day finance figures are behind the ledger — rebuild the finance group.', ['differences' => $differences]];
    }

    private function freshness(CarbonImmutable $now): array
    {
        $last = $this->aggregation->lastSuccess();
        $minutes = $last === null ? null : (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 60);
        $limit = (int) config('analytics.freshness.stale_after_minutes');
        $failed = (int) DB::table('analytics_runs')->where('status', 'FAILED')->where('started_at', '>=', $now->subDay())->count();
        $status = $last === null ? 'WARNING' : ($minutes > $limit * 6 ? 'ERROR' : ($minutes > $limit || $failed > 0 ? 'WARNING' : 'HEALTHY'));

        return [$status, $last === null ? 'The aggregation has not run yet.' : ($status === 'HEALTHY' ? 'The read models are fresh.' : 'The read models are older than they should be, or a run failed.'), ['minutes_since_last_success' => $minutes, 'failed_runs_24h' => $failed, 'stale_after_minutes' => $limit]];
    }
}
