<?php

namespace App\Enums;

/**
 * How a support case ended (Module 37). A resolution code is a statement about the CASE. It changes nothing in the
 * order, payment, refund or finance records: "REFUND_COMPLETED" may only be chosen when the linked refund itself says
 * so (the service checks), and choosing it refunds nothing.
 */
enum SupportResolutionCode: string
{
    case Answered = 'ANSWERED';
    case OrderResolved = 'ORDER_RESOLVED';
    case RefundCompleted = 'REFUND_COMPLETED';
    case PaymentReconciled = 'PAYMENT_RECONCILED';
    case RestaurantResolved = 'RESTAURANT_RESOLVED';
    case DuplicateCase = 'DUPLICATE_CASE';
    case NoActionRequired = 'NO_ACTION_REQUIRED';
    case UserWithdrew = 'USER_WITHDREW';
    case EscalatedExternally = 'ESCALATED_EXTERNALLY';
    case Other = 'OTHER';

    /** The codes an administrator may give when cancelling a case instead of resolving it. */
    public function forCancellation(): bool
    {
        return in_array($this, [self::DuplicateCase, self::NoActionRequired, self::UserWithdrew, self::Other], true);
    }
}
