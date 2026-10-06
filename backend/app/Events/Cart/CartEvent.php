<?php

namespace App\Events\Cart;

use App\Models\Cart;

/**
 * Something happened to a cart. Product events, not audit events: they feed counters and a log line that names
 * the cart, its owner type, its restaurant and a few numbers — never the items, the special instructions or
 * anything about the journey. Listeners can rely on `name()` being stable.
 */
abstract class CartEvent implements CartActivity
{
    public readonly string $cartId;

    public readonly string $owner;

    public readonly ?string $restaurantId;

    /**
     * @param  array<string, int|string|bool|null>  $facts  safe numbers and codes only
     */
    public function __construct(Cart $cart, public readonly array $facts = [])
    {
        $this->cartId = (string) $cart->public_id;
        $this->owner = $cart->isGuest() ? 'guest' : 'customer';
        $this->restaurantId = $cart->relationLoaded('location') ? $cart->location?->public_id : null;
    }

    abstract public function name(): string;
}
