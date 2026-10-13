<?php

namespace App\Enums;

/**
 * How urgent a support case is (Module 37). Decided by the backend from the category (configuration) and changed only
 * by an administrator with a reason — a requester never sets it. Priority is not escalation: escalation asks for more
 * attention from somebody else.
 */
enum SupportCasePriority: string
{
    case Low = 'LOW';
    case Normal = 'NORMAL';
    case High = 'HIGH';
    case Urgent = 'URGENT';

    /** Sort weight: urgent first. */
    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 1,
            self::High => 2,
            self::Normal => 3,
            self::Low => 4,
        };
    }
}
