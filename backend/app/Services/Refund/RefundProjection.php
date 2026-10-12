<?php

namespace App\Services\Refund;

use App\Enums\OrderRefundStatus;
use App\Enums\RefundStatus;
use App\Events\Order\OrderEvent;
use App\Models\Order;
use App\Models\OrderEvent as Line;
use App\Models\PaymentRefundBalance;
use App\Models\Refund;
use App\Services\Order\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * The order's refund status, derived from its refunds (Module 35).
 *
 * An order does not keep a refund truth of its own: every change of a refund recomputes orders.refund_status here,
 * in the same transaction and under the order's row lock. Nothing else writes that column. The order's state
 * (REJECTED, CANCELLED, COMPLETED …), its prices, its tax lines and its payment state are never touched.
 *
 *   a refund needs a person                              → REVIEW_REQUIRED
 *   a refund is approved or with the provider            → PROCESSING
 *   a refund is owed and nobody approved it yet          → REQUIRED
 *   the provider failed a refund and nothing replaces it → FAILED
 *   confirmed refunds cover everything that was paid     → REFUNDED
 *   confirmed refunds cover a part of it                 → PARTIALLY_REFUNDED
 *   otherwise                                            → NONE
 *
 * A request nobody approved (REQUESTED) and a cancelled refund do not show on the order.
 *
 * When what the customer is told about a refund changes, a line is added to the order's timeline and the order is
 * announced (after the commit), so every client that follows the order re-reads it.
 */
final class RefundProjection
{
    public function __construct(private readonly OrderStateMachine $orders) {}

    /**
     * @param  Order|null  $order  locked by the caller; null for a refund of a payment that never became an order
     */
    public function project(?Order $order, PaymentRefundBalance $balance, Refund $cause, ?RefundStatus $from, RefundStatus $to): void
    {
        if ($order === null) {
            return;
        }
        $statuses = Refund::query()->where('order_id', $order->getKey())->toBase()->pluck('status')->all();
        $derived = self::derive($statuses, (int) $balance->paid_minor, (int) $balance->refunded_minor);
        $changed = $derived !== $order->refund_status;
        if ($changed) {
            // The version moves with it, so a client that follows the order sees a newer one. A refund that starts inside the
            // step that ended the order (a rejection, a cancellation) rides on that step's own version.
            $order->forceFill(['refund_status' => $derived->value, 'version' => (int) $order->version + ($from === null ? 0 : 1)])->save();
        }

        // What the customer is told about THIS refund, before and after.
        $told = $to->forCustomer();
        $line = null;
        if ($told !== $from?->forCustomer()) {
            $type = match ($told) {
                'REQUIRED' => Line::REFUND_REQUIRED,
                'PROCESSING' => Line::REFUND_PROCESSING,
                'REFUNDED' => Line::REFUND_COMPLETED,
                'UNDER_REVIEW' => Line::REFUND_UNDER_REVIEW,
                default => Line::REFUND_CLOSED,
            };
            $visible = $told !== null || $from?->forCustomer() !== null;
            $detail = ['amount_minor' => $cause->amountMinor(), 'currency' => (string) $cause->currency, 'refund_status' => $derived->value];
            if ($told === 'REQUIRED') {
                $detail['reason'] = $cause->reason_code->value.($cause->reason_detail === null ? '' : ':'.$cause->reason_detail);
            }
            $line = $this->orders->note($order, $type, Line::ACTOR_SYSTEM, null, null, null, $detail, null, null, $visible);
        }
        if ($line !== null || $changed) {
            $sequence = $line?->sequence;
            // REFUND_REQUIRED keeps its own domain event (Module 31): the order workflow announces it.
            if ($told !== 'REQUIRED' || $from !== null) {
                DB::afterCommit(fn () => event(new OrderEvent(OrderEvent::REFUND_UPDATED, $order, array_filter(['sequence' => $sequence, 'refund' => $derived->value], fn ($v): bool => $v !== null))));
            }
        }
    }

    /**
     * @param  list<string>  $statuses  the states of every refund of the order
     */
    public static function derive(array $statuses, int $paidMinor, int $refundedMinor): OrderRefundStatus
    {
        $has = fn (RefundStatus ...$any): bool => array_intersect(array_map(fn (RefundStatus $s): string => $s->value, $any), $statuses) !== [];

        return match (true) {
            $has(RefundStatus::ReviewRequired) => OrderRefundStatus::ReviewRequired,
            // PROCESSING only once the provider has (or may have) the refund; an approved refund is still owed.
            $has(RefundStatus::SubmissionUnknown, RefundStatus::Processing) => OrderRefundStatus::Processing,
            $has(RefundStatus::Required, RefundStatus::Approved, RefundStatus::Submitting) => OrderRefundStatus::Required,
            $has(RefundStatus::Failed) => OrderRefundStatus::Failed,
            $refundedMinor > 0 && $refundedMinor >= $paidMinor => OrderRefundStatus::Refunded,
            $refundedMinor > 0 && $has(RefundStatus::Refunded) => OrderRefundStatus::PartiallyRefunded,
            default => OrderRefundStatus::None,
        };
    }
}
