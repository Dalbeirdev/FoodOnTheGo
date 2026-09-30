<?php

namespace App\Auth;

use InvalidArgumentException;

/**
 * The thing a permission is exercised on: a restaurant organization, a restaurant location or a market,
 * identified by its public id.
 */
final readonly class Scope
{
    public const TYPES = ['organization', 'location', 'market'];

    public function __construct(public string $type, public string $id)
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown scope type [{$type}].");
        }
    }

    public static function organization(string $publicId): self
    {
        return new self('organization', $publicId);
    }

    public static function location(string $publicId): self
    {
        return new self('location', $publicId);
    }

    public static function market(string $publicId): self
    {
        return new self('market', $publicId);
    }
}
