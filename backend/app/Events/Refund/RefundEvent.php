<?php

namespace App\Events\Refund;

use App\Models\Refund;

/**
 * Something happened to a refund (Module 35). Raised AFTER the change has been committed. It carries what may be
 * counted and logged — ids, the status, the provider's name, the amount in minor units and codes — never a provider
 * secret, a customer's contact details or a provider's raw answer.
 */
final class RefundEvent
{
    public const REQUIRED = 'required';

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const SUBMITTED = 'submitted';

    public const SUBMISSION_UNKNOWN = 'submission_unknown';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const REVIEW_REQUIRED = 'review_required';

    public const RECONCILED = 'reconciled';

    public const OVER_REFUND_BLOCKED = 'over_refund_blocked';

    public const EXTERNAL_REFUND_SEEN = 'external_refund_seen';

    public const DISPUTE_RECORDED = 'dispute_recorded';

    public readonly ?int $refundKey;

    public readonly ?string $refundId;

    public readonly ?string $paymentId;

    public readonly ?string $orderNumber;

    public readonly ?string $status;

    public readonly ?string $provider;

    public readonly ?string $currency;

    public readonly ?int $amountMinor;

    public readonly ?string $reason;

    public readonly ?int $version;

    /**
     * @param  array<string, scalar|null>  $facts  codes, counts and durations only
     */
    public function __construct(public readonly string $name, ?Refund $refund, public readonly array $facts = [])
    {
        $this->refundKey = $refund?->getKey() === null ? null : (int) $refund->getKey();
        $this->refundId = $refund === null ? null : (string) $refund->public_id;
        $this->paymentId = $refund === null ? null : (string) $refund->payment_public_id;
        $this->orderNumber = $refund?->order_number;
        $this->status = $refund?->status->value;
        $this->provider = $refund === null ? null : (string) $refund->provider;
        $this->currency = $refund === null ? null : (string) $refund->currency;
        $this->amountMinor = $refund?->amountMinor();
        $this->reason = $refund?->reason_code->value;
        $this->version = $refund === null ? null : (int) $refund->version;
    }
}
