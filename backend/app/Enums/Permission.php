<?php

namespace App\Enums;

/**
 * Catalogue of permissions enforced by the backend. The prefix decides which principal type may hold one.
 * Adding a case here does not implement the feature — it reserves the name the feature must check.
 * Customers hold no permissions: their access is ownership of their own resources.
 */
enum Permission: string
{
    case RestaurantProfileView = 'restaurant.profile.view';
    case RestaurantProfileManage = 'restaurant.profile.manage';
    case RestaurantHoursManage = 'restaurant.hours.manage';
    case RestaurantPickupSettingsManage = 'restaurant.pickup_settings.manage';
    case RestaurantMenuView = 'restaurant.menu.view';
    case RestaurantMenuManage = 'restaurant.menu.manage';
    case RestaurantOrdersView = 'restaurant.orders.view';
    case RestaurantOrdersUpdate = 'restaurant.orders.update';
    case RestaurantPickupVerify = 'restaurant.pickup.verify';
    case RestaurantReviewsView = 'restaurant.reviews.view';
    case RestaurantReviewsRespond = 'restaurant.reviews.respond';
    case RestaurantStaffView = 'restaurant.staff.view';
    case RestaurantStaffManage = 'restaurant.staff.manage';
    case RestaurantAnalyticsView = 'restaurant.analytics.view';
    case RestaurantNotificationsView = 'restaurant.notifications.view';
    case RestaurantSettingsManage = 'restaurant.settings.manage';

    case AdminRestaurantsView = 'admin.restaurants.view';
    case AdminRestaurantsApprove = 'admin.restaurants.approve';
    case AdminRestaurantsSuspend = 'admin.restaurants.suspend';
    case AdminCustomersView = 'admin.customers.view';
    case AdminCustomersManage = 'admin.customers.manage';
    case AdminOrdersView = 'admin.orders.view';
    case AdminOrdersOverride = 'admin.orders.override';
    case AdminPaymentsView = 'admin.payments.view';
    case AdminRefundsView = 'admin.refunds.view';
    case AdminRefundsIssue = 'admin.refunds.issue';
    case AdminSettlementsView = 'admin.settlements.view';
    case AdminReviewsView = 'admin.reviews.view';
    case AdminReviewsModerate = 'admin.reviews.moderate';
    case AdminPromotionsView = 'admin.promotions.view';
    case AdminPromotionsManage = 'admin.promotions.manage';
    case AdminSupportView = 'admin.support.view';
    case AdminSupportManage = 'admin.support.manage';
    case AdminMarketsView = 'admin.markets.view';
    case AdminMarketsManage = 'admin.markets.manage';
    case AdminCitiesView = 'admin.cities.view';
    case AdminCitiesManage = 'admin.cities.manage';
    case AdminServiceAreasView = 'admin.service_areas.view';
    case AdminServiceAreasManage = 'admin.service_areas.manage';
    case AdminMarketConfigurationView = 'admin.market_configuration.view';
    case AdminMarketConfigurationManage = 'admin.market_configuration.manage';
    case AdminMarketFeaturesManage = 'admin.market_features.manage';
    case AdminConfigurationManage = 'admin.configuration.manage';
    case AdminNotificationsManage = 'admin.notifications.manage';
    case AdminUsersView = 'admin.users.view';
    case AdminUsersManage = 'admin.users.manage';
    case AdminRolesView = 'admin.roles.view';
    case AdminRolesManage = 'admin.roles.manage';
    case AdminAuditView = 'admin.audit.view';
    case AdminSecurityView = 'admin.security.view';
    case AdminAnalyticsView = 'admin.analytics.view';
    case AdminSystemView = 'admin.system.view';
    case AdminSettingsManage = 'admin.settings.manage';

    public function principalType(): PrincipalType
    {
        return str_starts_with($this->value, 'admin.') ? PrincipalType::AdminUser : PrincipalType::RestaurantUser;
    }

    /**
     * @return list<self>
     */
    public static function for(PrincipalType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission): bool => $permission->principalType() === $type));
    }
}
