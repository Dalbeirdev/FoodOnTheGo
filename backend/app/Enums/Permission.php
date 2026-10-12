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
    case RestaurantReviewsReport = 'restaurant.reviews.report';
    case RestaurantStaffView = 'restaurant.staff.view';
    case RestaurantStaffManage = 'restaurant.staff.manage';
    case RestaurantAnalyticsView = 'restaurant.analytics.view';
    case RestaurantNotificationsView = 'restaurant.notifications.view';
    case RestaurantSettingsManage = 'restaurant.settings.manage';

    case AdminRestaurantsView = 'admin.restaurants.view';
    case AdminRestaurantsApprove = 'admin.restaurants.approve';
    case AdminRestaurantsSuspend = 'admin.restaurants.suspend';
    case AdminRestaurantsManage = 'admin.restaurants.manage';
    case AdminCustomersView = 'admin.customers.view';
    case AdminCustomersManage = 'admin.customers.manage';
    case AdminOrdersView = 'admin.orders.view';
    case AdminOrdersOverride = 'admin.orders.override';
    /** Ask for a failed order creation to be tried again. Creates nothing by hand; the payment's verification still decides. */
    case AdminOrdersRecover = 'admin.orders.recover';
    case AdminPaymentsView = 'admin.payments.view';
    /** Ask the provider again about a payment. It changes nothing a provider does not confirm; there is no "mark as paid". */
    case AdminPaymentsReconcile = 'admin.payments.reconcile';
    case AdminRefundsView = 'admin.refunds.view';
    /** Ask for a refund (Module 35). A request moves no money: it has to be approved and executed. */
    case AdminRefundsCreate = 'admin.refunds.create';
    /** Approve or turn down a refund. Who asked is not who approved. */
    case AdminRefundsApprove = 'admin.refunds.approve';
    /** Send an approved refund to the provider, or try a failed one again — the only actions that reach the provider. */
    case AdminRefundsIssue = 'admin.refunds.issue';
    /** Ask the provider again about a refund. It changes nothing a provider does not confirm; there is no "mark as refunded". */
    case AdminRefundsReconcile = 'admin.refunds.reconcile';
    /** Read the disputes / chargebacks a provider reported (Module 35 foundation). Nothing can be done to them here. */
    case AdminDisputesView = 'admin.disputes.view';
    case AdminSettlementsView = 'admin.settlements.view';
    case AdminReviewsView = 'admin.reviews.view';
    case AdminReviewsModerate = 'admin.reviews.moderate';
    case AdminReviewsRestore = 'admin.reviews.restore';
    case AdminReviewsReportsView = 'admin.reviews.reports.view';
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
    case AdminNotificationsView = 'admin.notifications.view';
    case AdminNotificationsTest = 'admin.notifications.test';
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
