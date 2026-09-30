<?php

namespace App\Enums;

use App\Enums\Concerns\ControlledStatus;

enum MarketStatus: string
{
    use ControlledStatus;

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

    public function servesCustomers(): bool
    {
        return $this->isServingCustomers();
    }

    /**
     * A closed market can only be reopened as a draft (a deliberate relaunch), never straight to live.
     */
    public static function transitions(): array
    {
        return [
            'DRAFT' => ['PILOT', 'ACTIVE', 'CLOSED'],
            'PILOT' => ['ACTIVE', 'PAUSED', 'CLOSED'],
            'ACTIVE' => ['PAUSED', 'CLOSED'],
            'PAUSED' => ['PILOT', 'ACTIVE', 'CLOSED'],
            'CLOSED' => ['DRAFT'],
        ];
    }
}
