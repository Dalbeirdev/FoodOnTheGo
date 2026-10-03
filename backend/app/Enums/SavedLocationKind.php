<?php

namespace App\Enums;

/**
 * What a saved journey location is for the customer. Shortcuts for planning a journey — never delivery addresses.
 * The free-text label carries the customer's own words ("Mum's place"); the kind only picks the icon.
 */
enum SavedLocationKind: string
{
    case Home = 'HOME';
    case Work = 'WORK';
    case Other = 'OTHER';
}
