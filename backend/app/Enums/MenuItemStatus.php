<?php

namespace App\Enums;

/**
 * One state instead of several overlapping flags. SOLD_OUT and TEMPORARILY_UNAVAILABLE stay visible to customers
 * (greyed out, not orderable); DISABLED and ARCHIVED are not shown. ARCHIVED is final (the row stays for history).
 */
enum MenuItemStatus: string
{
    case Active = 'ACTIVE';
    case SoldOut = 'SOLD_OUT';
    case TemporarilyUnavailable = 'TEMPORARILY_UNAVAILABLE';
    case Disabled = 'DISABLED';
    case Archived = 'ARCHIVED';

    public function isCustomerVisible(): bool
    {
        return in_array($this, [self::Active, self::SoldOut, self::TemporarilyUnavailable], true);
    }

    public function isOrderable(): bool
    {
        return $this === self::Active;
    }

    /**
     * The states a restaurant may set directly (archiving is a separate, final action).
     *
     * @return list<string>
     */
    public static function settable(): array
    {
        return [self::Active->value, self::SoldOut->value, self::TemporarilyUnavailable->value, self::Disabled->value];
    }
}
