<?php

use App\Enums\PickupMethod;

/*
|--------------------------------------------------------------------------
| Restaurants (Module 23)
|--------------------------------------------------------------------------
|
| Platform limits for restaurant-managed settings. Nothing here is a market
| fact: currency, time zone and which pickup modes / methods exist come from
| the market and from each location.
|
*/

return [

    /*
    | A pickup method may be enabled by a location only when the market allows it. The value is the market
    | feature that switches the method on; null = always available.
    */

    'pickup_methods' => [
        PickupMethod::Counter->value => null,
        PickupMethod::Curbside->value => 'curbside_pickup',
        PickupMethod::DriveThrough->value => 'drive_through_pickup',
    ],

    /*
    | Market features behind the two pickup modes.
    */

    'pickup_modes' => [
        'asap' => 'asap_pickup',
        'scheduled' => 'scheduled_pickup',
    ],

    /*
    | Bounds for what a restaurant may configure (minutes unless stated).
    */

    'limits' => [
        'periods_per_day' => (int) env('RESTAURANT_MAX_PERIODS_PER_DAY', 4),
        'prep_minutes' => [1, 240],
        'lead_minutes' => [0, 720],
        'buffer_minutes' => [0, 120],
        'cutoff_minutes' => [0, 240],
        'slot_intervals' => [5, 10, 15, 20, 30, 60],
        // How far ahead customers may schedule: platform maximum (the restaurant chooses within it).
        'schedule_horizon_minutes' => [0, (int) env('RESTAURANT_MAX_SCHEDULE_HORIZON_MINUTES', 10080)],
        'capacity_per_slot' => [1, 500],
        'pause_max_hours' => (int) env('RESTAURANT_PAUSE_MAX_HOURS', 168),
        'special_hours_days_ahead' => (int) env('RESTAURANT_SPECIAL_HOURS_DAYS_AHEAD', 366),
        'cuisines' => 5,
        'features' => 12,
        'gallery_images' => 12,
        'description' => 2000,
    ],

    /*
    | A location's coordinates must be this close to the centre of its city when no service area of that city
    | covers them (metres) — catches coordinates entered for the wrong city.
    */

    'city_radius_meters' => (int) env('RESTAURANT_CITY_RADIUS_METERS', 60000),

    /*
    | Days of special hours included in the public restaurant payload.
    */

    'public_special_hours_days' => (int) env('RESTAURANT_PUBLIC_SPECIAL_HOURS_DAYS', 30),

    /*
    | Seconds the customer-safe restaurant payload (profile, hours, pickup methods — never the open / closed
    | answer, which is calculated per request) may be reused. Every change to restaurant or market data makes
    | cached entries unreachable at once; this is only the upper bound.
    */

    'public_cache_seconds' => (int) env('RESTAURANT_PUBLIC_CACHE_SECONDS', 300),

];
