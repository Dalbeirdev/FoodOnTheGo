<?php

namespace App\Enums;

/**
 * Where a payment dispute / chargeback stands, in provider-neutral words (Module 35 foundation). Only a verified
 * provider event sets it. A dispute is not a refund: it is started through the payment network, not by FoodOnTheGo.
 */
enum DisputeStatus: string
{
    case Open = 'OPEN';
    case ActionRequired = 'ACTION_REQUIRED';
    case UnderReview = 'UNDER_REVIEW';
    case Won = 'WON';
    case Lost = 'LOST';
    case Closed = 'CLOSED';
    case Unknown = 'UNKNOWN';

    /** Not decided yet: a refund now could pay the same money back twice. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::ActionRequired, self::UnderReview, self::Unknown], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isOpen())));
    }
}
