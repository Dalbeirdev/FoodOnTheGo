<?php

namespace App\Services\Journey\Data;

/**
 * One row of a place search: enough to show a suggestion and to resolve it later. Coordinates may be absent
 * (some providers only return them on resolve) and are never trusted from a client anyway.
 */
final readonly class PlaceSuggestion
{
    public function __construct(
        public string $provider,
        public string $placeId,
        public string $name,
        public string $formattedAddress,
        public ?string $countryCode,
        /** city | station | airport | landmark | address */
        public string $kind = 'landmark',
        public ?string $region = null,
        public ?string $locality = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row  a row written by toArray()
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['provider'], (string) $row['place_id'], (string) $row['name'], (string) $row['formatted_address'], $row['country_code'] ?? null,
            (string) ($row['kind'] ?? 'landmark'), $row['region'] ?? null, $row['locality'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'place_id' => $this->placeId,
            'name' => $this->name,
            'formatted_address' => $this->formattedAddress,
            'country_code' => $this->countryCode,
            'kind' => $this->kind,
            'region' => $this->region,
            'locality' => $this->locality,
        ];
    }
}
