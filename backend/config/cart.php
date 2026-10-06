<?php

/*
|--------------------------------------------------------------------------
| Cart (Module 27)
|--------------------------------------------------------------------------
|
| Lifetimes and bounds of the cart. Nothing here is a market fact: the
| currency of a cart is the currency of its restaurant location. The
| values are development defaults, not production decisions.
|
*/

return [

    /*
    | How long a cart lives after its last change (every mutation renews it). A cart past this instant answers
    | "expired" until its owner clears it; `carts:prune` closes it after the grace period and deletes closed carts
    | after the retention period. A CONVERTED cart (an order was created from it — Module 31) is never deleted here.
    */
    'ttl' => [
        'guest_hours' => (int) env('CART_GUEST_TTL_HOURS', 168),
        'customer_hours' => (int) env('CART_CUSTOMER_TTL_HOURS', 720),
        'expired_grace_hours' => (int) env('CART_EXPIRED_GRACE_HOURS', 24),
        'closed_retention_days' => (int) env('CART_CLOSED_RETENTION_DAYS', 30),
    ],

    /*
    | Guest carts are addressed by a token the server mints (header X-Cart-Token; only its SHA-256 hash is stored).
    | A token may START a cart only for this many days after it was issued; an existing cart keeps answering to
    | its token for as long as the cart lives.
    */
    'guest' => [
        'token_ttl_days' => (int) env('CART_GUEST_TOKEN_TTL_DAYS', 30),
    ],

    /*
    | Bounds. The quantity of one line is limited by the item itself (menu_items.max_quantity, within the platform
    | bound menu.limits.quantity_per_line); the length of special instructions is menu.limits.instructions, the
    | figure the item page already shows. `total_quantity` = 0 means no cart-wide bound.
    */
    'limits' => [
        'lines' => (int) env('CART_MAX_LINES', 50),
        'total_quantity' => (int) env('CART_MAX_TOTAL_QUANTITY', 0),
    ],

    /*
    | Counters for the administrators' system page (numbers only, never cart contents) are kept this long.
    */
    'metrics' => [
        'retention_hours' => (int) env('CART_METRICS_RETENTION_HOURS', 48),
    ],
];
