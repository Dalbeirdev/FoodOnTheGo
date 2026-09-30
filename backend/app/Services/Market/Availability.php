<?php

namespace App\Services\Market;

use App\Enums\AvailabilityReason;
use App\Models\City;
use App\Models\Market;
use App\Models\MarketRegion;
use App\Models\ServiceArea;

/**
 * The answer to "can FoodOnTheGo serve this location?". `reason` is set exactly when `supported` is false.
 * Records that are not open to customers (a draft market, a planned city) are not carried here, so they
 * cannot leak through the public API.
 */
final readonly class Availability
{
    public function __construct(
        public bool $supported,
        public ?AvailabilityReason $reason,
        public ?Market $market = null,
        public ?MarketRegion $region = null,
        public ?City $city = null,
        public ?ServiceArea $serviceArea = null,
    ) {}

    public static function unsupported(AvailabilityReason $reason, ?Market $market = null, ?MarketRegion $region = null, ?City $city = null): self
    {
        return new self(false, $reason, $market, $region, $city);
    }
}
