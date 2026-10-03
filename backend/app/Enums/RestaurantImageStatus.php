<?php

namespace App\Enums;

/**
 * No moderation workflow exists yet: an image is shown (ACTIVE) or kept for history only (ARCHIVED).
 */
enum RestaurantImageStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
