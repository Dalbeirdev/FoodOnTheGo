<?php

namespace App\Enums;

/**
 * Health of an external provider as derived from the platform's own recent calls to it. Nothing is claimed
 * without calls: no recent call = UNKNOWN.
 */
enum ProviderHealthState: string
{
    case Operational = 'OPERATIONAL';
    case Degraded = 'DEGRADED';
    case Outage = 'OUTAGE';
    case Unknown = 'UNKNOWN';
}
