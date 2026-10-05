<?php

namespace App\Enums;

/**
 * Why a call to a places or routing provider did not produce an answer. The services map these onto the API
 * error codes; only TRANSIENT kinds are ever retried.
 */
enum ProviderFailure: string
{
    case Timeout = 'TIMEOUT';
    case Unavailable = 'UNAVAILABLE';
    case RateLimited = 'RATE_LIMITED';
    case RouteNotFound = 'ROUTE_NOT_FOUND';
    case PlaceNotFound = 'PLACE_NOT_FOUND';
    case InvalidLocation = 'INVALID_LOCATION';
    case Misconfigured = 'MISCONFIGURED';

    /**
     * A failure that a second attempt may resolve. Rate limiting is deliberately NOT retried: a retry would make
     * the quota problem worse.
     */
    public function transient(): bool
    {
        return in_array($this, [self::Timeout, self::Unavailable], true);
    }

    /**
     * Counter the failure is recorded under (ProviderMetrics).
     */
    public function metric(): string
    {
        return match ($this) {
            self::Timeout => 'timeouts',
            self::RateLimited => 'rate_limited',
            self::RouteNotFound, self::PlaceNotFound, self::InvalidLocation => 'not_found',
            default => 'failures',
        };
    }
}
