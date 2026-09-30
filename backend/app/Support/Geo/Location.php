<?php

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * One shape for "a place" across every API: WGS84 coordinates plus the optional descriptive fields a
 * places provider returns. Only the coordinates are trusted for decisions; the rest is a hint or display data.
 */
final readonly class Location
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $countryCode = null,
        public ?string $formattedAddress = null,
        public ?string $placeId = null,
        public ?string $region = null,
        public ?string $locality = null,
        public ?string $postalCode = null,
        public ?string $timezone = null,
    ) {
        Geo::assertCoordinates($latitude, $longitude);

        if ($countryCode !== null && preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new InvalidArgumentException('Country code must be ISO 3166-1 alpha-2.');
        }
    }

    /**
     * Validation rules for a request that carries a location under $prefix ("" = top level).
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'lat' => ['required', 'numeric', 'between:-90,90'],
            $prefix.'lng' => ['required', 'numeric', 'between:-180,180'],
            $prefix.'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            $prefix.'formatted_address' => ['sometimes', 'nullable', 'string', 'max:300'],
            $prefix.'place_id' => ['sometimes', 'nullable', 'string', 'max:200'],
            $prefix.'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            $prefix.'locality' => ['sometimes', 'nullable', 'string', 'max:120'],
            $prefix.'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            $prefix.'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) $data['lat'], (float) $data['lng'], $data['country_code'] ?? null, $data['formatted_address'] ?? null,
            $data['place_id'] ?? null, $data['region'] ?? null, $data['locality'] ?? null, $data['postal_code'] ?? null, $data['timezone'] ?? null,
        );
    }

    /**
     * geometry(Point, 4326) expression + bindings (longitude first).
     *
     * @return array{0: string, 1: array{0: float, 1: float}}
     */
    public function pointSql(): array
    {
        return ['ST_SetSRID(ST_MakePoint(?, ?), 4326)', [$this->longitude, $this->latitude]];
    }
}
