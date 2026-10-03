<?php

namespace App\Enums;

/**
 * Why a restaurant location cannot be ordered from right now. Machine-readable: clients choose the wording.
 * The first reason that applies is reported, in the order of the cases below.
 *
 * Restaurant staff and administrators see the exact reason. Customers only see a restaurant that is visible
 * to them, and for it the customer-safe code from forCustomers(): the geography reasons collapse into
 * AREA_UNAVAILABLE and the approval reasons never appear (such a restaurant is simply not found).
 */
enum RestaurantAvailabilityReason: string
{
    case MarketUnavailable = 'MARKET_UNAVAILABLE';
    case RestaurantNotApproved = 'RESTAURANT_NOT_APPROVED';
    case RestaurantSuspended = 'RESTAURANT_SUSPENDED';
    case LocationNotApproved = 'LOCATION_NOT_APPROVED';
    case LocationSuspended = 'LOCATION_SUSPENDED';
    case OutsideServiceArea = 'OUTSIDE_SERVICE_AREA';
    case RegionUnavailable = 'REGION_UNAVAILABLE';
    case CityUnavailable = 'CITY_UNAVAILABLE';
    case ServiceAreaUnavailable = 'SERVICE_AREA_UNAVAILABLE';
    case TemporarilyClosed = 'TEMPORARILY_CLOSED';
    case PickupUnavailable = 'PICKUP_UNAVAILABLE';
    case NotAcceptingOrders = 'NOT_ACCEPTING_ORDERS';
    case ClosedNow = 'CLOSED_NOW';

    /** The area the restaurant is in is not served right now (paused region, city or service area). */
    public const AREA_UNAVAILABLE = 'AREA_UNAVAILABLE';

    /**
     * Customer-safe code for a restaurant that customers can see.
     */
    public function forCustomers(): string
    {
        return in_array($this, [self::RegionUnavailable, self::CityUnavailable, self::ServiceAreaUnavailable], true) ? self::AREA_UNAVAILABLE : $this->value;
    }
}
