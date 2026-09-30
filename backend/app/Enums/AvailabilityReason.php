<?php

namespace App\Enums;

/**
 * Why a location is not serviceable. Machine-readable: clients choose the (localised) wording.
 */
enum AvailabilityReason: string
{
    /** The point is in no market FoodOnTheGo knows, or in one that is not open to customers yet. */
    case MarketUnsupported = 'MARKET_UNSUPPORTED';

    /** The market exists and has served customers, but is paused. */
    case MarketPaused = 'MARKET_PAUSED';

    /** The state / region around the point is not open. */
    case RegionUnavailable = 'REGION_UNAVAILABLE';

    /** The nearest known city is planned, paused or unavailable. */
    case CityUnavailable = 'CITY_UNAVAILABLE';

    /** A service area covers the point but is paused / disabled, or outside its effective dates. */
    case ServiceAreaPaused = 'SERVICE_AREA_PAUSED';

    /** The market is open but no service area covers the point. */
    case OutsideServiceArea = 'OUTSIDE_SERVICE_AREA';
}
