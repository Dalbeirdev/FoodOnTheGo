<?php

namespace App\Contracts\Journey;

use App\Exceptions\ProviderException;
use App\Services\Journey\Data\PlaceSearchScope;
use App\Services\Journey\Data\PlaceSuggestion;
use App\Services\Journey\Data\ResolvedPlace;

/**
 * Finds places a traveller may type (cities, stations, airports, addresses) and resolves one to coordinates.
 *
 * The core never depends on a vendor: which implementation runs is configuration (PLACES_DRIVER). A provider
 * applies its own timeout, maps vendor errors to ProviderException and never logs queries or credentials.
 * Place text from a client is never trusted for a decision — only a resolved place's coordinates are.
 */
interface PlacesProvider
{
    /**
     * Driver name as configured ("fake", "google").
     */
    public function name(): string;

    /**
     * True for the deterministic development provider (never acceptable in production).
     */
    public function isFake(): bool;

    /**
     * Suggestions for a typed query, restricted to the scope's country.
     *
     * @return list<PlaceSuggestion>
     *
     * @throws ProviderException
     */
    public function search(string $query, PlaceSearchScope $scope): array;

    /**
     * Coordinates and address of one suggestion by its provider id.
     *
     * @throws ProviderException PLACE_NOT_FOUND when the id is unknown
     */
    public function resolve(string $placeId, PlaceSearchScope $scope): ResolvedPlace;
}
