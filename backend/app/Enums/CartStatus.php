<?php

namespace App\Enums;

/**
 * The life of a cart. ACTIVE is the only state a customer works with; the others say how it ended:
 *
 *   CONVERTED    an order was created from it (Module 31) — kept as the hand-over record, never pruned here
 *   EXPIRED      nobody touched it for its lifetime
 *   ABANDONED    its owner ended it: cleared it, or replaced it with a cart of another restaurant
 *   INVALIDATED  it stopped being a cart of its own: its lines were merged into the customer's cart at sign-in
 *
 * An ACTIVE cart whose expires_at has passed already answers EXPIRED to its owner (Cart::effectiveStatus()).
 */
enum CartStatus: string
{
    case Active = 'ACTIVE';
    case Converted = 'CONVERTED';
    case Expired = 'EXPIRED';
    case Abandoned = 'ABANDONED';
    case Invalidated = 'INVALIDATED';
}
