<?php

namespace App\Enums;

/**
 * - ACTIVE: usable once the provider integration exists.
 * - EXPIRED: the safe metadata says the card expired; never used for a payment.
 * - REVOKED: removed by the customer (the row stays for the payment history of later modules).
 * - UNAVAILABLE: the provider no longer honours the reference.
 */
enum PaymentMethodStatus: string
{
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case Unavailable = 'UNAVAILABLE';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
