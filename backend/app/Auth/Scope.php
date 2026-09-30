<?php

namespace App\Auth;

use InvalidArgumentException;

/**
 * The thing a permission is exercised on: a restaurant organization, a restaurant location or a market,
 * identified by public id. A location scope also names the organization it belongs to, so a role held for
 * the whole organization covers it.
 */
final readonly class Scope
{
    public const TYPES = ['organization', 'location', 'market'];

    public function __construct(public string $type, public string $id, public ?string $organizationId = null)
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown scope type [{$type}].");
        }
    }

    public static function organization(string $publicId): self
    {
        return new self('organization', $publicId);
    }

    public static function location(string $publicId, ?string $organizationId = null): self
    {
        return new self('location', $publicId, $organizationId);
    }

    public static function market(string $publicId): self
    {
        return new self('market', $publicId);
    }
}
