<?php

namespace App\Enums;

/**
 * What became of a VERIFIED payment — kept apart from the payment itself, because "the money arrived" and "the order
 * exists" are two truths that can differ. Module 31 creates the order; until then every verified payment waits here.
 */
enum PaymentFulfillmentStatus: string
{
    /** The payment is not verified: there is nothing to fulfil. */
    case NotApplicable = 'NOT_APPLICABLE';
    /** Paid, and the checkout can still become an order as it stands. */
    case OrderPending = 'ORDER_PENDING';
    /** Paid, but the checkout can no longer become an order as it stands: capacity has to be found again or the money returned. */
    case RecoveryRequired = 'RECOVERY_REQUIRED';
    /** The order exists (Module 31). */
    case OrderCreated = 'ORDER_CREATED';
}
