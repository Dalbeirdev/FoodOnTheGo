<?php

namespace App\Events\Cart;

/**
 * The lines of a guest cart were merged into the cart of the customer who signed in.
 */
final class CartMerged extends CartEvent
{
    public function name(): string
    {
        return 'merged';
    }
}
