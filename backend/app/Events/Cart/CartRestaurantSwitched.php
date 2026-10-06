<?php

namespace App\Events\Cart;

/**
 * The owner confirmed replacing the cart with a cart of another restaurant.
 */
final class CartRestaurantSwitched extends CartEvent
{
    public function name(): string
    {
        return 'restaurant_switched';
    }
}
