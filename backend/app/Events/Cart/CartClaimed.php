<?php

namespace App\Events\Cart;

/**
 * A guest cart became the cart of the customer who signed in.
 */
final class CartClaimed extends CartEvent
{
    public function name(): string
    {
        return 'claimed';
    }
}
