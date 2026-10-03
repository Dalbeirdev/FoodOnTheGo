<?php

namespace App\Enums;

/**
 * Why a menu item cannot be ordered right now — the first failing condition, machine-readable. Restaurant-level
 * reasons come from RestaurantAvailabilityService and are passed through as RESTAURANT_UNAVAILABLE for customers
 * (with the restaurant's own reason alongside).
 */
enum MenuAvailabilityReason: string
{
    case RestaurantUnavailable = 'RESTAURANT_UNAVAILABLE';
    case MenuInactive = 'MENU_INACTIVE';
    case CategoryInactive = 'CATEGORY_INACTIVE';
    case ItemSoldOut = 'ITEM_SOLD_OUT';
    case ItemTemporarilyUnavailable = 'ITEM_TEMPORARILY_UNAVAILABLE';
    case ItemDisabled = 'ITEM_DISABLED';
    case ItemArchived = 'ITEM_ARCHIVED';
    case RequiredGroupUnavailable = 'REQUIRED_GROUP_UNAVAILABLE';
    /** Reserved for item / category schedules (not built in Module 24). */
    case OutsideItemSchedule = 'OUTSIDE_ITEM_SCHEDULE';

    /**
     * Reasons that keep the item visible to customers (shown as not orderable).
     */
    public function keepsVisible(): bool
    {
        return in_array($this, [self::RestaurantUnavailable, self::ItemSoldOut, self::ItemTemporarilyUnavailable, self::RequiredGroupUnavailable, self::OutsideItemSchedule], true);
    }
}
