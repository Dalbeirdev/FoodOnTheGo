<?php

namespace App\Enums;

enum MarketStatus: string
{
    case Draft = 'DRAFT';
    case Pilot = 'PILOT';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Closed = 'CLOSED';

    /**
     * Customers may only be served by markets in these states.
     */
    public function isServingCustomers(): bool
    {
        return $this === self::Active || $this === self::Pilot;
    }
}
