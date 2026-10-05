<?php

namespace App\Services\Journey\Providers;

use App\Contracts\Journey\PlacesProvider;
use App\Contracts\Journey\RoutingProvider;
use App\Exceptions\ProviderException;
use App\Services\Journey\ProviderMetrics;

/**
 * Builds the configured places and routing providers (config/services.php → places / routing; credentials from
 * the environment only). The core depends on the two interfaces; adding a vendor is one driver class here.
 *
 * The deterministic fake providers are refused unless journey.providers.allow_fake is on — production can
 * never plan journeys on synthetic roads by accident. An unknown or unusable driver is a MISCONFIGURED failure
 * that the API reports as "temporarily unavailable" (and logs), never a fabricated answer.
 */
final class ProviderManager
{
    public const DRIVERS = ['fake', 'google'];

    private ?PlacesProvider $places = null;

    private ?RoutingProvider $routing = null;

    public function __construct(private readonly ProviderMetrics $metrics) {}

    public function places(): PlacesProvider
    {
        return $this->places ??= match ($driver = $this->driver('places')) {
            'fake' => $this->fake('places', fn (): PlacesProvider => new FakePlacesProvider),
            'google' => new GooglePlacesProvider((array) config('services.places')),
            default => throw ProviderException::misconfigured('places', "unknown PLACES_DRIVER [{$driver}]"),
        };
    }

    public function routing(): RoutingProvider
    {
        return $this->routing ??= match ($driver = $this->driver('routing')) {
            'fake' => $this->fake('routing', fn (): RoutingProvider => new FakeRoutingProvider),
            'google' => new GoogleRoutingProvider((array) config('services.routing')),
            default => throw ProviderException::misconfigured('routing', "unknown ROUTING_DRIVER [{$driver}]"),
        };
    }

    /**
     * Configuration and health of both capabilities for administrators: driver, whether it is the development
     * provider, whether the live provider has what it needs, and the health derived from recent calls.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $rows = [];
        foreach (['places', 'routing'] as $capability) {
            $driver = $this->driver($capability);
            $missing = [];
            if ($driver === 'fake' && ! $this->fakeAllowed()) {
                $missing[] = strtoupper($capability).'_DRIVER (the fake provider is not allowed in this environment)';
            }
            if ($driver === 'google' && (string) config("services.{$capability}.key") === '') {
                $missing[] = strtoupper($capability).'_API_KEY';
            }
            if (! in_array($driver, self::DRIVERS, true)) {
                $missing[] = strtoupper($capability)."_DRIVER (unknown driver [{$driver}])";
            }
            $rows[] = [
                'capability' => $capability,
                'driver' => $driver,
                'mode' => $driver === 'fake' ? 'development' : 'live',
                'configured' => $missing === [],
                'missing' => $missing,
                'timeout_seconds' => (int) config("services.{$capability}.timeout"),
                'health' => $this->metrics->health($capability, $driver),
            ];
        }

        return $rows;
    }

    public function fakeAllowed(): bool
    {
        return (bool) config('journey.providers.allow_fake');
    }

    private function driver(string $capability): string
    {
        return (string) config("services.{$capability}.driver") ?: 'fake';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function fake(string $capability, callable $build): mixed
    {
        if (! $this->fakeAllowed()) {
            throw ProviderException::misconfigured($capability, 'the fake provider is not allowed in this environment — configure '.strtoupper($capability).'_DRIVER');
        }

        return $build();
    }
}
