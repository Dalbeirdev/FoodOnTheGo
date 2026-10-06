<?php

namespace App\Enums;

/**
 * Why a cart stopped being ACTIVE, and the status it ends in.
 */
enum CartCloseReason: string
{
    case Cleared = 'cleared';
    case RestaurantSwitch = 'restaurant_switch';
    case Merged = 'merged';
    case DiscardedAtSignIn = 'discarded_at_sign_in';
    case ReplacedAtSignIn = 'replaced_at_sign_in';
    case Expired = 'expired';

    public function status(): CartStatus
    {
        return match ($this) {
            self::Expired => CartStatus::Expired,
            self::Merged => CartStatus::Invalidated,
            default => CartStatus::Abandoned,
        };
    }
}
