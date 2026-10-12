<?php

namespace App\Enums;

/**
 * A restaurant payout's state (Module 36).
 *
 * REQUIRED         made for an approved settlement; not sent yet
 * SUBMITTING       being sent to the provider right now
 * PROCESSING       the provider has it
 * PAID             the provider confirmed it — or it was paid outside and recorded with its evidence
 * FAILED           the provider refused it, it failed, or it was reversed: nothing reached the restaurant
 * UNKNOWN          it was sent and no answer came: NOT sent again — the provider is asked what it has
 * REVIEW_REQUIRED  the provider's answers do not settle it: a person looks
 * CANCELLED        withdrawn before it was sent
 *
 * Nothing sets PAID by hand: there is no "mark as paid".
 */
enum PayoutStatus: string
{
    case Required = 'REQUIRED';
    case Submitting = 'SUBMITTING';
    case Processing = 'PROCESSING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Unknown = 'UNKNOWN';
    case ReviewRequired = 'REVIEW_REQUIRED';
    case Cancelled = 'CANCELLED';

    /**
     * The state in a RESTAURANT's words: PROCESSING (anything that is not final — also a payout whose outcome the
     * finance team is still finding out), PAID (confirmed) or FAILED (nothing reached the restaurant; the settlement
     * is paid by a new payout). A withdrawn payout is never shown at all.
     */
    public function forRestaurant(): string
    {
        return match ($this) {
            self::Paid => 'PAID',
            self::Failed, self::Cancelled => 'FAILED',
            default => 'PROCESSING',
        };
    }

    public function isAlive(): bool
    {
        return ! in_array($this, [self::Failed, self::Cancelled], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled], true);
    }

    /** @return list<self> the provider may still have something to say */
    public static function unsettled(): array
    {
        return [self::Submitting, self::Processing, self::Unknown];
    }
}
