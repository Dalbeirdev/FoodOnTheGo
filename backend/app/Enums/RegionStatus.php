<?php

namespace App\Enums;

use App\Enums\Concerns\ControlledStatus;

/**
 * Operational status of a region (state, union territory, province). A region in the table is reference
 * geography; only PILOT / ACTIVE regions can have serviceable cities.
 */
enum RegionStatus: string
{
    use ControlledStatus;

    case Planned = 'PLANNED';
    case Pilot = 'PILOT';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Disabled = 'DISABLED';

    public function servesCustomers(): bool
    {
        return $this === self::Active || $this === self::Pilot;
    }

    public static function transitions(): array
    {
        return [
            'PLANNED' => ['PILOT', 'ACTIVE', 'DISABLED'],
            'PILOT' => ['ACTIVE', 'PAUSED', 'DISABLED'],
            'ACTIVE' => ['PILOT', 'PAUSED', 'DISABLED'],
            'PAUSED' => ['PILOT', 'ACTIVE', 'DISABLED'],
            'DISABLED' => ['PLANNED'],
        ];
    }
}
