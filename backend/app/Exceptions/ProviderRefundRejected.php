<?php

namespace App\Exceptions;

use App\Enums\ProviderFailure;

/**
 * The provider understood a refund request and refused it (Module 35). Carries a normalized reason — never the
 * provider's own sentence, a credential or anything of the request.
 *
 * Three kinds of reason matter to the refund domain:
 *  - the refund certainly does not exist and the same request cannot succeed (most of them): the refund FAILED;
 *  - the provider may already hold this very refund (a duplicate receipt, a request still in progress): the
 *    outcome is UNKNOWN and is looked up, never retried blindly;
 *  - the provider and FoodOnTheGo disagree about what can be refunded (already refunded, more than captured, a
 *    dispute): a person has to look.
 */
final class ProviderRefundRejected extends ProviderException
{
    public const AMOUNT_EXCEEDS_AVAILABLE = 'AMOUNT_EXCEEDS_AVAILABLE';

    public const ALREADY_REFUNDED = 'ALREADY_REFUNDED';

    public const INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';

    public const PAYMENT_NOT_CAPTURED = 'PAYMENT_NOT_CAPTURED';

    public const METHOD_NOT_SUPPORTED = 'METHOD_NOT_SUPPORTED';

    public const PARTIAL_NOT_SUPPORTED = 'PARTIAL_NOT_SUPPORTED';

    public const REFUNDS_DISABLED = 'REFUNDS_DISABLED';

    public const DISPUTE_BLOCK = 'DISPUTE_BLOCK';

    public const DUPLICATE_RECEIPT = 'DUPLICATE_RECEIPT';

    public const OPERATION_IN_PROGRESS = 'OPERATION_IN_PROGRESS';

    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    public const PAYMENT_TOO_OLD = 'PAYMENT_TOO_OLD';

    public const REJECTED = 'REJECTED';

    public function __construct(string $provider, public readonly string $reason)
    {
        parent::__construct(ProviderFailure::Rejected, $provider, "{$provider}: refund refused ({$reason})");
    }

    /** The provider may hold this refund already: look it up before anything else. */
    public function outcomeUnknown(): bool
    {
        return in_array($this->reason, [self::DUPLICATE_RECEIPT, self::OPERATION_IN_PROGRESS, self::IDEMPOTENCY_CONFLICT], true);
    }

    /** The provider's books and ours disagree, or a dispute is in the way: a person decides. */
    public function needsReview(): bool
    {
        return in_array($this->reason, [self::AMOUNT_EXCEEDS_AVAILABLE, self::ALREADY_REFUNDED, self::DISPUTE_BLOCK], true);
    }

    /** A later, new refund of the same amount may succeed (nothing about the payment forbids it). */
    public function retryable(): bool
    {
        return $this->reason === self::INSUFFICIENT_BALANCE;
    }
}
