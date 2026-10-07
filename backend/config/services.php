<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FoodOnTheGo providers
    |--------------------------------------------------------------------------
    |
    | Every external provider sits behind a contract and is selected by a
    | driver name. Keys stay server-side and are empty until the module that
    | integrates the provider. "timeout" values are seconds.
    |
    */

    /*
    | SMS (one-time codes). driver: log (sends nothing; local / testing only), msg91, twofactor, twilio.
    | fallback: optional second driver used when the first cannot deliver.
    | FoodOnTheGo generates and verifies the code itself; the provider only delivers it.
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'fallback' => env('SMS_FALLBACK_DRIVER'),
        'timeout' => (int) env('SMS_TIMEOUT', 10),

        'msg91' => [
            'auth_key' => env('SMS_MSG91_AUTH_KEY'),
            'otp_template_id' => env('SMS_MSG91_OTP_TEMPLATE_ID'),
        ],

        'twofactor' => [
            'api_key' => env('SMS_2FACTOR_API_KEY'),
            'template' => env('SMS_2FACTOR_TEMPLATE'),
        ],

        'twilio' => [
            'account_sid' => env('SMS_TWILIO_ACCOUNT_SID'),
            'auth_token' => env('SMS_TWILIO_AUTH_TOKEN'),
            'messaging_service_sid' => env('SMS_TWILIO_MESSAGING_SERVICE_SID'),
            'from' => env('SMS_TWILIO_FROM'),
        ],
    ],

    /*
    | WhatsApp Cloud API (Meta) for one-time codes. Enabled by listing "whatsapp" in OTP_CHANNELS and
    | filling these. otp_template is an approved AUTHENTICATION template with a copy-code button.
    */

    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'otp_template' => env('WHATSAPP_OTP_TEMPLATE'),
        'otp_template_language' => env('WHATSAPP_OTP_TEMPLATE_LANGUAGE', 'en'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
    ],

    /*
    | Truecaller one-tap verification (Android). Enabled when client_id is set.
    */

    'truecaller' => [
        'client_id' => env('TRUECALLER_CLIENT_ID'),
        'base_url' => env('TRUECALLER_BASE_URL', 'https://oauth-account-noneu.truecaller.com'),
        'timeout' => (int) env('TRUECALLER_TIMEOUT', 8),
    ],

    'maps' => [
        'driver' => env('MAPS_DRIVER'),
        'browser_key' => env('MAPS_BROWSER_KEY'),
    ],

    // Module 26: PLACES_DRIVER / ROUTING_DRIVER = fake (development, refused in production) | google. Keys are server-side only.
    'places' => [
        'driver' => env('PLACES_DRIVER'),
        'key' => env('PLACES_API_KEY'),
        'timeout' => (int) env('PLACES_TIMEOUT', 5),
        'base_url' => env('PLACES_BASE_URL', 'https://places.googleapis.com/v1'),
    ],

    'routing' => [
        'driver' => env('ROUTING_DRIVER'),
        'key' => env('ROUTING_API_KEY'),
        'timeout' => (int) env('ROUTING_TIMEOUT', 8),
        'base_url' => env('ROUTING_BASE_URL', 'https://routes.googleapis.com'),
    ],

    // Which payment provider is used: "razorpay", "fake" (never in production) or nothing (payments unavailable).
    'payment' => [
        'driver' => env('PAYMENT_DRIVER'),
        'timeout' => (int) env('PAYMENT_TIMEOUT', 15),
    ],

    // Razorpay — the initial provider for India (Module 30: TEST MODE ONLY). The key id is the only value a browser
    // or an app may see; the key secret and the webhook secret never leave the server.
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'mode' => env('RAZORPAY_MODE', 'test'),
        'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
        'checkout_script' => env('RAZORPAY_CHECKOUT_SCRIPT', 'https://checkout.razorpay.com/v1/checkout.js'),
        // "automatic": every order asks for automatic capture. "dashboard": the account's own setting decides.
        'capture' => [
            'mode' => env('RAZORPAY_CAPTURE', 'automatic'),
            'automatic_expiry_minutes' => (int) env('RAZORPAY_CAPTURE_AUTOMATIC_EXPIRY_MINUTES', 12),
            'manual_expiry_minutes' => (int) env('RAZORPAY_CAPTURE_MANUAL_EXPIRY_MINUTES', 7200),
            'refund_speed' => env('RAZORPAY_CAPTURE_REFUND_SPEED', 'normal'),
        ],
        // Method types to name in the application. Empty: the provider's own interface decides what the account offers.
        'methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('RAZORPAY_METHODS', ''))))),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS_PATH'),
    ],

    'websocket' => [
        'driver' => env('WEBSOCKET_DRIVER'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
