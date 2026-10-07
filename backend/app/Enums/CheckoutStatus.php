<?php

namespace App\Enums;

/**
 * State of a checkout session (Module 29). A checkout is a short-lived, server-priced quote for one cart — never an
 * order. It is not edited back to life: a stale or expired one is replaced by a new one.
 */
enum CheckoutStatus: string
{
    /** Validated and priced; the customer has not confirmed it yet (legal acceptance, payment context). */
    case Draft = 'DRAFT';
    /** Confirmed: everything was valid, the pickup time is held, payment can be started (Module 30). */
    case Ready = 'READY';
    /** Something it was priced from has changed (cart, pickup, prices, promotion, tax / fee rules, restaurant). */
    case Stale = 'STALE';
    /** Its time ran out. */
    case Expired = 'EXPIRED';
    /** A payment for it is in progress (set by Module 30). */
    case PaymentPending = 'PAYMENT_PENDING';
    /** An order was created from it (Module 31). */
    case Converted = 'CONVERTED';
    /** Ended by the customer, or replaced by a newer checkout of the same cart. */
    case Cancelled = 'CANCELLED';

    /** DRAFT, READY and PAYMENT_PENDING: the one checkout a cart may have at a time. */
    public function isLive(): bool
    {
        return in_array($this, [self::Draft, self::Ready, self::PaymentPending], true);
    }

    /** Still open to the customer's changes (promotion, confirmation). */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Ready;
    }
}
