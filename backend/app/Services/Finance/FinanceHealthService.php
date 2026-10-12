<?php

namespace App\Services\Finance;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\FinanceRun;
use App\Services\Finance\Payout\PayoutProviderManager;
use App\Services\Finance\ProviderSettlement\ProviderSettlementSources;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The finance domain's health, as it is — for the administrators' system page (Module 36).
 *
 * Nothing is called healthy because it exists: a ledger that was never checked says NEVER_CHECKED, a payout provider
 * that is the development fake says FAKE_PROVIDER, and everything the business has not decided is listed as pending.
 */
final class FinanceHealthService
{
    public function __construct(
        private readonly FinanceMetrics $metrics,
        private readonly PayoutProviderManager $payouts,
        private readonly ProviderSettlementSources $sources,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(int $hours = 24): array
    {
        $last = fn (string $kind): ?FinanceRun => FinanceRun::query()->where('kind', $kind)->where('status', '<>', 'RUNNING')->orderByDesc('id')->first();
        $state = fn (?FinanceRun $run): array => [
            'status' => $run === null ? 'NEVER_RUN' : $run->status,
            'last_run_at' => $run?->finished_at?->toIso8601String(),
            'problems' => $run?->problems,
            'result' => $run?->result,
        ];
        $verify = $last(FinanceRun::VERIFY_LEDGER);
        $grace = now()->subMinutes(max(0, (int) config('finance.internal_reconciliation.posting_after_minutes')));
        $unposted = [
            'payments' => (int) DB::table('payment_attempts as x')->where('x.status', PaymentStatus::Verified->value)->where('x.amount_minor', '>', 0)->where('x.verified_at', '<', $grace)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'payment')->whereColumn('t.source_id', 'x.id')->where('t.type', 'PAYMENT_VERIFIED'))->count(),
            'refunds' => (int) DB::table('refunds as x')->where('x.status', RefundStatus::Refunded->value)->where('x.completed_at', '<', $grace)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'refund')->whereColumn('t.source_id', 'x.id')->where('t.type', 'REFUND_COMPLETED'))->count(),
            'completed_orders' => (int) DB::table('orders as x')->where('x.status', 'COMPLETED')->where('x.completed_at', '<', $grace)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_financial_allocations as a')->whereColumn('a.order_id', 'x.id')->whereIn('a.status', ['RECOGNIZED', 'REVIEW_REQUIRED', 'VOID']))->count(),
        ];
        $differences = DB::table('reconciliation_differences')->whereIn('status', ['OPEN', 'INVESTIGATING'])->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->map(fn ($n): int => (int) $n)->all();
        $reasons = (array) config('finance.refund_liability.by_reason');

        return [
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'window_hours' => $hours,
            'ledger' => [
                ...$state($verify),
                'status' => $verify === null ? 'NEVER_CHECKED' : ($verify->status === 'OK' ? 'BALANCED' : $verify->status),
                'transactions' => (int) DB::table('ledger_transactions')->count(),
                'entries' => (int) DB::table('ledger_entries')->count(),
                'imbalance_events' => $this->metrics->sum('ledger_imbalance', $hours) + $this->metrics->sum('ledger_refused', $hours),
            ],
            'internal_reconciliation' => $state($last(FinanceRun::RECONCILE_INTERNAL)),
            'provider_reconciliation' => [...$this->sources->report(), ...$state($last(FinanceRun::PROVIDER_IMPORT)), 'source_status' => $this->sources->report()['status']],
            'payout_provider' => $this->payouts->report(),
            'queue' => ['name' => (string) config('finance.queue'), 'unposted' => $unposted, 'status' => array_sum($unposted) === 0 ? 'UP_TO_DATE' : 'BEHIND'],
            'differences_open' => ['total' => array_sum($differences), 'by_severity' => (object) $differences],
            'events' => $this->metrics->events($hours),
            // What the code does today, and that none of it is an approved policy.
            'policies' => [
                'recognition' => ['value' => (string) config('finance.recognition.trigger'), 'approval' => (string) config('finance.recognition.approval')],
                'commission' => ['value' => 'CONFIGURED_PER_PLAN', 'approval' => 'PENDING BUSINESS APPROVAL (no production rate exists; TEST plans only)'],
                'tax_owner' => ['value' => (string) config('finance.tax.owner'), 'approval' => (string) config('finance.tax.approval')],
                'fee_owner' => ['value' => (string) config('finance.fees.owner'), 'approval' => (string) config('finance.fees.approval')],
                'shared_promotion_split' => ['value' => config('finance.promotions.shared_restaurant_share_bps') === null ? 'UNDEFINED' : (int) config('finance.promotions.shared_restaurant_share_bps'), 'approval' => (string) config('finance.promotions.approval')],
                'provider_fees' => ['value' => (string) config('finance.provider_fees.policy'), 'approval' => (string) config('finance.provider_fees.approval')],
                'refund_liability' => ['value' => $reasons === [] ? 'UNASSIGNED (a person decides each refund)' : array_map(fn (array $p): string => $p[0].($p[1] === null ? '' : ':'.$p[1]), $reasons), 'approval' => (string) config('finance.refund_liability.approval')],
                'holds' => ['value' => ['refund_pending' => (bool) config('finance.holds.refund_pending'), 'dispute' => (bool) config('finance.holds.dispute')], 'approval' => (string) config('finance.holds.approval')],
                'settlement_cadence' => ['value' => (string) config('finance.settlements.cadence'), 'approval' => (string) config('finance.settlements.approval')],
                'minimum_payout' => ['value' => config('finance.settlements.minimum_payout_minor'), 'approval' => (string) config('finance.settlements.approval')],
                'four_eyes' => ['value' => ['settlements' => (bool) config('finance.settlements.four_eyes'), 'payouts' => (bool) config('finance.payouts.four_eyes'), 'adjustments' => (bool) config('finance.adjustments.four_eyes')], 'approval' => 'PENDING BUSINESS APPROVAL'],
            ],
            'flags' => [
                'live_payouts' => 'DISABLED',
                'restaurant_kyc' => 'NOT_IMPLEMENTED',
                'gst_rate_hardcoded' => false,
                'tds_tcs_hardcoded' => false,
                'tax_invoice' => (string) config('finance.tax_documents.tax_invoice'),
                'commission_invoice' => (string) config('finance.tax_documents.commission_invoice'),
                'credit_note' => (string) config('finance.tax_documents.credit_note'),
                'withholding' => (string) config('finance.tax_documents.withholding'),
                'production' => 'NO',
            ],
        ];
    }
}
