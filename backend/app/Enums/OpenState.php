<?php

namespace App\Enums;

/**
 * The calculated answer to "is this restaurant open at this instant?" — derived from the operational status,
 * the special hours of the local date and the weekly hours, in the restaurant's own time zone.
 */
enum OpenState: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case TemporarilyClosed = 'TEMPORARILY_CLOSED';
}
