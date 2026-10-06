<?php

namespace App\Services\Cart;

use App\Models\Cart;

/**
 * The outcome of one cart change: the cart as it now is, the line that was written (when one was), whether an
 * identical configuration was already in the cart (its quantity was raised instead of adding a second line), and
 * — after a confirmed restaurant switch — a summary of the cart that was replaced.
 */
final readonly class CartChange
{
    /**
     * @param  array<string, mixed>|null  $replaced
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public ?Cart $cart,
        public ?string $lineId = null,
        public bool $merged = false,
        public bool $created = false,
        public ?array $replaced = null,
        public array $extra = [],
    ) {}
}
