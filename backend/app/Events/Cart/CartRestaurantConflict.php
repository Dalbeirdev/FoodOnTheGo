<?php

namespace App\Events\Cart;

/**
 * An item of another restaurant was refused: one cart, one restaurant.
 */
final class CartRestaurantConflict extends CartEvent
{
    public function name(): string
    {
        return 'restaurant_conflict';
    }
}
