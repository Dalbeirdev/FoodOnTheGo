<?php

namespace App\Enums;

/**
 * Why an order can or cannot be reviewed. ORDER_NOT_OWNED is never sent to a client: another customer's order is
 * "not found" for the caller.
 */
enum ReviewEligibilityReason: string
{
    case Eligible = 'ELIGIBLE';
    case OrderNotCompleted = 'ORDER_NOT_COMPLETED';
    case OrderNotOwned = 'ORDER_NOT_OWNED';
    case ReviewAlreadyExists = 'REVIEW_ALREADY_EXISTS';
    case ReviewWindowExpired = 'REVIEW_WINDOW_EXPIRED';
    case OrderNotEligible = 'ORDER_NOT_ELIGIBLE';
}
