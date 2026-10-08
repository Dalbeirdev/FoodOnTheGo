<?php

namespace App\Enums;

/**
 * How an order was paid for, as a customer reads it. Derived from the payment domain when the order is created;
 * a refund changes the order's refund status, never this.
 */
enum OrderPaymentState: string
{
    /** A VERIFIED payment attempt for exactly the order's total. */
    case Paid = 'PAID';
    /** A total of zero: nothing was charged and no provider was involved. */
    case PaymentNotRequired = 'PAYMENT_NOT_REQUIRED';
}
