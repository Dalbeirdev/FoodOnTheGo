<?php

namespace App\Enums;

/**
 * Where a support case stands against its INTERNAL targets (Module 37). Not a contractual commitment and never shown
 * to a customer or a restaurant.
 */
enum SupportSlaStatus: string
{
    case OnTrack = 'ON_TRACK';
    case AtRisk = 'AT_RISK';
    case Breached = 'BREACHED';
    case Paused = 'PAUSED';
    /** No policy applies to the case: nothing is measured. */
    case NotApplicable = 'N/A';
}
