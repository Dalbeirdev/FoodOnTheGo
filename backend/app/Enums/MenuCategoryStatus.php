<?php

namespace App\Enums;

/**
 * ACTIVE is shown and orderable; INACTIVE hides the whole category (temporarily unavailable, seasonal) without
 * touching its items; ARCHIVED is kept for history.
 */
enum MenuCategoryStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';
}
