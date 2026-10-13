<?php

namespace App\Listeners;

use App\Events\Journey\JourneyDiscovered;
use App\Events\Journey\JourneyPlanned;
use App\Events\Pickup\PickupSelectionEvent;
use App\Services\Analytics\ServerEventRecorder;

/**
 * Turns what the backend answered into server-recorded analytics events (Module 38): a planned journey, a discovery
 * answer, a refused pickup time. The recorder swallows its own failures — a traveller's request never fails, and is
 * never told anything, because analytics could not write.
 */
final class RecordAnalyticsEvents
{
    public function __construct(private readonly ServerEventRecorder $recorder) {}

    public function handleJourneyPlanned(JourneyPlanned $event): void
    {
        $this->recorder->journeyPlanned($event->journey);
    }

    public function handleJourneyDiscovered(JourneyDiscovered $event): void
    {
        $this->recorder->journeyDiscovered($event->journey, $event->result);
    }

    public function handlePickupSelection(PickupSelectionEvent $event): void
    {
        if ($event->name === PickupSelectionEvent::REFUSED) {
            $this->recorder->pickupRefused($event->restaurantId, isset($event->facts['reason']) ? (string) $event->facts['reason'] : null);
        }
    }
}
