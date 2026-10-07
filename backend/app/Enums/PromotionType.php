<?php

namespace App\Enums;

/**
 * How a promotion reduces the merchandise subtotal. Only these two exist: item-specific offers, free fees and
 * "buy one get one" are not built and not approved.
 */
enum PromotionType: string
{
    /** An amount in the promotion's currency, e.g. 10000 minor units of INR. */
    case FixedAmount = 'FIXED_AMOUNT';
    /** A share of the merchandise subtotal in basis points (10 % = 1000), optionally capped by a maximum amount. */
    case Percentage = 'PERCENTAGE';
}
