<?php

namespace App\Enums;

/**
 * Where a support case came in (Module 37). The client application is named by the client and checked against the
 * caller's kind; the system sources are set by the backend only.
 */
enum SupportCaseSource: string
{
    case CustomerWeb = 'CUSTOMER_WEB';
    case Android = 'ANDROID';
    case RestaurantDashboard = 'RESTAURANT_DASHBOARD';
    case Admin = 'ADMIN';
    case SystemAlert = 'SYSTEM_ALERT';
    case PaymentRecovery = 'PAYMENT_RECOVERY';
    case RefundRecovery = 'REFUND_RECOVERY';
    case FinanceReconciliation = 'FINANCE_RECONCILIATION';
    case Security = 'SECURITY';

    /**
     * @return list<self>
     */
    public static function forCustomer(): array
    {
        return [self::CustomerWeb, self::Android];
    }
}
