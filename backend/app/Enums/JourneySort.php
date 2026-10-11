<?php

namespace App\Enums;

/**
 * Deterministic orderings of route-based discovery results. "Recommended" is a transparent rule — orderable at
 * the expected arrival first, then open, then the smallest actual detour, then route order — never an opaque score.
 */
enum JourneySort: string
{
    case Recommended = 'recommended';
    case LowestDetour = 'lowest_detour';
    case RouteOrder = 'route_order';
    case NearestToRoute = 'nearest_to_route';
    case FastestPickup = 'fastest_pickup';
    // Module 34: the average of the published reviews, then their number, then the smallest detour; restaurants
    // without a review last. A plain average — no confidence weighting has been approved.
    case HighestRated = 'highest_rated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
