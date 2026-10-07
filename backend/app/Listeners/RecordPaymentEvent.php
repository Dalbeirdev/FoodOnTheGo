<?php

namespace App\Listeners;

use App\Events\Payment\PaymentEvent;
use App\Services\Payment\PaymentMetrics;
use Illuminate\Support\Facades\Log;

/**
 * Every payment event is counted and written to the log (Module 30) — with ids, the status, the provider's name,
 * the amount in minor units and codes. Never a key, a signature, a customer's contact details or a provider's
 * raw answer: the event does not carry them.
 */
class RecordPaymentEvent
{
    public function __construct(private readonly PaymentMetrics $metrics) {}

    public function handle(PaymentEvent $event): void
    {
        $this->metrics->record($event->name);
        if ($event->name === PaymentEvent::VERIFIED) {
            $this->metrics->recordVerifiedMethod($event->methodType);
            if (is_int($event->facts['verification_ms'] ?? null) && $event->facts['verification_ms'] > 0) {
                $this->metrics->record('verification_ms_total', $event->facts['verification_ms']);
            }
        }
        if ($event->name === PaymentEvent::WEBHOOK_PROCESSED && is_int($event->facts['processing_ms'] ?? null) && $event->facts['processing_ms'] > 0) {
            $this->metrics->record('webhook_processing_ms_total', $event->facts['processing_ms']);
        }

        $level = in_array($event->name, [PaymentEvent::SIGNATURE_INVALID, PaymentEvent::WEBHOOK_REJECTED, PaymentEvent::MISMATCH, PaymentEvent::DUPLICATE_PAYMENT, PaymentEvent::REVIEW_REQUIRED, PaymentEvent::RECOVERY_REQUIRED], true) ? 'warning' : 'info';
        Log::log($level, 'payment.'.$event->name, array_filter([
            'payment' => $event->paymentId,
            'checkout' => $event->checkoutId,
            'status' => $event->status,
            'provider' => $event->provider,
            'currency' => $event->currency,
            'amount_minor' => $event->amountMinor,
        ], fn ($v): bool => $v !== null) + $event->facts);
    }
}
