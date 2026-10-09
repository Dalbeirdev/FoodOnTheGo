<?php

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Order\OrderMetrics;
use App\Services\Restaurant\RestaurantAccess;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Private realtime channels (Module 32)
|--------------------------------------------------------------------------
|
| Every channel is private and authorized HERE, on the server, with the same guards and the same access rules as the
| REST API — a channel name is an address, never a permission. The authorization endpoints are under /api/v1
| (routes/api.php): /customer/broadcasting/auth with the customer guard, /restaurant/broadcasting/auth with the
| restaurant guard, both bearer-token authenticated like every other route of their context. A refused subscription
| is counted (channel_auth_denied). No public order channel exists.
|
*/

// The customer who owns the order — and nobody else. A guest, another customer, a staff member: refused.
Broadcast::driver((string) config('orders.realtime.auth_driver'))->channel('customer.order.{orderId}', function (Customer $customer, string $orderId): bool {
    $ok = Str::isUuid($orderId) && Order::query()->where('public_id', $orderId)->where('customer_id', $customer->getKey())->exists();
    if (! $ok) {
        app(OrderMetrics::class)->record('channel_auth_denied');
    }

    return $ok;
}, ['guards' => ['customer']]);

// Staff who reach the location (their ACTIVE memberships, all-locations or this one) and may view its orders.
Broadcast::driver((string) config('orders.realtime.auth_driver'))->channel('restaurant.location.{locationId}', function (RestaurantUser $user, string $locationId): bool {
    $ok = false;
    if (Str::isUuid($locationId)) {
        $location = RestaurantLocation::query()->where('public_id', $locationId)->first();
        if ($location !== null) {
            try {
                app(RestaurantAccess::class)->authorize($user, $location, Permission::RestaurantOrdersView);
                $ok = true;
            } catch (Throwable) {
                $ok = false;
            }
        }
    }
    if (! $ok) {
        app(OrderMetrics::class)->record('channel_auth_denied');
    }

    return $ok;
}, ['guards' => ['restaurant']]);
