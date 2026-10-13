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
            // A manager runs the restaurant and reads its earnings; staff management and the payout destination are the owner's.
            'MANAGER' => ['Manager', array_values(array_filter(P::for(PrincipalType::RestaurantUser), fn (P $p): bool => ! in_array($p, [P::RestaurantStaffManage, P::RestaurantPayoutAccountView], true)))],
            'ORDER_STAFF' => ['Order staff', [...$view, P::RestaurantOrdersUpdate, P::RestaurantPickupVerify, P::RestaurantSupportView, P::RestaurantSupportCreate]],
            'MENU_MANAGER' => ['Menu manager', [...$view, P::RestaurantMenuManage, P::RestaurantReviewsView, P::RestaurantReviewsReport, P::RestaurantSupportView, P::RestaurantSupportCreate]],
            'VIEWER' => ['Viewer', [...$view, P::RestaurantReviewsView, P::RestaurantAnalyticsView, P::RestaurantSupportView]],
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
                P::AdminRestaurantsView, P::AdminRestaurantsSuspend, P::AdminCustomersView, P::AdminCustomersManage, P::AdminOrdersView, P::AdminOrdersOverride, P::AdminOrdersRecover,
                P::AdminPaymentsView, P::AdminRefundsView, P::AdminSettlementsView, P::AdminFinanceView, P::AdminFinancePayoutsView, P::AdminFinanceReconciliationView, P::AdminReviewsView, P::AdminReviewsReportsView, P::AdminPromotionsView, P::AdminSupportView, P::AdminSupportManage, P::AdminSupportAssign, P::AdminSupportNotes, P::AdminSupportEscalate,
                ...$markets, P::AdminNotificationsView, P::AdminNotificationsManage, P::AdminNotificationsTest, P::AdminAuditView, P::AdminAnalyticsView, P::AdminAnalyticsExport, P::AdminSystemView,
            ]],
            'RESTAURANT_ONBOARDING' => ['Restaurant onboarding', [P::AdminRestaurantsView, P::AdminRestaurantsApprove, P::AdminRestaurantsManage, P::AdminOrdersView, P::AdminReviewsView, P::AdminSupportView, ...$markets, P::AdminAnalyticsView]],
            'SUPPORT_ADMIN' => ['Support admin', [P::AdminRestaurantsView, P::AdminCustomersView, P::AdminOrdersView, P::AdminOrdersRecover, P::AdminPaymentsView, P::AdminRefundsView, P::AdminReviewsView, P::AdminSupportView, P::AdminSupportManage, P::AdminSupportAssign, P::AdminSupportNotes, P::AdminSupportEscalate, P::AdminNotificationsView, P::AdminNotificationsManage]],
            'FINANCE_ADMIN' => ['Finance admin', [P::AdminSupportView, P::AdminSupportManage, P::AdminSupportAssign, P::AdminSupportNotes, P::AdminSupportEscalate, P::AdminSupportRestrictedFinance, P::AdminRestaurantsView, P::AdminOrdersView, P::AdminPaymentsView, P::AdminPaymentsReconcile, P::AdminRefundsView, P::AdminRefundsCreate, P::AdminRefundsApprove, P::AdminRefundsIssue, P::AdminRefundsReconcile, P::AdminDisputesView, P::AdminSettlementsView, P::AdminFinanceView, P::AdminFinanceCommissionView, P::AdminFinanceCommissionManage, P::AdminFinanceSettlementsManage, P::AdminFinanceSettlementsApprove, P::AdminFinancePayoutsView, P::AdminFinancePayoutsExecute, P::AdminFinancePayoutsRecordExternal, P::AdminFinanceReconciliationView, P::AdminFinanceReconciliationManage, P::AdminFinanceAdjustmentsCreate, P::AdminFinanceHoldsManage, P::AdminFinanceLedgerView, P::AdminFinanceExport, ...$markets, P::AdminAuditView, P::AdminAnalyticsView, P::AdminAnalyticsExport]],
            'MODERATION_ADMIN' => ['Moderation admin', [P::AdminRestaurantsView, P::AdminCustomersView, P::AdminOrdersView, P::AdminReviewsView, P::AdminReviewsModerate, P::AdminReviewsRestore, P::AdminReviewsReportsView, P::AdminSupportView, P::AdminAuditView]],
            'ANALYST' => ['Analyst', [P::AdminRestaurantsView, P::AdminOrdersView, P::AdminPaymentsView, P::AdminSettlementsView, P::AdminFinanceView, P::AdminReviewsView, P::AdminPromotionsView, ...$markets, P::AdminNotificationsView, P::AdminAnalyticsView, P::AdminAnalyticsExport, P::AdminSystemView]],
        ];
    }
}
