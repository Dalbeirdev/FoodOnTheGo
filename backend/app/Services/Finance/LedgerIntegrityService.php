<?php

namespace App\Services\Finance;

use App\Enums\DifferenceType;
use App\Enums\LedgerAccountCode;
use App\Enums\PayoutStatus;
use App\Enums\SettlementStatus;
use App\Models\FinanceRun;
use App\Models\LedgerAccount;
use App\Models\ReconciliationDifference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ledger checked against itself and against the records it mirrors (Module 36) — `finance:verify-ledger`.
 *
 * The database already refuses an unbalanced or edited posting; this is the independent look that would notice if
 * something got past (a trigger disabled by hand, a restore, a defect): every figure is recomputed with plain SQL
 * sums, nothing is taken from a cache, and nothing is repaired — a finding is a difference for a person.
 *
 * A ledger imbalance is the most severe finding the platform has: it is logged as critical the moment it is seen.
 */
final class LedgerIntegrityService
{
    /** Where the record behind a posting lives, per source type. */
    private const SOURCES = [
        'payment' => 'payment_attempts', 'order' => 'orders', 'refund' => 'refunds', 'dispute' => 'payment_disputes', 'financial_adjustment' => 'financial_adjustments',
        'restaurant_payout' => 'restaurant_payouts', 'provider_settlement_item' => 'provider_settlement_items', 'provider_settlement' => 'provider_settlements',
    ];

    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly SettlementService $settlements,
        private readonly DifferenceService $differences,
        private readonly FinanceMetrics $metrics,
    ) {}

    public function verify(string $trigger = FinanceRun::BY_COMMAND, ?int $adminId = null): FinanceRun
    {
        $run = new FinanceRun;
        $run->forceFill(['kind' => FinanceRun::VERIFY_LEDGER, 'status' => 'RUNNING', 'triggered_by' => $trigger, 'admin_user_id' => $adminId, 'started_at' => now()])->save();
        $found = [];
        $checks = [];

        // 1. Every transaction balances, per currency, and has at least two lines.
        $unbalanced = DB::table('ledger_transactions as t')->leftJoin('ledger_entries as e', 'e.ledger_transaction_id', '=', 't.id')
            ->selectRaw("t.id, t.public_id, t.posting_key, t.currency, t.market_id, count(e.id) as lines, coalesce(sum(case e.direction when 'D' then e.amount_minor else -e.amount_minor end), 0) as difference, coalesce(sum(case e.direction when 'D' then e.amount_minor else 0 end), 0) as debits")
            ->groupBy('t.id', 't.public_id', 't.posting_key', 't.currency', 't.market_id')
            ->havingRaw("coalesce(sum(case e.direction when 'D' then e.amount_minor else -e.amount_minor end), 0) <> 0 or count(e.id) < 2")->limit(200)->get();
        foreach ($unbalanced as $row) {
            $found[] = $key = 'ledger-imbalance:'.$row->public_id;
            $this->differences->report(DifferenceType::LedgerImbalance, $key, ['subject_type' => 'ledger_transaction', 'subject_id' => (int) $row->id, 'subject_public_id' => (string) $row->public_id, 'subject_reference' => mb_substr((string) $row->posting_key, 0, 80),
                'market_id' => $row->market_id, 'currency' => (string) $row->currency, 'expected_minor' => 0, 'actual_minor' => (int) $row->difference, 'detail' => ['lines' => (int) $row->lines]]);
            $this->metrics->record('ledger_imbalance');
            Log::critical('finance.ledger_imbalance', ['transaction' => $row->public_id, 'difference_minor' => (int) $row->difference, 'currency' => $row->currency, 'lines' => (int) $row->lines]);
        }
        $checks['unbalanced_transactions'] = $unbalanced->count();

        // 2. Currencies never meet: an entry is in the currency of its transaction and of its account.
        $checks['currency_mismatches'] = (int) DB::table('ledger_entries as e')->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where(fn ($q) => $q->whereColumn('e.currency', '<>', 't.currency')->orWhereColumn('e.currency', '<>', 'a.currency'))->count();

        // 3. One business event, one posting.
        $checks['duplicate_postings'] = (int) DB::table('ledger_transactions')->selectRaw('posting_key')->groupBy('posting_key')->havingRaw('count(*) > 1')->get()->count();

        // 4. Every posting has the record it is about.
        $orphans = 0;
        foreach (self::SOURCES as $type => $table) {
            $orphans += (int) DB::table('ledger_transactions as t')->where('t.source_type', $type)->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table.' as s')->whereColumn('s.id', 't.source_id'))->count();
        }
        $checks['orphan_postings'] = $orphans + (int) DB::table('ledger_transactions')->whereNotIn('source_type', array_keys(self::SOURCES))->count();

        // 5. The restaurants' payable lines, minus what was paid out, are the ledger's payable balances.
        $checks['payable_mismatches'] = 0;
        $checks['negative_restaurant_balances'] = 0;
        $pairs = DB::table('restaurant_payable_items')->select('location_id', 'currency')->distinct()
            ->union(DB::table('ledger_accounts')->where('code', LedgerAccountCode::RestaurantPayable->value)->select('location_id', 'currency'))->get();
        foreach ($pairs as $pair) {
            [$expected, $actual] = $this->settlements->payableFigures((int) $pair->location_id, (string) $pair->currency);
            $key = 'payable:'.$pair->location_id.':'.$pair->currency;
            if ($expected !== $actual) {
                $found[] = $key;
                $checks['payable_mismatches']++;
                $this->differences->report(DifferenceType::PayableMismatch, $key, ['subject_type' => 'restaurant_location', 'subject_id' => (int) $pair->location_id, 'location_id' => (int) $pair->location_id,
                    'market_id' => DB::table('restaurant_locations')->where('id', $pair->location_id)->value('market_id'), 'currency' => (string) $pair->currency, 'expected_minor' => $expected, 'actual_minor' => $actual]);
            }
            $checks['negative_restaurant_balances'] += $actual < 0 ? 1 : 0;
        }

        // 6. A clearing account, a liability or the bank on the wrong side of zero.
        $checks['inverted_accounts'] = 0;
        foreach (LedgerAccount::query()->where('owner_type', '<>', LedgerAccount::OWNER_LOCATION)->get() as $account) {
            if ($account->code->mayNotInvert() && ($balance = $this->ledger->balance($account)) < 0) {
                $found[] = $key = 'inverted:'.$account->public_id;
                $checks['inverted_accounts']++;
                $this->differences->report(DifferenceType::ClearingInverted, $key, ['subject_type' => 'ledger_account', 'subject_id' => (int) $account->getKey(), 'subject_public_id' => (string) $account->public_id,
                    'subject_reference' => $account->code->value.($account->provider === null ? '' : ' / '.$account->provider), 'currency' => (string) $account->currency, 'expected_minor' => 0, 'actual_minor' => $balance]);
            }
        }

        // 7. States that contradict each other.
        $checks['settled_lines_of_cancelled_settlements'] = (int) DB::table('restaurant_payable_items as i')->join('restaurant_settlements as s', 's.id', '=', 'i.settlement_id')->where('s.status', SettlementStatus::Cancelled->value)->count();
        $checks['paid_settlements_without_paid_payout'] = (int) DB::table('restaurant_settlements as s')->where('s.status', SettlementStatus::Paid->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('restaurant_payouts as p')->whereColumn('p.settlement_id', 's.id')->where('p.status', PayoutStatus::Paid->value))->count();
        $checks['paid_payouts_without_posting'] = (int) DB::table('restaurant_payouts as p')->where('p.status', PayoutStatus::Paid->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'restaurant_payout')->whereColumn('t.source_id', 'p.id')->where('t.type', 'PAYOUT_PAID'))->count();
        $checks['recognized_orders_without_payable_line'] = (int) DB::table('order_financial_allocations as a')->where('a.status', 'RECOGNIZED')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('restaurant_payable_items as i')->whereColumn('i.allocation_id', 'a.id'))->count();

        // What was found before and is not found now agrees again.
        foreach (ReconciliationDifference::query()->unresolved()->whereIn('type', [DifferenceType::LedgerImbalance->value, DifferenceType::PayableMismatch->value, DifferenceType::ClearingInverted->value])
            ->where(fn ($q) => $q->where('dedupe_key', 'like', 'ledger-imbalance:%')->orWhere('dedupe_key', 'like', 'payable:%')->orWhere('dedupe_key', 'like', 'inverted:%'))->whereNotIn('dedupe_key', $found)->pluck('dedupe_key') as $key) {
            $this->differences->settled((string) $key, 'The integrity check no longer finds it.');
        }

        // A negative restaurant balance is a state finance has to look at — not a defect of the ledger.
        $problems = array_sum(array_diff_key($checks, ['negative_restaurant_balances' => 0]));
        $run->forceFill([
            'status' => $problems > 0 ? 'PROBLEMS' : 'OK',
            'result' => ['transactions' => (int) DB::table('ledger_transactions')->count(), 'entries' => (int) DB::table('ledger_entries')->count(), 'accounts' => (int) DB::table('ledger_accounts')->count(), 'checks' => $checks],
            'problems' => $problems, 'finished_at' => now(),
        ])->save();

        return $run;
    }
}
