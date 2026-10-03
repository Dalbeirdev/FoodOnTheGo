<?php

namespace App\Enums;

/**
 * How an order is handed over. Which methods a location may offer is configuration: the market must allow
 * the method (config/restaurant.php maps a method to a market feature) and the location must enable it.
 */
enum PickupMethod: string
{
    case Counter = 'COUNTER';
    case Curbside = 'CURBSIDE';
    case DriveThrough = 'DRIVE_THROUGH';
}
