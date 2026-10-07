<?php

namespace App\Listeners;

use App\Events\Checkout\CheckoutEvent;
use App\Services\Checkout\CheckoutMetrics;
use Illuminate\Support\Facades\Log;

/**
 * Counts checkout events and writes one log line each: the checkout's public id, its status, its currency and total
 * in minor units and a reason code. No cart contents, no order note, no phone number, no payment reference.
 */
class RecordCheckoutEvent
{
    public function __construct(private readonly CheckoutMetrics $metrics) {}

    public function handle(CheckoutEvent $event): void
    {
        $this->metrics->record($event->name);
        $reason = (string) ($event->facts['reason'] ?? '');
        if ($event->name === CheckoutEvent::STALE && in_array($reason, ['PRICE_CHANGED', 'PRICING_CHANGED'], true)) {
            $this->metrics->record('price_changed');
        }
        if (in_array($event->name, [CheckoutEvent::STALE, CheckoutEvent::BLOCKED], true) && str_starts_with($reason, 'PICKUP_')) {
            $this->metrics->record('pickup_invalid');
        }
        if ($event->name === CheckoutEvent::PROMOTION_APPLIED && ($event->discountMinor ?? 0) > 0) {
            $this->metrics->record('discount_minor_total', (int) $event->discountMinor);
        }

        Log::info('checkout.'.$event->name, array_filter([
            'checkout' => $event->checkoutId,
            'status' => $event->status,
            'currency' => $event->currency,
            'total_minor' => $event->totalMinor,
        ], fn ($v): bool => $v !== null) + $event->facts);
    }
}
