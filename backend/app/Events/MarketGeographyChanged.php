<?php

namespace App\Events;

use App\Models\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A region, city, service area or route corridor of a market was created or changed by an administrator.
 * Raised inside the same transaction, so whatever depends on the geography stays consistent with it.
 */
class MarketGeographyChanged
{
    use Dispatchable;

    public function __construct(public readonly Market $market, public readonly Model $record) {}
}
