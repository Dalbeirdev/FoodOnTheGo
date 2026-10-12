<?php

namespace App\Http\Resources\Refund;

use App\Enums\RefundStatus;
use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentRefundBalance;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Models\RefundEvent;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A refund as each side may see it (Module 35).
 *
 *  - The customer: their own refunds of an order — a reference, the amount, a status in four plain words (required,
 *    processing, refunded, under review) and a neutral reason. Never a provider reference, an internal note, who
 *    approved it or why it failed. A request nobody approved and a cancelled one are not shown.
 *  - The restaurant: whether money is going back for an order and how much. No refund ids, no provider, no controls.
 *  - The administrator: the record — amounts, the payment's balance, the provider's references and state, every
 *    provider attempt, the timeline, the provider events, the audit trail — and which actions the refund's state
 *    allows. No secret exists here to show; there is no "mark as refunded".
 *
 * Every time is an instant in UTC. Every amount is minor units with its currency.
 */
final class RefundPresenter
{
    /* ------------------------------------------------------------------ customer */

    /**
     * The refund block of a customer's order. Null for an order that was never paid for.
     *
     * @return array<string, mixed>|null
     */
    public function forCustomerOrder(Order $order): ?array
    {
        if ($order->payment_public_id === null) {
            return null;
        }
        $currency = (string) $order->currency;
        $visible = $order->refunds->filter(fn (Refund $r): bool => $r->status->forCustomer() !== null)->values();
        $sum = fn (callable $where): array => (new Money((int) $visible->filter($where)->sum(fn (Refund $r): int => $r->amountMinor()), $currency))->toArray();

        return [
            // The customer's word: a refund that failed or needs a person is UNDER_REVIEW here.
            'status' => $order->refund_status->forCustomer(),
            'paid' => $order->total()->toArray(),
            // Confirmed by the payment provider — nothing else counts as refunded.
            'refunded' => $sum(fn (Refund $r): bool => $r->status === RefundStatus::Refunded),
            // Owed or on its way, not confirmed yet.
            'pending' => $sum(fn (Refund $r): bool => $r->status !== RefundStatus::Refunded),
            'items' => $visible->map(fn (Refund $r): array => [
                'id' => (string) $r->public_id,
                'reference' => $r->supportReference(),
                'type' => (string) $r->type,
                'amount' => $r->amount()->toArray(),
                'status' => $r->status->forCustomer(),
                'reason' => ['code' => $r->reason_code->value, 'label' => $r->reason_code->customerLabel()],
                'requested_at' => self::utc($r->requested_at),
                'completed_at' => $r->status === RefundStatus::Refunded ? self::utc($r->completed_at) : null,
            ])->all(),
            // No number of days is promised: when a refund shows on a statement depends on the customer's bank.
            'timing_note' => null,
        ];
    }

    /* ------------------------------------------------------------------ restaurant */

    /**
     * What a restaurant sees of an order's refunds: the state and the amounts. No ids, no provider, no actions.
     *
     * @return array<string, mixed>|null
     */
    public function forRestaurantOrder(Order $order): ?array
    {
        if ($order->payment_public_id === null) {
            return null;
        }
        $currency = (string) $order->currency;
        $visible = $order->refunds->filter(fn (Refund $r): bool => $r->status->forCustomer() !== null);

        return [
            'status' => $order->refund_status->value,
            'amount' => (new Money((int) $visible->sum(fn (Refund $r): int => $r->amountMinor()), $currency))->toArray(),
            'refunded' => (new Money((int) $visible->filter(fn (Refund $r): bool => $r->status === RefundStatus::Refunded)->sum(fn (Refund $r): int => $r->amountMinor()), $currency))->toArray(),
            // The restaurant does not control refunds: the platform does.
            'controls' => 'NOT_PROVIDED',
        ];
    }

