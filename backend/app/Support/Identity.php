<?php

namespace App\Support;

/**
 * Normalises the "email or phone" identity used by the approved Login / Sign-up designs.
 * Indian numbers are the default market: a bare 10-digit number becomes +91XXXXXXXXXX.
 */
final class Identity
{
    public const DEFAULT_COUNTRY_CODE = '+91';

    public static function isEmail(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Returns the E.164-style phone number, or null when the value is not a plausible phone. */
    public static function normalisePhone(string $value): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($value)) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (! str_starts_with($digits, '+')) {
            $digits = strlen($digits) === 10 ? self::DEFAULT_COUNTRY_CODE.$digits : '+'.$digits;
        }
        $national = substr($digits, 1);

        return preg_match('/^\d{10,15}$/', $national) === 1 ? $digits : null;
    }

    /**
     * Splits a raw identity into the column it should be matched against.
     *
     * @return array{column: 'email'|'phone', value: string}|null
     */
    public static function resolve(string $value): ?array
    {
        if (self::isEmail($value)) {
            return ['column' => 'email', 'value' => strtolower(trim($value))];
        }
        $phone = self::normalisePhone($value);

        return $phone === null ? null : ['column' => 'phone', 'value' => $phone];
    }
}
