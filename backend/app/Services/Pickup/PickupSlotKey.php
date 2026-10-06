<?php

namespace App\Services\Pickup;

use App\Models\RestaurantLocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The identity of a pickup slot as clients hand it back: `ps1_<start, base 36>_<interval minutes>_<tag>`.
 *
 * A client never sends a time. It sends the key of a slot it was offered; the backend reads the start and the
 * interval out of it, checks that the key was issued by this backend for THIS restaurant location (the tag is an
 * HMAC over location, start and interval) and then decides again, from the current hours, settings, cart and
 * capacity, whether that slot may still be chosen. A key therefore proves nothing about availability — it only
 * names a slot — and a made-up or altered key names nothing.
 */
final class PickupSlotKey
{
    private const PREFIX = 'ps1';

    public static function make(RestaurantLocation $location, CarbonInterface $start, int $intervalMinutes): string
    {
        $timestamp = $start->getTimestamp();

        return self::PREFIX.'_'.base_convert((string) $timestamp, 10, 36).'_'.$intervalMinutes.'_'.self::tag($location, $timestamp, $intervalMinutes);
    }

    /**
     * @return array{start: CarbonImmutable, interval: int}|null null = not a key of this location
     */
    public static function parse(RestaurantLocation $location, mixed $key): ?array
    {
        if (! is_string($key) || preg_match('/\A'.self::PREFIX.'_([0-9a-z]{1,12})_([0-9]{1,3})_([0-9a-f]{12})\z/', $key, $m) !== 1) {
            return null;
        }
        $timestamp = (int) base_convert($m[1], 36, 10);
        $interval = (int) $m[2];
        if ($interval < 1 || ! hash_equals(self::tag($location, $timestamp, $interval), $m[3])) {
            return null;
        }

        return ['start' => CarbonImmutable::createFromTimestampUTC($timestamp), 'interval' => $interval];
    }

    private static function tag(RestaurantLocation $location, int $timestamp, int $intervalMinutes): string
    {
        $secret = hash_hmac('sha256', 'foodonthego.pickup.slot-key', (string) config('app.key'), true);

        return substr(hash_hmac('sha256', $location->public_id.'|'.$timestamp.'|'.$intervalMinutes, $secret), 0, 12);
    }
}
