<?php

namespace App\Enums;

/**
 * Where a refund stands (Module 35) — FoodOnTheGo's own state, kept apart from the provider's status word
 * (refunds.provider_status) and from the states of the order and of the payment.
 *
 *   REQUIRED ─┐                                   ┌──► REFUNDED            (the provider confirmed it — final)
 *   REQUESTED ┴─► APPROVED ─► SUBMITTING ─► PROCESSING ─► FAILED            (the provider refused it — a new try needs a person)
 *        │            │            ├──► SUBMISSION_UNKNOWN ─► (looked up at the provider, never guessed)
 *        └────────────┴──► CANCELLED (final)       └──► REVIEW_REQUIRED     (a person decides)
 *
 * The provider's "pending" is PROCESSING here: the specification's PENDING is not a state of its own.
 */
enum RefundStatus: string
{
    /** A system condition owes the customer money (a rejected or cancelled paid order). Nobody approved it yet. */
    case Required = 'REQUIRED';
    /** A person asked for it. Nobody approved it yet. */
    case Requested = 'REQUESTED';
    /** Approved — by the policy or by a person — and not sent to the provider yet. */
    case Approved = 'APPROVED';
    /** Being sent to the provider right now. */
    case Submitting = 'SUBMITTING';
    /** It was sent and no answer came back: the provider may or may not have created it. Never sent again blindly. */
    case SubmissionUnknown = 'SUBMISSION_UNKNOWN';
    /** The provider has it and has not confirmed it. Not "refunded". */
    case Processing = 'PROCESSING';
    /** The provider confirmed the refund. */
    case Refunded = 'REFUNDED';
    /** The provider definitively refused or failed it. The amount is free again. */
    case Failed = 'FAILED';
    /** Turned down or withdrawn before anything was sent. */
    case Cancelled = 'CANCELLED';
    /** It cannot go on by itself: a person looks at it. */
    case ReviewRequired = 'REVIEW_REQUIRED';

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'REQUIRED' => ['APPROVED', 'CANCELLED', 'REVIEW_REQUIRED'],
        'REQUESTED' => ['APPROVED', 'CANCELLED', 'REVIEW_REQUIRED'],
        'APPROVED' => ['SUBMITTING', 'CANCELLED', 'REVIEW_REQUIRED'],
        'SUBMITTING' => ['PROCESSING', 'REFUNDED', 'FAILED', 'SUBMISSION_UNKNOWN', 'APPROVED', 'REVIEW_REQUIRED'],
        'SUBMISSION_UNKNOWN' => ['SUBMITTING', 'PROCESSING', 'REFUNDED', 'FAILED', 'REVIEW_REQUIRED'],
        'PROCESSING' => ['REFUNDED', 'FAILED', 'REVIEW_REQUIRED'],
        'FAILED' => ['APPROVED', 'CANCELLED', 'REFUNDED', 'PROCESSING'],
        'REVIEW_REQUIRED' => ['APPROVED', 'SUBMITTING', 'PROCESSING', 'REFUNDED', 'FAILED', 'CANCELLED'],
        'REFUNDED' => [],
        'CANCELLED' => [],
    ];

    public function allows(self $to): bool
    {
        return in_array($to->value, self::ALLOWED[$this->value], true);
    }

    /** Nothing more can happen to it. */
    public function isTerminal(): bool
    {
        return $this === self::Refunded || $this === self::Cancelled;
    }

    /**
     * Its amount is held against the payment: asked for, approved, on its way or not understood. Only a refund the
     * provider definitively failed, and one that was cancelled, give their amount back.
     */
    public function reserves(): bool
    {
        return in_array($this, [self::Required, self::Requested, self::Approved, self::Submitting, self::SubmissionUnknown, self::Processing, self::ReviewRequired], true);
    }

    /** The provider may know about it. */
    public function withProvider(): bool
    {
        return in_array($this, [self::Submitting, self::SubmissionUnknown, self::Processing], true);
    }

    /** Waiting for a person's approval. */
    public function awaitsApproval(): bool
    {
        return $this === self::Required || $this === self::Requested;
    }

    /**
     * What the customer is told about ONE refund. A request nobody approved and a cancelled one are not shown.
     */
    public function forCustomer(): ?string
    {
        return match ($this) {
            // Owed, and the provider does not have it yet: approving a refund or queueing its submission sends nothing.
            self::Required, self::Approved, self::Submitting => 'REQUIRED',
            // With the provider — or possibly with it (a submission that was never answered).
            self::SubmissionUnknown, self::Processing => 'PROCESSING',
            self::Refunded => 'REFUNDED',
            self::Failed, self::ReviewRequired => 'UNDER_REVIEW',
            self::Requested, self::Cancelled => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function reservingValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->reserves())));
    }
}
