<?php

namespace App\Events\Cart;

/**
 * The cleanup closed a cart nobody had touched for its lifetime.
 */
final class CartExpired extends CartEvent
{
    public function name(): string
    {
        return 'expired';
    }
}
