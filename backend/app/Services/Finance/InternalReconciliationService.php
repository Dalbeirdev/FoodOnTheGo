<?php

namespace App\Services\Finance;

use App\Enums\DifferenceType;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\RefundStatus;
use App\Models\FinanceRun;
use App\Models\ReconciliationDifference;
use Illuminate\Support\Facades\DB;

/**
 * The platform's own financial records compared with each other (Module 36) — `finance:reconcile-internal`.
 *
 *   a paid order        has ONE verified payment with the order's amount, currency and checkout
 *   an unpaid order     is allowed only with a zero total (PAYMENT_NOT_REQUIRED); cash was never approved
 *   a verified payment  became an order, or is refunded (or being refunded) — otherwise it is an orphan
 *   refunds             never add up to more than the payment
 *   a paid payout       is the amount of its settlement
 *   a financial event   has its ledger posting
 *   an order            whose terms nobody could decide waits visibly for a person
 *
 * It reads; it never repairs. Each finding is a difference, and a finding that is gone is closed by the same rule
 * that found it.
 */
final class InternalReconciliationService
{
    private const TYPES = [
        DifferenceType::PaymentOrderAmountMismatch, DifferenceType::PaymentOrderCurrencyMismatch, DifferenceType::OrderWithoutPayment, DifferenceType::OrphanPayment,
        DifferenceType::RefundExceedsPayment, DifferenceType::PayoutSettlementMismatch, DifferenceType::MissingLedgerPosting, DifferenceType::AllocationReviewRequired,
    ];

    public function __construct(private readonly DifferenceService $differences) {}

