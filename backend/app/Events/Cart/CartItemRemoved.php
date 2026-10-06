<?php

namespace App\Events\Cart;

/**
 * A line was removed.
 */
final class CartItemRemoved extends CartEvent
{
    public function name(): string
    {
        return 'item_removed';
    }
}
