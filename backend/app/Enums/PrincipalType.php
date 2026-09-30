<?php

namespace App\Enums;

/**
 * The three identity contexts of the platform. They are never interchangeable: a permission belongs to
 * exactly one of them and a principal can only hold permissions of its own type.
 */
enum PrincipalType: string
{
    case Customer = 'CUSTOMER';
    case RestaurantUser = 'RESTAURANT_USER';
    case AdminUser = 'ADMIN_USER';
}
