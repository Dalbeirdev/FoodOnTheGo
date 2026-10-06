<?php

namespace App\Events\Cart;

/**
 * The owner ended the cart.
 */
final class CartCleared extends CartEvent
{
    public function name(): string
    {
        return 'cleared';
    }
}
