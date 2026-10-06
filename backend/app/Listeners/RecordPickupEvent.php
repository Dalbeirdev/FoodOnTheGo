<?php

namespace App\Listeners;

use App\Events\Pickup\PickupSelectionEvent;
use App\Services\Pickup\PickupMetrics;
use Illuminate\Support\Facades\Log;

/**
 * Every pickup-selection event becomes a counter and one log line: the cart, its owner type, its restaurant and
 * the event's codes. The chosen minute, the cart's contents and the journey are never logged.
 */
class RecordPickupEvent
{
    public function __construct(private readonly PickupMetrics $metrics) {}

    public function handle(PickupSelectionEvent $event): void
    {
        $this->metrics->record(match ($event->name) {
            PickupSelectionEvent::SELECTED => ($event->facts['mode'] ?? null) === 'ASAP' ? 'selected_asap' : 'selected_scheduled',
            default => $event->name,
        });
        if (($event->facts['reason'] ?? null) === 'SLOT_FULL') {
            $this->metrics->record('slot_full');
        }

        Log::info('pickup.'.$event->name, ['cart' => $event->cartId, 'owner' => $event->owner, 'restaurant' => $event->restaurantId] + $event->facts);
    }
}
