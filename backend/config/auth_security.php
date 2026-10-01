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

    // Languages of the security notices sent to restaurant and admin users (MFA changed). Staff accounts have no
    // language preference yet, so a notice carries every language listed here, in this order; the subject uses
    // the first. A language without a translation is skipped.
    'notice_locales' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTH_NOTICE_LOCALES', 'en'))))),

    'password' => [
        'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
        'reset_ttl_minutes' => (int) env('AUTH_PASSWORD_RESET_TTL_MINUTES', 30),
    ],

    /*
    | Multi-factor authentication (TOTP authenticator app + recovery codes).
    | When "required" is true for a principal type, an account without MFA can
    | sign in only to enrol: its token cannot use the platform until it does.
    */

    'mfa' => [
        'required' => [
            'admin' => (bool) env('AUTH_MFA_REQUIRED_ADMIN', false),
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
