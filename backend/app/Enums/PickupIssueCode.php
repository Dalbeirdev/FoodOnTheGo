<?php

namespace App\Enums;

/**
 * Why pickup cannot be offered, or why an option / a selection is not valid. Machine-readable: clients choose the
 * wording. The same codes are used in availability answers (`issues`, `reason`) and — lower-cased — as error
 * codes of refused selections.
 */
enum PickupIssueCode: string
{
    case PickupDisabled = 'PICKUP_DISABLED';
    case RestaurantUnavailable = 'RESTAURANT_UNAVAILABLE';
    case MarketUnavailable = 'MARKET_UNAVAILABLE';
    case RestaurantNotAccepting = 'RESTAURANT_NOT_ACCEPTING';
    /** Closed right now: ASAP is not possible; scheduled times may still be offered. */
    case RestaurantClosed = 'RESTAURANT_CLOSED';
    /** Open, but the order cannot be ready before the restaurant stops handing over orders. */
    case ClosesTooSoon = 'CLOSES_TOO_SOON';
    case AsapUnavailable = 'ASAP_UNAVAILABLE';
    case AsapDisabled = 'ASAP_DISABLED';
    case ScheduledDisabled = 'SCHEDULED_DISABLED';
    case NoPickupSlots = 'NO_PICKUP_SLOTS';
    case FullyBooked = 'FULLY_BOOKED';
    case SlotFull = 'SLOT_FULL';
    case SlotExpired = 'SLOT_EXPIRED';
    case SlotInvalid = 'SLOT_INVALID';
    case SlotTooEarly = 'SLOT_TOO_EARLY';
    case SlotOutsideHorizon = 'SLOT_OUTSIDE_HORIZON';
    case PickupMethodUnavailable = 'PICKUP_METHOD_UNAVAILABLE';
    case CartInvalid = 'CART_INVALID';
    case CartChanged = 'CART_CHANGED';
    case CartExpired = 'CART_EXPIRED';
    case JourneyStale = 'JOURNEY_STALE';

    /**
     * The error code of a refused request (project convention: lower snake case).
     */
    public function errorCode(): string
    {
        return strtolower($this->value);
    }
}
