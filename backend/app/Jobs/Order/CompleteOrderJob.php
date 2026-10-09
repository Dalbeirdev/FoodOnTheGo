<?php

namespace App\Jobs\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderCompletionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Completes a picked-up order on the queue (Module 32) when the completion right after the pickup failed. Safe to
 * run twice: a completed order is left as it is. If every try fails the sweep (orders:recover) completes it.
 */
final class CompleteOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(public readonly int $orderId, public readonly string $trigger)
    {
        $this->onQueue((string) config('orders.fulfillment.queue'));
        $this->afterCommit();
    }

    public function handle(OrderCompletionService $completion): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null || $order->status !== OrderStatus::PickedUp) {
            return;
        }
        $completion->complete($order, $this->trigger);
    }
}
