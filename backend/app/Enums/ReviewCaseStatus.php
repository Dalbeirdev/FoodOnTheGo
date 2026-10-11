<?php

namespace App\Enums;

enum ReviewCaseStatus: string
{
    case Open = 'OPEN';
    case Escalated = 'ESCALATED';
    case Resolved = 'RESOLVED';

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
