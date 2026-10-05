<?php

namespace App\Exceptions;

use App\Enums\ProviderFailure;
use RuntimeException;
use Throwable;

/**
 * A places or routing provider did not answer usefully. Carries only the kind of failure and the provider's
 * name: never the request (coordinates, place text), never a credential, never the raw provider body.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly ProviderFailure $failure,
        public readonly string $provider,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : "{$provider}: {$failure->value}", 0, $previous);
    }

    public static function timeout(string $provider, ?Throwable $previous = null): self
    {
        return new self(ProviderFailure::Timeout, $provider, previous: $previous);
    }

    public static function unavailable(string $provider, string $detail = ''): self
    {
        return new self(ProviderFailure::Unavailable, $provider, $detail === '' ? '' : "{$provider}: {$detail}");
    }

    public static function rateLimited(string $provider): self
    {
        return new self(ProviderFailure::RateLimited, $provider);
    }

    public static function routeNotFound(string $provider): self
    {
        return new self(ProviderFailure::RouteNotFound, $provider);
    }

    public static function placeNotFound(string $provider): self
    {
        return new self(ProviderFailure::PlaceNotFound, $provider);
    }

    public static function invalidLocation(string $provider): self
    {
        return new self(ProviderFailure::InvalidLocation, $provider);
    }

    public static function misconfigured(string $provider, string $detail): self
    {
        return new self(ProviderFailure::Misconfigured, $provider, "{$provider}: {$detail}");
    }

    /**
     * The API error this failure becomes. Route and place lookups fail "softly" (422 / 404) because the
     * customer can change the input; everything else is a 503 the clients retry later. Nothing is fabricated.
     */
    public function toApi(string $capability = 'routing'): ApiException
    {
        return match ($this->failure) {
            ProviderFailure::RouteNotFound => new ApiException(422, 'route_not_found', 'No road route is available between these locations.'),
            ProviderFailure::PlaceNotFound => new ApiException(404, 'place_not_found', 'This place could not be found.'),
            ProviderFailure::InvalidLocation => new ApiException(422, 'invalid_location', 'One of the locations cannot be used for a journey.'),
            ProviderFailure::RateLimited => new ApiException(503, 'provider_rate_limited', 'Route planning is busy right now. Please try again in a moment.', ['retry_after_seconds' => 30]),
            default => new ApiException(503, $capability.'_temporarily_unavailable', $capability === 'places' ? 'Place search is temporarily unavailable. Please try again shortly.' : 'Route planning is temporarily unavailable. Please try again shortly.', ['retry_after_seconds' => 15]),
        };
    }
}
