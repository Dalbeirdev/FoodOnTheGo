<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URLS', '')))),

    'allowed_origins_patterns' => [],

    // X-Journey-Token: a guest's own journey is read with the token issued when it was planned (Module 26) — a header, never a URL.
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-Id', 'X-Requested-With', 'X-Journey-Token', 'X-Cart-Token'],

    // Content-Disposition: a CSV download's file name has to be readable by the web application (Module 36).
    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'Idempotency-Replayed', 'Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => true,

];
