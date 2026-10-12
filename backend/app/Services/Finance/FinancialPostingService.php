<?php

namespace App\Services\Finance;

use App\Enums\AllocationStatus;
use App\Enums\DisputeStatus;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Enums\OrderPaymentState;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\OrderFinancialAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns what happened in the payment, order and refund domains into ledger postings (Module 36). It READS those
 * domains and never changes them: a payment stays the payment module's, an order the order module's.
 *
 * WHEN MONEY IS RECOGNIZED (the timing policy, documented in docs/backend/finance.md):
 *
 *   payment VERIFIED      Dr PROVIDER_CLEARING / Cr PAYMENT_CLEARING — the provider holds the customer's money for the
 *                         platform; it is nobody's revenue and nobody's earning yet.          key payment:{id}:verified
 *   order CREATED         the order's allocation is frozen (no posting)
 *   order COMPLETED       the allocation is recognized: earning, commission, fees, tax         key order:{id}:recognized
 *   order REJECTED /
 *   CANCELLED             the allocation is void (no posting); the money goes back through a refund
 *   refund REFUNDED       Cr PROVIDER_CLEARING, and Dr PAYMENT_CLEARING when the order was never recognized —
 *                         else Dr REFUND_CLEARING until it is decided who bears it             key refund:{id}:completed
 *   dispute LOST          Cr PROVIDER_CLEARING, Dr PAYMENT_CLEARING or DISPUTE_CLEARING the same way   key dispute:{id}:lost
 *
 * Every method is safe to call any number of times, from the event, from a retried job and from the sweep at once:
 * the posting key decides. Whether a client verification, a webhook and a reconciliation all confirmed the same
 * payment does not matter — it is one payment and one posting.
 */
