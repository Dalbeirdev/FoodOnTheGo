<?php

namespace App\Enums;

/**
 * Where an order stands — the operational state, kept apart from the money (PaymentStatus / OrderRefundStatus).
 *
 *   CREATED ──► WAITING_FOR_RESTAURANT ──► ACCEPTED ──► PREPARING ──► READY_FOR_PICKUP ──► PICKED_UP ──► COMPLETED
 *                        │
 *                        └──► REJECTED
 *   WAITING_FOR_RESTAURANT / ACCEPTED / PREPARING / READY_FOR_PICKUP ──► CANCELLED (administrators only, Module 31)
 *
 * CREATED exists only inside the transaction that creates the order; the first state anybody reads is
 * WAITING_FOR_RESTAURANT. PICKED_UP and COMPLETED are in the machine, but nothing reaches them before Module 32
 * (secure pickup verification). Terminal states are never reopened.
 */
enum OrderStatus: string
{
    case Created = 'CREATED';
    /** The restaurant has not answered yet. */
    case WaitingForRestaurant = 'WAITING_FOR_RESTAURANT';
    case Accepted = 'ACCEPTED';
    case Preparing = 'PREPARING';
    case ReadyForPickup = 'READY_FOR_PICKUP';
    /** Module 32: the customer collected it (verified). */
    case PickedUp = 'PICKED_UP';
    case Completed = 'COMPLETED';
    /** The restaurant could not accept it. */
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /**
     * An order that is still on its way to the customer: it occupies its pickup slot and is "active" for the
     * restaurant and the customer.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::WaitingForRestaurant, self::Accepted, self::Preparing, self::ReadyForPickup], true);
    }

    /**
     * Whether the state machine allows this step at all. Who may take it is decided by OrderStateMachine.
     */
    public function allows(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Created => [self::WaitingForRestaurant],
            self::WaitingForRestaurant => [self::Accepted, self::Rejected, self::Cancelled],
            self::Accepted => [self::Preparing, self::Cancelled],
            self::Preparing => [self::ReadyForPickup, self::Cancelled],
            self::ReadyForPickup => [self::PickedUp, self::Cancelled],
            self::PickedUp => [self::Completed],
            self::Completed, self::Rejected, self::Cancelled => [],
        }, true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isActive())));
    }
}
