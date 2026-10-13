<?php

namespace App\Events\Journey;

use App\Models\Journey;

/**
 * A journey was planned: a route was found and stored for its owner (Module 38). A product event — it feeds
 * analytics with the journey's bands and corridors, never with its addresses, its points or its route.
 */
final class JourneyPlanned
{
    public function __construct(public readonly Journey $journey) {}
}
