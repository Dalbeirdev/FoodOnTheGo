<?php

namespace App\Events\Payment;

use App\Models\PaymentAttempt;

/**
 * Something happened to a payment (Module 30). The event carries what may be counted and logged — ids, the status,
 * the provider's name, the amount in minor units and a reason code — never a provider secret, a signature, a
 * customer's contact details or a provider's raw answer.
 *
 * It is raised after the change has been committed.
 */
final class PaymentEvent
{
    public const ATTEMPT_CREATED = 'attempt_created';

    public const PROVIDER_READY = 'provider_ready';

    public const INITIALIZATION_FAILED = 'initialization_failed';

    public const INITIALIZATION_UNKNOWN = 'initialization_unknown';

    public const CLIENT_CALLBACK = 'client_callback';

    public const SIGNATURE_INVALID = 'signature_invalid';

    public const WEBHOOK_RECEIVED = 'webhook_received';

    public const WEBHOOK_DUPLICATE = 'webhook_duplicate';

    public const WEBHOOK_REJECTED = 'webhook_rejected';

    public const WEBHOOK_IGNORED = 'webhook_ignored';

    public const WEBHOOK_FAILED = 'webhook_failed';

    public const WEBHOOK_PROCESSED = 'webhook_processed';

    public const PROCESSING = 'processing';

    public const PENDING = 'pending';

    public const AUTHORIZED = 'authorized';

    public const VERIFIED = 'verified';

    public const LATE_SUCCESS = 'late_success';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const UNKNOWN = 'unknown';

    public const REVIEW_REQUIRED = 'review_required';

    public const MISMATCH = 'mismatch';

    public const DUPLICATE_PAYMENT = 'duplicate_payment';

    public const RECONCILED = 'reconciled';

    public const RECOVERY_REQUIRED = 'recovery_required';

    public readonly ?string $paymentId;

    public readonly ?string $checkoutId;

    public readonly ?string $status;

    public readonly ?string $provider;

    public readonly ?string $currency;

    public readonly ?int $amountMinor;

    public readonly ?string $methodType;

    /**
     * @param  array<string, scalar|null>  $facts  codes, counts and durations only
     */
    public function __construct(public readonly string $name, ?PaymentAttempt $attempt, public readonly array $facts = [])
    {
        $this->paymentId = $attempt === null ? null : (string) $attempt->public_id;
        $this->checkoutId = $attempt === null ? null : (string) $attempt->checkout_public_id;
        $this->status = $attempt?->status->value;
        $this->provider = $attempt === null ? null : (string) $attempt->provider;
        $this->currency = $attempt === null ? null : (string) $attempt->currency;
        $this->amountMinor = $attempt === null ? null : (int) $attempt->amount_minor;
        $this->methodType = $attempt?->method_type;
    }
}
