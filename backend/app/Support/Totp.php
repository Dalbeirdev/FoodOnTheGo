<?php

namespace App\Support;

use SensitiveParameter;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30-second step, 6 digits) — the scheme every
 * authenticator app implements. Verified against the RFC test vectors in TotpTest.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new 160-bit secret, Base32 encoded (what the authenticator app stores).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function codeAt(#[SensitiveParameter] string $secret, int $timestamp, int $digits = self::DIGITS): string
    {
        return self::codeForStep($secret, intdiv($timestamp, self::PERIOD), $digits);
    }

    /**
     * Returns the matching time step when the code is valid now or within ±$window steps (clock drift),
     * otherwise null. The caller stores the step so a code cannot be replayed.
     */
    public static function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, int $timestamp, int $window = 1): ?int
    {
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $current = intdiv($timestamp, self::PERIOD);
        $matched = null;
        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if (hash_equals(self::codeForStep($secret, $step, self::DIGITS), $code)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /**
     * otpauth:// URI the authenticator app reads (usually shown as a QR code by the client).
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }

    private static function codeForStep(#[SensitiveParameter] string $secret, int $step, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
