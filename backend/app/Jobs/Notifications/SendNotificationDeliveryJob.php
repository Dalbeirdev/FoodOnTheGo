<?php

namespace App\Jobs\Notifications;

use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sends one delivery. The job itself never retries (tries = 1): the delivery service owns the retry state (attempt
 * count, backoff, next_retry_at) and re-dispatches a delayed job; a crashed worker leaves the row SENDING and
 * `notifications:recover` re-queues it after the lease. Unique per delivery until processing starts (so the retry the
 * handler itself dispatches is not swallowed by its own lock); the send leases the row, so two workers never race on it.
 */
class SendNotificationDeliveryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue((string) config('notifications.queue'));
    }

    /** Clears a lock a crashed worker may have left (the sweep calls this before re-dispatching). */
    public static function releaseLock(int $deliveryId): void
    {
        (new UniqueLock(Cache::store()))->release(new self($deliveryId));
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(NotificationDeliveryService $deliveries): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery !== null) {
            $deliveries->process($delivery);
        }
    }
}
