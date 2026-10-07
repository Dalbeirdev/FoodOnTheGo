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
];
