<?php

/*
|--------------------------------------------------------------------------
| Journeys, routing and route-based restaurant discovery (Module 26)
|--------------------------------------------------------------------------
|
| Policy and bounds for journey planning. Provider credentials live in
| config/services.php (places, routing) and come from the environment only.
| Distances are metres and durations seconds internally; clients convert.
| Nothing here is a market fact: a market may override the corridor width,
| the detour limit and the candidate cap within the platform bounds below
| (market_configurations.journey).
|
*/

return [

    'providers' => [
        /*
        | The deterministic fake providers exist for local development and automated tests. They are refused
        | wherever this is false — production must never silently plan journeys on synthetic roads.
        */
        'allow_fake' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) && ! in_array(env('JOURNEY_ALLOW_FAKE_PROVIDERS'), [false, 'false', '0', 0, 'off', 'no'], true),   // Module 39: an allow-list of environments

        /*
        | Bounded retries for TRANSIENT provider failures only (timeouts, 5xx, connection errors): `attempts`
        | is the total number of tries; the wait grows with each try plus a random jitter.
        */
        'retry' => [
            'attempts' => (int) env('JOURNEY_PROVIDER_RETRY_ATTEMPTS', 2),
            'backoff_ms' => (int) env('JOURNEY_PROVIDER_RETRY_BACKOFF_MS', 200),
            'jitter_ms' => (int) env('JOURNEY_PROVIDER_RETRY_JITTER_MS', 150),
        ],

        /*
        | Waypoints per routing-provider matrix request (one detour batch). Google's route matrix accepts up to
        | 625 elements per call (100 with traffic); a batch of 25 waypoints is 25 elements per call here.
        */
        'detour_batch_size' => (int) env('JOURNEY_DETOUR_BATCH_SIZE', 25),

        /*
        | Minutes of recent calls that decide the health state shown to administrators.
        */
        'health_window_minutes' => (int) env('JOURNEY_PROVIDER_HEALTH_WINDOW_MINUTES', 10),

        'google' => [
            'polyline_quality' => env('JOURNEY_GOOGLE_POLYLINE_QUALITY', 'HIGH_QUALITY'),
        ],
    ],

    /*
    | Dynamic search corridor around the customer's own route (metres). The default applies when the market
    | configuration sets nothing; the customer may widen or narrow within the bounds. This is NOT the static
    | operational route corridor of Module 22.
    */
    'corridor' => [
        'default_width_meters' => (int) env('JOURNEY_CORRIDOR_DEFAULT_METERS', 3000),
        'min_width_meters' => (int) env('JOURNEY_CORRIDOR_MIN_METERS', 500),
        'max_width_meters' => (int) env('JOURNEY_CORRIDOR_MAX_METERS', 10000),
    ],

    /*
    | PostGIS shortlist bounds. `shortlist_cap` is the most restaurants ever sent to the routing provider for one
    | journey. The route is cut into segments of `segment_length_meters`; a segment keeps at most
    | max(`per_segment_cap`, shortlist_cap / number of segments) candidates, taken round robin across segments, so
    | the whole route is represented. `sql_limit` is only a safety valve for a pathologically dense corridor.
    */
    'candidates' => [
        'sql_limit' => (int) env('JOURNEY_CANDIDATE_SQL_LIMIT', 2000),
        'shortlist_cap' => (int) env('JOURNEY_SHORTLIST_CAP', 40),
        'shortlist_cap_max' => 100,
        'segment_length_meters' => (int) env('JOURNEY_SEGMENT_LENGTH_METERS', 25000),
        'per_segment_cap' => (int) env('JOURNEY_PER_SEGMENT_CAP', 8),
    ],

    /*
    | Actual detour (origin → restaurant → destination minus the base route), minutes.
    */
    'detour' => [
        'default_max_minutes' => (int) env('JOURNEY_DETOUR_DEFAULT_MAX_MINUTES', 15),
        'min_max_minutes' => 1,
        'max_max_minutes' => (int) env('JOURNEY_DETOUR_MAX_MAX_MINUTES', 60),
    ],

    /*
    | A journey is a point-in-time search context: it expires, and expired rows are removed by journeys:prune
    | after a grace period during which the API still answers "expired" instead of "not found".
    */
    'ttl_minutes' => (int) env('JOURNEY_TTL_MINUTES', 30),
    'prune_grace_minutes' => (int) env('JOURNEY_PRUNE_GRACE_MINUTES', 60),

    /*
    | Departure time: absent = now. A departure slightly in the past (clock skew) is treated as now; one further
    | in the past is refused, as is one too far ahead.
    */
    'departure' => [
        'past_tolerance_minutes' => (int) env('JOURNEY_DEPARTURE_PAST_TOLERANCE_MINUTES', 10),
        'max_days_ahead' => (int) env('JOURNEY_DEPARTURE_MAX_DAYS_AHEAD', 14),
    ],

    /*
    | Route geometry: positions above `max_points` are simplified (topology preserving) for the corridor search;
    | the provider's full line is still stored for display up to the hard cap of geo.max_positions.
    */
    'geometry' => [
        'max_points' => (int) env('JOURNEY_GEOMETRY_MAX_POINTS', 2000),
        'simplify_tolerance_meters' => (int) env('JOURNEY_GEOMETRY_SIMPLIFY_METERS', 25),
        'same_point_meters' => (int) env('JOURNEY_SAME_POINT_METERS', 50),
        // The corridor search cuts the route into compact pieces (vertices at most this far apart, a few vertices per
        // piece) so each spatial-index probe has a tight bounding box, whatever the direction of the route.
        'piece_segment_meters' => (int) env('JOURNEY_GEOMETRY_PIECE_SEGMENT_METERS', 2000),
        'piece_max_vertices' => (int) env('JOURNEY_GEOMETRY_PIECE_MAX_VERTICES', 5),
    ],

    /*
    | Seconds. Route plans are cached under a privacy-safe hash (rounded coordinates, provider, preferences and
    | departure bucket — never the places themselves); discovery results per journey + filters, so paging never
    | recalculates detours. Restaurant status is re-checked on every page read regardless.
    */
    'cache' => [
        'route_seconds' => (int) env('JOURNEY_ROUTE_CACHE_SECONDS', 300),
        'results_seconds' => (int) env('JOURNEY_RESULTS_CACHE_SECONDS', 300),
        'places_seconds' => (int) env('JOURNEY_PLACES_CACHE_SECONDS', 60),
        // One computation per journey + candidate set at a time: concurrent first reads wait for it (never longer than
        // `compute_wait_seconds`, after which they compute themselves rather than fail).
        'compute_lock_seconds' => (int) env('JOURNEY_COMPUTE_LOCK_SECONDS', 30),
        'compute_wait_seconds' => (int) env('JOURNEY_COMPUTE_WAIT_SECONDS', 10),
    ],

    'pagination' => [
        'page_size' => (int) env('JOURNEY_PAGE_SIZE', 10),
        'max_page_size' => (int) env('JOURNEY_MAX_PAGE_SIZE', 25),
    ],

    'eligibility' => [
        // A restaurant without an ACTIVE menu cannot take an order, so it is not a journey candidate.
        'require_active_menu' => filter_var(env('JOURNEY_REQUIRE_ACTIVE_MENU', true), FILTER_VALIDATE_BOOL),
    ],

    'places' => [
        'max_results' => (int) env('JOURNEY_PLACES_MAX_RESULTS', 6),
        'min_query_length' => 2,
    ],

    /*
    | Signed-in customers get the PLACES they searched for and chose recorded in their Module 25 recent places
    | (bounded, deduplicated, clearable, pruned). A raw position — the device's "current location" or a pin — and a
    | saved place are used for the journey and never copied there. Guests never leave a trace beyond the expiring
    | journey itself.
    */
    'record_recent_locations' => filter_var(env('JOURNEY_RECORD_RECENT_LOCATIONS', true), FILTER_VALIDATE_BOOL),

    /*
    | Places resolved by a LIVE provider are not copied into recent places until the provider's storage terms have been
    | confirmed for it (provider content must not be assumed storable); the development provider's places are. The
    | journey itself only lives for `ttl_minutes`.
    */
    'record_recent_live_places' => filter_var(env('JOURNEY_RECORD_RECENT_LIVE_PLACES', false), FILTER_VALIDATE_BOOL),

];
