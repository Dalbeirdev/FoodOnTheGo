<?php

namespace App\Enums;

/**
 * Where a promotion can be used. A promotion never applies wider than its scope; one with amounts additionally
 * applies only to a checkout in its currency.
 */
enum PromotionScope: string
{
    case Platform = 'PLATFORM';
    case Market = 'MARKET';
    case RestaurantOrganization = 'RESTAURANT_ORGANIZATION';
    case RestaurantLocation = 'RESTAURANT_LOCATION';
}
