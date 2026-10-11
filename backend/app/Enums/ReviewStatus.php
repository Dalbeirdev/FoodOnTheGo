<?php

namespace App\Enums;

/**
 * Where a review stands. Only PUBLISHED is public and counted in a rating. A review is never deleted: REMOVED is the
 * customer's (or an administrator's) withdrawal, kept with its history; the order cannot be reviewed again.
 *
 *   submit ─► PUBLISHED                       (nothing flagged by the screening)
 *   submit ─► PENDING_MODERATION              (flagged: a person decides)
 *   PENDING_MODERATION ─► PUBLISHED | REJECTED
 *   PUBLISHED ─► HIDDEN | REMOVED | PENDING_MODERATION (an edit that the screening flags)
 *   HIDDEN ─► PUBLISHED (restore) | REJECTED
 *   REJECTED ─► PUBLISHED (restore)
 */
enum ReviewStatus: string
{
    case PendingModeration = 'PENDING_MODERATION';
    case Published = 'PUBLISHED';
    case Hidden = 'HIDDEN';
    case Rejected = 'REJECTED';
    case Removed = 'REMOVED';

    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether the customer may still change or withdraw the review (the windows are checked separately).
     */
    public function ownerMayChange(): bool
    {
        return in_array($this, [self::Published, self::PendingModeration], true);
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::PendingModeration => [self::Published, self::Rejected, self::Removed],
            self::Published => [self::Hidden, self::Removed, self::PendingModeration],
            self::Hidden => [self::Published, self::Rejected, self::Removed],
            self::Rejected => [self::Published],
            self::Removed => [],
        };
    }

    public function canBecome(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
