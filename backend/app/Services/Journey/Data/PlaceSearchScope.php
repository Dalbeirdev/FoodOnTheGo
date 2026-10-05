<?php

namespace App\Services\Journey\Data;

use App\Support\Geo\Location;

/**
 * Where a place search is allowed to look: the market's country (a hard boundary, read from the market —
 * never a literal country in provider code), the language, an optional bias point and the result cap.
 */
final readonly class PlaceSearchScope
{
    public function __construct(
        public string $countryCode,
        public string $languageCode = 'en',
        public ?Location $near = null,
        public int $limit = 6,
        public ?string $timezone = null,
    ) {}
}
