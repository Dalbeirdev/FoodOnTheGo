<?php

namespace App\Jobs\Order;

use App\Enums\OrderFulfillmentStatus;
use App\Models\OrderFulfillment;
use App\Services\Order\OrderFulfillmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns a verified payment into an order on the queue (Module 31). Safe to run twice: the fulfilment record is
 * locked and an order that exists is found, not created again. A technical failure is tried again with a growing
 * wait; a capacity / restaurant / market problem is handed to a person and not retried here.
 */
final class FulfillCheckoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $paymentPublicId)
    {
        $this->onQueue((string) config('orders.fulfillment.queue'));
        $this->afterCommit();
    }

    public function handle(OrderFulfillmentService $fulfillment): void
    {
        $result = $fulfillment->fulfillPayment($this->paymentPublicId, OrderFulfillment::TRIGGER_PAYMENT_VERIFIED);
        if ($result->status === OrderFulfillmentStatus::Retrying && $this->attempts() < $this->tries) {
            // The sweep tries again at the scheduled time whatever happens to this job; the queue's own retry only
            // shortens the wait for the first tries.
            $this->release((int) ($this->backoff[$this->attempts() - 1] ?? 120));
        }
    }
}
