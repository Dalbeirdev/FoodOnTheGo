<?php

namespace App\Events;

use App\Auth\Principal;
use App\Models\SecurityEvent;

/**
 * Raised after a security event row is written (Module 21 recorder). Module 33 listens to it for the customer notices
 * that follow a security event (a changed phone number); nothing else reacts to it.
 */
final readonly class SecurityEventRecorded
{
    public function __construct(public SecurityEvent $event, public ?Principal $principal) {}
}
