<?php

namespace App\Services\Analytics;

/**
 * The only way a customer or a journey enters an analytics table (Module 38): as a keyed hash.
 *
 * A pseudonym answers "is this the same customer / the same journey as that one?" and nothing else. It is not the
 * internal id, it carries no phone number or e-mail address, and without the key it cannot be computed from either.
 * No API returns one.
 *
 * The same value must come out of PHP and out of an SQL statement (the read models are built with INSERT … SELECT),
 * so the construction is one both can do: the first 32 hex characters of SHA-256 over "key:kind:value".
 */
final class Pseudonymizer
{
    public function customer(int $customerId): string
    {
        return $this->of('customer', (string) $customerId);
    }

    public function journey(string $journeyPublicId): string
    {
        return $this->of('journey', strtolower($journeyPublicId));
    }

    /**
     * The same pseudonym as an SQL expression over a column, with its binding.
     *
     * @param  'customer'|'journey'  $kind
     * @return array{0: string, 1: list<string>}
     */
    public function sql(string $kind, string $expression): array
    {
        return ["substr(encode(sha256(convert_to(? || lower(({$expression})::text), 'UTF8')), 'hex'), 1, 32)", [$this->key().':'.$kind.':']];
    }

    /**
     * A stable event id for something the server records once: the same facts give the same id, so recording it
     * again is a duplicate the unique index refuses.
     */
    public function eventId(string ...$parts): string
    {
        $hex = substr(hash('sha256', $this->key().':event:'.implode('|', $parts)), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function of(string $kind, string $value): string
    {
        return substr(hash('sha256', $this->key().':'.$kind.':'.$value), 0, 32);
    }

    private function key(): string
    {
        $configured = config('analytics.privacy.pseudonym_key');

        return is_string($configured) && $configured !== '' ? $configured : hash('sha256', 'foodonthego-analytics|'.(string) config('app.key'));
    }
}
