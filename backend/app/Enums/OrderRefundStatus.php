<?php

namespace App\Enums;

/**
 * Whether money has to go back for an order — kept apart from the order's state and from the payment's.
 * "REFUNDED" is never written by this module: it waits for the provider's own confirmation (the refund module).
 */
enum OrderRefundStatus: string
{
    case None = 'NONE';
    /** The order ended without being fulfilled after it was paid: a refund has to be made. */
    case Required = 'REQUIRED';
    /** A refund has been asked of the provider and is not confirmed yet. */
    case Pending = 'PENDING';
    case Partial = 'PARTIAL';
    /** The provider confirmed the refund. */
    case Refunded = 'REFUNDED';
    case Failed = 'FAILED';
}
