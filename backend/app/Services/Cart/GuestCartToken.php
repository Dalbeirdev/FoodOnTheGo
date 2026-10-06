<?php

namespace App\Services\Cart;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use SensitiveParameter;

/**
 * The token a guest's cart answers to (header X-Cart-Token).
 *
 *   gc1.<issued, base 36>.<64 hex = 256 random bits>.<32 hex = HMAC-SHA256 tag>
 *
 * Minted by the server and signed, so the backend only ever works with tokens it issued itself; nothing is stored
 * until the first item is added — then the cart keeps the SHA-256 hash of the token, never the token. Knowing a
 * cart's id is never enough to read it. The token is a secret: it travels in a header, never in a URL, and is
 * never written to a log.
 */
final class GuestCartToken
{
    public const HEADER = 'X-Cart-Token';

    private const VERSION = 'gc1';

    public function mint(?CarbonInterface $at = null): string
    {
        $body = self::VERSION.'.'.base_convert((string) ($at ?? now())->getTimestamp(), 10, 36).'.'.bin2hex(random_bytes(32));

        return $body.'.'.$this->tag($body);
    }

    /**
     * The hash and the issue time of a token this server minted; null for anything else.
     *
     * @return array{hash: string, issued_at: CarbonImmutable}|null
     */
    public function parse(#[SensitiveParameter] ?string $token): ?array
    {
        if (! is_string($token) || preg_match('/^(gc1\.([0-9a-z]{1,9})\.[0-9a-f]{64})\.([0-9a-f]{32})$/', $token, $m) !== 1) {
            return null;
        }
        if (! hash_equals($this->tag($m[1]), $m[3])) {
            return null;
        }

        return ['hash' => self::hash($token), 'issued_at' => CarbonImmutable::createFromTimestampUTC((int) base_convert($m[2], 36, 10))];
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    private function tag(string $body): string
    {
        return substr(hash_hmac('sha256', $body, hash_hmac('sha256', 'foodonthego.cart.guest-token', (string) config('app.key'), true)), 0, 32);
    }
}
