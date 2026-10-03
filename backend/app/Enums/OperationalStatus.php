<?php

namespace App\Enums;

/**
 * What the restaurant itself says about the outlet, independent of the weekly hours:
 *
 * - OPERATING: the outlet runs normally; whether it is open right now follows its hours.
 * - TEMPORARILY_CLOSED: closed until further notice (renovation, staff shortage …) whatever the hours say.
 *
 * This is not the administrative status (approval / suspension) and not the "accepting FoodOnTheGo orders" switch.
 */
enum OperationalStatus: string
{
    case Operating = 'OPERATING';
    case TemporarilyClosed = 'TEMPORARILY_CLOSED';
}
