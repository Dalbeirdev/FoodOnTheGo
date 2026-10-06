<?php

namespace App\Events\Cart;

/**
 * A guest cart and a customer cart of different restaurants met at sign-in; the customer has to choose.
 */
final class CartMergeConflict extends CartEvent
{
    public function name(): string
    {
        return 'merge_conflict';
    }
}
