<?php

namespace App\Listeners;

use App\Events\Cart\CartActivity;
use App\Events\Cart\CartEvent;
use App\Services\Cart\CartMetrics;
use Illuminate\Support\Facades\Log;

/**
 * Every cart event becomes a counter and one log line: the cart, its owner type, its restaurant and the event's
 * own numbers. A cart is private — what is in it, what the customer wrote and where they are going is never
 * logged, so there is nothing here to redact.
 */
class RecordCartEvent
{
    public function __construct(private readonly CartMetrics $metrics) {}

    public function handle(CartActivity $event): void
    {
        if (! $event instanceof CartEvent) {
            return;
        }
        $this->metrics->record($event->name());
        if (is_int($event->facts['price_changes'] ?? null)) {
            $this->metrics->record('price_changes_accepted', $event->facts['price_changes']);
        }

        Log::info('cart.'.$event->name(), ['cart' => $event->cartId, 'owner' => $event->owner, 'restaurant' => $event->restaurantId] + $event->facts);
    }
}
