<?php

namespace App\Services\Journey;

use App\Exceptions\ProviderException;
use App\Models\Market;
use App\Services\Journey\Data\PlaceSearchScope;
use App\Services\Journey\Data\PlaceSuggestion;
use App\Services\Journey\Data\ResolvedPlace;
use App\Services\Journey\Providers\ProviderManager;
use App\Services\Market\MarketLocationResolver;
use App\Support\Geo\Location;
use Illuminate\Support\Facades\Cache;

/**
 * Place search and resolution for the journey planner, on whatever PlacesProvider is configured. The search is
 * restricted to the market's country (read from the market, never a literal), briefly cached per normalized
 * query so a traveller's keystrokes do not become one provider call each, and a resolved place always carries
 * a time zone (the market's, or its nearest city's when a market spans several).
 */
final class PlaceService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly ProviderCall $call,
        private readonly MarketLocationResolver $resolver,
    ) {}

    /**
     * @return list<PlaceSuggestion>
     */
    public function search(string $query, Market $market, ?Location $near = null): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', ' ', $query)));
        if (mb_strlen($text) < (int) config('journey.places.min_query_length')) {
            return [];
        }
        $text = mb_substr($text, 0, 120);

        try {
            $provider = $this->providers->places();
            $scope = $this->scope($market, $near);
            $key = 'journey:places:'.$provider->name().':'.$market->country_code.':'.hash('sha256', mb_strtolower($text).'|'.($near === null ? '' : round($near->latitude, 2).','.round($near->longitude, 2)));

            // Plain rows in the cache (no PHP class is unserialized from it); suggestions are rebuilt on read.
            $rows = Cache::remember($key, (int) config('journey.cache.places_seconds'), fn (): array => array_map(
                fn (PlaceSuggestion $suggestion): array => $suggestion->toArray(),
                $this->call->run($provider->name(), 'places.search', fn (): array => $provider->search($text, $scope)),
            ));

            return array_map(fn (array $row): PlaceSuggestion => PlaceSuggestion::fromArray($row), array_values($rows));
        } catch (ProviderException $e) {
            throw $e->toApi('places');
        }
    }

    public function resolve(string $placeId, Market $market): ResolvedPlace
    {
        try {
            $provider = $this->providers->places();
            $scope = $this->scope($market);
            $place = $this->call->run($provider->name(), 'places.resolve', fn (): ResolvedPlace => $provider->resolve($placeId, $scope));
        } catch (ProviderException $e) {
            throw $e->toApi('places');
        }

        return $place->location->timezone !== null ? $place : new ResolvedPlace($place->provider, $place->placeId, $place->name, $this->withTimezone($place->location, $market), $place->kind);
    }

    /**
     * The same location with a time zone: the market's single zone, otherwise the nearest known city's.
     */
    public function withTimezone(Location $location, Market $market): Location
    {
        if ($location->timezone !== null) {
            return $location;
        }

        return new Location(
            $location->latitude, $location->longitude, $location->countryCode, $location->formattedAddress, $location->placeId,
            $location->region, $location->locality, $location->postalCode, $this->timezoneFor($location, $market),
        );
    }

    public function timezoneFor(Location $location, Market $market): string
    {
        if (($market->timezone_strategy ?? 'single') !== 'single') {
            $city = $this->resolver->nearestCity($location, $market);
            if ($city !== null && $city->timezone) {
                return (string) $city->timezone;
            }
        }

        return (string) ($market->default_timezone ?: 'UTC');
    }

    public function scope(Market $market, ?Location $near = null): PlaceSearchScope
    {
        return new PlaceSearchScope(
            (string) $market->country_code,
            strtolower(explode('-', (string) ($market->default_locale ?: 'en'))[0]) ?: 'en',
            $near,
            max(1, (int) config('journey.places.max_results')),
            (string) ($market->default_timezone ?: 'UTC'),
        );
    }

    /**
     * @return array{name: string, mode: string}
     */
    public function provider(): array
    {
        $provider = $this->providers->places();

        return ['name' => $provider->name(), 'mode' => $provider->isFake() ? 'development' : 'live'];
    }
}
