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
    // Module 38: asking for a CSV of the restaurant's own analytics.
    case RestaurantAnalyticsExport = 'restaurant.analytics.export';
    case RestaurantNotificationsView = 'restaurant.notifications.view';
    case RestaurantSettingsManage = 'restaurant.settings.manage';
    /** Read the location's earnings, settlement statements and payout states (Module 36). Never another restaurant's. */
    case RestaurantFinanceView = 'restaurant.finance.view';
    /** See where payouts go (the provider's mask of the destination and its verification state) — the owner's. */
    case RestaurantPayoutAccountView = 'restaurant.payout_account.view';
    /** Read the support cases of the locations the member may open (Module 37). Money cases also need restaurant.finance.view. */
    case RestaurantSupportView = 'restaurant.support.view';
    /** Open a support case for the restaurant and write in it. */
    case RestaurantSupportCreate = 'restaurant.support.create';

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
    /** Read restaurant settlements and their statements (Module 36). */
    case AdminSettlementsView = 'admin.settlements.view';
    /** Read the finance overview, the restaurants' payables, allocations and reports (Module 36). */
    case AdminFinanceView = 'admin.finance.view';
    /** Read commercial terms (commission plans). Negotiated terms are confidential: not part of admin.finance.view. */
    case AdminFinanceCommissionView = 'admin.finance.commission.view';
    /** Make, end or withdraw a commission plan. A plan starts in the future and never rewrites an order already allocated. */
    case AdminFinanceCommissionManage = 'admin.finance.commission.manage';
    /** Generate settlements (also as a dry run) and withdraw one that was not paid. */
    case AdminFinanceSettlementsManage = 'admin.finance.settlements.manage';
    /** Approve a calculated settlement. Approving sends no money. */
    case AdminFinanceSettlementsApprove = 'admin.finance.settlements.approve';
    case AdminFinancePayoutsView = 'admin.finance.payouts.view';
    /** Send an approved settlement's payout to the payout provider, try a failed one again, ask the provider again. There is no "mark as paid". */
    case AdminFinancePayoutsExecute = 'admin.finance.payouts.execute';
    /** Record a payout that was made outside the platform, with its bank reference, date and evidence. */
    case AdminFinancePayoutsRecordExternal = 'admin.finance.payouts.record_external';
    case AdminFinanceReconciliationView = 'admin.finance.reconciliation.view';
    /** Import provider settlement records, run reconciliation, and close a difference with a reason. */
    case AdminFinanceReconciliationManage = 'admin.finance.reconciliation.manage';
    /** Decide who bears a refund or a dispute, decide an order's missing terms, and post a manual adjustment — each with a reason. */
    case AdminFinanceAdjustmentsCreate = 'admin.finance.adjustments.create';
    /** Place and release a financial hold. */
    case AdminFinanceHoldsManage = 'admin.finance.holds.manage';
    /** Read the ledger: accounts, transactions and entries. Nobody can write it through the API. */
    case AdminFinanceLedgerView = 'admin.finance.ledger.view';
    /** Download finance data as CSV. */
    case AdminFinanceExport = 'admin.finance.export';
    case AdminReviewsView = 'admin.reviews.view';
    case AdminReviewsModerate = 'admin.reviews.moderate';
    case AdminReviewsRestore = 'admin.reviews.restore';
    case AdminReviewsReportsView = 'admin.reviews.reports.view';
    case AdminPromotionsView = 'admin.promotions.view';
    case AdminPromotionsManage = 'admin.promotions.manage';
    /** Read support cases and their conversations (Module 37) — not the internal notes, not restricted cases. */
    case AdminSupportView = 'admin.support.view';
    /** Work a case: reply to the requester, change its status and priority, link records, resolve, reopen, cancel. It opens NO other domain: no payment, refund or finance action follows from it. */
    case AdminSupportManage = 'admin.support.manage';
    /** Give a case to a queue or a person. */
    case AdminSupportAssign = 'admin.support.assign';
    /** Read and write the internal notes of a case. */
    case AdminSupportNotes = 'admin.support.notes';
    /** Escalate a case to another queue or level. */
    case AdminSupportEscalate = 'admin.support.escalate';
    /** Open finance-restricted cases (provider disputes, reconciliation differences). */
    case AdminSupportRestrictedFinance = 'admin.support.restricted.finance';
    /** Open security-restricted cases. */
    case AdminSupportRestrictedSecurity = 'admin.support.restricted.security';
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
    // Module 38: asking for a curated CSV report. A finance or support report needs that domain's permission as well.
    case AdminAnalyticsExport = 'admin.analytics.export';
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