    /* ------------------------------------------------------------------ admin */

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(Refund $refund): array
    {
        return [
            'id' => (string) $refund->public_id,
            'reference' => $refund->supportReference(),
            'type' => (string) $refund->type,
            'status' => $refund->status->value,
            'needs_review' => in_array($refund->status, [RefundStatus::ReviewRequired, RefundStatus::Failed, RefundStatus::SubmissionUnknown], true),
            'awaits_approval' => $refund->status->awaitsApproval(),
            'amount' => (new Money((int) $refund->requested_amount_minor, (string) $refund->currency))->toArray(),
            'approved_amount' => $refund->approved_amount_minor === null ? null : (new Money((int) $refund->approved_amount_minor, (string) $refund->currency))->toArray(),
            'currency' => (string) $refund->currency,
            'reason' => ['code' => $refund->reason_code->value, 'detail' => $refund->reason_detail],
            'provider' => (string) $refund->provider,
            'provider_mode' => $refund->provider_mode,
            'order' => $refund->order_number === null ? null : ['number' => (string) $refund->order_number],
            'payment' => ['id' => (string) $refund->payment_public_id, 'reference' => 'PAY-'.strtoupper(substr(str_replace('-', '', (string) $refund->payment_public_id), 0, 10))],
            // A customer is a reference here: the refund screens are not a customer directory.
            'customer' => ['id' => $refund->customer_public_id],
            'restaurant' => ['name' => (string) ($refund->restaurant_name ?? '')],
            'market' => $refund->market?->slug,
            'requested_by' => ['type' => (string) $refund->requested_by_type, 'id' => $refund->requested_by_public_id],
            'policy' => $refund->policy_decision === null ? null : ['decision' => (string) $refund->policy_decision, 'reason' => $refund->policy_reason],
            'requested_at' => self::utc($refund->requested_at),
            'completed_at' => self::utc($refund->completed_at),
            'updated_at' => self::utc($refund->updated_at),
            'version' => (int) $refund->version,
        ];
    }

