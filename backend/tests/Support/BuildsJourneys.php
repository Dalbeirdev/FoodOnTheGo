<?php

namespace Tests\Support;

use App\Contracts\Journey\PlacesProvider;
use App\Contracts\Journey\RoutingProvider;
use App\Models\City;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Journey\Providers\FakePlacesProvider;
use App\Services\Journey\Providers\FakeRoutingProvider;
use Illuminate\Testing\TestResponse;

/**
 * Journeys for the Module 26 tests, on top of the restaurant world: a short west → east route across the
 * standard Noida service area, eligible restaurants placed by coordinates, and the two fake providers to script.
 *
 * The standard route runs along latitude 28.55 from longitude 77.30 to 77.40 (about 10 km, one segment).
 */
trait BuildsJourneys
{
    use BuildsCustomers, BuildsMenus, BuildsRestaurants;

    /** [lat, lng] */
    protected const WEST = [28.55, 77.30];

    protected const EAST = [28.55, 77.40];

    protected ?RestaurantOrganization $organization = null;

    protected function fakeRouting(): FakeRoutingProvider
    {
        $provider = app(RoutingProvider::class);
        assert($provider instanceof FakeRoutingProvider);

        return $provider;
    }

    protected function fakePlaces(): FakePlacesProvider
    {
        $provider = app(PlacesProvider::class);
        assert($provider instanceof FakePlacesProvider);

        return $provider;
    }

    /**
     * An APPROVED, open (09:00–22:00), serviceable restaurant with an ACTIVE menu — everything a journey candidate needs.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $cuisines
     * @param  list<array{0: int|list<int>|null, 1: string, 2: string}>|null  $hours  null = 09:00–22:00 daily
     */
    protected function eligible(string $name, float $lat, float $lng, array $attributes = [], array $cuisines = [], ?array $hours = null, ?RestaurantOrganization $organization = null, bool $menu = true, ?City $city = null): RestaurantLocation
    {
        $organization ??= $this->organization ?? ($this->organization = $this->organization());
        $location = $this->hours($this->location($organization, $name, $attributes, $lat, $lng, $city), $hours ?? [[null, '09:00', '22:00']]);
        if ($cuisines !== []) {
            $this->cuisines($location, $cuisines);
        }
        if ($menu) {
            $this->menuOf($location);
        }

        return $location;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function planBody(array $overrides = []): array
    {
        return array_replace_recursive([
            'origin' => ['lat' => self::WEST[0], 'lng' => self::WEST[1], 'label' => 'West gate'],
            'destination' => ['lat' => self::EAST[0], 'lng' => self::EAST[1], 'label' => 'East gate'],
        ], $overrides);
    }

    /**
     * Plans a guest journey and returns what a client keeps: id, token and the header to send.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{id: string, token: string, headers: array{X-Journey-Token: string}, response: TestResponse}
     */
    protected function planGuest(array $overrides = []): array
    {
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/v1/journeys/plan', $this->planBody($overrides))->assertCreated();

        return ['id' => $response->json('id'), 'token' => $response->json('guest_token'), 'headers' => ['X-Journey-Token' => $response->json('guest_token')], 'response' => $response];
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $query
     */
    protected function restaurantsOf(string $journeyId, array $headers = [], array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/journeys/'.$journeyId.'/restaurants'.($query === [] ? '' : '?'.http_build_query($query)), $headers);
    }

    /**
     * @return list<string>
     */
    protected function namesOf(TestResponse $response): array
    {
        return $response->json('data.*.restaurant.name');
    }
}
