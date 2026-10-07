<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Life cycle of a promotion. DRAFT, ACTIVE, PAUSED and DISABLED are stored; SCHEDULED and EXPIRED are what an ACTIVE
 * promotion is before its start and after its end — they follow from the clock and are never written.
 */
enum PromotionStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Expired = 'EXPIRED';
    case Disabled = 'DISABLED';

    /** The states a row can hold. */
    public const STORED = [self::Draft, self::Active, self::Paused, self::Disabled];

    /** What a stored status means at a given moment. */
    public static function effective(self $stored, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt, CarbonInterface $at): self
    {
        if ($stored !== self::Active) {
            return $stored;
        }
        if ($endsAt !== null && $at->greaterThanOrEqualTo($endsAt)) {
            return self::Expired;
        }

        return $startsAt !== null && $at->lessThan($startsAt) ? self::Scheduled : self::Active;
    }

    /** Stored states this one may move to. DISABLED is final. */
    public function mayBecome(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Active, self::Disabled], true),
            self::Active => in_array($next, [self::Paused, self::Disabled], true),
            self::Paused => in_array($next, [self::Active, self::Disabled], true),
            default => false,
        };
    }
}
