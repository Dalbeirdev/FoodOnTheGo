<?php

/*
|--------------------------------------------------------------------------
| One-time passwords (customer phone sign-in)
|--------------------------------------------------------------------------
|
| Policy values for the OTP service built in the identity module. Codes are
| stored hashed, expire quickly, allow few attempts and are never logged.
|
*/

return [
    'length' => (int) env('OTP_LENGTH', 6),
    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 30),
    'max_sends_per_hour' => (int) env('OTP_MAX_SENDS_PER_HOUR', 5),

    /*
    | Text of the SMS for providers that take free text (Twilio). :code and :minutes are replaced.
    | In India it must match the DLT-registered template word for word. Template-based providers
    | (MSG91, 2Factor) keep the text in their own panel and ignore this.
    */

    'sms_template' => env('OTP_SMS_TEMPLATE', 'Your FoodOnTheGo verification code is :code. It expires in :minutes minutes. Do not share it with anyone.'),

    /*
    | A fixed development code is honoured only in the environments listed here,
    | only when OTP_DEV_CODE is set and only while SMS_DRIVER=log (nothing is
    | sent). With a real SMS driver the code is always random, also locally.
    | App\Services\Auth\DevelopmentOtp refuses "production" and "staging" even
    | if they are added to the list.
    */

    'development' => [
        'environments' => ['local', 'testing'],
        'code' => env('OTP_DEV_CODE'),
    ],
];
