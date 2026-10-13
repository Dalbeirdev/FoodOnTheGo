<?php

namespace App\Services\Support;

/**
 * The number a person reads out for a support case (Module 37): "CS-" and eight characters of an alphabet without
 * 0 / O / 1 / I. Random — it says nothing about how many cases exist — and unique by a database constraint (the
 * writer retries on a collision). It identifies a case in conversation. It is NOT an authorization: every endpoint
 * decides by who is asking, never by the fact that a number is known.
 */
final class SupportCaseNumbers
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const PATTERN = '/\ACS-[A-HJ-NP-Z2-9]{8}\z/';

    /** For routes: a case is addressed by its public id or by its number. */
    public const ROUTE_PATTERN = '[0-9a-fA-F-]{36}|[Cc][Ss]-[A-Za-z2-9]{8}';

    public static function generate(): string
    {
        $out = '';
        foreach (str_split(random_bytes(8)) as $byte) {
            $out .= self::ALPHABET[ord($byte) % 32];
        }

        return 'CS-'.$out;
    }

    public static function looksLikeOne(string $text): bool
    {
        return preg_match(self::PATTERN, strtoupper(trim($text))) === 1;
    }
}
