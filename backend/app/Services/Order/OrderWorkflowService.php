<?php

namespace App\Services\Order;

use App\Auth\Principal;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderRejectionReason;
use App\Enums\OrderStatus;
use App\Events\Order\OrderEvent;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderEvent as Line;
use App\Models\OrderRefund;
use App\Models\RestaurantUser;
use App\Services\Audit\AuditRecorder;
use App\Services\Pickup\PickupCapacityRepository;
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
 * Rejecting or cancelling a PAID order never touches money here: the order is marked REFUND REQUIRED, with a refund
 * record for the refund module, and the customer is told. Nothing is refunded until a provider confirms it.
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
            $this->closeOut($order, Line::ACTOR_RESTAURANT, (string) $user->public_id, 'RESTAURANT_REJECTED:'.$reason->value, $at);
            $this->audit->record('order.rejected', $order, $user, ['reason' => $reason->value], $note, $order->market_id);
            DB::afterCommit(function () use ($order, $reason): void {
                $this->metrics->record('rejected');
                event(new OrderEvent(OrderEvent::RESTAURANT_REJECTED, $order, ['reason' => $reason->value]));
            });
        }, $prepare);
    }

    /**
     * An administrator ends an order (support, a restaurant that cannot be reached, a customer's request judged by
     * a person). With a reason, audited; a paid order becomes REFUND REQUIRED. Customers cannot do this themselves.
     */
    public function cancel(Order $order, AdminUser $admin, string $reason, ?CarbonInterface $at = null): Order
    {
        $at = CarbonImmutable::instance($at ?? now());
        $prepare = fn (Order $order) => $order->forceFill(['cancellation_reason' => mb_substr($reason, 0, 500)]);

        return $this->step($order, OrderStatus::Cancelled, Line::ACTOR_ADMIN, $admin, null, ['by' => 'ADMIN'], $at, function (Order $order) use ($admin, $reason, $at): void {
            $this->closeOut($order, Line::ACTOR_ADMIN, (string) $admin->public_id, 'ADMIN_CANCELLED', $at);
            $this->audit->record('order.cancelled', $order, $admin, ['status' => ['to' => OrderStatus::Cancelled->value]], $reason, $order->market_id);
            DB::afterCommit(function () use ($order): void {
                $this->metrics->record('cancelled');
                event(new OrderEvent(OrderEvent::CANCELLED, $order, ['by' => 'ADMIN']));
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
     * and — when it was paid — a refund is required. The amount is the order's total: nothing was changed after
     * payment, so nothing less than everything is owed.
     */
    private function closeOut(Order $order, string $actorType, string $actorPublicId, string $reason, CarbonImmutable $at): void
    {
        $reservation = $order->pickupReservation;
        if ($reservation !== null) {
            $this->capacity->release($reservation);
        }
        $this->credentials->revoke($order);
        if ($order->payment_state !== OrderPaymentState::Paid || $order->refund_status !== OrderRefundStatus::None) {
            return;
        }
        $order->forceFill(['refund_status' => OrderRefundStatus::Required->value])->save();
        (new OrderRefund)->forceFill([
            'order_id' => $order->getKey(),
            'payment_attempt_id' => $order->payment_attempt_id,
            'amount_minor' => (int) $order->total_minor,
            'currency' => (string) $order->currency,
            'reason' => $reason,
            'status' => OrderRefund::STATUS_REQUIRED,
            'requested_by_type' => $actorType,
            'requested_by_public_id' => $actorPublicId,
            'requested_at' => $at,
        ])->save();
        $this->machine->note($order, Line::REFUND_REQUIRED, Line::ACTOR_SYSTEM, null, null, null, ['amount_minor' => (int) $order->total_minor, 'currency' => (string) $order->currency, 'reason' => $reason], $at, self::requestId());
        DB::afterCommit(function () use ($order, $reason): void {
            $this->metrics->record('refund_required');
            event(new OrderEvent(OrderEvent::REFUND_REQUIRED, $order, ['reason' => $reason, 'amount_minor' => (int) $order->total_minor]));
        });
    }

    private static function requestId(): ?string
    {
        $id = Context::get('request_id');

        return $id === null ? null : (string) $id;
    }
}
