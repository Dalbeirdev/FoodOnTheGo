<?php

namespace App\Enums;

/**
 * Whether money has to go back for an order — kept apart from the order's state and from the payment's.
 *
 * Since Module 35 this is DERIVED from the order's refunds (RefundProjection) inside the transaction that changes a
 * refund; nothing else writes it. "REFUNDED" appears only when the provider confirmed refunds for everything that
 * was paid.
 */
enum OrderRefundStatus: string
{
    case None = 'NONE';
    /** A refund is owed and the payment provider does not have it yet (not approved, approved, or about to be sent). */
    case Required = 'REQUIRED';
    /** A refund is with the provider (or may be: an unanswered submission) and not confirmed yet. */
    case Processing = 'PROCESSING';
    /** The provider confirmed refunds for a part of what was paid. */
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    /** The provider confirmed refunds for everything that was paid. */
    case Refunded = 'REFUNDED';
    /** The provider refused the refund; a person decides what happens next. */
    case Failed = 'FAILED';
    /** A refund of this order needs a person. */
    case ReviewRequired = 'REVIEW_REQUIRED';

    /**
     * The word a CUSTOMER is given. A refund that failed or needs a person is "under review" for them: the internal
     * words FAILED and REVIEW_REQUIRED never leave through a customer answer.
     */
    public function forCustomer(): string
    {
        return in_array($this, [self::Failed, self::ReviewRequired], true) ? 'UNDER_REVIEW' : $this->value;
    }

    /** Something is owed or under way that the customer is still waiting for. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Required, self::Processing, self::Failed, self::ReviewRequired], true);
    }

    /** A person at FoodOnTheGo has to act. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Required, self::Failed, self::ReviewRequired], true);
    }
}
