<?php

namespace Database\Seeders;

use App\Enums\Permission as P;
use App\Enums\PrincipalType;
use App\Services\Rbac\RoleService;
use Illuminate\Database\Seeder;

/**
 * Reference data (every environment): the starting roles. They are bundles of permissions — the backend
 * always checks the permission, never the role name — and can be changed later without code changes.
 * Re-running resets each system role to the bundle below.
 */
class RoleSeeder extends Seeder
{
    public function run(RoleService $roles): void
    {
        foreach (self::restaurantRoles() as $code => [$name, $permissions]) {
            $roles->define(PrincipalType::RestaurantUser, $code, $name, $permissions, system: true);
        }

        foreach (self::adminRoles() as $code => [$name, $permissions]) {
            $roles->define(PrincipalType::AdminUser, $code, $name, $permissions, system: true);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<P>}>
     */
    public static function restaurantRoles(): array
    {
        $view = [P::RestaurantProfileView, P::RestaurantMenuView, P::RestaurantOrdersView, P::RestaurantNotificationsView];

        return [
            'OWNER' => ['Owner', P::for(PrincipalType::RestaurantUser)],
            'MANAGER' => ['Manager', array_values(array_filter(P::for(PrincipalType::RestaurantUser), fn (P $p): bool => $p !== P::RestaurantStaffManage))],
            'ORDER_STAFF' => ['Order staff', [...$view, P::RestaurantOrdersUpdate, P::RestaurantPickupVerify]],
            'MENU_MANAGER' => ['Menu manager', [...$view, P::RestaurantMenuManage, P::RestaurantReviewsView]],
            'VIEWER' => ['Viewer', [...$view, P::RestaurantReviewsView, P::RestaurantAnalyticsView]],
        ];
    }

    /**
     * SUPER_ADMIN is for platform administration only; day-to-day admins get a functional role.
     *
     * @return array<string, array{0: string, 1: list<P>}>
     */
    public static function adminRoles(): array
    {
        $markets = [P::AdminMarketsView, P::AdminCitiesView, P::AdminServiceAreasView, P::AdminMarketConfigurationView];

        return [
            'SUPER_ADMIN' => ['Super admin', P::for(PrincipalType::AdminUser)],
            'OPERATIONS_ADMIN' => ['Operations admin', [
                P::AdminRestaurantsView, P::AdminRestaurantsSuspend, P::AdminCustomersView, P::AdminCustomersManage, P::AdminOrdersView, P::AdminOrdersOverride,
                P::AdminPaymentsView, P::AdminRefundsView, P::AdminSettlementsView, P::AdminReviewsView, P::AdminPromotionsView, P::AdminSupportView, P::AdminSupportManage,
                ...$markets, P::AdminNotificationsManage, P::AdminAuditView, P::AdminAnalyticsView, P::AdminSystemView,
            ]],
            'RESTAURANT_ONBOARDING' => ['Restaurant onboarding', [P::AdminRestaurantsView, P::AdminRestaurantsApprove, P::AdminOrdersView, P::AdminReviewsView, P::AdminSupportView, ...$markets, P::AdminAnalyticsView]],
            'SUPPORT_ADMIN' => ['Support admin', [P::AdminRestaurantsView, P::AdminCustomersView, P::AdminOrdersView, P::AdminPaymentsView, P::AdminRefundsView, P::AdminReviewsView, P::AdminSupportView, P::AdminSupportManage, P::AdminNotificationsManage]],
            'FINANCE_ADMIN' => ['Finance admin', [P::AdminRestaurantsView, P::AdminOrdersView, P::AdminPaymentsView, P::AdminRefundsView, P::AdminRefundsIssue, P::AdminSettlementsView, ...$markets, P::AdminAuditView, P::AdminAnalyticsView]],
            'MODERATION_ADMIN' => ['Moderation admin', [P::AdminRestaurantsView, P::AdminCustomersView, P::AdminOrdersView, P::AdminReviewsView, P::AdminReviewsModerate, P::AdminSupportView, P::AdminAuditView]],
            'ANALYST' => ['Analyst', [P::AdminRestaurantsView, P::AdminOrdersView, P::AdminPaymentsView, P::AdminSettlementsView, P::AdminReviewsView, P::AdminPromotionsView, ...$markets, P::AdminAnalyticsView, P::AdminSystemView]],
        ];
    }
}
