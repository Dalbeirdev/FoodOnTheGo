<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The one rounding policy of every financial calculation (Module 29): integers only, half up, at the currency's
 * minor unit. A browser, a phone or a float never rounds an amount.
 *
 *  - a share of an amount (a percentage in basis points) is rounded half up to the minor unit;
 *  - a total that is split into parts (the components of a tax that is included in the price) is split by the
 *    largest-remainder method, so that the parts always add up to the total exactly.
 */
final class Rounding
{
    /**
     * numerator / denominator, rounded half up (0.5 goes up). Both are non-negative integers.
     */
    public static function divide(int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new InvalidArgumentException('Rounding needs a non-negative numerator and a positive denominator.');
        }

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * The share of an amount given in basis points (10 % = 1000), rounded half up.
     */
    public static function basisPoints(int $amountMinor, int $basisPoints): int
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('A rate cannot be negative.');
        }

        return self::divide($amountMinor * $basisPoints, 10000);
    }

    /**
     * The part of a tax-inclusive amount that is tax, for a combined rate in basis points:
     * amount − amount / (1 + rate), rounded half up.
     */
    public static function includedTax(int $grossMinor, int $basisPoints): int
    {
        if ($basisPoints <= 0) {
            return 0;
        }

        return $grossMinor - self::divide($grossMinor * 10000, 10000 + $basisPoints);
    }

    /**
     * Splits a total over weights so that the parts add up to it exactly (largest remainder; ties go to the earlier
     * part).
     *
     * @param  list<int>  $weights  non-negative
     * @return list<int>
     */
    public static function allocate(int $totalMinor, array $weights): array
    {
        $sum = array_sum($weights);
        if ($totalMinor < 0 || $sum < 0 || min([0, ...$weights]) < 0) {
            throw new InvalidArgumentException('Allocation needs non-negative values.');
        }
        if ($weights === []) {
            return [];
        }
        if ($sum === 0) {
            return array_fill(0, count($weights), 0);
        }

        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            $parts[$i] = intdiv($totalMinor * $weight, $sum);
            $remainders[$i] = ($totalMinor * $weight) % $sum;
        }
        $left = $totalMinor - array_sum($parts);
        arsort($remainders, SORT_NUMERIC);
        // arsort is not stable for equal values: order equal remainders by position.
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        foreach ($order as $i) {
            if ($left <= 0) {
                break;
            }
            $parts[$i]++;
            $left--;
        }

        return array_values($parts);
    }
}
