<?php

namespace App\Services\Order;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The human-friendly order number: FOTG-YYYYMMDD-XXXXXX.
 *
 * The date is the restaurant-local day the order was placed; the six characters are random from an alphabet
 * without 0/O/1/I. One order number is one of 2^30 for a day — readable over the phone, stable, never reused
 * (the column is unique; a collision is retried). It identifies, it does not authorize: every order endpoint
 * still asks who is looking.
 */
final class OrderNumbers
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const PATTERN = '/\AFOTG-[0-9]{8}-[A-HJ-NP-Z2-9]{6}\z/';

    public static function generate(CarbonInterface $placedAt, string $timezone): string
    {
        $day = $placedAt->copy()->setTimezone($timezone)->format('Ymd');
        $tail = '';
        foreach (str_split(random_bytes(6)) as $byte) {
            $tail .= self::ALPHABET[ord($byte) % 32];
        }

        return 'FOTG-'.$day.'-'.$tail;
    }

    public static function valid(string $value): bool
    {
        return preg_match(self::PATTERN, Str::upper($value)) === 1;
    }
}
