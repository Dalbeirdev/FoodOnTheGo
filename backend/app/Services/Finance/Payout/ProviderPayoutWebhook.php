<?php

namespace App\Services\Finance\Payout;

use Carbon\CarbonImmutable;

/**
 * What a payout provider's webhook says (Module 36): which payout, which event, when. It is a hint to look — the
 * payout's state is always read from the provider's own record afterwards, never from the webhook's body.
 */
final readonly class ProviderPayoutWebhook
{
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $payoutReference,
        public ?CarbonImmutable $createdAt,
    ) {}
}
