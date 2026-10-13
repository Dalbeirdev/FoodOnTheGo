<?php

namespace App\Events\Journey;

use App\Models\Journey;
use App\Services\Journey\DiscoveryResult;

/**
 * Discovery answered for a journey: these restaurants were returned, or none and why (Module 38). A product event —
 * it feeds analytics with which restaurants were RETURNED (not proven seen) and with the reason of an empty answer.
 */
final class JourneyDiscovered
{
    public function __construct(public readonly Journey $journey, public readonly DiscoveryResult $result) {}
}
