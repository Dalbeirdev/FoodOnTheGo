<?php

namespace App\Enums;

/**
 * A payout as a payout provider reports it, in provider-neutral words (Module 36).
 */
enum ProviderPayoutState: string
{
    /** Accepted, queued or being processed. */
    case Processing = 'PROCESSING';
    /** The money reached the destination. */
    case Processed = 'PROCESSED';
    /** Refused, failed or cancelled: nothing was sent. */
    case Failed = 'FAILED';
    /** Sent and returned by the bank. */
    case Reversed = 'REVERSED';
    case Unknown = 'UNKNOWN';
}
