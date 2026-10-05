<?php

namespace App\Services\Journey\Data;

use Carbon\CarbonImmutable;

/**
 * What a route request may vary: driving only (Module 26 approves no other mode), an optional departure
 * instant with its offset, and the route modifiers a traveller may ask for.
 */
final readonly class RouteOptions
{
    public const TRAVEL_MODE = 'DRIVE';

    public function __construct(
        public ?CarbonImmutable $departureAt = null,
        public bool $avoidTolls = false,
        public bool $avoidFerries = false,
        public bool $avoidHighways = false,
        public string $languageCode = 'en',
    ) {}

    /**
     * @return array{avoid_tolls: bool, avoid_ferries: bool, avoid_highways: bool}
     */
    public function preferences(): array
    {
        return ['avoid_tolls' => $this->avoidTolls, 'avoid_ferries' => $this->avoidFerries, 'avoid_highways' => $this->avoidHighways];
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    public static function fromPreferences(array $preferences, ?CarbonImmutable $departureAt, string $languageCode = 'en'): self
    {
        return new self($departureAt, (bool) ($preferences['avoid_tolls'] ?? false), (bool) ($preferences['avoid_ferries'] ?? false), (bool) ($preferences['avoid_highways'] ?? false), $languageCode);
    }
}