    /**
     * @param  array{approve: bool, reject: bool, execute: bool, retry: bool, reconcile: bool}  $may  what the caller's permissions allow
     * @return array<string, mixed>
     */
    public function detailForAdmin(Refund $refund, ?PaymentRefundBalance $balance, array $may): array
    {
        $status = $refund->status;
        $neverSent = (int) $refund->generation === 0;
        $allowed = [
            'approve' => $status->awaitsApproval() || ($status === RefundStatus::ReviewRequired && $neverSent),
            'reject' => $status->awaitsApproval() || in_array($status, [RefundStatus::Approved, RefundStatus::Failed], true) || ($status === RefundStatus::ReviewRequired && $neverSent),
            'execute' => $status === RefundStatus::Approved,
            'retry' => ($status === RefundStatus::Failed && (bool) $refund->retryable) || $status === RefundStatus::SubmissionUnknown || ($status === RefundStatus::ReviewRequired && $refund->provider_receipt !== null),
            'reconcile' => $refund->provider_receipt !== null && ! $status->isTerminal(),
        ];
        $audit = AuditEvent::query()->where('target_type', $refund->getTable())->where('target_public_id', $refund->public_id)->orderBy('occurred_at')->orderBy('id')->limit(200)->get();

        return array_replace($this->forAdmin($refund), [
            'note' => $refund->reason_note,
            'approval' => $refund->approved_at === null ? null : [
                'mode' => (string) $refund->approval_mode,
                'by' => $refund->approvedBy === null ? null : ['id' => (string) $refund->approvedBy->public_id, 'name' => (string) $refund->approvedBy->name],
                'at' => self::utc($refund->approved_at),
                'note' => $refund->approval_note,
            ],
            'closed' => $refund->cancelled_at === null ? null : [
                'by' => $refund->closedBy === null ? null : ['id' => (string) $refund->closedBy->public_id, 'name' => (string) $refund->closedBy->name],
                'at' => self::utc($refund->cancelled_at),
                'note' => $refund->closed_note,
            ],
            'failure' => $refund->failure_code === null ? null : ['category' => $refund->failure_category, 'code' => (string) $refund->failure_code, 'message' => $refund->failure_message, 'retryable' => (bool) $refund->retryable],
            'review_reason' => $refund->review_reason,
            // The provider's references and its own status word. No key, no secret, no payload exists here.
            'provider_detail' => [
                'name' => (string) $refund->provider,
                'mode' => $refund->provider_mode,
                'payment_reference' => $refund->provider_payment_reference,
                'refund_reference' => $refund->provider_refund_reference,
                'status' => $refund->provider_status,
                'receipt' => $refund->provider_receipt,
                'trace_reference' => $refund->provider_trace_reference,
            ],
            'submission' => ['generation' => (int) $refund->generation, 'attempts' => (int) $refund->submit_attempts, 'unknown_resubmits' => (int) $refund->unknown_resubmits, 'submitted_at' => self::utc($refund->submitted_at)],
            'reconciliation' => ['runs' => (int) $refund->reconcile_runs, 'last_at' => self::utc($refund->last_reconciled_at), 'next_at' => self::utc($refund->next_action_at)],
            'balance' => self::balance($balance, (string) $refund->currency),
            'order' => $refund->order === null ? null : ['id' => (string) $refund->order->public_id, 'number' => (string) $refund->order->order_number, 'status' => $refund->order->status->value, 'refund_status' => $refund->order->refund_status->value, 'total' => $refund->order->total()->toArray()],
            'payment' => $this->forAdmin($refund)['payment'] + ['status' => $refund->paymentAttempt?->status->value, 'amount' => $refund->paymentAttempt?->amount()->toArray(), 'method_type' => $refund->paymentAttempt?->method_type],
            'times' => [
                'requested_at' => self::utc($refund->requested_at), 'approved_at' => self::utc($refund->approved_at), 'submitted_at' => self::utc($refund->submitted_at), 'processing_at' => self::utc($refund->processing_at),
                'completed_at' => self::utc($refund->completed_at), 'failed_at' => self::utc($refund->failed_at), 'cancelled_at' => self::utc($refund->cancelled_at),
            ],
            'attempts' => $refund->attempts->map(fn (RefundAttempt $a): array => [
                'at' => self::utc($a->started_at), 'finished_at' => self::utc($a->finished_at), 'operation' => (string) $a->operation, 'generation' => (int) $a->generation, 'source' => (string) $a->source,
                'outcome' => (string) $a->outcome, 'provider_status' => $a->provider_status, 'provider_refund_reference' => $a->provider_refund_reference, 'failure_code' => $a->failure_code,
                'idempotency_key' => $a->idempotency_key, 'latency_ms' => $a->latency_ms,
            ])->all(),
            'timeline' => $refund->events->map(fn (RefundEvent $e): array => [
                'at' => self::utc($e->occurred_at), 'type' => (string) $e->type, 'source' => (string) $e->source, 'from' => $e->from_status, 'to' => $e->to_status, 'detail' => (object) ($e->detail ?? []),
            ])->all(),
            'webhook_events' => $refund->webhookEvents->map(fn (PaymentWebhookEvent $e): array => [
                'id' => (string) $e->public_id, 'type' => (string) $e->event_type, 'normalized_type' => $e->normalized_type, 'received_at' => self::utc($e->received_at), 'processed_at' => self::utc($e->processed_at),
                'processing_status' => (string) $e->processing_status, 'outcome' => $e->outcome, 'deliveries' => (int) $e->deliveries,
            ])->all(),
            'audit' => $audit->map(fn (AuditEvent $e): array => [
                'at' => self::utc($e->occurred_at), 'action' => (string) $e->action, 'actor_type' => $e->actor_type, 'actor_id' => $e->actor_public_id, 'reason' => $e->reason, 'changes' => (object) ($e->changes ?? []),
            ])->all(),
            'disputes' => $this->disputesOf($refund->paymentAttempt),
            // What the refund's state allows AND the caller may do. Nothing here sets a status: there is no "mark refunded".
            'actions' => [
                'approve' => $allowed['approve'] && $may['approve'],
                'reject' => $allowed['reject'] && $may['approve'],
                'execute' => $allowed['execute'] && $may['execute'],
                'retry' => $allowed['retry'] && $may['execute'],
                'reconcile' => $allowed['reconcile'] && $may['reconcile'],
                'mark_refunded' => 'NOT_PROVIDED',
            ],
        ]);
    }

