<?php

namespace App\Listeners;

use App\Events\Cart\CartActivity;
use App\Events\Cart\CartEvent;
use App\Events\Pickup\PickupSelectionEvent;
use App\Services\Checkout\CheckoutService;

/**
 * A cart or its pickup time changed (Modules 27 / 28): the open checkouts of that cart that no longer match it go
 * stale at once and give back the pickup time they held (Module 29). Reading a checkout would find the same thing —
 * this only makes sure a held place is not kept until somebody looks.
 */
class InvalidateCheckoutsOfChangedCart
{
    public function __construct(private readonly CheckoutService $checkouts) {}

    public function handle(CartActivity|PickupSelectionEvent $event): void
    {
        if ($event instanceof PickupSelectionEvent) {
            if (in_array($event->name, [PickupSelectionEvent::SELECTED, PickupSelectionEvent::CLEARED], true)) {
                $this->checkouts->invalidateForCart($event->cartId);
            }

            return;
        }
        if ($event instanceof CartEvent) {
            $this->checkouts->invalidateForCart($event->cartId);
        }
    }
}