    public function run(string $trigger = FinanceRun::BY_COMMAND, ?int $adminId = null): FinanceRun
    {
        $run = new FinanceRun;
        $run->forceFill(['kind' => FinanceRun::RECONCILE_INTERNAL, 'status' => 'RUNNING', 'triggered_by' => $trigger, 'admin_user_id' => $adminId, 'started_at' => now()])->save();
        $found = [];
        $counts = [];
        $report = function (DifferenceType $type, string $key, array $facts) use (&$found, &$counts): void {
            $found[] = $key;
            $counts[$type->value] = ($counts[$type->value] ?? 0) + 1;
            $this->differences->report($type, $key, $facts);
        };

        // Paid orders against their payment.
        $orders = DB::table('orders as o')->leftJoin('payment_attempts as p', 'p.id', '=', 'o.payment_attempt_id')->where('o.payment_state', 'PAID')
            ->where(fn ($q) => $q->whereNull('p.id')->orWhere('p.status', '<>', PaymentStatus::Verified->value)->orWhereColumn('p.amount_minor', '<>', 'o.total_minor')->orWhereColumn('p.currency', '<>', 'o.currency')->orWhereColumn('p.checkout_public_id', '<>', 'o.checkout_public_id'))
            ->limit(500)->get(['o.id', 'o.public_id', 'o.order_number', 'o.market_id', 'o.location_id', 'o.total_minor', 'o.currency', 'o.checkout_public_id', 'p.id as payment_id', 'p.status as payment_status', 'p.amount_minor as paid_minor', 'p.currency as paid_currency', 'p.checkout_public_id as payment_checkout']);
        foreach ($orders as $o) {
            $facts = ['subject_type' => 'order', 'subject_id' => (int) $o->id, 'subject_public_id' => (string) $o->public_id, 'subject_reference' => (string) $o->order_number, 'market_id' => $o->market_id, 'location_id' => $o->location_id, 'currency' => (string) $o->currency];
            match (true) {
                $o->payment_id === null || $o->payment_status !== PaymentStatus::Verified->value => $report(DifferenceType::OrderWithoutPayment, 'order:'.$o->public_id.':no-payment', [...$facts, 'expected_minor' => (int) $o->total_minor, 'actual_minor' => null, 'detail' => ['payment_status' => $o->payment_status ?? 'NO_PAYMENT']]),
                $o->paid_currency !== $o->currency => $report(DifferenceType::PaymentOrderCurrencyMismatch, 'order:'.$o->public_id.':currency', [...$facts, 'detail' => ['order_currency' => $o->currency, 'payment_currency' => $o->paid_currency]]),
                default => $report(DifferenceType::PaymentOrderAmountMismatch, 'order:'.$o->public_id.':amount', [...$facts, 'expected_minor' => (int) $o->total_minor, 'actual_minor' => (int) $o->paid_minor, 'detail' => ['same_checkout' => $o->payment_checkout === $o->checkout_public_id]]),
            };
        }

        // An order nobody paid for is allowed only when there was nothing to pay.
        foreach (DB::table('orders')->where('payment_state', '<>', 'PAID')->where('total_minor', '>', 0)->limit(500)->get(['id', 'public_id', 'order_number', 'market_id', 'location_id', 'total_minor', 'currency', 'payment_state']) as $o) {
            $report(DifferenceType::OrderWithoutPayment, 'order:'.$o->public_id.':unpaid', ['subject_type' => 'order', 'subject_id' => (int) $o->id, 'subject_public_id' => (string) $o->public_id, 'subject_reference' => (string) $o->order_number,
                'market_id' => $o->market_id, 'location_id' => $o->location_id, 'currency' => (string) $o->currency, 'expected_minor' => (int) $o->total_minor, 'actual_minor' => 0, 'detail' => ['payment_state' => $o->payment_state]]);
        }

        // Verified payments that became nothing.
        $orphans = DB::table('payment_attempts as p')->leftJoin('payment_refund_balances as b', 'b.payment_attempt_id', '=', 'p.id')
            ->where('p.status', PaymentStatus::Verified->value)->where('p.verified_at', '<', now()->subMinutes(max(0, (int) config('finance.internal_reconciliation.orphan_after_minutes'))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders as o')->whereColumn('o.payment_attempt_id', 'p.id'))
            ->whereRaw('coalesce(b.reserved_minor, 0) + coalesce(b.refunded_minor, 0) + coalesce(b.charged_back_minor, 0) < p.amount_minor')
            ->limit(500)->get(['p.id', 'p.public_id', 'p.market_id', 'p.location_id', 'p.amount_minor', 'p.currency', 'p.provider_payment_reference']);
        foreach ($orphans as $p) {
            $report(DifferenceType::OrphanPayment, 'payment:'.$p->public_id.':orphan', ['subject_type' => 'payment', 'subject_id' => (int) $p->id, 'subject_public_id' => (string) $p->public_id, 'provider_reference' => $p->provider_payment_reference,
                'market_id' => $p->market_id, 'location_id' => $p->location_id, 'currency' => (string) $p->currency, 'expected_minor' => 0, 'actual_minor' => (int) $p->amount_minor, 'detail' => ['note' => 'A verified payment without an order and without a refund that covers it.']]);
        }

        // Refunds never exceed the payment.
        $over = DB::table('refunds as r')->join('payment_attempts as p', 'p.id', '=', 'r.payment_attempt_id')->where('r.status', RefundStatus::Refunded->value)
            ->groupBy('p.id', 'p.public_id', 'p.market_id', 'p.amount_minor', 'p.currency')->havingRaw('sum(coalesce(r.approved_amount_minor, r.requested_amount_minor)) > p.amount_minor')
            ->limit(500)->get(['p.id', 'p.public_id', 'p.market_id', 'p.amount_minor', 'p.currency', DB::raw('sum(coalesce(r.approved_amount_minor, r.requested_amount_minor)) as refunded')]);
        foreach ($over as $p) {
            $report(DifferenceType::RefundExceedsPayment, 'payment:'.$p->public_id.':over-refunded', ['subject_type' => 'payment', 'subject_id' => (int) $p->id, 'subject_public_id' => (string) $p->public_id, 'market_id' => $p->market_id,
                'currency' => (string) $p->currency, 'expected_minor' => (int) $p->amount_minor, 'actual_minor' => (int) $p->refunded]);
        }

        // A paid payout is its settlement's amount, and a paid settlement is paid by exactly that.
        $payouts = DB::table('restaurant_payouts as p')->join('restaurant_settlements as s', 's.id', '=', 'p.settlement_id')->where('p.status', PayoutStatus::Paid->value)
            ->where(fn ($q) => $q->whereColumn('p.amount_minor', '<>', 's.net_payable_minor')->orWhereColumn('p.currency', '<>', 's.currency')->orWhereColumn('s.paid_minor', '<>', 'p.amount_minor'))
            ->limit(500)->get(['p.id', 'p.public_id', 'p.market_id', 'p.location_id', 'p.amount_minor', 'p.currency', 's.net_payable_minor', 's.reference']);
        foreach ($payouts as $p) {
            $report(DifferenceType::PayoutSettlementMismatch, 'payout:'.$p->public_id.':settlement', ['subject_type' => 'restaurant_payout', 'subject_id' => (int) $p->id, 'subject_public_id' => (string) $p->public_id, 'subject_reference' => (string) $p->reference,
                'market_id' => $p->market_id, 'location_id' => $p->location_id, 'currency' => (string) $p->currency, 'expected_minor' => (int) $p->net_payable_minor, 'actual_minor' => (int) $p->amount_minor]);
        }

        // Financial events without their posting (after the events and the sweep had their time).
        $grace = now()->subMinutes(max(0, (int) config('finance.internal_reconciliation.posting_after_minutes')));
        $missing = [
            ['payment', DB::table('payment_attempts as x')->where('x.status', PaymentStatus::Verified->value)->where('x.amount_minor', '>', 0)->where('x.verified_at', '<', $grace)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'payment')->whereColumn('t.source_id', 'x.id')->where('t.type', 'PAYMENT_VERIFIED'))
                ->limit(500)->get(['x.id', 'x.public_id', 'x.market_id', 'x.currency', 'x.amount_minor as amount', DB::raw('null as reference')])],
            ['refund', DB::table('refunds as x')->where('x.status', RefundStatus::Refunded->value)->where('x.completed_at', '<', $grace)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'refund')->whereColumn('t.source_id', 'x.id')->where('t.type', 'REFUND_COMPLETED'))
                ->limit(500)->get(['x.id', 'x.public_id', 'x.market_id', 'x.currency', DB::raw('coalesce(x.approved_amount_minor, x.requested_amount_minor) as amount'), 'x.order_number as reference'])],
            ['order', DB::table('orders as x')->where('x.status', 'COMPLETED')->where('x.completed_at', '<', $grace)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_financial_allocations as a')->whereColumn('a.order_id', 'x.id')->whereIn('a.status', ['RECOGNIZED', 'REVIEW_REQUIRED', 'VOID']))
                ->limit(500)->get(['x.id', 'x.public_id', 'x.market_id', 'x.currency', 'x.total_minor as amount', 'x.order_number as reference'])],
        ];
        foreach ($missing as [$kind, $rows]) {
            foreach ($rows as $row) {
                $report(DifferenceType::MissingLedgerPosting, $kind.':'.$row->public_id.':no-posting', ['subject_type' => $kind, 'subject_id' => (int) $row->id, 'subject_public_id' => (string) $row->public_id, 'subject_reference' => $row->reference,
                    'market_id' => $row->market_id, 'currency' => (string) $row->currency, 'expected_minor' => (int) $row->amount, 'actual_minor' => 0]);
            }
        }

        // Orders whose terms wait for a person.
        foreach (DB::table('order_financial_allocations')->where('status', 'REVIEW_REQUIRED')->limit(500)->get(['id', 'public_id', 'order_number', 'market_id', 'location_id', 'currency', 'customer_total_minor', 'review_reason']) as $a) {
            $report(DifferenceType::AllocationReviewRequired, 'allocation:'.$a->public_id.':review', ['subject_type' => 'order_financial_allocation', 'subject_id' => (int) $a->id, 'subject_public_id' => (string) $a->public_id, 'subject_reference' => (string) $a->order_number,
                'market_id' => $a->market_id, 'location_id' => $a->location_id, 'currency' => (string) $a->currency, 'expected_minor' => null, 'actual_minor' => (int) $a->customer_total_minor, 'detail' => ['reason' => $a->review_reason]]);
        }

        $settled = 0;
        foreach (ReconciliationDifference::query()->unresolved()->where('source', ReconciliationDifference::SOURCE_INTERNAL)->whereIn('type', array_map(fn (DifferenceType $t): string => $t->value, self::TYPES))
            ->whereNotIn('dedupe_key', $found)->where('dedupe_key', 'not like', 'payout-record:%')->pluck('dedupe_key') as $key) {
            $settled += $this->differences->settled((string) $key, 'The records agree now.') ? 1 : 0;
        }

        $run->forceFill(['status' => $found === [] ? 'OK' : 'PROBLEMS', 'result' => ['found' => $counts, 'auto_resolved' => $settled], 'problems' => count($found), 'finished_at' => now()])->save();

        return $run;
    }
}
