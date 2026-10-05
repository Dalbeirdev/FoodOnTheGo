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

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
