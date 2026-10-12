<?php

namespace App\Services\Order;

use App\Auth\Principal;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRejectionReason;
use App\Enums\OrderStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Events\Order\OrderEvent;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderEvent as Line;
use App\Models\Refund;
use App\Models\RestaurantUser;
use App\Services\Audit\AuditRecorder;
use App\Services\Pickup\PickupCapacityRepository;
use App\Services\Refund\RefundService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * What a restaurant does with an order, and what an administrator may do over its head (Module 31).
 *
 * Every step runs under the order's row lock, is checked against OrderStatus::allows, and is idempotent: the same
 * step asked twice (a retried request, two staff clicking at once) succeeds once and then answers "already there"
 * with the order as it is, never with a second event. A client that says which version it saw (expected_version)
 * is refused with 409 when the order moved meanwhile — so a staff member never accepts what somebody else just
 * rejected without seeing it.
 *
 * Rejecting or cancelling a PAID order never touches money here: it creates ONE refund in the refund domain
 * (Module 35) — REQUIRED, for what is left of the payment — in the same transaction, and the customer is told a
 * refund is due. Nothing is refunded until a provider confirms it; the order stays REJECTED / CANCELLED whatever
 * becomes of the refund. A customer can cancel only when the business has approved a cancellation policy
 * (config refunds.customer_cancellation) — it is disabled until then.
 * Pickup (PICKED_UP) is deliberately absent: it is Module 32's verified step.
 */
final class OrderWorkflowService
{
    public function __construct(
        private readonly OrderStore $orders,
        private readonly OrderStateMachine $machine,
        private readonly PickupCapacityRepository $capacity,
        private readonly PickupCredentials $credentials,
        private readonly AuditRecorder $audit,
        private readonly OrderMetrics $metrics,
        private readonly RefundService $refunds,
    ) {}

    public function accept(Order $order, RestaurantUser $user, ?int $expectedVersion = null, ?CarbonInterface $at = null): Order
    {
        return $this->step($order, OrderStatus::Accepted, Line::ACTOR_RESTAURANT, $user, $expectedVersion, [], $at, function (Order $order) use ($user): void {
            $this->audit->record('order.accepted', $order, $user, [], null, $order->market_id);
            DB::afterCommit(function () use ($order): void {
                $this->metrics->record('accepted');
                event(new OrderEvent(OrderEvent::RESTAURANT_ACCEPTED, $order));
            });
        });
    }

    public function startPreparing(Order $order, RestaurantUser $user, ?int $expectedVersion = null, ?CarbonInterface $at = null): Order
    {
        return $this->step($order, OrderStatus::Preparing, Line::ACTOR_RESTAURANT, $user, $expectedVersion, [], $at, function (Order $order) use ($user): void {
            $this->audit->record('order.preparing', $order, $user, [], null, $order->market_id);
            DB::afterCommit(function () use ($order): void {
                $this->metrics->record('preparing');
                event(new OrderEvent(OrderEvent::PREPARING, $order));
            });
        });
    }

    public function ready(Order $order, RestaurantUser $user, ?int $expectedVersion = null, ?CarbonInterface $at = null): Order
    {
        return $this->step($order, OrderStatus::ReadyForPickup, Line::ACTOR_RESTAURANT, $user, $expectedVersion, [], $at, function (Order $order) use ($user): void {
            $this->audit->record('order.ready', $order, $user, [], null, $order->market_id);
            DB::afterCommit(function () use ($order): void {
                $this->metrics->record('ready');
                event(new OrderEvent(OrderEvent::READY, $order));
            });
        });
    }

    /**
     * The restaurant turns the order down, with a structured reason the customer is told in neutral words. The
     * note is for the restaurant and the platform, not the customer. A paid order becomes REFUND REQUIRED.
     */
    public function reject(Order $order, RestaurantUser $user, OrderRejectionReason $reason, ?string $note = null, ?int $expectedVersion = null, ?CarbonInterface $at = null): Order
    {
        $at = CarbonImmutable::instance($at ?? now());
        $prepare = fn (Order $order) => $order->forceFill(['rejection_reason' => $reason->value, 'rejection_note' => $note === null ? null : mb_substr($note, 0, 500)]);

        return $this->step($order, OrderStatus::Rejected, Line::ACTOR_RESTAURANT, $user, $expectedVersion, ['reason' => $reason->value, 'message' => $reason->customerMessage()], $at, function (Order $order) use ($user, $reason, $note, $at): void {
            $this->closeOut($order, Line::ACTOR_RESTAURANT, (string) $user->public_id, RefundReason::RestaurantRejected, $reason->value, $at);
            $this->audit->record('order.rejected', $order, $user, ['reason' => $reason->value], $note, $order->market_id);
            DB::afterCommit(function () use ($order, $reason): void {
                $this->metrics->record('rejected');
                event(new OrderEvent(OrderEvent::RESTAURANT_REJECTED, $order, ['reason' => $reason->value]));
            });
        }, $prepare);
    }

