<?php

namespace App\Enums;

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;

/**
 * The three identity contexts of the platform. Each has its own table, model and authentication guard;
 * they are never interchangeable and the client never chooses which one it is.
 */
enum PrincipalType: string
{
    case Customer = 'CUSTOMER';
    case RestaurantUser = 'RESTAURANT_USER';
    case AdminUser = 'ADMIN_USER';

    /**
     * Name of the authentication guard (config/auth.php) and of the token ability for this context.
     */
    public function guard(): string
    {
        return match ($this) {
            self::Customer => 'customer',
            self::RestaurantUser => 'restaurant',
            self::AdminUser => 'admin',
        };
    }

    /**
     * Alias stored in polymorphic columns (tokens, role assignments, security events).
     */
    public function morphAlias(): string
    {
        return match ($this) {
            self::Customer => 'customer',
            self::RestaurantUser => 'restaurant_user',
            self::AdminUser => 'admin_user',
        };
    }

    /**
     * @return class-string<Customer|RestaurantUser|AdminUser>
     */
    public function model(): string
    {
        return match ($this) {
            self::Customer => Customer::class,
            self::RestaurantUser => RestaurantUser::class,
            self::AdminUser => AdminUser::class,
        };
    }
}
