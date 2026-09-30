<?php

namespace App\Enums;

/**
 * Account state of restaurant users and admin users.
 *
 * - INVITED: created, has not set a password yet; cannot sign in.
 * - ACTIVE: may sign in.
 * - SUSPENDED: temporarily blocked; existing sessions stop working.
 * - DISABLED: permanently closed; existing sessions stop working.
 */
enum StaffStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Disabled = 'DISABLED';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