    /**
     * An administrator ends an order (support, a restaurant that cannot be reached, a customer's request judged by
     * a person). With a written reason and a controlled reason code, audited; a paid order gets its refund
     * (REQUIRED). Who ended it and why are kept on the order.
     */
    public function cancel(Order $order, AdminUser $admin, string $reason, ?CarbonInterface $at = null, RefundReason $code = RefundReason::AdminCancellation): Order
    {
        $at = CarbonImmutable::instance($at ?? now());
        $prepare = fn (Order $order) => $order->forceFill(['cancellation_reason' => mb_substr($reason, 0, 300), 'cancelled_by_type' => Line::ACTOR_ADMIN, 'cancellation_reason_code' => $code->value]);

        return $this->step($order, OrderStatus::Cancelled, Line::ACTOR_ADMIN, $admin, null, ['by' => 'ADMIN'], $at, function (Order $order) use ($admin, $reason, $code, $at): void {
            $this->closeOut($order, Line::ACTOR_ADMIN, (string) $admin->public_id, $code, null, $at);
            $this->audit->record('order.cancelled', $order, $admin, ['status' => ['to' => OrderStatus::Cancelled->value], 'reason_code' => ['from' => null, 'to' => $code->value]], $reason, $order->market_id);
            DB::afterCommit(function () use ($order): void {
                $this->metrics->record('cancelled');
                event(new OrderEvent(OrderEvent::CANCELLED, $order, ['by' => 'ADMIN']));
            });
        }, $prepare);
    }

    /**
     * The customer cancels their own order — ONLY when a cancellation policy is switched on (it is not: PENDING
     * BUSINESS / LEGAL APPROVAL). The policy says in which states an order may be cancelled and what that does to the
     * money; the customer chooses neither. Checked under the order's lock: when the restaurant moved the order a
     * moment earlier, the state the policy sees is the new one.
     *
     *   FULL_REFUND    a refund for what is left of the payment is owed (REQUIRED) and follows the refund policy
     *   MANUAL_REVIEW  a refund is asked for (REQUESTED): a person decides whether money goes back
     *   NO_REFUND      the order is cancelled and no refund is created
     *
     * @throws ApiException 409 order_cancellation_not_allowed / order_version_conflict
     */
    public function cancelByCustomer(Order $order, Customer $customer, string $reasonCode, ?string $note = null, ?int $expectedVersion = null, ?CarbonInterface $at = null): Order
    {
        $policy = (array) config('refunds.customer_cancellation');
        if (! (bool) ($policy['enabled'] ?? false)) {
            throw ApiException::conflict('order_cancellation_not_allowed', 'Orders cannot be cancelled in the app yet. Please contact FoodOnTheGo support.', ['reason' => 'CUSTOMER_CANCELLATION_DISABLED']);
        }
        $at = CarbonImmutable::instance($at ?? now());
        $effect = in_array($policy['financial_effect'] ?? null, ['FULL_REFUND', 'NO_REFUND', 'MANUAL_REVIEW'], true) ? (string) $policy['financial_effect'] : 'MANUAL_REVIEW';
        $prepare = function (Order $order) use ($policy, $reasonCode, $note): void {
            if (! in_array($order->status->value, (array) ($policy['allowed_statuses'] ?? []), true)) {
                throw ApiException::conflict('order_cancellation_not_allowed', 'This order can no longer be cancelled in the app. Please contact FoodOnTheGo support.', ['reason' => 'ORDER_STATE_NOT_CANCELLABLE', 'current_status' => $order->status->value, 'current_version' => (int) $order->version]);
            }
            $order->forceFill(['cancellation_reason' => mb_substr(trim($reasonCode.($note === null || trim($note) === '' ? '' : ': '.trim($note))), 0, 300), 'cancelled_by_type' => Line::ACTOR_CUSTOMER, 'cancellation_reason_code' => RefundReason::CustomerCancellation->value]);
        };

        return $this->step($order, OrderStatus::Cancelled, Line::ACTOR_CUSTOMER, $customer, $expectedVersion, ['by' => 'CUSTOMER', 'reason' => $reasonCode], $at, function (Order $order) use ($customer, $reasonCode, $effect, $policy, $at): void {
            $this->closeOut($order, Line::ACTOR_CUSTOMER, (string) $customer->public_id, RefundReason::CustomerCancellation, $reasonCode, $at, $effect);
            $this->audit->record('order.cancelled', $order, $customer, ['status' => ['to' => OrderStatus::Cancelled->value], 'financial_effect' => ['from' => null, 'to' => $effect], 'policy_version' => ['from' => null, 'to' => (string) ($policy['policy_version'] ?? '')]], $reasonCode, $order->market_id);
            DB::afterCommit(function () use ($order): void {
                $this->metrics->record('cancelled');
                event(new OrderEvent(OrderEvent::CANCELLED, $order, ['by' => 'CUSTOMER']));
            });
        }, $prepare);
    }

