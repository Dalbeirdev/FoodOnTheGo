<?php

namespace App\Enums;

/**
 * How a moderation case ended. NO_VIOLATION is a real outcome: an unfavourable review that breaks no rule stays.
 */
enum ReviewCaseResolution: string
{
    case NoViolation = 'NO_VIOLATION';
    case Published = 'PUBLISHED';
    case Hidden = 'HIDDEN';
    case Rejected = 'REJECTED';
    case Restored = 'RESTORED';
    case ResponseHidden = 'RESPONSE_HIDDEN';
    case Withdrawn = 'WITHDRAWN';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
