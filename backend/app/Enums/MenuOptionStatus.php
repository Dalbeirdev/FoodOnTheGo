<?php

namespace App\Enums;

/**
 * TEMPORARILY_UNAVAILABLE options stay visible but cannot be selected; DISABLED and ARCHIVED are not shown to
 * customers. A past order keeps its own copy of what was chosen (Module 31), so nothing is ever rewritten here.
 */
enum MenuOptionStatus: string
{
    case Active = 'ACTIVE';
    case TemporarilyUnavailable = 'TEMPORARILY_UNAVAILABLE';
    case Disabled = 'DISABLED';
    case Archived = 'ARCHIVED';

    public function isCustomerVisible(): bool
    {
        return $this === self::Active || $this === self::TemporarilyUnavailable;
    }

    public function isSelectable(): bool
    {
        return $this === self::Active;
    }
}
