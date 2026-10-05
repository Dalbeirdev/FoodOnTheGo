<?php

namespace App\Services\Journey\Data;

use App\Support\Geo\Location;

/**
 * A place with coordinates, as the provider resolved it. The Location is the Module 22 shape every other
 * module reads — there is no second format for "a point on the map".
 */
final readonly class ResolvedPlace
{
    public function __construct(
        public string $provider,
        public string $placeId,
        public string $name,
        public Location $location,
        public string $kind = 'landmark',
    ) {}
}
