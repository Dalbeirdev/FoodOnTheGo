<?php

namespace App\Events\Cart;

/**
 * A cart was started (the first item of a guest or a customer).
 */
final class CartCreated extends CartEvent
{
    public function name(): string
    {
        return 'created';
    }
}
