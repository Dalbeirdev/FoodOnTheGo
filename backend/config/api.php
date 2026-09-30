<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Defaults for every collection endpoint (App\Http\Support\ListQuery).
    |
    */

    'pagination' => [
        'default_size' => (int) env('API_PAGE_SIZE', 25),
        'max_size' => (int) env('API_PAGE_SIZE_MAX', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | How long an Idempotency-Key stays bound to its first request.
    |
    */

    'idempotency' => [
        'ttl_minutes' => (int) env('API_IDEMPOTENCY_TTL_MINUTES', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Readiness diagnostics
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/ready lists each dependency. It is public only when this is
    | true (local / testing); otherwise it requires admin.system.view.
    |
    */

    'readiness' => [
        'public' => (bool) env('API_READINESS_PUBLIC', false),
    ],

];
