<?php

namespace App\Events\Cart;

/**
 * The quantity, the options or the instructions of a line changed.
 */
final class CartItemUpdated extends CartEvent
{
    public function name(): string
    {
        return 'item_updated';
    }
}
