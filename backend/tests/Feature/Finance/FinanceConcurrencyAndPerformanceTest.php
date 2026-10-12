<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialAdjustment;
use App\Models\LedgerTransaction;
use App\Models\OrderFinancialAllocation;
use App\Models\PaymentAttempt;
use App\Models\ProviderSettlementItem;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantSettlement;
use App\Services\Finance\AdjustmentService;
use App\Services\Finance\FinancialPostingService;
use App\Services\Finance\ProviderSettlement\FakeSettlementSource;
use App\Services\Finance\SettlementCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Module 36 — what holds when two things happen at once, and what the finance queries cost.
 *
 * CONCURRENCY: a test process is one database session, so a race is shown the way the earlier modules show theirs —
 * by the locks and constraints that decide it: the lock another session cannot take, and the row the database
 * refuses whatever the application is asked. PERFORMANCE: queries are counted and time is measured on this machine's
 * test database; the numbers are measurements, never targets, and are written to FINANCE_PERF_REPORT when it is set.
 */
class FinanceConcurrencyAndPerformanceTest extends TestCase
{
    use BuildsFinance, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    protected function tearDown(): void
    {
        if (($file = (string) env('FINANCE_PERF_REPORT', '')) !== '' && $this->report !== []) {
            $existing = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
            file_put_contents($file, json_encode([...$existing, ...$this->report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int, 2: mixed} queries, milliseconds, the work's result
     */
    private function measure(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $result = $work();
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $ms, $result];
    }

    private function refused(callable $write, string $what): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The database accepted: '.$what);
        } catch (QueryException $e) {
            $this->assertContains((string) $e->getCode(), ['23505', '23514', '23001', '23503'], $what.': '.$e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_two_sessions_cannot_calculate_the_same_restaurant_at_the_same_time(): void
    {
        $key = 'finance:settlement:'.$this->burgerHub->id.':INR';
        // This session calculates Burger Hub's INR settlement: it holds the lock until its transaction ends.
        DB::statement('select pg_advisory_xact_lock(hashtext(?))', [$key]);

        // ANOTHER session (a second connection to the same database) cannot take it — it would wait; and it can take
        // the lock of another restaurant or another currency at once.
        config(['database.connections.second_session' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('second_session');
        $other->beginTransaction();
        try {
            $this->assertFalse((bool) $other->selectOne('select pg_try_advisory_xact_lock(hashtext(?)) as taken', [$key])->taken, 'a second calculation of the same restaurant and currency has to wait');
            $this->assertTrue((bool) $other->selectOne('select pg_try_advisory_xact_lock(hashtext(?)) as taken', ['finance:settlement:'.$this->burgerHub->id.':USD'])->taken);
            $this->assertTrue((bool) $other->selectOne('select pg_try_advisory_xact_lock(hashtext(?)) as taken', ['finance:settlement:'.($this->burgerHub->id + 1).':INR'])->taken);
        } finally {
            $other->rollBack();
            DB::purge('second_session');
        }
    }

    public function test_the_database_refuses_everything_a_race_could_try_to_write_twice(): void
    {
        $order = $this->completedOrderWith();
        [$refunded, $refund] = $this->refundedCompletedOrder(10000);
        $this->asFinance();
        $adjustment = FinancialAdjustment::query()->firstOrFail();
        $this->financePost('/adjustments/'.$adjustment->public_id.'/decide', ['restaurant_share_minor' => 10000, 'reason' => 'TEST FIXTURE: the kitchen\'s mistake'])->assertOk();
        $transaction = LedgerTransaction::query()->where('type', 'ORDER_RECOGNIZED')->firstOrFail();
        $item = $this->earningOf($order);
        $allocation = $this->allocationOf($order);
        $copy = fn (object $model, array $overrides): array => [...collect($model->getAttributes())->except(['id'])->all(), ...$overrides];

        // POSTING: the same business event a second time.
        $this->refused(fn () => DB::table('ledger_transactions')->insert($copy($transaction, ['public_id' => (string) Str::uuid()])), 'a second posting with the same key');
        // ORDER: a second allocation of the same order.
        $this->refused(fn () => DB::table('order_financial_allocations')->insert($copy($allocation, ['public_id' => (string) Str::uuid(), 'ledger_transaction_id' => null, 'status' => 'PENDING', 'recognized_at' => null])), 'a second allocation of one order');
        // EARNING: a second payable line for the same event — the same order cannot be earned twice.
        $this->refused(fn () => DB::table('restaurant_payable_items')->insert($copy($item, ['public_id' => (string) Str::uuid()])), 'a second earning of one order');
        // REFUND: a second liability decision for the same refund, and a second restaurant share for it.
        $this->refused(fn () => DB::table('financial_adjustments')->insert($copy($adjustment->refresh(), ['public_id' => (string) Str::uuid(), 'ledger_transaction_id' => null, 'status' => 'PENDING_DECISION', 'posted_at' => null])), 'a second liability for one refund');
        $share = RestaurantPayableItem::query()->where('type', 'REFUND_ADJUSTMENT')->firstOrFail();
        $this->refused(fn () => DB::table('restaurant_payable_items')->insert($copy($share, ['public_id' => (string) Str::uuid()])), 'a second restaurant share of one refund');
        // A line that is SETTLED names its settlement, and one that is not names none: there is no half-consumed line.
        $this->refused(fn () => DB::table('restaurant_payable_items')->where('id', $item->id)->update(['status' => 'SETTLED']), 'a settled line without a settlement');
        $this->refused(fn () => DB::table('restaurant_payable_items')->where('id', $item->id)->update(['settlement_id' => 999999]), 'a line of a settlement that does not exist');
        // A recognized allocation always has its posting; an allocation's numbers always add up.
        $this->refused(fn () => DB::table('order_financial_allocations')->where('id', $allocation->id)->update(['commission_minor' => 1]), 'a commission that does not fit the allocation');
        $this->refused(fn () => DB::table('order_financial_allocations')->where('id', $allocation->id)->update(['ledger_transaction_id' => null]), 'a recognized allocation without its posting');

        // The same events through the application, again and again: nothing is added.
        $counts = fn (): array => [LedgerTransaction::query()->count(), OrderFinancialAllocation::query()->count(), RestaurantPayableItem::query()->count(), FinancialAdjustment::query()->count()];
        $before = $counts();
        $postings = app(FinancialPostingService::class);
        for ($i = 0; $i < 3; $i++) {
            $postings->orderChanged($order->refresh());
            $postings->orderChanged($refunded->refresh());
            $postings->refundChanged($refund->refresh());
            $postings->paymentVerified(PaymentAttempt::query()->findOrFail($order->payment_attempt_id));
            $postings->sweep();
        }
        $this->assertSame($before, $counts());

        // PROVIDER IMPORT: two workers reading the same provider record keep one row.
        $payment = PaymentAttempt::query()->findOrFail($order->payment_attempt_id);
        (new FakeSettlementSource)->settleDay(CarbonImmutable::parse('2026-10-07'), [['reference' => (string) $payment->provider_payment_reference, 'amount' => 50000, 'fee' => 1000, 'tax' => 153]]);
        $this->financePost('/provider-settlements/import', ['day' => '2026-10-07'])->assertCreated();
        $line = ProviderSettlementItem::query()->firstOrFail();
        $this->refused(fn () => DB::table('provider_settlement_items')->insert($copy($line, ['public_id' => (string) Str::uuid(), 'snapshot' => json_encode($line->snapshot)])), 'the same provider record twice');
        $this->assertSame(0, DB::table('provider_settlement_items')->insertOrIgnore($copy($line, ['public_id' => (string) Str::uuid(), 'snapshot' => json_encode($line->snapshot)])), 'the import\'s own write ignores a record that is there');
        $this->assertLedgerBalanced();
    }

    public function test_what_the_finance_queries_cost_and_that_lists_do_not_grow_with_their_rows(): void
    {
        // Warm the framework; then one measured order completion (its posting runs inline under the test's synchronous queue).
        $this->completedOrderWith();
        $ready = $this->readyOrder($this->nextPhone(), '17:45');
        $payload = $this->credentialOf($ready[1], $ready[2]['order_number'])['qr_payload'];
        [$postingQueries, $postingMs] = $this->measure(fn () => $this->verifyQr($payload)->assertOk());
        $this->report['order_completion_with_recognition'] = ['queries' => $postingQueries, 'ms' => $postingMs, 'note' => 'pickup verification + completion + the finance posting (inline in tests; a queued job in production)'];

        // Many payable lines for the lists: manual credits are real lines with real postings.
        $this->asFinance();
        $adjustments = app(AdjustmentService::class);
        $make = fn (int $n) => array_map(fn (int $i) => $adjustments->manual($this->burgerHub, $this->asFinance(), ['direction' => 'CREDIT_RESTAURANT', 'amount_minor' => 100 + $i, 'currency' => 'INR', 'reason_code' => 'CORRECTION',
            'reason' => 'TEST FIXTURE: a line for the performance measurement', 'supporting_reference' => 'PERF-'.$i]), range(1, $n));
        $make(10);
        $this->asActor($owner = $this->member($this->organization, 'OWNER'));
        [$fewSummary, $fewSummaryMs] = $this->measure(fn () => $this->restaurantFinance('/summary', $this->burgerHub)->assertOk());
        [$fewLines] = $this->measure(fn () => $this->restaurantFinance('/earnings', $this->burgerHub, 'per_page=100')->assertOk());
        $this->asFinance();
        [$fewPayables] = $this->measure(fn () => $this->financeGet('/payables')->assertOk());
        [$fewLedger] = $this->measure(fn () => $this->financeGet('/ledger/transactions?page[size]=100')->assertOk());
        [$fewOverview] = $this->measure(fn () => $this->financeGet('/overview')->assertOk());

        [$bulkQueries, $bulkMs] = $this->measure(fn () => $make(190));
        $this->report['manual_adjustment_posting'] = ['queries_per_adjustment' => round($bulkQueries / 190, 1), 'ms_per_adjustment' => round($bulkMs / 190, 1), 'adjustments' => 190];
        $this->asActor($owner);
        [$manySummary, $manySummaryMs] = $this->measure(fn () => $this->restaurantFinance('/summary', $this->burgerHub)->assertOk());
        [$manyLines, $manyLinesMs] = $this->measure(fn () => $this->restaurantFinance('/earnings', $this->burgerHub, 'per_page=100')->assertOk()->assertJsonCount(100, 'data'));
        $this->asFinance();
        [$manyPayables, $manyPayablesMs] = $this->measure(fn () => $this->financeGet('/payables')->assertOk());
        [$manyLedger, $manyLedgerMs] = $this->measure(fn () => $this->financeGet('/ledger/transactions?page[size]=100')->assertOk()->assertJsonCount(100, 'data'));
        [$manyOverview, $manyOverviewMs] = $this->measure(fn () => $this->financeGet('/overview')->assertOk());
        $this->report['restaurant_earnings_summary'] = ['queries' => $manySummary, 'ms' => $manySummaryMs, 'lines' => 202];
        $this->report['restaurant_earnings_list_100'] = ['queries' => $manyLines, 'ms' => $manyLinesMs];
        $this->report['admin_payables'] = ['queries' => $manyPayables, 'ms' => $manyPayablesMs];
        $this->report['admin_ledger_list_100'] = ['queries' => $manyLedger, 'ms' => $manyLedgerMs];
        $this->report['admin_finance_overview'] = ['queries' => $manyOverview, 'ms' => $manyOverviewMs, 'ledger_transactions' => LedgerTransaction::query()->count()];
        // N+1 AUDIT: a page of 100 costs what a page of 12 costs — SQL sums and eager loading, never a query per row.
        $this->assertLessThanOrEqual($fewSummary + 1, $manySummary, 'the earnings summary is a fixed number of aggregate queries');
        $this->assertLessThanOrEqual($fewLines + 1, $manyLines, 'the earnings list does not query per line');
        $this->assertLessThanOrEqual($fewPayables + 1, $manyPayables);
        $this->assertLessThanOrEqual($fewLedger + 1, $manyLedger, 'the ledger list does not query per transaction');
        $this->assertLessThanOrEqual($fewOverview + 1, $manyOverview, 'the overview is aggregate queries whatever the size of the ledger');
        $this->assertLessThanOrEqual(30, $manySummary);
        $this->assertLessThanOrEqual(40, $manyOverview);

        // SETTLEMENT GENERATION of 202 lines: a fixed number of statements (the lines are written in bulk).
        $this->nextWeek();
        [$generateQueries, $generateMs] = $this->measure(fn () => app(SettlementCalculationService::class)->generate(collect([$this->burgerHub])));
        $settlement = RestaurantSettlement::query()->firstOrFail();
        $this->assertSame(202, $settlement->line_count);
        $this->assertLessThanOrEqual(30, $generateQueries, 'generating a settlement does not write line by line');
        [$detailQueries, $detailMs] = $this->measure(fn () => $this->financeGet('/settlements/'.$settlement->public_id)->assertOk()->assertJsonCount(202, 'settlement.lines'));
        [$listQueries, $listMs] = $this->measure(fn () => $this->financeGet('/settlements')->assertOk());
        [$approveQueries, $approveMs] = $this->measure(fn () => $this->financePost('/settlements/'.$settlement->public_id.'/approve')->assertOk());
        $this->report['settlement_generation_202_lines'] = ['queries' => $generateQueries, 'ms' => $generateMs];
        $this->report['settlement_detail_202_lines'] = ['queries' => $detailQueries, 'ms' => $detailMs];
        $this->report['settlement_list'] = ['queries' => $listQueries, 'ms' => $listMs];
        $this->report['settlement_approval_with_ledger_recheck'] = ['queries' => $approveQueries, 'ms' => $approveMs];
        $this->assertLessThanOrEqual(30, $detailQueries);

        // RECONCILIATION: an import of 50 provider lines.
        $source = new FakeSettlementSource;
        $source->settleDay(CarbonImmutable::parse('2026-10-07'), array_map(fn (int $i): array => ['reference' => 'fpay_perf_'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'amount' => 10000, 'fee' => 200, 'tax' => 31], range(1, 50)));
        [$importQueries, $importMs] = $this->measure(fn () => $this->financePost('/provider-settlements/import', ['day' => '2026-10-07'])->assertCreated());
        $this->report['provider_import_and_reconciliation_50_lines'] = ['queries' => $importQueries, 'ms' => $importMs, 'note' => 'per line: one existence check, one insert, one match — bounded per record, not per page'];
        $this->assertSame(50, ProviderSettlementItem::query()->count());

        // EXPLAIN: the heavy finance queries have an index to use (the planner is told not to prefer a sequential scan
        // of these small test tables, so that what is shown is whether a usable index exists).
        DB::statement('set local enable_seqscan = off');
        $plans = [
            'settleable_lines_of_a_location' => ['select * from restaurant_payable_items where location_id = ? and currency = ? and status = ? and available_at < ? order by available_at, id', [$this->burgerHub->id, 'INR', 'AVAILABLE', now()]],
            'ledger_entries_of_an_account' => ['select sum(amount_minor) from ledger_entries where account_id = ?', [DB::table('ledger_accounts')->where('code', 'RESTAURANT_PAYABLE')->value('id')]],
            'postings_of_a_source' => ['select 1 from ledger_transactions where source_type = ? and source_id = ? and type = ?', ['payment', 1, 'PAYMENT_VERIFIED']],
            'recognized_allocations_of_a_market_and_period' => ['select sum(commission_minor) from order_financial_allocations where market_id = ? and recognized_at >= ? and recognized_at < ?', [$this->market->id, now()->subMonth(), now()]],
            'open_differences_by_severity' => ['select count(*) from reconciliation_differences where status = ? and severity = ?', ['OPEN', 'HIGH']],
            'settlements_of_a_location' => ['select * from restaurant_settlements where location_id = ? order by period_end desc limit 25', [$this->burgerHub->id]],
            'payouts_due_for_a_check' => ['select * from restaurant_payouts where status = ? and next_action_at <= ? order by next_action_at limit 50', ['PROCESSING', now()]],
            'provider_item_by_reference' => ['select * from provider_settlement_items where provider = ? and type = ? and entity_reference = ?', ['fake', 'PAYMENT', 'fpay_perf_000001']],
        ];
        foreach ($plans as $name => [$sql, $bindings]) {
            $plan = implode("\n", array_map(fn ($row): string => (string) current((array) $row), DB::select('explain (analyze, costs off, timing off, summary off) '.$sql, $bindings)));
            $this->assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap Index Scan/', $plan, $name.":\n".$plan);
            preg_match('/(Index Only Scan|Index Scan|Bitmap Index Scan)[^\n]* (?:using|on) ([a-z0-9_]+)/', $plan, $m);
            $this->report['explain'][$name] = trim(($m[1] ?? 'Index Scan').' '.($m[2] ?? ''));
        }
    }
}