    /**
     * The refunds of an order or of a payment as a short list for the order / payment screens.
     *
     * @param  Collection<int, Refund>  $refunds
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(Collection $refunds): array
    {
        return $refunds->map(fn (Refund $r): array => [
            'id' => (string) $r->public_id,
            'reference' => $r->supportReference(),
            'type' => (string) $r->type,
            'amount' => $r->amount()->toArray(),
            'reason' => $r->reason_code->value.($r->reason_detail === null ? '' : ':'.$r->reason_detail),
            'status' => $r->status->value,
            'provider_refund_reference' => $r->provider_refund_reference,
            'requested_by' => ['type' => (string) $r->requested_by_type, 'id' => $r->requested_by_public_id],
            'requested_at' => self::utc($r->requested_at),
            'completed_at' => self::utc($r->completed_at),
        ])->values()->all();
    }

    /**
     * What a payment can still give back, as figures.
     *
     * @return array<string, mixed>
     */
    public static function balance(?PaymentRefundBalance $balance, string $currency, ?int $paidMinor = null): array
    {
        $m = fn (int $v): array => (new Money($v, $currency))->toArray();
        $paid = (int) ($balance?->paid_minor ?? $paidMinor ?? 0);
        $refunded = (int) ($balance?->refunded_minor ?? 0);
        $reserved = (int) ($balance?->reserved_minor ?? 0);
        $chargedBack = (int) ($balance?->charged_back_minor ?? 0);

        return [
            'paid' => $m($paid),
            'refunded' => $m($refunded),
            'in_flight' => $m($reserved),
            'charged_back' => $m($chargedBack),
            'available' => $m(max(0, $paid - $refunded - $reserved - $chargedBack)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dispute(PaymentDispute $dispute): array
    {
        return [
            'id' => (string) $dispute->public_id,
            'status' => $dispute->status->value,
            'open' => $dispute->status->isOpen(),
            'provider' => (string) $dispute->provider,
            'provider_dispute_reference' => (string) $dispute->provider_dispute_reference,
            'provider_status' => $dispute->provider_status,
            'phase' => $dispute->phase,
            'reason_code' => $dispute->reason_code,
            'amount' => $dispute->amount()->toArray(),
            'amount_deducted' => (new Money((int) $dispute->amount_deducted_minor, (string) $dispute->currency))->toArray(),
            'payment' => ['id' => (string) $dispute->payment_public_id, 'reference' => 'PAY-'.strtoupper(substr(str_replace('-', '', (string) $dispute->payment_public_id), 0, 10)), 'provider_payment_reference' => $dispute->provider_payment_reference],
            'order' => $dispute->order_number === null ? null : ['number' => (string) $dispute->order_number],
            'restaurant' => ['name' => (string) ($dispute->restaurant_name ?? '')],
            'market' => $dispute->market?->slug,
            'opened_at' => self::utc($dispute->opened_at),
            'respond_by_at' => self::utc($dispute->respond_by_at),
            'closed_at' => self::utc($dispute->closed_at),
            'updated_at' => self::utc($dispute->updated_at),
            // Read-only and provider-synced: answering a dispute happens with the provider, not here.
            'actions' => ['respond' => 'NOT_PROVIDED', 'accept' => 'NOT_PROVIDED'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function disputesOf(?PaymentAttempt $attempt): array
    {
        return $attempt === null ? [] : $attempt->disputes->map(fn (PaymentDispute $d): array => [
            'id' => (string) $d->public_id, 'status' => $d->status->value, 'open' => $d->status->isOpen(), 'amount' => $d->amount()->toArray(), 'reason_code' => $d->reason_code, 'respond_by_at' => self::utc($d->respond_by_at),
        ])->values()->all();
    }

    private static function utc(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->toIso8601String();
    }
}
