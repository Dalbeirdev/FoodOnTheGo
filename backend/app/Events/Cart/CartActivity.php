<?php

namespace App\Events\Cart;

/**
 * Marker of every cart event, so one listener can record them all (Laravel resolves listeners by the event's
 * class and its interfaces, not by its parent class).
 */
interface CartActivity
{
    public function name(): string;
}
