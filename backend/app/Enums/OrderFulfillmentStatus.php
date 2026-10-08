<?php

namespace App\Enums;

/**
 * The state of ONE attempt to turn a checkout into an order (an order_fulfillments row, one per checkout).
 *
 *   PENDING ──► PROCESSING ──► SUCCEEDED
 *                   ├──► RETRYING ──► PROCESSING …
 *                   ├──► FAILED            (nothing more will be tried without a person — final for the automation)
 *                   └──► REVIEW_REQUIRED   (the money is there and the order cannot be made as it stands)
 */
enum OrderFulfillmentStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    /** A technical failure that will be tried again with a growing wait. */
    case Retrying = 'RETRYING';
    case Failed = 'FAILED';
    /** Capacity, the restaurant or the market make the order impossible as it stands — a person decides. */
    case ReviewRequired = 'REVIEW_REQUIRED';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Retrying], true);
    }
}
