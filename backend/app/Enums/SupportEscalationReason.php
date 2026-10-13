<?php

namespace App\Enums;

/**
 * Why a support case was escalated (Module 37). Escalation asks another team or a higher level to look; it is not a
 * priority and it performs nothing in the domain the case is about.
 */
enum SupportEscalationReason: string
{
    case FinancialRisk = 'FINANCIAL_RISK';
    case CustomerImpact = 'CUSTOMER_IMPACT';
    case RestaurantImpact = 'RESTAURANT_IMPACT';
    case Security = 'SECURITY';
    case ProviderIssue = 'PROVIDER_ISSUE';
    case SlaRisk = 'SLA_RISK';
    case ManagementReview = 'MANAGEMENT_REVIEW';
    case Other = 'OTHER';
}
