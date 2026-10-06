<?php

namespace App\Events\Cart;

/**
 * An item was added: a new line, or a higher quantity on a line with the same configuration.
 */
final class CartItemAdded extends CartEvent
{
    public function name(): string
    {
        return 'item_added';
    }
}
