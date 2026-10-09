<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Events\Order\OrderEvent as OrderDomainEvent;
use App\Exceptions\ApiException;
use App\Jobs\Order\CompleteOrderJob;
use App\Models\Order;
use App\Models\OrderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PICKED_UP → COMPLETED (Module 32). V1 has no step between the verified pickup and the end of the order, so the
 * order completes by itself — in its own transaction after the pickup commit (never inside it: a failure here leaves
 * the order PICKED_UP, the credential consumed, and the sweep completes it later). Idempotent: a completed order is
 * returned as it is; any other state than PICKED_UP is refused. Both lines stay on the timeline.
 *
 * Completion changes nothing about the money: the payment keeps its state and amount, the snapshot is immutable.
 */
class OrderCompletionService   // not final: tests replace the completion to prove a pickup survives its failure
{
    public const TRIGGER_AFTER_PICKUP = 'AUTO_AFTER_PICKUP';

    public const TRIGGER_SWEEP = 'SWEEP';

    public function __construct(private readonly OrderStore $orders, private readonly OrderStateMachine $machine, private readonly OrderMetrics $metrics) {}

    /**
     * Called right after a pickup commit: tries once in this request; if that fails, a queued job retries, and the
     * sweep is the last net. Nothing here can undo the pickup.
     */
    public function completeAfterPickup(Order $order): void
    {
        try {
            $this->complete($order, self::TRIGGER_AFTER_PICKUP);
        } catch (\Throwable $e) {
            Log::warning('order.completion_deferred', ['order' => (string) $order->public_id, 'error' => $e::class]);
            $this->metrics->record('completion_deferred');
            try {
                CompleteOrderJob::dispatch((int) $order->getKey(), self::TRIGGER_AFTER_PICKUP);
            } catch (\Throwable $again) {
                // A synchronous queue runs the job here and it failed too: the sweep is the last net.
                Log::warning('order.completion_job_failed', ['order' => (string) $order->public_id, 'error' => $again::class]);
            }
        }
    }

    /**
     * @throws ApiException 409 order_invalid_transition when the order is not PICKED_UP (and not already COMPLETED)
     */
    public function complete(Order $order, string $trigger = self::TRIGGER_AFTER_PICKUP, ?CarbonImmutable $at = null): Order
    {
        $at ??= CarbonImmutable::instance(now());

        return DB::transaction(function () use ($order, $trigger, $at): Order {
            $locked = $this->orders->locked((int) $order->getKey());
            if ($locked->status === OrderStatus::Completed) {
                return $locked->load(OrderStore::relations());
            }
            if ($locked->status !== OrderStatus::PickedUp) {
                throw ApiException::conflict('order_invalid_transition', 'Only a picked-up order can be completed.', ['from' => $locked->status->value, 'to' => OrderStatus::Completed->value]);
            }
            $this->machine->move($locked, OrderStatus::Completed, OrderEvent::ACTOR_SYSTEM, null, ['trigger' => $trigger], $at);
            $this->metrics->record('completed');
            $this->metrics->record('completed_'.strtolower($trigger));
            DB::afterCommit(function () use ($locked, $trigger): void {
                event(new OrderDomainEvent(OrderDomainEvent::COMPLETED, $locked, ['trigger' => $trigger]));
            });

            return $locked->load(OrderStore::relations());
        });
    }

    /**
     * The sweep (orders:recover): orders picked up a while ago that are still not completed — a job that failed for
     * good, or a worker that was not running.
     */
    public function sweep(): int
    {
        if (! (bool) config('orders.completion.automatic')) {
            return 0;
        }
        $cutoff = CarbonImmutable::instance(now())->subSeconds(max(1, (int) config('orders.completion.sweep_after_seconds')));
        $done = 0;
        Order::query()->where('status', OrderStatus::PickedUp->value)->where('picked_up_at', '<=', $cutoff)->orderBy('picked_up_at')->limit(200)->get()
            ->each(function (Order $order) use (&$done): void {
                try {
                    $this->complete($order, self::TRIGGER_SWEEP);
                    $done++;
                } catch (\Throwable $e) {
                    Log::warning('order.completion_sweep_failed', ['order' => (string) $order->public_id, 'error' => $e::class]);
                }
            });

        return $done;
    }
}
