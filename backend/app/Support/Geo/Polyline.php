<?php

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * Google's encoded polyline algorithm (precision 1e-5), the interchange form routing providers and map SDKs
 * share. Positions are [longitude, latitude] like every geometry in this API (GeoJSON order).
 */
final class Polyline
{
    private const PRECISION = 1e5;

    /**
     * @param  list<array{0: float, 1: float}>  $positions  [lng, lat]
     */
    public static function encode(array $positions): string
    {
        $out = '';
        $lastLat = 0;
        $lastLng = 0;
        foreach ($positions as [$lng, $lat]) {
            $iLat = (int) round($lat * self::PRECISION);
            $iLng = (int) round($lng * self::PRECISION);
            $out .= self::encodeValue($iLat - $lastLat).self::encodeValue($iLng - $lastLng);
            $lastLat = $iLat;
            $lastLng = $iLng;
        }

        return $out;
    }

    /**
     * @return list<array{0: float, 1: float}> [lng, lat]
     */
    public static function decode(string $encoded): array
    {
        $positions = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;
        while ($index < $length) {
            $lat += self::decodeValue($encoded, $index, $length);
            $lng += self::decodeValue($encoded, $index, $length);
            $positions[] = [$lng / self::PRECISION, $lat / self::PRECISION];
        }

        return $positions;
    }

    private static function encodeValue(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $out = '';
        while ($value >= 0x20) {
            $out .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $out.chr($value + 63);
    }

    private static function decodeValue(string $encoded, int &$index, int $length): int
    {
        $result = 0;
        $shift = 0;
        do {
            if ($index >= $length) {
                throw new InvalidArgumentException('Truncated polyline.');
            }
            $byte = ord($encoded[$index++]) - 63;
            if ($byte < 0) {
                throw new InvalidArgumentException('Invalid polyline character.');
            }
            $result |= ($byte & 0x1F) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }
}
