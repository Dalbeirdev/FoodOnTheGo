<?php

namespace App\Enums;

/**
 * Cuisines and restaurant features are data, not code. An entry that is no longer offered becomes INACTIVE —
 * it is never deleted while restaurants still reference it.
 */
enum TaxonomyStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
