<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An SMS could not be handed to the provider. The message is safe to log: it never contains the code,
 * the message body, the request URL or a credential.
 */
class DeliveryException extends RuntimeException
{
    public function __construct(public readonly string $provider, public readonly string $reason, public readonly bool $configuration = false)
    {
        parent::__construct("SMS delivery through [{$provider}] failed: {$reason}");
    }

    public static function notConfigured(string $provider, string $missing): self
    {
        return new self($provider, "missing configuration ({$missing})", true);
    }
}
