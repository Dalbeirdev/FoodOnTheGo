<?php

/*
|--------------------------------------------------------------------------
| Module 39 — security and privacy settings that are not owned by one domain
|--------------------------------------------------------------------------
|
| Every value is a development default PENDING BUSINESS / SECURITY APPROVAL. Production values are listed in
| .env.production.example and checked by `php artisan security:check`.
*/

$list = fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $v): bool => $v !== ''));
$appHost = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);

return [
    // Proxies allowed to set X-Forwarded-For / -Proto / -Host. Null = none (the connecting address is the client's).
    // A list of addresses / CIDR ranges of the load balancer, or '*' only behind a proxy that overwrites the headers.
    'trusted_proxies' => ($proxies = $list(env('TRUSTED_PROXIES'))) === [] ? null : ($proxies === ['*'] ? '*' : $proxies),

    // Host headers answered outside local / testing (Laravel's TrustHosts): the host of APP_URL and TRUSTED_HOSTS.
    // Regular expressions, anchored.
    'trusted_hosts' => array_values(array_unique(array_map(fn (string $host): string => '^'.preg_quote($host).'$', array_filter([$appHost ?: null, ...$list(env('TRUSTED_HOSTS'))])))),

    // Module 39: how long after an order ended its restaurant still sees the customer's full name and phone number
    // (afterwards: masked). PENDING BUSINESS APPROVAL.
    'restaurant_contact_hours' => (int) env('RESTAURANT_CONTACT_HOURS', 24),

    // Module 39: the largest API request body that is not a file upload (LimitRequestBody → 413).
    'max_body_bytes' => (int) env('SECURITY_MAX_BODY_BYTES', 262144),
];
