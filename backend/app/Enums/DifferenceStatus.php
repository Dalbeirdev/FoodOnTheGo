<?php

namespace App\Enums;

/**
 * A reconciliation difference stays visible until somebody — or a deterministic rule — closes it with a reason.
 */
enum DifferenceStatus: string
{
    case Open = 'OPEN';
    case Investigating = 'INVESTIGATING';
    case Resolved = 'RESOLVED';
    case IgnoredWithReason = 'IGNORED_WITH_REASON';

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::Investigating;
    }
}
