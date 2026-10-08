<?php

namespace App\Listeners;

use App\Events\Order\OrderEvent;
use Illuminate\Support\Facades\Log;

/**
 * One log line per order event (Module 31): ids, states and codes — never an item, a note, a phone number or an
 * amount of anybody.
 */
final class RecordOrderEvent
{
    public function handle(OrderEvent $event): void
    {
        Log::info($event->type, array_filter([
            'order' => $event->orderId,
            'order_number' => $event->orderNumber,
            'status' => $event->status,
            'checkout' => $event->checkoutId,
            'payment' => $event->paymentId,
            'location' => $event->locationId,
            'detail' => $event->detail === [] ? null : array_intersect_key($event->detail, array_flip(['trigger', 'from', 'to', 'reason', 'code', 'refund', 'actor'])),
        ], fn ($v) => $v !== null));
    }
}