final class FinancialPostingService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly OrderAllocationService $allocations,
        private readonly AdjustmentService $adjustments,
        private readonly HoldService $holds,
        private readonly FinanceMetrics $metrics,
    ) {}

    public function paymentVerified(PaymentAttempt $payment): ?LedgerTransaction
    {
        if ($payment->status !== PaymentStatus::Verified || (int) $payment->amount_minor <= 0) {
            return null;
        }
        $currency = (string) $payment->currency;
        $organizationId = $payment->location_id === null ? null : DB::table('restaurant_locations')->where('id', $payment->location_id)->value('organization_id');

        return $this->ledger->post(
            LedgerDraft::for('payment:'.$payment->public_id.':verified', LedgerTransactionType::PaymentVerified, $currency, CarbonImmutable::instance($payment->verified_at ?? now()))
                ->source('payment', (int) $payment->getKey(), (string) $payment->public_id, $payment->provider_payment_reference)
                ->dimensions($payment->market_id, $organizationId === null ? null : (int) $organizationId, $payment->location_id)
                ->explained(null, null, ['provider' => (string) $payment->provider, 'provider_mode' => $payment->provider_mode])
                ->debit($this->ledger->provider((string) $payment->provider, $currency), (int) $payment->amount_minor, 'PAYMENT_HELD_BY_PROVIDER')
                ->credit($this->ledger->platform(LedgerAccountCode::PaymentClearing, $currency), (int) $payment->amount_minor, 'CUSTOMER_PAYMENT'),
        );
    }

    /**
     * An order was created or changed state: its allocation exists and is where the order is.
     */
    public function orderChanged(Order $order, string $basis = OrderAllocationService::BASIS_ORDER): OrderFinancialAllocation
    {
        // The payment first: an order is never recognized against money the ledger has not seen arrive.
        if ($order->payment_state === OrderPaymentState::Paid && $order->payment_attempt_id !== null && ($payment = PaymentAttempt::query()->find($order->payment_attempt_id)) !== null) {
            $this->paymentVerified($payment);
        }

        return $this->allocations->settle($order, $basis);
    }

    /**
     * A refund changed: an open one holds the order's unsettled earning (policy); a completed one is posted; one that
     * is over releases what was held for it.
     */
    public function refundChanged(Refund $refund): void
    {
        match (true) {
            $refund->status === RefundStatus::Refunded => $this->refundCompleted($refund),
            $refund->status === RefundStatus::Cancelled => $this->holds->releaseSystem('refund:'.$refund->public_id, 'The refund was cancelled.'),
            default => $this->holds->placeForRefund($refund),
        };
    }

    public function refundCompleted(Refund $refund): ?LedgerTransaction
    {
        if ($refund->status !== RefundStatus::Refunded) {
            return null;
        }
        $transaction = DB::transaction(function () use ($refund): LedgerTransaction {
            $payment = PaymentAttempt::query()->findOrFail($refund->payment_attempt_id);
            $this->paymentVerified($payment);
            // Read under a lock: the order cannot be recognized between this look and the posting.
            $allocation = $refund->order_id === null ? null : OrderFinancialAllocation::query()->where('order_id', $refund->order_id)->lockForUpdate()->first();
            $recognized = $allocation?->status === AllocationStatus::Recognized;
            $currency = (string) $refund->currency;
            $amount = $refund->amountMinor();

            $posted = $this->ledger->post(
                LedgerDraft::for('refund:'.$refund->public_id.':completed', LedgerTransactionType::RefundCompleted, $currency, CarbonImmutable::instance($refund->completed_at ?? now()))
                    ->source('refund', (int) $refund->getKey(), (string) $refund->public_id, $refund->order_number)
                    ->dimensions($refund->market_id, $allocation?->organization_id, $refund->location_id)
                    ->explained(null, null, ['reason_code' => $refund->reason_code->value, 'order_recognized' => $recognized, 'provider' => (string) $refund->provider])
                    ->debit($this->ledger->platform($recognized ? LedgerAccountCode::RefundClearing : LedgerAccountCode::PaymentClearing, $currency), $amount, $recognized ? 'REFUND_TO_BE_ASSIGNED' : 'CUSTOMER_MONEY_RETURNED')
                    ->credit($this->ledger->provider((string) $refund->provider, $currency), $amount, 'REFUND_PAID_BY_PROVIDER'),
            );
            if ($posted->wasRecentlyCreated) {
                $this->metrics->record('refund_posted');
            }
            if ($recognized) {
                $this->adjustments->forRefund($refund, $allocation);
            }

            return $posted;
        });
        $this->holds->releaseSystem('refund:'.$refund->public_id, 'The refund is completed.');

        return $transaction;
    }

    /**
     * A dispute as the provider reported it: an open one holds the order's unsettled earning (policy); a LOST one is
     * posted — the provider took the money. Who bears it is never decided here.
     */
    public function disputeChanged(PaymentDispute $dispute): ?LedgerTransaction
    {
        if ($dispute->status->isOpen()) {
            $this->holds->placeForDispute($dispute);

            return null;
        }
        $this->holds->releaseSystem('dispute:'.$dispute->public_id, 'The dispute is over ('.$dispute->status->value.').');
        if ($dispute->status !== DisputeStatus::Lost) {
            return null;
        }
        $taken = (int) $dispute->amount_deducted_minor > 0 ? (int) $dispute->amount_deducted_minor : (int) $dispute->amount_minor;
        if ($taken <= 0) {
            return null;
        }

        return DB::transaction(function () use ($dispute, $taken): LedgerTransaction {
            $payment = PaymentAttempt::query()->findOrFail($dispute->payment_attempt_id);
            $this->paymentVerified($payment);
            $allocation = $dispute->order_id === null ? null : OrderFinancialAllocation::query()->where('order_id', $dispute->order_id)->lockForUpdate()->first();
            $recognized = $allocation?->status === AllocationStatus::Recognized;
            $currency = (string) $dispute->currency;

            $posted = $this->ledger->post(
                LedgerDraft::for('dispute:'.$dispute->public_id.':lost', LedgerTransactionType::DisputeLost, $currency, CarbonImmutable::instance($dispute->closed_at ?? $dispute->last_event_at ?? now()))
                    ->source('dispute', (int) $dispute->getKey(), (string) $dispute->public_id, $dispute->order_number)
                    ->dimensions($dispute->market_id, $allocation?->organization_id, $allocation?->location_id)
                    ->explained(null, null, ['order_recognized' => $recognized, 'provider' => (string) $dispute->provider])
                    ->debit($this->ledger->platform($recognized ? LedgerAccountCode::DisputeClearing : LedgerAccountCode::PaymentClearing, $currency), $taken, $recognized ? 'DISPUTE_TO_BE_ASSIGNED' : 'CUSTOMER_MONEY_TAKEN_BACK')
                    ->credit($this->ledger->provider((string) $dispute->provider, $currency), $taken, 'DISPUTE_DEDUCTED_BY_PROVIDER'),
            );
            if ($posted->wasRecentlyCreated) {
                $this->metrics->record('dispute_posted');
            }
            if ($recognized) {
                $this->adjustments->forDispute($dispute, $allocation, $taken);
            }

            return $posted;
        });
    }

    /**
     * Everything that happened and is not in the ledger yet — the last net behind the events, and the backfill of
     * what existed before this module. Bounded, repeatable, and blind to nothing: each kind is found by the absence
     * of its posting, not by a flag somebody could forget to set.
     *
     * @return array{payments: int, allocations: int, recognized: int, voided: int, refunds: int, disputes: int, holds_released: int, failed: int}
     */
    public function sweep(?int $limit = null, string $basis = OrderAllocationService::BASIS_BACKFILL): array
    {
        $limit ??= max(1, (int) config('finance.sweep.batch'));
        $done = ['payments' => 0, 'allocations' => 0, 'recognized' => 0, 'voided' => 0, 'refunds' => 0, 'disputes' => 0, 'holds_released' => 0, 'failed' => 0];
        $guard = function (string $what, callable $work) use (&$done): void {
            try {
                $work();
            } catch (Throwable $e) {
                $done['failed']++;
                Log::error('finance.sweep_failed', ['what' => $what, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
                if (app()->environment('testing')) {
                    throw $e;
                }
            }
        };

        $payments = PaymentAttempt::query()->where('status', PaymentStatus::Verified->value)->where('amount_minor', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'payment')->whereColumn('t.source_id', 'payment_attempts.id')->where('t.type', LedgerTransactionType::PaymentVerified->value))
            ->orderBy('id')->limit($limit)->get();
        foreach ($payments as $payment) {
            $guard('payment', function () use ($payment, &$done): void {
                $done['payments'] += $this->paymentVerified($payment)?->wasRecentlyCreated ? 1 : 0;
            });
        }

        $orders = Order::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_financial_allocations as a')->whereColumn('a.order_id', 'orders.id'))->orderBy('id')->limit($limit)->get();
        foreach ($orders as $order) {
            $guard('allocation', function () use ($order, $basis, &$done): void {
                $this->allocations->snapshot($order, $basis);
                $done['allocations']++;
            });
        }

        $open = Order::query()->join('order_financial_allocations as a', 'a.order_id', '=', 'orders.id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('a.status', AllocationStatus::Pending->value)->where('orders.status', 'COMPLETED'))
                ->orWhere(fn ($w) => $w->whereIn('a.status', [AllocationStatus::Pending->value, AllocationStatus::ReviewRequired->value])->whereIn('orders.status', ['REJECTED', 'CANCELLED'])))
            ->orderBy('orders.id')->limit($limit)->select('orders.*')->get();
        foreach ($open as $order) {
            $guard('order', function () use ($order, $basis, &$done): void {
                $status = $this->orderChanged($order, $basis)->status;
                $done['recognized'] += $status === AllocationStatus::Recognized ? 1 : 0;
                $done['voided'] += $status === AllocationStatus::Void ? 1 : 0;
            });
        }

        $refunds = Refund::query()->where('status', RefundStatus::Refunded->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'refund')->whereColumn('t.source_id', 'refunds.id')->where('t.type', LedgerTransactionType::RefundCompleted->value))
            ->orderBy('id')->limit($limit)->get();
        foreach ($refunds as $refund) {
            $guard('refund', function () use ($refund, &$done): void {
                $done['refunds'] += $this->refundCompleted($refund)?->wasRecentlyCreated ? 1 : 0;
            });
        }

        $disputes = PaymentDispute::query()->where('status', DisputeStatus::Lost->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_transactions as t')->where('t.source_type', 'dispute')->whereColumn('t.source_id', 'payment_disputes.id')->where('t.type', LedgerTransactionType::DisputeLost->value))
            ->orderBy('id')->limit($limit)->get();
        foreach ([...$disputes, ...PaymentDispute::query()->whereIn('status', DisputeStatus::openValues())->orderBy('id')->limit($limit)->get()] as $dispute) {
            $guard('dispute', function () use ($dispute, &$done): void {
                $done['disputes'] += $this->disputeChanged($dispute)?->wasRecentlyCreated ? 1 : 0;
            });
        }

        $guard('holds', function () use (&$done): void {
            $done['holds_released'] = $this->holds->sync();
        });

        return $done;
    }
}
