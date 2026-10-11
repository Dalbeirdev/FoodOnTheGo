<?php

/*
|--------------------------------------------------------------------------
| Notifications (Module 33)
|--------------------------------------------------------------------------
|
| The notification domain: which domain events become customer notifications, over which channels, under which
| preference rule, with which template — plus the providers, the retry policy and the retention. Nothing here is a
| market fact. A notification never changes an order.
|
*/

return [

    /*
    | Channels and their providers. A channel whose driver is `off` is disabled platform-wide (policy result
    | SKIP_CHANNEL_DISABLED). PUSH: `fcm` (FCM HTTP v1 with a service account, FIREBASE_CREDENTIALS = path of the
    | git-ignored JSON key) or `fake` (deterministic, local and tests). EMAIL: `mail` (Laravel's mailer — MAIL_MAILER=log
    | locally, a real transport is PENDING CONFIGURATION) or `fake`. SMS: `fake` or `off` — no SMS provider is approved for
    | notifications yet (the OTP provider of Module 21 is a separate policy and is never reused here).
    */

    'channels' => [
        'PUSH' => ['driver' => env('PUSH_DRIVER', 'fake')],
        'EMAIL' => ['driver' => env('EMAIL_NOTIFICATIONS_DRIVER', 'mail'), 'require_verified' => true],
        'SMS' => ['driver' => env('SMS_NOTIFICATIONS_DRIVER', 'off')],
        'IN_APP' => ['driver' => 'database'],
    ],

    'fcm' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),            // absolute path of the service-account JSON; never committed
        'timeout_seconds' => (float) env('FCM_TIMEOUT_SECONDS', 10),
        'endpoint' => env('FCM_ENDPOINT', 'https://fcm.googleapis.com/v1'),
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'max_payload_bytes' => 4096,                             // FCM HTTP v1 limit for a token message
        'ttl_seconds' => (int) env('FCM_TTL_SECONDS', 3600),     // an order notice older than an hour is stale
        'restricted_package_name' => env('FCM_RESTRICTED_PACKAGE_NAME'),
    ],

    'fake' => [
        // The fake push provider answers from the token itself: a token containing these words simulates that outcome.
        'unregistered_marker' => 'unregistered',
        'transient_marker' => 'transient',
        'quota_marker' => 'quota',
        'auth_marker' => 'authfail',
        'keep_last' => 50,                                      // messages kept in the cache for local inspection
    ],

    /*
    | The registry: domain event → notification type. `channels` lists the channels the type may use; `policy` per channel:
    | mandatory (always, when a destination exists), preference (the Module 25 matrix decides), off (never for this type).
    | `priority` is the push priority (HIGH only for time-critical notices). `target` is the allow-listed action target.
    */

    'types' => [
        'order.created' => ['category' => 'ORDER_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'ORDER_DETAIL', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'preference', 'SMS' => 'off']],
        'order.accepted' => ['category' => 'ORDER_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'ORDER_TRACKING', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'off', 'SMS' => 'off']],
        'order.rejected' => ['category' => 'ORDER_UPDATES', 'subject' => 'ORDER', 'priority' => 'HIGH', 'target' => 'ORDER_DETAIL', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'preference', 'SMS' => 'off']],
        'order.preparing' => ['category' => 'ORDER_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'ORDER_TRACKING', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'off', 'EMAIL' => 'off', 'SMS' => 'off']],
        'order.ready' => ['category' => 'PICKUP_UPDATES', 'subject' => 'ORDER', 'priority' => 'HIGH', 'target' => 'ORDER_TRACKING', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'off', 'SMS' => 'preference']],
        'order.picked_up' => ['category' => 'PICKUP_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'ORDER_DETAIL', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'off', 'EMAIL' => 'off', 'SMS' => 'off']],
        'order.completed' => ['category' => 'ORDER_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'ORDER_DETAIL', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'off', 'EMAIL' => 'preference', 'SMS' => 'off']],
        'order.cancelled' => ['category' => 'ORDER_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'ORDER_DETAIL', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'preference', 'SMS' => 'off']],
        'refund.pending' => ['category' => 'PAYMENT_UPDATES', 'subject' => 'ORDER', 'priority' => 'NORMAL', 'target' => 'PAYMENT_STATUS', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'preference', 'SMS' => 'off']],
        'payment.recovery_required' => ['category' => 'PAYMENT_UPDATES', 'subject' => 'ORDER', 'priority' => 'HIGH', 'target' => 'PAYMENT_STATUS', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'preference', 'EMAIL' => 'preference', 'SMS' => 'off']],
        'account.phone_changed' => ['category' => 'ACCOUNT_SECURITY', 'subject' => 'ACCOUNT', 'priority' => 'HIGH', 'target' => 'ACCOUNT_SECURITY', 'channels' => ['IN_APP' => 'mandatory', 'PUSH' => 'mandatory', 'EMAIL' => 'mandatory', 'SMS' => 'off']],
    ],

    // Domain event name → notification type (the Module 31 / 32 OrderEvent names; events not listed notify nobody).
    'order_events' => [
        'order.created' => 'order.created',
        'order.restaurant_accepted' => 'order.accepted',
        'order.restaurant_rejected' => 'order.rejected',
        'order.preparing' => 'order.preparing',
        'order.ready' => 'order.ready',
        'order.picked_up' => 'order.picked_up',
        'order.completed' => 'order.completed',
        'order.cancelled' => 'order.cancelled',
        'order.refund_required' => 'refund.pending',
        'order.recovery_required' => 'payment.recovery_required',
        'order.fulfillment_failed' => 'payment.recovery_required',
    ],

    // Security events (Module 21 / 25 recorder) → notification type, customers only.
    'security_events' => [
        'PHONE_CHANGED' => 'account.phone_changed',
    ],

    // Android notification channels (stable ids; the app creates them) per category.
    'android_channels' => [
        'ORDER_UPDATES' => 'orders',
        'PICKUP_UPDATES' => 'pickup',
        'PAYMENT_UPDATES' => 'orders',
        'ACCOUNT_SECURITY' => 'account',
        'PROMOTIONS' => 'offers',
        'PRODUCT_UPDATES' => 'offers',
    ],

    'default_locale' => env('NOTIFICATIONS_DEFAULT_LOCALE', 'en-IN'),

    'limits' => [
        'push_title' => 60,
        'push_body' => 180,
        'in_app_title' => 120,
        'in_app_body' => 400,
        'sms_body' => 160,
        'page_size' => 20,
        'max_page_size' => 50,
        'devices_per_customer' => 10,
    ],

    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    'retry' => [
        'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 5),
        'base_seconds' => (int) env('NOTIFICATIONS_RETRY_BASE_SECONDS', 30),   // 30 s, 60 s, 120 s, 240 s (+ jitter)
        'max_seconds' => (int) env('NOTIFICATIONS_RETRY_MAX_SECONDS', 900),
        'rate_limited_min_seconds' => 60,                                      // FCM: minimum initial delay 1 minute for 429
        'sending_lease_seconds' => (int) env('NOTIFICATIONS_SENDING_LEASE_SECONDS', 120),
        'auth_failure_pause_seconds' => (int) env('NOTIFICATIONS_AUTH_PAUSE_SECONDS', 300),
    ],

    'retention' => [
        'notifications_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 90),
        'security_notifications_days' => (int) env('NOTIFICATIONS_SECURITY_RETENTION_DAYS', 365),
        'deliveries_days' => (int) env('NOTIFICATIONS_DELIVERY_RETENTION_DAYS', 30),
        'revoked_devices_days' => (int) env('NOTIFICATIONS_REVOKED_DEVICE_RETENTION_DAYS', 30),
    ],

    'metrics' => [
        'retention_hours' => (int) env('NOTIFICATIONS_METRICS_RETENTION_HOURS', 48),
    ],

    // The admin test send exists only where this is true (never in production).
    'admin_test_enabled' => env('NOTIFICATIONS_ADMIN_TEST_ENABLED', env('APP_ENV') !== 'production'),

];
