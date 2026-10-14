<?php

/*
|--------------------------------------------------------------------------
| Identity and session security
|--------------------------------------------------------------------------
|
| Policy values for the three principal types. Everything is environment
| driven so staging / production can be stricter than local development.
|
*/

return [

    /*
    | Access-token lifetime in minutes. A token is one signed-in device; it is
    | stored hashed, can be revoked at any time and stops working at expiry.
    */

    'token_ttl_minutes' => [
        'customer' => (int) env('AUTH_TOKEN_TTL_CUSTOMER_MINUTES', 43200),
        'restaurant' => (int) env('AUTH_TOKEN_TTL_RESTAURANT_MINUTES', 720),
        'admin' => (int) env('AUTH_TOKEN_TTL_ADMIN_MINUTES', 480),
    ],

    /*
    | Restaurant / admin passwords: length over composition rules.
    */

    /*
    | Hours an administrator invitation link stays valid. It is single use; sending a new one cancels the old.
    */

    'invitation_ttl_hours' => (int) env('AUTH_INVITATION_TTL_HOURS', 72),

    'password' => [
        'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
        'reset_ttl_minutes' => (int) env('AUTH_PASSWORD_RESET_TTL_MINUTES', 30),
    ],

    /*
    | Multi-factor authentication (TOTP authenticator app + recovery codes).
    | When "required" is true for a principal type, an account without MFA can
    | sign in only to enrol: its token cannot use the platform until it does.
    */

    // Module 39: failures counted per ACCOUNT inside a window; at the limit that step is refused for lock_minutes.
    'lockout' => [
        'password' => ['failures' => (int) env('AUTH_LOCKOUT_PASSWORD_FAILURES', 10), 'window_minutes' => 15, 'lock_minutes' => (int) env('AUTH_LOCKOUT_PASSWORD_MINUTES', 15)],
        'mfa' => ['failures' => (int) env('AUTH_LOCKOUT_MFA_FAILURES', 10), 'window_minutes' => 60, 'lock_minutes' => (int) env('AUTH_LOCKOUT_MFA_MINUTES', 30)],
    ],

    // Module 39: an administrator's token that was not used for this long is revoked on its next use (0 = off).
    'admin_idle_minutes' => (int) env('AUTH_ADMIN_IDLE_MINUTES', 60),

    'mfa' => [
        'required' => [
            'admin' => (bool) env('AUTH_MFA_REQUIRED_ADMIN', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),   // Module 39: on by default everywhere except local development and the test suite
            'restaurant' => (bool) env('AUTH_MFA_REQUIRED_RESTAURANT', false),
        ],
        'challenge_ttl_seconds' => (int) env('AUTH_MFA_CHALLENGE_TTL_SECONDS', 300),
        'challenge_max_attempts' => (int) env('AUTH_MFA_CHALLENGE_MAX_ATTEMPTS', 5),
        'recovery_codes' => 8,
    ],

    /*
    | Password given to the local fixture accounts by LocalFixtureSeeder. Empty
    | means "do not create fixture accounts". Never set outside local / testing.
    */

    'fixture_password' => env('LOCAL_FIXTURE_PASSWORD'),

    /*
    | Optional password source for `php artisan admin:create` when it runs
    | unattended. Leave empty to be asked at a hidden prompt.
    */

    'bootstrap_password' => env('ADMIN_BOOTSTRAP_PASSWORD'),

];
