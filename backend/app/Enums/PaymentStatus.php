<?php

namespace App\Enums;

/**
 * Where one payment attempt stands — FoodOnTheGo's own vocabulary, whatever the provider calls its states.
 *
 *   CREATED ──► PROVIDER_READY ──► PROCESSING ──► VERIFIED
 *                    │                 ├──► AUTHORIZED ──► VERIFIED
 *                    │                 ├──► PENDING ─────► VERIFIED | FAILED
 *                    │                 ├──► FAILED | CANCELLED | EXPIRED
 *                    │                 └──► UNKNOWN ─────► … | REVIEW_REQUIRED
 *                    └──► CANCELLED | EXPIRED
 *
 * VERIFIED is the only state that lets an order be created, and it is final: nothing that arrives later can take it
 * back. The states that end an attempt without money (FAILED, CANCELLED, EXPIRED) can still be overturned — but only
 * by reconciliation holding the provider's own evidence that the money was captured after all ("late success").
 */
enum PaymentStatus: string
{
    /** The attempt exists; the provider has not been asked yet. */
    case Created = 'CREATED';
    /** The provider has an order for it; the customer can pay in the provider's interface. */
    case ProviderReady = 'PROVIDER_READY';
    /** Something happened on the customer's side and is being verified. */
    case Processing = 'PROCESSING';
    /** The provider has a payment that is neither successful nor failed yet. */
    case Pending = 'PENDING';
    /** The bank approved, the money is not captured yet. Not paid. */
    case Authorized = 'AUTHORIZED';
    /** The provider captured exactly this amount in this currency for this attempt's own order. */
    case Verified = 'VERIFIED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    /** The evidence is missing or contradicts itself. Neither paid nor unpaid may be assumed. */
    case Unknown = 'UNKNOWN';
    /** Automatic reconciliation could not settle it, or what it found needs a person. */
    case ReviewRequired = 'REVIEW_REQUIRED';

    /**
     * An attempt that may still end in a payment: no second attempt may start beside it.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Created, self::ProviderReady, self::Processing, self::Pending, self::Authorized, self::Unknown], true);
    }

    /**
     * Ended without money, as far as is known: the customer may try again.
     */
    public function endedUnpaid(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired], true);
    }

    /**
     * The customer can still open the provider's interface for this attempt.
     */
    public function payable(): bool
    {
        return $this === self::ProviderReady;
    }

    /**
     * Whether the state machine allows this step at all. Who may take it is decided by PaymentStateMachine.
     */
    public function allows(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Created => [self::ProviderReady, self::Failed, self::Expired, self::Unknown],
            self::ProviderReady, self::Processing => [self::Processing, self::Pending, self::Authorized, self::Verified, self::Failed, self::Cancelled, self::Expired, self::Unknown, self::ReviewRequired],
            self::Pending => [self::Processing, self::Authorized, self::Verified, self::Failed, self::Cancelled, self::Expired, self::Unknown, self::ReviewRequired],
            self::Authorized => [self::Verified, self::Failed, self::Unknown, self::ReviewRequired],
            self::Unknown => [self::ProviderReady, self::Processing, self::Pending, self::Authorized, self::Verified, self::Failed, self::Cancelled, self::Expired, self::ReviewRequired],
            // Late success: only with the provider's evidence, only through reconciliation.
            self::Failed, self::Cancelled, self::Expired => [self::Verified, self::ReviewRequired],
            self::ReviewRequired => [self::Verified, self::Failed],
            self::Verified => [],
        }, true) && $to !== $this;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isOpen())));
    }
}
