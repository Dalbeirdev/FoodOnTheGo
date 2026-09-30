<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default market
    |--------------------------------------------------------------------------
    |
    | ISO 3166-1 alpha-2 code of the market used when a request does not name
    | one. Everything else about a market (currency, locale, time zone, units,
    | features, status) is data in the markets table — never configuration or
    | code.
    |
    */

    'default_country' => env('MARKET_DEFAULT_COUNTRY', 'IN'),

];
