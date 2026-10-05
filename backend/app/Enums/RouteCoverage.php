<?php

namespace App\Enums;

/**
 * Whether FoodOnTheGo serves anything along a route. A route may exist (the routing provider found a road)
 * while no serviceable area lies within the search corridor: that is an answer with zero restaurants and this
 * reason, never an error.
 */
enum RouteCoverage: string
{
    case Covered = 'COVERED';
    case NoServiceCoverage = 'NO_SERVICE_COVERAGE';
}
