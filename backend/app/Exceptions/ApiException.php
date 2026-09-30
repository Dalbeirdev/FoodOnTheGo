<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A failure the API consumer is meant to see: carries the HTTP status, a stable machine-readable code
 * and a customer-safe message. Application services throw this instead of building responses.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $errorCode, string $message): self
    {
        return new self(404, $errorCode, $message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function conflict(string $errorCode, string $message, array $details = []): self
    {
        return new self(409, $errorCode, $message, $details);
    }
}
