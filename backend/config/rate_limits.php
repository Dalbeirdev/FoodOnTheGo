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

    'availability' => (int) env('RATE_LIMIT_AVAILABILITY', 30),
    'search' => (int) env('RATE_LIMIT_SEARCH', 60),
    'payment' => (int) env('RATE_LIMIT_PAYMENT', 10),
    'admin_sensitive' => (int) env('RATE_LIMIT_ADMIN_SENSITIVE', 20),

    // Journeys (Module 26): place search and planning call external providers. journey_detours = how often per minute ONE
    // journey may send a shortlist to the routing provider (calculated results are reused and only count as reads).
    'places_search' => (int) env('RATE_LIMIT_PLACES_SEARCH', 60),
    'places_search_guest' => (int) env('RATE_LIMIT_PLACES_SEARCH_GUEST', 30),
    'journey_plan' => (int) env('RATE_LIMIT_JOURNEY_PLAN', 12),
    'journey_plan_guest' => (int) env('RATE_LIMIT_JOURNEY_PLAN_GUEST', 6),
    'journey_detours' => (int) env('RATE_LIMIT_JOURNEY_DETOURS', 12),
    'journey_read' => (int) env('RATE_LIMIT_JOURNEY_READ', 120),
    // Carts (Module 27): normal use must stay responsive — these only stop obvious abuse. cart_create_guest = how many NEW
    // anonymous carts one address may start per minute (a signed-in customer has one cart per account anyway).
    'cart_read' => (int) env('RATE_LIMIT_CART_READ', 120),
    'cart_write' => (int) env('RATE_LIMIT_CART_WRITE', 60),
    'cart_write_guest' => (int) env('RATE_LIMIT_CART_WRITE_GUEST', 40),
    'cart_create_guest' => (int) env('RATE_LIMIT_CART_CREATE_GUEST', 10),

    // Checkout (Module 29), per signed-in customer. Trying promotion codes is the tightest: a code is a secret of sorts.
    'checkout_read' => (int) env('RATE_LIMIT_CHECKOUT_READ', 120),
    'checkout_write' => (int) env('RATE_LIMIT_CHECKOUT_WRITE', 30),
    'promotion_apply' => (int) env('RATE_LIMIT_PROMOTION_APPLY', 10),
    // Payments (Module 30). 'payment' above limits starting a payment and reporting its end; reading the status is
    // polled while a payment is being verified. Webhooks come from the provider, not from a customer: their limit
    // is per sending address and only keeps a flood away — the signature decides what is accepted.
    'payment_read' => (int) env('RATE_LIMIT_PAYMENT_READ', 120),
    'payment_webhook' => (int) env('RATE_LIMIT_PAYMENT_WEBHOOK', 600),
    // Notifications (Module 33), per signed-in customer: registering the installation happens at sign-in and on a token
    // refresh; the inbox is read on every visit; the admin test send is a deliberate, audited action.
    'device_register' => (int) env('RATE_LIMIT_DEVICE_REGISTER', 20),
    'notifications_read' => (int) env('RATE_LIMIT_NOTIFICATIONS_READ', 120),
    'notifications_write' => (int) env('RATE_LIMIT_NOTIFICATIONS_WRITE', 60),
    'admin_notification_test' => (int) env('RATE_LIMIT_ADMIN_NOTIFICATION_TEST', 5),

    // Module 34 — reviews: reading (own, public, dashboard), writing (submit / edit / remove / respond), reporting.
    'review_read' => (int) env('RATE_LIMIT_REVIEW_READ', 120),
    'review_write' => (int) env('RATE_LIMIT_REVIEW_WRITE', 10),
    'review_report' => (int) env('RATE_LIMIT_REVIEW_REPORT', 5),
    // Orders (Module 31). Reading is polled by a customer waiting for the order and by a restaurant's queue screen;
    // a step (accept, reject, ready) is a person's click.
    'order_read' => (int) env('RATE_LIMIT_ORDER_READ', 120),
    'order_write' => (int) env('RATE_LIMIT_ORDER_WRITE', 60),
    // Module 35: a customer's cancellation requests, and an administrator's refund actions (each one may reach the provider).
    'order_cancel' => (int) env('RATE_LIMIT_ORDER_CANCEL', 6),
    'refund_action' => (int) env('RATE_LIMIT_REFUND_ACTION', 30),
    // Module 32: pickup verifications per staff member and minute (the service adds a per-location and a per-credential
    // limit of its own) and realtime channel authorizations per principal and minute.
    'pickup_verify' => (int) env('RATE_LIMIT_PICKUP_VERIFY', 30),
    'channel_auth' => (int) env('RATE_LIMIT_CHANNEL_AUTH', 60),
    // Module 36 — finance. Reading (dashboards poll), an administrator's financial action, and a CSV download.
    'finance_read' => (int) env('RATE_LIMIT_FINANCE_READ', 120),
    'finance_action' => (int) env('RATE_LIMIT_FINANCE_ACTION', 30),
    'finance_export' => (int) env('RATE_LIMIT_FINANCE_EXPORT', 10),

    // Module 37 — support cases. Reading (an open case polls), opening a case, writing in one, and the service desk's
    // actions. Enough for somebody who needs help; too little for somebody who floods. Per person and per kind of account.
    'support_read' => (int) env('RATE_LIMIT_SUPPORT_READ', 120),
    'support_case_create' => (int) env('RATE_LIMIT_SUPPORT_CASE_CREATE', 6),
    'support_write' => (int) env('RATE_LIMIT_SUPPORT_WRITE', 20),
    'support_admin_action' => (int) env('RATE_LIMIT_SUPPORT_ADMIN_ACTION', 60),

    // Module 38 — analytics. Reading a report (a dashboard opens several at once), asking for or downloading an export,
    // and the applications' behavioural events (a batch at a time; per session of a guest, per customer when signed in).
    'analytics_read' => (int) env('RATE_LIMIT_ANALYTICS_READ', 120),
    'analytics_export' => (int) env('RATE_LIMIT_ANALYTICS_EXPORT', 10),
    'analytics_ingest' => (int) env('RATE_LIMIT_ANALYTICS_INGEST', 60),
];
