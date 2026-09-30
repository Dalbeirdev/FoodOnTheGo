<?php

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | There is deliberately no "default user". Every protected route names the
    | guard of the principal type it serves; the default below only matters for
    | code that asks for "the" guard without naming one, and it is the least
    | privileged context.
    |
    */

    'defaults' => [
        'guard' => 'customer',
        'passwords' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | One Sanctum token guard per principal type. A guard only accepts a token
    | whose owner is a row of its own provider's table, so a customer token is
    | simply "not authenticated" on a restaurant or admin route.
    |
    */

    'guards' => [
        'customer' => ['driver' => 'sanctum', 'provider' => 'customers'],
        'restaurant' => ['driver' => 'sanctum', 'provider' => 'restaurant_users'],
        'admin' => ['driver' => 'sanctum', 'provider' => 'admin_users'],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'customers' => ['driver' => 'eloquent', 'model' => Customer::class],
        'restaurant_users' => ['driver' => 'eloquent', 'model' => RestaurantUser::class],
        'admin_users' => ['driver' => 'eloquent', 'model' => AdminUser::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Laravel's password broker is not used: App\Services\Auth\PasswordResetService
    | implements hashed, expiring, single-use tokens for restaurant and admin
    | users (config/auth_security.php). Customers have no password.
    |
    */

    'passwords' => [],

    'password_timeout' => 10800,

];
