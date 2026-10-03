<?php

namespace App\Enums;

/**
 * Administrative state of a whole menu. Only an ACTIVE menu is shown to customers; DRAFT is being built,
 * INACTIVE is switched off by the restaurant, ARCHIVED is kept for history and never shown again.
 */
enum MenuStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';

    public function isCustomerVisible(): bool
    {
        return $this === self::Active;
    }
}
