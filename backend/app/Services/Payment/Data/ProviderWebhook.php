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
    ) {}

    public function handled(): bool
    {
        return $this->normalizedType !== null;
    }
}
