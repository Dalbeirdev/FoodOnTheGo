<?php

namespace App\Enums;

/**
 * - ACTIVE: normal sign-in and use.
 * - RESTRICTED: may sign in; later modules limit what the account can do (e.g. no new orders) by policy.
 * - SUSPENDED: platform decision; cannot sign in and existing sessions stop working.
 * - DEACTIVATED: closed at the customer's request or by a business process; cannot sign in. Reactivation and
 *   erasure are handled by support / privacy processes, never by simply signing in again.
 */
enum CustomerStatus: string
{
    case Active = 'ACTIVE';
    case Restricted = 'RESTRICTED';
    case Suspended = 'SUSPENDED';
    case Deactivated = 'DEACTIVATED';

    public function canAuthenticate(): bool
    {
        return $this === self::Active || $this === self::Restricted;
    }
}
