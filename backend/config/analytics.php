<?php

/*
|--------------------------------------------------------------------------
| Analytics, reporting and exports (Module 38)
|--------------------------------------------------------------------------
|
| Analytics is a READ / AGGREGATION layer. It never decides what is true about an order, a payment, a refund or
| money: every figure is read from the domain that owns it (orders, payment attempts, refunds, the financial
| ledger, support cases, reviews, notifications). The only thing analytics stores of its own is BEHAVIOURAL
| telemetry — what a session looked at on the way to an order — and that telemetry can never move a payment, an
| order or an amount.
|
| Everything here is operating policy. The bands, the windows, the thresholds and the retention periods are
| DEVELOPMENT DEFAULTS, PENDING BUSINESS APPROVAL.
|
*/

return [

    'approval' => 'PENDING BUSINESS APPROVAL',

    /*
    | Behavioural events. `enabled` off = the ingestion endpoint accepts and stores nothing and the server-recorded
    | events are skipped; no customer flow notices.
    */
    'events' => [
        'enabled' => (bool) env('ANALYTICS_EVENTS_ENABLED', true),
        'schema_version' => 1,
        // One request carries at most this many events; a larger batch is refused as a whole.
        'max_batch' => 25,
        // The stored properties of one event, as JSON.
        'max_properties_bytes' => 512,
        // A client clock that runs ahead: an event "from the future" is stored with the server's time.
        'max_future_seconds' => 300,
        // An Android device may send what it buffered offline; anything older than this is refused.
        'max_age_hours' => 72,

        /*
        | THE REGISTRY — the one list of events. A name that is not here is refused; a property that is not here is
        | refused. `source` says who may record it: CLIENT (the web and Android applications, through the ingestion
        | endpoint) or SERVER (the backend itself, when it answers a journey or a pickup request).
        |
        | There is deliberately NO payment_verified, order_created, order_completed or refund_completed here: those
        | are facts of the payment, order and refund domains and are read from them, never reported by a client.
        |
        |   refs        references the event may carry: restaurant | journey | item | checkout  (required: [...])
        |   properties  name => rule. Rules: bool | int:min,max | enum:A|B|C
        |   privacy     BEHAVIOURAL (what a session did) | OPERATIONAL (what the service answered)
        |   dedupe      how a repeat is recognised
        */
        'registry' => [
            'journey_search_started' => ['source' => 'CLIENT', 'stage' => 1, 'trigger' => 'The traveller submits the journey form.', 'refs' => [], 'required' => [], 'properties' => [], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; the client sends one per submission'],
            'journey_results_viewed' => ['source' => 'CLIENT', 'stage' => 2, 'trigger' => 'The list of restaurants on the route is shown for a planned journey (also when it is empty).', 'refs' => ['journey'], 'required' => ['journey'], 'properties' => ['results' => 'int:0,500'], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per journey per session'],
            'restaurant_viewed' => ['source' => 'CLIENT', 'stage' => 3, 'trigger' => 'The restaurant page is opened and its details are shown.', 'refs' => ['restaurant', 'journey'], 'required' => ['restaurant'], 'properties' => ['origin' => 'enum:JOURNEY|BROWSE|DIRECT'], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per restaurant per session'],
            'menu_viewed' => ['source' => 'CLIENT', 'stage' => 4, 'trigger' => 'The menu of a restaurant is displayed.', 'refs' => ['restaurant', 'journey'], 'required' => ['restaurant'], 'properties' => [], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per restaurant per session'],
            'item_viewed' => ['source' => 'CLIENT', 'stage' => null, 'trigger' => 'The detail of a menu item is opened.', 'refs' => ['restaurant', 'item'], 'required' => ['restaurant', 'item'], 'properties' => [], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per item per session'],
            'item_added_to_cart' => ['source' => 'CLIENT', 'stage' => 5, 'trigger' => 'The backend accepted an item into the cart.', 'refs' => ['restaurant', 'item'], 'required' => ['restaurant'], 'properties' => ['quantity' => 'int:1,50'], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; one per accepted add'],
            'pickup_viewed' => ['source' => 'CLIENT', 'stage' => null, 'trigger' => 'The pickup time page is shown.', 'refs' => ['restaurant'], 'required' => [], 'properties' => [], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per restaurant per session'],
            'pickup_selected' => ['source' => 'CLIENT', 'stage' => 6, 'trigger' => 'The backend accepted a pickup time for the cart.', 'refs' => ['restaurant'], 'required' => [], 'properties' => ['mode' => 'enum:ASAP|SCHEDULED', 'method' => 'enum:COUNTER|CURBSIDE|DRIVE_THROUGH', 'recommended' => 'bool'], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; one per accepted selection'],
            'checkout_started' => ['source' => 'CLIENT', 'stage' => 7, 'trigger' => 'The backend opened a checkout for the cart.', 'refs' => ['restaurant', 'checkout'], 'required' => ['checkout'], 'properties' => [], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per checkout per session'],
            'payment_started' => ['source' => 'CLIENT', 'stage' => 8, 'trigger' => 'The traveller pressed Pay and the backend created a payment attempt.', 'refs' => ['checkout'], 'required' => ['checkout'], 'properties' => ['method' => 'enum:CARD|UPI|WALLET|NET_BANKING|OTHER'], 'privacy' => 'BEHAVIOURAL', 'dedupe' => 'event id; once per checkout per session'],

            'journey_search_succeeded' => ['source' => 'SERVER', 'stage' => null, 'trigger' => 'The backend planned a journey (a route was found).', 'refs' => ['journey'], 'required' => ['journey'], 'properties' => ['distance_band' => 'band', 'duration_band' => 'band', 'coverage' => 'enum:COVERED|NO_SERVICE_COVERAGE', 'corridors' => 'ids', 'origin_city' => 'id', 'destination_city' => 'id'], 'privacy' => 'OPERATIONAL', 'dedupe' => 'one per journey (the event id is derived from the journey)'],
            'journey_search_no_result' => ['source' => 'SERVER', 'stage' => null, 'trigger' => 'Discovery found no restaurant for a journey, and not because of the traveller\'s own filters.', 'refs' => ['journey'], 'required' => ['journey'], 'properties' => ['reason' => 'enum:NO_SERVICE_COVERAGE|NO_RESTAURANTS_IN_CORRIDOR|NO_REACHABLE_RESTAURANTS'], 'privacy' => 'OPERATIONAL', 'dedupe' => 'one per journey'],
            'restaurant_impression' => ['source' => 'SERVER', 'stage' => null, 'trigger' => 'A restaurant was RETURNED in a discovery answer for a journey (returned, not proven seen on a screen).', 'refs' => ['journey', 'restaurant'], 'required' => ['journey', 'restaurant'], 'properties' => ['detour_band' => 'band'], 'privacy' => 'OPERATIONAL', 'dedupe' => 'one per journey per restaurant'],
            'pickup_slot_refused' => ['source' => 'SERVER', 'stage' => null, 'trigger' => 'The backend refused a pickup time.', 'refs' => ['restaurant'], 'required' => ['restaurant'], 'properties' => ['reason' => 'code'], 'privacy' => 'OPERATIONAL', 'dedupe' => 'event id'],
        ],
    ],

    /*
    | The anonymous session: a random identifier the application makes up. It is not a device identifier, it is not
    | derived from hardware, and it is replaced when it has been idle or has lived too long. The clients carry the
    | same two numbers (the registry document states them).
    */
    'session' => [
        'idle_minutes' => 30,
        'max_hours' => 12,
    ],

    /*
    | The canonical funnel. Stages 1–8 are behavioural events of ONE session, in this order; stages 9–11 are read from
    | the payment and order domains through the checkout the session opened.
    */
    'funnel' => [
        'stages' => [
            1 => ['key' => 'journey_search', 'label' => 'Journey search', 'event' => 'journey_search_started', 'source' => 'Behavioural event'],
            2 => ['key' => 'route_results', 'label' => 'Route results', 'event' => 'journey_results_viewed', 'source' => 'Behavioural event'],
            3 => ['key' => 'restaurant_viewed', 'label' => 'Restaurant viewed', 'event' => 'restaurant_viewed', 'source' => 'Behavioural event'],
            4 => ['key' => 'menu_viewed', 'label' => 'Menu viewed', 'event' => 'menu_viewed', 'source' => 'Behavioural event'],
            5 => ['key' => 'added_to_cart', 'label' => 'Added to cart', 'event' => 'item_added_to_cart', 'source' => 'Behavioural event'],
            6 => ['key' => 'pickup_selected', 'label' => 'Pickup selected', 'event' => 'pickup_selected', 'source' => 'Behavioural event'],
            7 => ['key' => 'checkout_started', 'label' => 'Checkout started', 'event' => 'checkout_started', 'source' => 'Behavioural event'],
            8 => ['key' => 'payment_started', 'label' => 'Payment started', 'event' => 'payment_started', 'source' => 'Behavioural event'],
            9 => ['key' => 'payment_verified', 'label' => 'Payment verified', 'event' => null, 'source' => 'Payment domain: a VERIFIED payment attempt of the session\'s checkout'],
            10 => ['key' => 'order_created', 'label' => 'Order created', 'event' => null, 'source' => 'Order domain: the order of the session\'s checkout'],
            11 => ['key' => 'order_completed', 'label' => 'Order completed', 'event' => null, 'source' => 'Order domain: that order is COMPLETED'],
        ],
        // A payment counts for the session when it was verified within this time of the checkout being opened.
        'conversion_window_hours' => 24,
        // Sessions are looked at again for this long, so that an order completed later still counts.
        'completion_window_days' => 7,
    ],

    /*
    | Bands. A journey, a detour and a distance are stored as a BAND, never as the number: [label, upper bound].
    | The last band has no upper bound.
    */
    'bands' => [
        'detour_minutes' => [['0-5', 5], ['5-10', 10], ['10-15', 15], ['15+', null]],
        'journey_km' => [['0-25', 25], ['25-50', 50], ['50-100', 100], ['100-200', 200], ['200+', null]],
        'journey_minutes' => [['0-30', 30], ['30-60', 60], ['60-120', 120], ['120-240', 240], ['240+', null]],
    ],

    /*
    | Privacy. The pseudonym of a customer is a keyed hash of the internal id — no analytics table holds a phone
    | number, an e-mail address, a name, an address, a coordinate or a route. A journey is known to analytics only
    | by a keyed hash of its id and by its bands and corridors.
    */
    'privacy' => [
        // Empty = derived from the application key. Changing it starts new pseudonyms.
        'pseudonym_key' => env('ANALYTICS_PSEUDONYM_KEY'),
        // Fine-grained route rows (a corridor, a city, a band) built on fewer journeys than this are not shown.
        'min_cohort' => (int) env('ANALYTICS_MIN_COHORT', 5),
        // A city is named for a journey's origin or destination only within this distance of the city's centre.
        'city_radius_km' => 40,
        'advertising_trackers' => false,
    ],

    /*
    | A rate is shown only when its denominator is at least this; under it the answer says "insufficient data".
    */
    'small_sample' => [
        'min_denominator' => (int) env('ANALYTICS_MIN_DENOMINATOR', 5),
    ],

    /*
    | Reporting periods. The time zone of a report is the market's own (markets.default_timezone); a restaurant's
    | report uses the restaurant's.
    */
    'periods' => [
        'presets' => ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'custom'],
        'default' => 'last_30_days',
        'max_range_days' => 366,
        // Up to this many days a chart is by hour / by day / by week; beyond the last, by month.
        'hourly_up_to_days' => 2,
        'daily_up_to_days' => 62,
        'weekly_up_to_days' => 182,
    ],

    /*
    | Aggregation. The read models are recomputed per (market, local day): a run deletes the rows of a day and writes
    | them again from the sources, so running twice never counts twice.
    */
    'aggregation' => [
        'scheduled' => (bool) env('ANALYTICS_AGGREGATION_SCHEDULED', true),
        'every_minutes' => 5,
        // Every run recomputes this many most recent local days (late events, orders still moving).
        'trailing_days' => 2,
        // Source rows changed this long before the last run are looked at again (clock skew, long transactions).
        'overlap_seconds' => 120,
        'groups' => ['orders', 'customers', 'restaurants', 'items', 'funnel', 'routes', 'payments', 'refunds', 'finance', 'support', 'notifications', 'pickup'],
        'queue' => env('ANALYTICS_QUEUE', 'default'),
    ],

    /*
    | Freshness — what a screen may say. Nothing aggregated is called "live".
    */
    'freshness' => [
        'aggregated' => ['mode' => 'AGGREGATED', 'label' => 'Updated about every 5 minutes'],
        'operational' => ['mode' => 'OPERATIONAL', 'label' => 'Read from the operational records now'],
        'ledger' => ['mode' => 'LEDGER', 'label' => 'Read from the financial ledger now'],
        // The aggregates are "stale" when the last successful run is older than this.
        'stale_after_minutes' => 20,
    ],

    /*
    | The cache holds ANSWERS of the reporting API for a short time — never a balance, and nothing that authorizes a
    | payout or any other action. The key carries the caller's scope.
    */
    'cache' => [
        'enabled' => (bool) env('ANALYTICS_CACHE_ENABLED', true),
        'seconds' => (int) env('ANALYTICS_CACHE_SECONDS', 60),
    ],

    /*
    | Exports: curated CSV reports, built by a queued job into PRIVATE storage and handed out only to the person who
    | asked, while they still hold the permission, until the file expires.
    */
    'exports' => [
        'disk' => env('ANALYTICS_EXPORT_DISK', 'local'),
        'directory' => 'analytics-exports',
        'expiry_hours' => (int) env('ANALYTICS_EXPORT_EXPIRY_HOURS', 24),
        'max_rows' => (int) env('ANALYTICS_EXPORT_MAX_ROWS', 50000),
        'max_range_days' => 366,
        // One person may have this many exports waiting or being built.
        'max_pending_per_requester' => 5,
        'queue' => env('ANALYTICS_QUEUE', 'default'),
        /*
        | The curated reports. `permissions` are needed IN ADDITION to the export permission of the audience.
        */
        'reports' => [
            'orders' => ['audiences' => ['ADMIN', 'RESTAURANT'], 'label' => 'Orders', 'admin_permissions' => [], 'restaurant_permissions' => []],
            'payments' => ['audiences' => ['ADMIN'], 'label' => 'Payments', 'admin_permissions' => ['admin.payments.view'], 'restaurant_permissions' => []],
            'refunds' => ['audiences' => ['ADMIN'], 'label' => 'Refunds', 'admin_permissions' => ['admin.refunds.view'], 'restaurant_permissions' => []],
            'restaurant_performance' => ['audiences' => ['ADMIN'], 'label' => 'Restaurant performance', 'admin_permissions' => [], 'restaurant_permissions' => []],
            'customer_funnel' => ['audiences' => ['ADMIN'], 'label' => 'Customer funnel', 'admin_permissions' => [], 'restaurant_permissions' => []],
            'route_performance' => ['audiences' => ['ADMIN'], 'label' => 'Route performance', 'admin_permissions' => [], 'restaurant_permissions' => []],
            'support_cases' => ['audiences' => ['ADMIN'], 'label' => 'Support cases', 'admin_permissions' => ['admin.support.view'], 'restaurant_permissions' => []],
            'financial_summary' => ['audiences' => ['ADMIN'], 'label' => 'Financial summary', 'admin_permissions' => ['admin.finance.view', 'admin.finance.export'], 'restaurant_permissions' => []],
            'menu_items' => ['audiences' => ['RESTAURANT'], 'label' => 'Menu items', 'admin_permissions' => [], 'restaurant_permissions' => []],
        ],
    ],

    /*
    | Retention. Raw behavioural events are short-lived; the aggregates built from them stay.
    */
    'retention' => [
        'raw_event_days' => (int) env('ANALYTICS_RAW_EVENT_DAYS', 180),
        'session_days' => (int) env('ANALYTICS_SESSION_DAYS', 400),
        'run_days' => 30,
        'quality_days' => 30,
        'expired_export_days' => 30,
    ],

    /*
    | Data quality: what the checks tolerate before they say WARNING.
    */
    'quality' => [
        'scheduled' => (bool) env('ANALYTICS_QUALITY_SCHEDULED', true),
        // A verified payment that has no order after this many minutes is reported.
        'payment_without_order_minutes' => 30,
        // Share of behavioural events without a market that is still HEALTHY.
        'unknown_market_share' => 0.05,
        // Share of sessions with an out-of-order stage that is still HEALTHY.
        'invalid_transition_share' => 0.10,
    ],

    'metrics' => [
        'retention_hours' => (int) env('ANALYTICS_METRICS_RETENTION_HOURS', 48),
    ],

    'production' => 'NO',
];
