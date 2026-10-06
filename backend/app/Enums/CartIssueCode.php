<?php

namespace App\Enums;

use App\Enums\CartIssueSeverity as Severity;

/**
 * What the backend found when it compared a cart with the current menu and restaurant. Machine-readable: the
 * clients choose the wording. A line with an issue is never removed for the customer — it stays, explained.
 */
enum CartIssueCode: string
{
    // one line
    case ItemSoldOut = 'ITEM_SOLD_OUT';
    case ItemTemporarilyUnavailable = 'ITEM_TEMPORARILY_UNAVAILABLE';
    case ItemDisabled = 'ITEM_DISABLED';
    case ItemRemoved = 'ITEM_REMOVED';
    case OptionUnavailable = 'OPTION_UNAVAILABLE';
    case RequiredCustomizationMissing = 'REQUIRED_CUSTOMIZATION_MISSING';
    case InvalidCustomization = 'INVALID_CUSTOMIZATION';
    case QuantityLimit = 'QUANTITY_LIMIT';
    case PriceChanged = 'PRICE_CHANGED';
    // the whole cart
    case CartExpired = 'CART_EXPIRED';
    case RestaurantUnavailable = 'RESTAURANT_UNAVAILABLE';
    case RestaurantNotAccepting = 'RESTAURANT_NOT_ACCEPTING';
    case RestaurantClosedNow = 'RESTAURANT_CLOSED_NOW';
    case MenuUnavailable = 'MENU_UNAVAILABLE';
    case MenuChanged = 'MENU_CHANGED';
    case CurrencyMismatch = 'CURRENCY_MISMATCH';
    case JourneyStale = 'JOURNEY_STALE';

    public function severity(): Severity
    {
        return match ($this) {
            self::PriceChanged => Severity::Review,
            // Closed now is not a verdict on the order: pickup time and opening hours are decided in Module 28.
            self::RestaurantClosedNow, self::MenuChanged, self::JourneyStale => Severity::Info,
            default => Severity::Blocking,
        };
    }
}
