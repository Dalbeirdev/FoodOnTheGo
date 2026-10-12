<?php

namespace App\Enums;

/**
 * Why a restaurant's money is held back from settlement and payout (Module 36).
 */
enum HoldReason: string
{
    case RefundPending = 'REFUND_PENDING';
    case Dispute = 'DISPUTE';
    case Compliance = 'COMPLIANCE';
    case Reconciliation = 'RECONCILIATION';
    case ManualReview = 'MANUAL_REVIEW';

    /** @return list<self> what a person may choose (the first two are placed by the system) */
    public static function forPeople(): array
    {
        return [self::Dispute, self::Compliance, self::Reconciliation, self::ManualReview];
    }
}
