<?php

namespace App\Jobs\Payment;

use App\Services\Payment\PaymentWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handles one stored provider event off the request path (Module 30).
 *
 * The webhook request only verifies, stores and answers; this job does the work. It can run any number of times —
 * the event is done once — and when it keeps failing the event stays marked FAILED, is listed with the failed
 * jobs, and is picked up again by the scheduled sweep. An accepted event is never lost.
 */
class ProcessPaymentWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue((string) config('payments.webhook.queue'));
        // The event row must be visible to the worker: wait for the transaction that stored it.
        $this->afterCommit();
    }

    public function handle(PaymentWebhookService $webhooks): void
    {
        $webhooks->process($this->eventId);
    }
}
