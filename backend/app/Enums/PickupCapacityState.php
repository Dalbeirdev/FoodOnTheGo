<?php

namespace App\Enums;

/**
 * What a customer may know about the capacity of a pickup slot. Never a count: how many orders a restaurant
 * takes per slot, and how many other customers chose it, is not the customer's business.
 */
enum PickupCapacityState: string
{
    case Available = 'AVAILABLE';
    case Limited = 'LIMITED';
    case Full = 'FULL';
}
