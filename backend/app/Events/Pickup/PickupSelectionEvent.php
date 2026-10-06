<?php

namespace App\Events\Pickup;

use App\Models\Cart;

/**
 * Something happened to the pickup time chosen for a cart. A product event, not an audit event: it feeds counters
 * and one log line naming the cart, its owner type, its restaurant and a few codes — never the cart's contents,
 * the chosen minute, or anything about the customer's journey.
 */
final class PickupSelectionEvent
{
    public const SELECTED = 'selected';

    public const CLEARED = 'cleared';

    /** A selection that was valid is not valid any more (cart changed, restaurant changed, slot full, time passed). */
    public const INVALIDATED = 'invalidated';

    /** A choice was refused when it was made (slot full, slot not valid, ASAP not possible …). */
    public const REFUSED = 'refused';

    public readonly string $cartId;

    public readonly string $owner;

    public readonly ?string $restaurantId;

    /**
     * @param  array<string, int|string|bool|null>  $facts  safe codes only: mode, method, status, reason, journey_aware
     */
    public function __construct(public readonly string $name, Cart $cart, public readonly array $facts = [])
    {
        $this->cartId = (string) $cart->public_id;
        $this->owner = $cart->isGuest() ? 'guest' : 'customer';
        $this->restaurantId = $cart->relationLoaded('location') ? $cart->location?->public_id : null;
    }
}
