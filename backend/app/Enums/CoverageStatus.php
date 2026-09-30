<?php

namespace App\Enums;

use App\Enums\Concerns\ControlledStatus;

/**
 * Status of a service area or a route corridor. TESTING is internal: visible to administrators, never a
 * reason to tell a customer they are covered.
 */
enum CoverageStatus: string
{
    use ControlledStatus;

    case Planned = 'PLANNED';
    case Testing = 'TESTING';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Disabled = 'DISABLED';

    public function servesCustomers(): bool
    {
        return $this === self::Active;
    }

    public static function transitions(): array
    {
        return [
            'PLANNED' => ['TESTING', 'ACTIVE', 'DISABLED'],
            'TESTING' => ['ACTIVE', 'PAUSED', 'DISABLED'],
            'ACTIVE' => ['TESTING', 'PAUSED', 'DISABLED'],
            'PAUSED' => ['TESTING', 'ACTIVE', 'DISABLED'],
            'DISABLED' => ['PLANNED'],
        ];
    }
}
