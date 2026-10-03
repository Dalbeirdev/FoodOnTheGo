<?php

/*
|--------------------------------------------------------------------------
| Customer account (Module 25)
|--------------------------------------------------------------------------
|
| Limits and policies for the customer's own data: profile, favorites, saved
| journey locations, recent locations, payment-method references and
| notification preferences. Nothing here is a market fact; the locale options
| and the phone format come from the customer's market.
|
*/

return [

    'limits' => [
        'name' => 120,
        'label' => 40,
        'address' => 300,
        'saved_locations' => (int) env('CUSTOMER_MAX_SAVED_LOCATIONS', 20),
        'favorite_cuisines' => 10,
        'search_radius_km' => [5, 100],
        'favorites_page' => 50,
        'recent_locations' => (int) env('CUSTOMER_MAX_RECENT_LOCATIONS', 10),
        'deletion_reason' => 300,
    ],

    /*
    | Recent locations are explicit customer selections only (never background tracking), bounded to the
    | newest `limits.recent_locations`, deduplicated by place or by rounded coordinates, clearable by the
    | customer at any time and pruned after this many days (customer:prune-recent-locations).
    */

    'recent_locations_retention_days' => (int) env('CUSTOMER_RECENT_LOCATIONS_RETENTION_DAYS', 90),

    /*
    | Sensitive actions (phone change, account deletion request) need recent authentication: a session
    | signed in within `fresh_token_minutes`, or a code verified on the account's phone within `window_minutes`.
    */

    'reauth' => [
        'fresh_token_minutes' => (int) env('CUSTOMER_REAUTH_FRESH_TOKEN_MINUTES', 30),
        'window_minutes' => (int) env('CUSTOMER_REAUTH_WINDOW_MINUTES', 10),
    ],

    /*
    | Profile photo: checked by content, stored under avatars/{customer}/{uuid}.{ext} on the disk and served
    | by GET /api/v1/media/{path}. No file system path appears in a response.
    */

    'avatar' => [
        'disk' => env('CUSTOMER_AVATAR_DISK', 'public'),
        'max_bytes' => (int) env('CUSTOMER_AVATAR_MAX_BYTES', 2 * 1024 * 1024),
        'min_dimension' => 120,
        'max_dimension' => 2000,
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
    | Notification preferences are a category × channel matrix. Transactional categories default to on;
    | marketing defaults to off and is never inferred; locked channels of a category cannot be switched off
    | (security notices). Delivery itself is a later module — these are the customer's choices only.
    */

    'notifications' => [
        'channels' => ['PUSH', 'SMS', 'EMAIL', 'IN_APP'],
        'categories' => [
            'ORDER_UPDATES' => ['name' => 'Order updates', 'description' => 'Confirmed, being prepared, ready for pickup', 'transactional' => true, 'defaults' => ['PUSH' => true, 'SMS' => true, 'EMAIL' => true, 'IN_APP' => true], 'locked' => []],
            'PICKUP_UPDATES' => ['name' => 'Pickup updates', 'description' => 'Pickup codes, counter changes and delays', 'transactional' => true, 'defaults' => ['PUSH' => true, 'SMS' => true, 'EMAIL' => false, 'IN_APP' => true], 'locked' => []],
            'PAYMENT_UPDATES' => ['name' => 'Payment & refund updates', 'description' => 'Payment confirmations and refunds', 'transactional' => true, 'defaults' => ['PUSH' => true, 'SMS' => false, 'EMAIL' => true, 'IN_APP' => true], 'locked' => []],
            'ACCOUNT_SECURITY' => ['name' => 'Account & security', 'description' => 'Sign-ins, phone changes and other security notices', 'transactional' => true, 'defaults' => ['PUSH' => true, 'SMS' => true, 'EMAIL' => true, 'IN_APP' => true], 'locked' => ['SMS', 'IN_APP']],
            'PROMOTIONS' => ['name' => 'Offers & promotions', 'description' => 'Deals and new restaurants on your routes', 'transactional' => false, 'defaults' => ['PUSH' => false, 'SMS' => false, 'EMAIL' => false, 'IN_APP' => false], 'locked' => []],
            'PRODUCT_UPDATES' => ['name' => 'Product updates', 'description' => 'New features and service announcements', 'transactional' => false, 'defaults' => ['PUSH' => false, 'SMS' => false, 'EMAIL' => false, 'IN_APP' => true], 'locked' => []],
        ],
    ],

];
