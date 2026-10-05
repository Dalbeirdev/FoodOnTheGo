<?php

namespace App\Enums;

/**
 * Outcome of the actual detour calculation for one restaurant.
 */
enum DetourStatus: string
{
    case Ok = 'OK';

    /** The provider found no drivable way via the restaurant (barrier, closed road, island). */
    case NoRoute = 'NO_ROUTE';

    /** The provider answered for the batch but not for this restaurant. */
    case Failed = 'FAILED';
}
