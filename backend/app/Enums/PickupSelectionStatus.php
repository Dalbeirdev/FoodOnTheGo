<?php

namespace App\Enums;

/**
 * State of the pickup time a customer chose for a cart. A selection is a choice, not a reservation: nothing is
 * held for the customer until checkout / order creation reserve it (later modules), so there is no RESERVED.
 */
enum PickupSelectionStatus: string
{
    /** Chosen and valid when it was last checked. */
    case Selected = 'SELECTED';
    /** The cart changed after the choice and the chosen time no longer fits it. */
    case Stale = 'STALE';
    /** The restaurant, its hours, its settings or the slot's capacity no longer allow it. */
    case Invalid = 'INVALID';
    /** The chosen time has passed, or the cart it belongs to has expired. */
    case Expired = 'EXPIRED';
    /** Copied onto an order (Module 31). */
    case Converted = 'CONVERTED';

    public function isUsable(): bool
    {
        return $this === self::Selected;
    }
}
