<?php

namespace App\Enums;

/**
 * Provider errors normalised for retry decisions (FCM error codes mapped in the provider, never leaked to customers).
 */
enum NotificationErrorCategory: string
{
    case Transient = 'TRANSIENT';
    case Permanent = 'PERMANENT';
    case RateLimited = 'RATE_LIMITED';
    case InvalidDestination = 'INVALID_DESTINATION';
    case AuthConfiguration = 'AUTH_CONFIGURATION';
    case Unknown = 'UNKNOWN';

    public function retryable(): bool
    {
        return in_array($this, [self::Transient, self::RateLimited, self::Unknown], true);
    }
}
