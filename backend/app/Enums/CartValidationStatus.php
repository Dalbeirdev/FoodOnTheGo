<?php

namespace App\Enums;

/**
 * The backend's verdict on a cart, recalculated on every read:
 *
 *   VALID         nothing stands between this cart and the next step
 *   NEEDS_REVIEW  the customer has to look at something and agree (a price changed)
 *   INVALID       it cannot proceed as it is (an item or option is unavailable, the restaurant cannot take orders …)
 */
enum CartValidationStatus: string
{
    case Valid = 'VALID';
    case NeedsReview = 'NEEDS_REVIEW';
    case Invalid = 'INVALID';
}
