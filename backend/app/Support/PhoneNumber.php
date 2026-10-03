<?php

namespace App\Support;

use App\Models\Market;
use InvalidArgumentException;

/**
 * A phone number in E.164 form (+<country code><national number>, 8–15 digits) — the only form that is
 * stored or compared, so "98765 43210", "098765 43210" and "+91 98765-43210" are one identity.
 *
 * Nothing here knows about a particular country: the dialling code, the national-number rule and the trunk
 * prefix come from the Market row the number is resolved against.
 */
final readonly class PhoneNumber
{
    private function __construct(public string $e164, public string $countryCallingCode) {}

    /**
     * Normalises user input in the context of a market.
     *
     * - Input starting with "+" or "00" is international and must belong to the given market.
     * - Anything else is a national number: the market's trunk prefix is dropped and its dialling code added.
     *
     * @throws InvalidArgumentException when the input is not a valid number for the market
     */
    public static function parse(string $input, Market $market): self
    {
        return self::normalise($input, $market, mobileOnly: true);
    }

    /**
     * A business contact number (a restaurant's public phone): normalised exactly like a customer number, but a
     * landline is as valid as a mobile — only the market's dialling code and trunk prefix apply, not its
     * mobile-number rule.
     *
     * @throws InvalidArgumentException when the input is not a plausible number for the market
     */
    public static function parseBusiness(string $input, Market $market): self
    {
        return self::normalise($input, $market, mobileOnly: false);
    }

    private static function normalise(string $input, Market $market, bool $mobileOnly): self
    {
        $trimmed = trim($input);
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';
        $dial = ltrim($market->phone_country_code, '+');

        if ($digits === '' || preg_match('/^[+\d\s().-]+$/', $trimmed) !== 1) {
            throw new InvalidArgumentException('The phone number may contain digits, spaces and + ( ) - . only.');
        }

        if (str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00')) {
            $international = str_starts_with($trimmed, '+') ? $digits : substr($digits, 2);
            if (! str_starts_with($international, $dial)) {
                throw new InvalidArgumentException('This phone number does not belong to the selected country.');
            }
            $national = substr($international, strlen($dial));
        } else {
            $national = $digits;
            $trunk = (string) $market->phone_trunk_prefix;
            if ($trunk !== '' && str_starts_with($national, $trunk)) {
                $national = substr($national, strlen($trunk));
            } elseif ($mobileOnly && str_starts_with($national, $dial) && self::matches($market, substr($national, strlen($dial))) && ! self::matches($market, $national)) {
                // "91 98765 43210" typed without the plus sign.
                $national = substr($national, strlen($dial));
            }
        }

        if ($mobileOnly && ! self::matches($market, $national)) {
            throw new InvalidArgumentException('Enter a valid mobile number for '.$market->name.'.');
        }
        if (! $mobileOnly && preg_match('/^[1-9]\d{5,11}$/', $national) !== 1) {
            throw new InvalidArgumentException('Enter a valid phone number for '.$market->name.'.');
        }

        $e164 = '+'.$dial.$national;
        if (preg_match('/^\+[1-9]\d{7,14}$/', $e164) !== 1) {
            throw new InvalidArgumentException('Enter a valid mobile number.');
        }

        return new self($e164, '+'.$dial);
    }

    /**
     * For values that are already stored / trusted E.164.
     */
    public static function fromE164(string $e164, string $countryCallingCode): self
    {
        if (preg_match('/^\+[1-9]\d{7,14}$/', $e164) !== 1 || ! str_starts_with($e164, $countryCallingCode)) {
            throw new InvalidArgumentException('Not an E.164 phone number.');
        }

        return new self($e164, $countryCallingCode);
    }

    /**
     * "+91 ******3210" — for screens and logs where the full number is not needed.
     */
    public function masked(): string
    {
        return self::mask($this->e164, $this->countryCallingCode);
    }

    public static function mask(string $e164, ?string $countryCallingCode = null): string
    {
        $prefix = $countryCallingCode !== null && str_starts_with($e164, $countryCallingCode) ? $countryCallingCode : '';
        $rest = substr($e164, strlen($prefix));
        $visible = substr($rest, -4);

        return trim($prefix.' '.str_repeat('*', max(0, strlen($rest) - 4)).$visible);
    }

    /**
     * Stable, non-reversible key for rate limiting and security events (never the number itself).
     */
    public function hash(): string
    {
        return hash('sha256', $this->e164);
    }

    public function __toString(): string
    {
        return $this->e164;
    }

    private static function matches(Market $market, string $national): bool
    {
        $pattern = $market->phone_national_pattern;

        if ($pattern === null || $pattern === '') {
            return preg_match('/^\d{4,14}$/', $national) === 1;
        }

        return preg_match('/'.str_replace('/', '\/', $pattern).'/', $national) === 1;
    }
}
