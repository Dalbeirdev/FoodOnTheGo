<?php

namespace App\Listeners;

use App\Events\Refund\RefundEvent;
use App\Services\Refund\RefundMetrics;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Every refund event is counted and written to the log (Module 35) — with the refund's and the payment's public
 * ids, the order number, the amount in minor units, the currency, the status, the provider's name and codes. Never a
 * key, a signature, a card or UPI detail, a customer's contact details or a provider's raw answer: the event does
 * not carry them.
 */
final class RecordRefundEvent
{
    public function __construct(private readonly RefundMetrics $metrics) {}

    public function handle(RefundEvent $event): void
    {
        $this->metrics->record($event->name);
        $level = in_array($event->name, [RefundEvent::FAILED, RefundEvent::REVIEW_REQUIRED, RefundEvent::SUBMISSION_UNKNOWN, RefundEvent::OVER_REFUND_BLOCKED, RefundEvent::EXTERNAL_REFUND_SEEN], true) ? 'warning' : 'info';
        Log::log($level, 'refund.'.$event->name, array_filter([
            'request_id' => Context::get('request_id'),
            'refund' => $event->refundId,
            'payment' => $event->paymentId,
            'order_number' => $event->orderNumber,
            'status' => $event->status,
            'provider' => $event->provider,
            'currency' => $event->currency,
            'amount_minor' => $event->amountMinor,
            'reason' => $event->reason,
        ], fn ($v): bool => $v !== null) + $event->facts);
    }
}
