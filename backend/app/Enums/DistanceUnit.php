<?php

namespace App\Enums;

/**
 * Presentation unit system of a market. Distances are always stored and computed in metres.
 */
enum DistanceUnit: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';
}
