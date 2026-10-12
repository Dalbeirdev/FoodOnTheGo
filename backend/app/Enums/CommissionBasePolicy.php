<?php

namespace App\Enums;

/**
 * What a commission percentage is taken from (Module 36). Always explicit on the plan — never assumed.
 *
 * In every policy the base is MERCHANDISE: tax added to the order and customer fees are never part of it. Whether
 * a production plan may include them is a commercial decision nobody has made; there is no policy for it.
 */
enum CommissionBasePolicy: string
{
    /** The items as priced, before any discount. */
    case MerchandiseGross = 'MERCHANDISE_GROSS';
    /** The items minus the part of the discount the restaurant pays for (a platform-funded discount does not lower it). */
    case MerchandiseNetOfRestaurantDiscount = 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT';
    /** The items minus the whole discount, whoever pays for it. */
    case MerchandiseNetOfAllDiscounts = 'MERCHANDISE_NET_OF_ALL_DISCOUNTS';

    public function base(int $merchandiseGross, int $restaurantDiscount, int $platformDiscount): int
    {
        return max(0, match ($this) {
            self::MerchandiseGross => $merchandiseGross,
            self::MerchandiseNetOfRestaurantDiscount => $merchandiseGross - $restaurantDiscount,
            self::MerchandiseNetOfAllDiscounts => $merchandiseGross - $restaurantDiscount - $platformDiscount,
        });
    }
}
