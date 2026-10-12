<?php

namespace App\Enums;

/**
 * A refund at a provider, normalized by the adapter. Business logic reads this, never the provider's own word.
 */
enum ProviderRefundState: string
{
    /** Accepted by the provider and not completed (Razorpay: "pending"). */
    case Pending = 'PENDING';
    /** Completed by the provider (Razorpay: "processed"). */
    case Processed = 'PROCESSED';
    /** The provider could not make it (Razorpay: "failed"). */
    case Failed = 'FAILED';
    /** A status this application does not know. Nothing is concluded from it. */
    case Unknown = 'UNKNOWN';
}
