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

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'key' => env('SMS_API_KEY'),
        'sender' => env('SMS_SENDER_ID'),
        'timeout' => (int) env('SMS_TIMEOUT', 10),
    ],

    'maps' => [
        'driver' => env('MAPS_DRIVER'),
        'browser_key' => env('MAPS_BROWSER_KEY'),
    ],

    'places' => [
        'driver' => env('PLACES_DRIVER'),
        'key' => env('PLACES_API_KEY'),
        'timeout' => (int) env('PLACES_TIMEOUT', 5),
    ],

    'routing' => [
        'driver' => env('ROUTING_DRIVER'),
        'key' => env('ROUTING_API_KEY'),
        'timeout' => (int) env('ROUTING_TIMEOUT', 8),
    ],

    'payment' => [
        'driver' => env('PAYMENT_DRIVER'),
        'key_id' => env('PAYMENT_KEY_ID'),
        'key_secret' => env('PAYMENT_KEY_SECRET'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'timeout' => (int) env('PAYMENT_TIMEOUT', 15),
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
