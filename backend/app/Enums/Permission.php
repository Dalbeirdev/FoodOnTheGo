<?php

namespace App\Enums;

/**
 * Catalogue of permissions enforced by the backend. The prefix decides which principal type may hold one.
 * Adding a case here does not implement the feature — it reserves the name the feature must check.
 */
enum Permission: string
{
    case RestaurantOrdersView = 'restaurant.orders.view';
    case RestaurantOrdersUpdate = 'restaurant.orders.update';
    case RestaurantMenuManage = 'restaurant.menu.manage';
    case RestaurantPickupVerify = 'restaurant.pickup.verify';
    case RestaurantStaffManage = 'restaurant.staff.manage';
    case RestaurantProfileManage = 'restaurant.profile.manage';

    case AdminRestaurantsApprove = 'admin.restaurants.approve';
    case AdminRefundsIssue = 'admin.refunds.issue';
    case AdminReviewsModerate = 'admin.reviews.moderate';
    case AdminMarketsView = 'admin.markets.view';
    case AdminMarketsManage = 'admin.markets.manage';
    case AdminUsersManage = 'admin.users.manage';
    case AdminSystemView = 'admin.system.view';

    public function principalType(): PrincipalType
    {
        return str_starts_with($this->value, 'admin.') ? PrincipalType::AdminUser : PrincipalType::RestaurantUser;
    }
}
