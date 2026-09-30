<?php

/*
|--------------------------------------------------------------------------
| Rate limits (requests per minute)
|--------------------------------------------------------------------------
|
| Named limiters are registered in AppServiceProvider and applied with the
| `throttle:<name>` middleware. The values below are development defaults,
| not production decisions: tune them per environment through the env.
|
*/

return [
    'api' => (int) env('RATE_LIMIT_API', 120),
    'auth_ip' => (int) env('RATE_LIMIT_AUTH_IP', 10),
    'auth_identity' => (int) env('RATE_LIMIT_AUTH_IDENTITY', 5),
    'password_reset' => (int) env('RATE_LIMIT_PASSWORD_RESET', 3),
    'otp_ip' => (int) env('RATE_LIMIT_OTP_IP', 10),
    'otp_phone' => (int) env('RATE_LIMIT_OTP_PHONE', 3),
    'search' => (int) env('RATE_LIMIT_SEARCH', 60),
    'payment' => (int) env('RATE_LIMIT_PAYMENT', 10),
    'admin_sensitive' => (int) env('RATE_LIMIT_ADMIN_SENSITIVE', 20),
];
