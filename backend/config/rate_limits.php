<?php

/*
|--------------------------------------------------------------------------
| Rate limits (requests per minute)
|--------------------------------------------------------------------------
|
| Named limiters are registered in AppServiceProvider and applied with the
| `throttle:<name>` middleware. The values below are development defaults,
| not production decisions: tune them per environment through the env.
| Admin sign-in is deliberately tighter than restaurant sign-in.
|
*/

return [
    'api' => (int) env('RATE_LIMIT_API', 120),

    'otp_ip' => (int) env('RATE_LIMIT_OTP_IP', 10),
    'otp_phone' => (int) env('RATE_LIMIT_OTP_PHONE', 3),
    'otp_verify_ip' => (int) env('RATE_LIMIT_OTP_VERIFY_IP', 20),
    'otp_verify_challenge' => (int) env('RATE_LIMIT_OTP_VERIFY_CHALLENGE', 10),

    'staff_login_ip' => (int) env('RATE_LIMIT_STAFF_LOGIN_IP', 20),
    'staff_login_identity' => (int) env('RATE_LIMIT_STAFF_LOGIN_IDENTITY', 5),
    'admin_login_ip' => (int) env('RATE_LIMIT_ADMIN_LOGIN_IP', 10),
    'admin_login_identity' => (int) env('RATE_LIMIT_ADMIN_LOGIN_IDENTITY', 3),
    'mfa_verify' => (int) env('RATE_LIMIT_MFA_VERIFY', 10),
    'password_reset' => (int) env('RATE_LIMIT_PASSWORD_RESET', 5),

    'search' => (int) env('RATE_LIMIT_SEARCH', 60),
    'payment' => (int) env('RATE_LIMIT_PAYMENT', 10),
    'admin_sensitive' => (int) env('RATE_LIMIT_ADMIN_SENSITIVE', 20),
];