    /**
     * One step, under the lock: already there → the order as it is (no second event); the version the client saw is
     * not the current one → 409; not allowed from here → 409; otherwise moved, recorded, announced after commit.
     *
     * @param  array<string, mixed>  $detail
     * @param  (callable(Order): void)  $after
     * @param  (callable(Order): mixed)|null  $prepare
     *
     * @throws ApiException 409 order_version_conflict / order_invalid_transition
     */
    private function step(Order $order, OrderStatus $to, string $actorType, Principal $actor, ?int $expectedVersion, array $detail, ?CarbonInterface $at, callable $after, ?callable $prepare = null): Order
    {
        $at = CarbonImmutable::instance($at ?? now());

        return DB::transaction(function () use ($order, $to, $actorType, $actor, $expectedVersion, $detail, $at, $after, $prepare): Order {
            $locked = $this->orders->locked((int) $order->getKey());
            if ($locked->status === $to) {
                return $locked->load(OrderStore::relations()); // idempotent: the step was already taken
            }
            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw ApiException::conflict('order_version_conflict', 'This order changed since you last saw it. Please refresh and try again.', ['current_version' => (int) $locked->version, 'current_status' => $locked->status->value]);
            }
            if (! $locked->status->allows($to)) {
                throw ApiException::conflict('order_invalid_transition', 'This order cannot be moved from '.$locked->status->value.' to '.$to->value.'.', ['from' => $locked->status->value, 'to' => $to->value, 'current_version' => (int) $locked->version]);
            }
            if ($prepare !== null) {
                $prepare($locked);
            }
            $this->machine->move($locked, $to, $actorType, (string) $actor->public_id, $detail, $at, self::requestId());
            $after($locked);

            return $locked->load(OrderStore::relations());
        });
    }

    /**
     * An order that will not be made: its place in the pickup slot is given back, its pickup credential revoked,
     * and — when it was paid — the refund domain is told that money is owed. The amount is not decided here: it is
     * what is left of the payment (RefundService). $effect is the financial effect of a customer's cancellation.
     *
     * @param  'FULL_REFUND'|'MANUAL_REVIEW'|'NO_REFUND'  $effect
     */
    private function closeOut(Order $order, string $actorType, string $actorPublicId, RefundReason $reason, ?string $detail, CarbonImmutable $at, string $effect = 'FULL_REFUND'): void
    {
        $reservation = $order->pickupReservation;
        if ($reservation !== null) {
            $this->capacity->release($reservation);
        }
        $this->credentials->revoke($order);
        if ($order->payment_state !== OrderPaymentState::Paid || $effect === 'NO_REFUND') {
            return; // nothing was charged (no refund can exist), or the approved policy says none is due
        }
        $known = Refund::query()->where('order_id', $order->getKey())->exists();
        $refund = $this->refunds->requireForOrder($order, $reason, $detail, $actorType, $actorPublicId, $at, $effect === 'MANUAL_REVIEW' ? RefundStatus::Requested : RefundStatus::Required);
        if ($refund === null || $known || $refund->status !== RefundStatus::Required) {
            return; // nothing is owed, it was recorded before, or a person decides first
        }
        $amount = $refund->amountMinor();
        $code = $reason->value.($detail === null ? '' : ':'.$detail);
        DB::afterCommit(function () use ($order, $code, $amount): void {
            $this->metrics->record('refund_required');
            event(new OrderEvent(OrderEvent::REFUND_REQUIRED, $order, ['reason' => $code, 'amount_minor' => $amount]));
        });
    }

    private static function requestId(): ?string
    {
        $id = Context::get('request_id');

        return $id === null ? null : (string) $id;
    }
}
