<?php

namespace App\Services\Payment\Data;

use Carbon\CarbonImmutable;

/**
 * A provider event, read from a webhook body whose signature has been verified.
 */
final readonly class ProviderWebhook
{
    public const PAYMENT_AUTHORIZED = 'PAYMENT_AUTHORIZED';

    public const PAYMENT_CAPTURED = 'PAYMENT_CAPTURED';

    public const PAYMENT_FAILED = 'PAYMENT_FAILED';

    public const ORDER_PAID = 'ORDER_PAID';

    // Module 35: refunds and disputes.
    public const REFUND_CREATED = 'REFUND_CREATED';

    public const REFUND_PROCESSED = 'REFUND_PROCESSED';

    public const REFUND_FAILED = 'REFUND_FAILED';

    public const DISPUTE_CREATED = 'DISPUTE_CREATED';

    public const DISPUTE_UPDATED = 'DISPUTE_UPDATED';

    public const DISPUTE_WON = 'DISPUTE_WON';

    public const DISPUTE_LOST = 'DISPUTE_LOST';

    public const DISPUTE_CLOSED = 'DISPUTE_CLOSED';

    public function __construct(
        /** The provider's unique id of the event, when it sends one. */
        public ?string $eventReference,
        /** The provider's own event name. */
        public string $type,
        /** One of the constants above, or null for an event FoodOnTheGo does not act on. */
        public ?string $normalizedType,
        public ?string $orderReference,
        public ?ProviderPayment $payment,
        public ?CarbonImmutable $createdAt,
        public ?ProviderRefund $refund = null,
        public ?ProviderDispute $dispute = null,
    ) {}

    public function handled(): bool
    {
        return $this->normalizedType !== null;
    }

    public function aboutRefund(): bool
    {
        return $this->normalizedType !== null && str_starts_with($this->normalizedType, 'REFUND_');
    }

    public function aboutDispute(): bool
    {
        return $this->normalizedType !== null && str_starts_with($this->normalizedType, 'DISPUTE_');
    }
}
