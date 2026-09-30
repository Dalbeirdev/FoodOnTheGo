<?php

namespace App\Enums;

use App\Enums\Concerns\ControlledStatus;

/**
 * A city being ACTIVE does not mean the whole city is covered — service areas define coverage.
 */
enum CityStatus: string
{
    use ControlledStatus;

    case Planned = 'PLANNED';
    case Pilot = 'PILOT';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Unavailable = 'UNAVAILABLE';

    public function servesCustomers(): bool
    {
        return $this === self::Active || $this === self::Pilot;
    }

    public static function transitions(): array
    {
        return [
            'PLANNED' => ['PILOT', 'ACTIVE', 'UNAVAILABLE'],
            'PILOT' => ['ACTIVE', 'PAUSED', 'UNAVAILABLE'],
            'ACTIVE' => ['PILOT', 'PAUSED', 'UNAVAILABLE'],
            'PAUSED' => ['PILOT', 'ACTIVE', 'UNAVAILABLE'],
            'UNAVAILABLE' => ['PLANNED'],
        ];
    }
}
