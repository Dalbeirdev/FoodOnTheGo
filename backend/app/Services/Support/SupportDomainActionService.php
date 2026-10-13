<?php

namespace App\Services\Support;

use App\Enums\Permission;
use App\Enums\SupportLinkType;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\OrderFulfillment;
use App\Models\PaymentAttemptEvent;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseLink;
use App\Services\Audit\AuditRecorder;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Refund\RefundService;
use Illuminate\Support\Facades\DB;

/**
 * Shortcuts from a case to an action of ANOTHER domain (Module 37): ask the payment provider about a payment again,
 * ask it about a refund again, retry the creation of an order.
 *
 * The case does not perform the action — the domain's own service does, exactly as its own screen would, under its
 * own rules and with its own audit record. And the case permission does not authorize it: the caller must hold the
 * DOMAIN's permission for the record's market, or the request ends with 403. No shortcut sets a status: a payment
 * is never marked verified here, a refund never marked completed, no money moves.
 */
final class SupportDomainActionService
{
    public const PAYMENT_RECONCILE = 'PAYMENT_RECONCILE';

    public const REFUND_RECONCILE = 'REFUND_RECONCILE';

    public const ORDER_RECOVER = 'ORDER_RECOVER';

    public const ACTIONS = [self::PAYMENT_RECONCILE, self::REFUND_RECONCILE, self::ORDER_RECOVER];

    /** The domain permission each shortcut needs — never a support permission. */
    public const PERMISSIONS = [
        self::PAYMENT_RECONCILE => Permission::AdminPaymentsReconcile,
        self::REFUND_RECONCILE => Permission::AdminRefundsReconcile,
        self::ORDER_RECOVER => Permission::AdminOrdersRecover,
    ];

    public function __construct(
        private readonly SupportLinkService $links,
        private readonly SupportCaseService $cases,
        private readonly SupportCaseStateMachine $machine,
        private readonly PaymentReconciliationService $payments,
        private readonly RefundService $refunds,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly AuditRecorder $audit,
        private readonly SupportMetrics $metrics,
    ) {}

    /**
     * @return array{action: string, link: string, reference: string, before: string, after: string, changed: bool}
     */
    public function run(SupportCase $case, AdminUser $admin, string $action, SupportCaseLink $link, string $reason): array
    {
        if ((int) $link->case_id !== (int) $case->getKey()) {
            throw ApiException::notFound('case_link_not_found', 'This record is not linked to this case.');
        }
        $wanted = $action === self::REFUND_RECONCILE ? SupportLinkType::Refund : SupportLinkType::Payment;
        if (! in_array($action, self::ACTIONS, true) || $link->link_type !== $wanted) {
            throw new ApiException(422, 'validation_failed', 'This action does not apply to this kind of record.', ['fields' => ['action' => ['Not available for a '.strtolower($link->link_type->value).'.']]]);
        }
        $record = $this->links->recordOf($link) ?? throw ApiException::notFound('linked_record_not_found', 'The linked record no longer exists.');
        $permission = self::PERMISSIONS[$action];
        if (! $this->links->adminAllows($admin, $permission, SupportLinkService::marketIdOf($link->link_type, $record))) {
            $this->metrics->record('domain_action_refused');
            throw new ApiException(403, 'domain_permission_required', 'Working on a case does not allow this: it needs the permission of the record\'s own domain.', ['required_permission' => $permission->value]);
        }
        [$before, $after] = match ($action) {
            self::PAYMENT_RECONCILE => $this->reconcilePayment($record, $admin, $reason),
            self::REFUND_RECONCILE => $this->reconcileRefund($record, $admin, $reason),
            self::ORDER_RECOVER => $this->recoverOrder($record, $admin, $reason),
        };
        // The case only remembers that somebody asked — and what the DOMAIN answered.
        DB::transaction(function () use ($case, $admin, $action, $link, $before, $after): void {
            $locked = $this->cases->lock($case);
            $this->machine->line($locked, SupportCaseEvent::DOMAIN_ACTION, SupportActor::admin($admin), SupportCaseEvent::INTERNAL, ['action' => $action, 'link_type' => $link->link_type->value, 'reference' => (string) $link->reference, 'before' => $before, 'after' => $after]);
        });
        $this->metrics->record('domain_action');

        return ['action' => $action, 'link' => (string) $link->public_id, 'reference' => (string) $link->reference, 'before' => $before, 'after' => $after, 'changed' => $before !== $after];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function reconcilePayment(mixed $attempt, AdminUser $admin, string $reason): array
    {
        $before = $attempt->status->value;
        $after = $this->payments->reconcile($attempt, PaymentAttemptEvent::SOURCE_ADMIN, [], (int) $admin->getAuthIdentifier());
        $this->audit->record('payment.reconciled', $after, $admin, ['status' => ['from' => $before, 'to' => $after->status->value]], $reason, $after->market_id);

        return [$before, $after->status->value];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function reconcileRefund(mixed $refund, AdminUser $admin, string $reason): array
    {
        $before = $refund->status->value;
        $after = $this->refunds->reconcile($refund, $admin, $reason);

        return [$before, $after->status->value];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function recoverOrder(mixed $attempt, AdminUser $admin, string $reason): array
    {
        $row = OrderFulfillment::query()->with(['checkout.market', 'order'])->where('payment_attempt_id', $attempt->getKey())->first()
            ?? throw ApiException::notFound('order_fulfillment_not_found', 'This payment has no order creation record to retry.');
        $before = $row->status->value;
        $after = $this->fulfillment->retry($row, (int) $admin->getAuthIdentifier());
        $this->audit->record('order.recovery_attempted', $after, $admin, ['status' => ['from' => $before, 'to' => $after->status->value], 'order' => $after->order?->public_id], $reason, $row->checkout?->market_id);

        return [$before, $after->status->value];
    }
}
