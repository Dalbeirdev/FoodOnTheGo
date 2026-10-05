<?php

namespace App\Services\Journey;

use App\Support\Geo\Location;

/**
 * One end of a journey as planned: the coordinates every decision uses (a Module 22 Location), and the display
 * data that travels with the journey. Origin and destination are the same type — there is no second shape.
 */
final readonly class JourneyEndpoint
{
    public function __construct(
        public Location $location,
        public string $label,
        /** place | coordinates | saved_location */
        public string $source,
        public string $kind = 'landmark',
        public ?string $placeProvider = null,
        public ?string $placeId = null,
    ) {}

    /**
     * The jsonb stored on the journey row.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return [
            'label' => $this->label,
            'formatted_address' => $this->location->formattedAddress,
            'lat' => $this->location->latitude,
            'lng' => $this->location->longitude,
            'country_code' => $this->location->countryCode,
            'region' => $this->location->region,
            'locality' => $this->location->locality,
            'postal_code' => $this->location->postalCode,
            'timezone' => $this->location->timezone,
            'place' => $this->placeId === null ? null : ['provider' => $this->placeProvider, 'id' => $this->placeId],
            'source' => $this->source,
            'kind' => $this->kind,
        ];
    }
}
