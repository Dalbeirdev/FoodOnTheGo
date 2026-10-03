<?php

/*
|--------------------------------------------------------------------------
| Menus (Module 24)
|--------------------------------------------------------------------------
|
| Platform limits for restaurant-managed menus. Nothing here is a market
| fact: the currency of a menu is the currency of its location.
|
*/

return [

    'limits' => [
        'name' => 120,
        'description' => (int) env('MENU_MAX_DESCRIPTION', 1000),
        'categories_per_menu' => (int) env('MENU_MAX_CATEGORIES', 60),
        'items_per_category' => (int) env('MENU_MAX_ITEMS_PER_CATEGORY', 300),
        'groups_per_item' => (int) env('MENU_MAX_GROUPS_PER_ITEM', 12),
        'options_per_group' => (int) env('MENU_MAX_OPTIONS_PER_GROUP', 40),
        'dietary_tags_per_item' => 6,
        'images_per_item' => (int) env('MENU_MAX_IMAGES_PER_ITEM', 6),
        // Minor units, whatever the currency: ₹1,00,000.00 / $1,000,000.00 / ¥100,000,000.
        'price_minor' => [0, (int) env('MENU_MAX_PRICE_MINOR', 10000000)],
        'preparation_minutes' => [0, 240],
        'quantity_per_line' => [1, (int) env('MENU_MAX_QUANTITY_PER_LINE', 50)],
        'default_max_quantity' => 20,
        'instructions' => 200,
        'bulk_items' => 100,
    ],

    /*
    | A negative option adjustment (a smaller portion) is allowed, but never more than the base price of its
    | item, and a configured price can never fall below zero (MenuPricingService refuses the combination).
    */

    'allow_negative_adjustments' => (bool) env('MENU_ALLOW_NEGATIVE_ADJUSTMENTS', true),

    /*
    | Item images: files are stored on the "public" disk under menu/{menu}/… and served by GET /api/v1/media/{path}
    | (no file system path ever appears in a response). Content is checked, not the file name.
    */

    'images' => [
        'disk' => env('MENU_IMAGE_DISK', 'public'),
        'max_bytes' => (int) env('MENU_IMAGE_MAX_BYTES', 2 * 1024 * 1024),
        'min_dimension' => 200,
        'max_dimension' => 4000,
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    /*
    | Seconds the customer menu document may be reused from the cache. Every customer-visible change bumps the
    | catalog version of the menu, which is part of the cache key, so stale entries become unreachable at once.
    */

    'public_cache_seconds' => (int) env('MENU_PUBLIC_CACHE_SECONDS', 300),

];
