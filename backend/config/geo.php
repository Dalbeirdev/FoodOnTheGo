<?php

/*
|--------------------------------------------------------------------------
| Geography
|--------------------------------------------------------------------------
|
| Spatial standard: WGS84 / SRID 4326 everywhere. Distances are metres.
|
*/

return [

    /*
    | Hard limit on the number of positions in one submitted geometry. Protects the API and PostGIS from
    | oversized or pathological payloads. A detailed city boundary is a few thousand positions.
    */

    'max_positions' => (int) env('GEO_MAX_POSITIONS', 5000),

    /*
    | A point with no covering service area is attributed to the nearest known city within this distance,
    | so the answer can say "the city is not open yet" instead of only "outside the service area".
    */

    'city_match_radius_meters' => (int) env('GEO_CITY_MATCH_RADIUS_METERS', 40000),

    /*
    | Seconds the public market configuration is cached. Every administrative change invalidates it at once.
    */

    'market_cache_seconds' => (int) env('GEO_MARKET_CACHE_SECONDS', 300),

];
