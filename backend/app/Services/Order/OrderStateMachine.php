<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one place an order's state changes (Module 31). Every step is checked against OrderStatus::allows, written
 * with its timestamp and its version, and recorded on the order's append-only timeline with who did it.
 *
 * The caller holds the order's row lock (OrderStore::locked) — the machine never locks itself, so that a service can
 * decide and move inside one transaction.
 */
final class OrderStateMachine
{
    /**
     * @param  array<string, mixed>  $detail
     *
     * @throws ApiException 409 order_invalid_transition
     */
    public function move(Order $order, OrderStatus $to, string $actorType, ?string $actorPublicId = null, array $detail = [], ?CarbonInterface $at = null, ?string $requestId = null, bool $customerVisible = true): Order
    {
        $at = CarbonImmutable::instance($at ?? now());
        $from = $order->status;
        if (! $from->allows($to)) {
            throw ApiException::conflict('order_invalid_transition', 'This order cannot be moved from '.$from->value.' to '.$to->value.'.', ['from' => $from->value, 'to' => $to->value]);
        }
        $stamp = match ($to) {
            OrderStatus::Accepted => 'accepted_at',
            OrderStatus::Rejected => 'rejected_at',
            OrderStatus::Preparing => 'preparing_at',
            OrderStatus::ReadyForPickup => 'ready_at',
            OrderStatus::PickedUp => 'picked_up_at',
            OrderStatus::Completed => 'completed_at',
            OrderStatus::Cancelled => 'cancelled_at',
            default => null,
        };
        $order->forceFill(array_filter([
            'status' => $to->value,
            'version' => (int) $order->version + 1,
            $stamp => $at,
        ], fn ($v) => $v !== null, ARRAY_FILTER_USE_BOTH))->save();
        $this->note($order, self::eventFor($to), $actorType, $actorPublicId, $from, $to, $detail, $at, $requestId, $customerVisible);

        return $order;
    }

    /**
     * A line on the timeline that is not a change of state (a refund requirement, a refused step).
     *
     * @param  array<string, mixed>  $detail
     */
    public function note(Order $order, string $type, string $actorType, ?string $actorPublicId = null, ?OrderStatus $from = null, ?OrderStatus $to = null, array $detail = [], ?CarbonInterface $at = null, ?string $requestId = null, bool $customerVisible = true): OrderEvent
    {
        // Module 32: one deterministic number per line of this order. The caller holds the order's row lock (every
        // state change does), so two lines cannot take the same number; the unique index refuses it if one ever tried.
        $sequence = (int) OrderEvent::query()->where('order_id', $order->getKey())->max('sequence') + 1;
        $event = (new OrderEvent)->forceFill([
            'order_id' => $order->getKey(),
            'sequence' => $sequence,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_type' => $actorType,
            'actor_public_id' => $actorPublicId,
            'detail' => $detail === [] ? null : $detail,
            'customer_visible' => $customerVisible,
            'request_id' => $requestId === null ? null : mb_substr($requestId, 0, 64),
            'occurred_at' => CarbonImmutable::instance($at ?? now()),
        ]);
        $event->save();

        return $event;
    }

    public static function eventFor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Created => OrderEvent::ORDER_CREATED,
            OrderStatus::WaitingForRestaurant => OrderEvent::WAITING_FOR_RESTAURANT,
            OrderStatus::Accepted => OrderEvent::RESTAURANT_ACCEPTED,
            OrderStatus::Rejected => OrderEvent::RESTAURANT_REJECTED,
            OrderStatus::Preparing => OrderEvent::PREPARATION_STARTED,
            OrderStatus::ReadyForPickup => OrderEvent::READY_FOR_PICKUP,
            OrderStatus::PickedUp => OrderEvent::CUSTOMER_PICKED_UP,
            OrderStatus::Completed => OrderEvent::ORDER_COMPLETED,
            OrderStatus::Cancelled => OrderEvent::ORDER_CANCELLED,
        };
    }
}
