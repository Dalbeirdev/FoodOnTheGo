<?php

namespace App\Listeners;

use App\Events\Order\OrderEvent;
use App\Events\Order\OrderUpdated;
use App\Models\Order;
use App\Models\OrderEvent as Line;
use App\Services\Order\OrderMetrics;
use Illuminate\Support\Facades\Log;

/**
 * Turns a domain order event (dispatched after its commit) into the realtime notice on the private channels
 * (Module 32). Reads the order once, as committed, so the version and the latest timeline line are the truth at the
 * moment of sending. The customer's channel and the location's channel get their own payloads — the location's
 * carries the item count and the pickup window, the customer's a message key; neither carries a person.
 *
 * A broadcaster that is down costs a bounded timeout and a counted failure, nothing else: the order is already
 * committed, and every client re-reads it on reconnect or on its next poll.
 */
final class BroadcastOrderUpdate
{
    /** Domain events that are not a change a client waits for (operations only). */
    private const SILENT = [OrderEvent::RECOVERY_REQUIRED, OrderEvent::FULFILLMENT_FAILED];

    private const MESSAGE_KEYS = [
        'WAITING_FOR_RESTAURANT' => 'order.waiting',
        'ACCEPTED' => 'order.accepted',
        'PREPARING' => 'order.preparing',
        'READY_FOR_PICKUP' => 'order.ready',
        'PICKED_UP' => 'order.picked_up',
        'COMPLETED' => 'order.completed',
        'REJECTED' => 'order.rejected',
        'CANCELLED' => 'order.cancelled',
    ];

    public function __construct(private readonly OrderMetrics $metrics) {}

    public function handle(OrderEvent $event): void
    {
        if (! (bool) config('orders.realtime.enabled') || in_array($event->type, self::SILENT, true) || $event->orderKey === null) {
            return;
        }
        $order = Order::query()->with('location:id,public_id')->find($event->orderKey);
        if ($order === null) {
            return;
        }
        $line = Line::query()->where('order_id', $order->getKey())->orderByDesc('sequence')->first();
        $base = [
            'schema_version' => (int) config('orders.realtime.schema_version'),
            'order_public_id' => (string) $order->public_id,
            'order_number' => (string) $order->order_number,
            'version' => (int) $order->version,
            'status' => $order->status->value,
            'event_type' => $event->type,
            'event_sequence' => $line === null ? null : (int) $line->sequence,
            'event_id' => $line === null ? null : (string) $order->public_id.'-'.(int) $line->sequence,
            'payment_state' => (string) $order->payment_state->value,
            'refund_status' => (string) $order->refund_status->value,
            'pickup_at' => $order->pickup_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'occurred_at' => ($line?->occurred_at ?? $order->updated_at ?? now())->toIso8601String(),
        ];
        $customer = $base + ['message_key' => self::MESSAGE_KEYS[$order->status->value] ?? 'order.updated'];
        $restaurant = $base + [
            'item_count' => (int) $order->item_count,
            'pickup_slot_starts_at' => $order->pickup_slot_starts_at?->toIso8601String(),
            'pickup_slot_ends_at' => $order->pickup_slot_ends_at?->toIso8601String(),
        ];
        $started = hrtime(true);
        try {
            broadcast(new OrderUpdated($customer, ['customer.order.'.$order->public_id]));
            broadcast(new OrderUpdated($restaurant, ['restaurant.location.'.$order->location?->public_id]));
            $this->metrics->record('broadcast_sent', 2);
            $this->metrics->record('broadcast_ms_total', (int) ((hrtime(true) - $started) / 1_000_000));
        } catch (\Throwable $e) {
            $this->metrics->record('broadcast_failed');
            Log::warning('order.broadcast_failed', ['order' => (string) $order->public_id, 'event' => $event->type, 'error' => $e::class]);
        }
    }
}
