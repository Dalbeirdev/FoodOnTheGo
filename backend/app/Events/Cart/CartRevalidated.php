<?php

namespace App\Events\Cart;

/**
 * The owner accepted the current prices: the snapshots were brought up to date.
 */
final class CartRevalidated extends CartEvent
{
    public function name(): string
    {
        return 'revalidated';
    }
}
