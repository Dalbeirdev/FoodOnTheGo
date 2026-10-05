<?php

namespace App\Services\Journey\Providers;

use App\Contracts\Journey\PlacesProvider;
use App\Exceptions\ProviderException;
use App\Services\Journey\Data\PlaceSearchScope;
use App\Services\Journey\Data\PlaceSuggestion;
use App\Services\Journey\Data\ResolvedPlace;
use App\Support\Geo\Location;

/**
 * Google Places API (New): text search for suggestions, place details for coordinates. Results are limited to
 * the scope's country (the market's, read from configuration — never a literal here). Only the fields needed
 * are requested, and nothing of the response is kept beyond the journey's own lifetime.
 *
 * Live use needs PLACES_DRIVER=google and PLACES_API_KEY — PENDING PROVIDER CONFIGURATION in this repository;
 * the adapter is verified against recorded response shapes (contract tests), not against the live service.
 */
final class GooglePlacesProvider implements PlacesProvider
{
    use CallsGoogleApis;

    public const NAME = 'google';

    private const SEARCH_MASK = 'places.id,places.displayName,places.formattedAddress,places.location,places.addressComponents,places.primaryType,places.types';

    private const DETAILS_MASK = 'id,displayName,formattedAddress,location,addressComponents,primaryType,types';

    /**
     * @param  array<string, mixed>  $config  services.places
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isFake(): bool
    {
        return false;
    }

    public function search(string $query, PlaceSearchScope $scope): array
    {
        $body = [
            'textQuery' => $query,
            'regionCode' => strtolower($scope->countryCode),
            'languageCode' => $scope->languageCode,
            'maxResultCount' => min(20, max(1, $scope->limit * 2)),
        ];
        if ($scope->near !== null) {
            $body['locationBias'] = ['circle' => ['center' => ['latitude' => $scope->near->latitude, 'longitude' => $scope->near->longitude], 'radius' => 50000.0]];
        }

        $response = $this->call(fn () => $this->request(self::SEARCH_MASK)->post($this->baseUrl('https://places.googleapis.com/v1').'/places:searchText', $body));

        $suggestions = [];
        foreach ((array) $response->json('places', []) as $place) {
            $parsed = $this->parse((array) $place);
            if ($parsed === null || $parsed['country'] !== $scope->countryCode) {
                continue; // outside the market — a hard boundary, applied after the fact because text search only biases
            }
            $suggestions[] = new PlaceSuggestion(self::NAME, $parsed['id'], $parsed['name'], $parsed['address'], $parsed['country'], $parsed['kind'], $parsed['region'], $parsed['locality']);
            if (count($suggestions) >= $scope->limit) {
                break;
            }
        }

        return $suggestions;
    }

    public function resolve(string $placeId, PlaceSearchScope $scope): ResolvedPlace
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,200}$/', $placeId) !== 1) {
            throw ProviderException::placeNotFound(self::NAME);
        }
        $response = $this->call(
            fn () => $this->request(self::DETAILS_MASK)->get($this->baseUrl('https://places.googleapis.com/v1').'/places/'.$placeId, ['languageCode' => $scope->languageCode]),
            fn (): ProviderException => ProviderException::placeNotFound(self::NAME),
        );
        $parsed = $this->parse((array) $response->json());
        if ($parsed === null || $parsed['lat'] === null) {
            throw ProviderException::placeNotFound(self::NAME);
        }

        return new ResolvedPlace(self::NAME, $parsed['id'], $parsed['name'], new Location(
            $parsed['lat'], $parsed['lng'], $parsed['country'], $parsed['address'], $parsed['id'], $parsed['region'], $parsed['locality'], $parsed['postal_code'], $scope->timezone,
        ), $parsed['kind']);
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array{id: string, name: string, address: string, country: string|null, kind: string, region: string|null, locality: string|null, postal_code: string|null, lat: float|null, lng: float|null}|null
     */
    private function parse(array $place): ?array
    {
        $id = (string) ($place['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $components = ['country' => null, 'administrative_area_level_1' => null, 'locality' => null, 'postal_code' => null];
        foreach ((array) ($place['addressComponents'] ?? []) as $component) {
            foreach ($components as $type => $value) {
                if ($value === null && in_array($type, (array) ($component['types'] ?? []), true)) {
                    $components[$type] = $type === 'country' ? strtoupper((string) ($component['shortText'] ?? '')) : (string) ($component['longText'] ?? $component['shortText'] ?? '');
                }
            }
        }
        $types = (array) ($place['types'] ?? []);
        $kind = match (true) {
            in_array('airport', $types, true) => 'airport',
            in_array('train_station', $types, true) || in_array('transit_station', $types, true) || in_array('bus_station', $types, true) => 'station',
            in_array('locality', $types, true) || in_array('administrative_area_level_2', $types, true) => 'city',
            in_array('street_address', $types, true) || in_array('premise', $types, true) || in_array('route', $types, true) => 'address',
            default => 'landmark',
        };
        $country = $components['country'] !== null && preg_match('/^[A-Z]{2}$/', $components['country']) === 1 ? $components['country'] : null;
        $lat = isset($place['location']['latitude']) ? (float) $place['location']['latitude'] : null;
        $lng = isset($place['location']['longitude']) ? (float) $place['location']['longitude'] : null;

        return [
            'id' => $id,
            'name' => (string) ($place['displayName']['text'] ?? $place['formattedAddress'] ?? $id),
            'address' => (string) ($place['formattedAddress'] ?? ''),
            'country' => $country,
            'kind' => $kind,
            'region' => $components['administrative_area_level_1'],
            'locality' => $components['locality'],
            'postal_code' => $components['postal_code'],
            'lat' => $lat !== null && $lng !== null ? $lat : null,
            'lng' => $lat !== null && $lng !== null ? $lng : null,
        ];
    }
}
