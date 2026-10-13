<?php

namespace App\Services\Analytics;

use App\Http\Resources\Finance\FinancePresenter;

/**
 * One figure of a report (Module 38), in the one shape every analytics answer uses.
 *
 * A figure exists only if the catalog defines it: an unknown key is a programming error and fails loudly, so no
 * screen can show a KPI without a definition. A rate always carries its numerator and its denominator; under the
 * minimum denominator it says "insufficient data" instead of a percentage of almost nothing. A comparison with the
 * previous period never divides by zero: a previous value of zero is "NEW", not "+∞ %".
 */
final class Metric
{
    /**
     * @return array<string, mixed>
     */
    public static function count(string $key, int $value, ?int $previous = null): array
    {
        return self::base($key) + ['value' => $value] + self::comparison($value, $previous);
    }

    /**
     * A money figure: one amount per currency — never a sum across currencies. `value` is the single amount when
     * there is exactly one currency, else null.
     *
     * @param  array<string, int>  $byCurrency  currency => minor units
     * @param  array<string, int>|null  $previous
     * @return array<string, mixed>
     */
    public static function money(string $key, array $byCurrency, ?array $previous = null, ?string $defaultCurrency = null): array
    {
        if ($byCurrency === [] && $defaultCurrency !== null) {
            $byCurrency = [$defaultCurrency => 0];
        }
        ksort($byCurrency);
        $values = [];
        foreach ($byCurrency as $currency => $minor) {
            $values[] = FinancePresenter::money((int) $minor, (string) $currency) + ($previous === null ? [] : self::comparison((int) $minor, (int) ($previous[$currency] ?? 0)));
        }
        $single = count($values) === 1;

        return self::base($key) + ['value' => $single ? ['amount' => $values[0]['amount'], 'currency' => $values[0]['currency']] : null, 'values' => $values, 'mixed_currencies' => count($values) > 1]
            + ($single && $previous !== null ? ['comparison' => $values[0]['comparison']] : ['comparison' => null]);
    }

    /**
     * A rate in percent with one decimal. Null with `insufficient` when the denominator is too small to mean anything.
     *
     * @param  array{0: int, 1: int}|null  $previous  [numerator, denominator] of the previous period
     * @return array<string, mixed>
     */
    public static function ratio(string $key, int $numerator, int $denominator, ?array $previous = null): array
    {
        $min = max(1, (int) config('analytics.small_sample.min_denominator'));
        $value = $denominator >= $min ? round($numerator * 100 / $denominator, 1) : null;
        $before = $previous !== null && $previous[1] >= $min ? round($previous[0] * 100 / $previous[1], 1) : null;

        return self::base($key) + [
            'value' => $value, 'numerator' => $numerator, 'denominator' => $denominator,
            'insufficient' => $denominator > 0 && $denominator < $min, 'no_data' => $denominator === 0, 'minimum_denominator' => $min,
            // Two rates are compared in percentage points — a change of a rate is not itself a percentage.
            'comparison' => $previous === null ? null : ['previous' => $before, 'change_points' => $value !== null && $before !== null ? round($value - $before, 1) : null,
                'state' => $value === null ? 'NO_DATA' : ($before === null ? 'NEW' : ($value > $before ? 'UP' : ($value < $before ? 'DOWN' : 'FLAT')))],
        ];
    }

    /**
     * A plain quotient (orders per customer, restaurants per journey) with two decimals.
     *
     * @return array<string, mixed>
     */
    public static function quotient(string $key, int $numerator, int $denominator): array
    {
        return self::base($key) + ['value' => $denominator > 0 ? round($numerator / $denominator, 2) : null, 'numerator' => $numerator, 'denominator' => $denominator, 'no_data' => $denominator === 0];
    }

    /**
     * A duration: the average over `count` things, and the median and the 90th percentile where they were computed.
     *
     * @return array<string, mixed>
     */
    public static function duration(string $key, int $sum, int $count, ?float $median = null, ?float $p90 = null, ?array $previous = null): array
    {
        $average = $count > 0 ? (int) round($sum / $count) : null;
        $before = $previous !== null && $previous[1] > 0 ? (int) round($previous[0] / $previous[1]) : null;

        return self::base($key) + [
            'value' => $average, 'average' => $average, 'median' => $median === null ? null : (int) round($median), 'p90' => $p90 === null ? null : (int) round($p90), 'count' => $count, 'no_data' => $count === 0,
        ] + ($previous === null ? [] : self::comparison($average ?? 0, $before, $average === null));
    }

    /**
     * An average rating with two decimals.
     *
     * @return array<string, mixed>
     */
    public static function rating(string $key, int $sum, int $count): array
    {
        return self::base($key) + ['value' => $count > 0 ? round($sum / $count, 2) : null, 'numerator' => $sum, 'denominator' => $count, 'no_data' => $count === 0];
    }

    /**
     * A money average (average or median order value) in one currency per row.
     *
     * @param  array<string, array{0: int, 1: int}>  $byCurrency  currency => [sum minor, count]
     * @return array<string, mixed>
     */
    public static function moneyAverage(string $key, array $byCurrency, ?string $defaultCurrency = null): array
    {
        ksort($byCurrency);
        $values = [];
        foreach ($byCurrency as $currency => [$sum, $count]) {
            $values[] = $count > 0 ? FinancePresenter::money((int) round($sum / $count), (string) $currency) + ['count' => $count] : ['amount' => null, 'currency' => (string) $currency, 'count' => 0];
        }
        if ($values === [] && $defaultCurrency !== null) {
            $values[] = ['amount' => null, 'currency' => $defaultCurrency, 'count' => 0];
        }
        $single = count($values) === 1;

        return self::base($key) + ['value' => $single && $values[0]['amount'] !== null ? ['amount' => $values[0]['amount'], 'currency' => $values[0]['currency']] : null, 'values' => $values,
            'mixed_currencies' => count($values) > 1, 'no_data' => array_sum(array_column($values, 'count')) === 0];
    }

    /**
     * A figure the caller may not see: the key and the reason, no number.
     *
     * @return array<string, mixed>
     */
    public static function restricted(string $key, string $permission): array
    {
        return self::base($key) + ['value' => null, 'restricted' => true, 'requires' => $permission];
    }

    /**
     * The change against the previous period. Never an infinite or undefined percentage.
     *
     * @return array{comparison: array<string, mixed>|null}
     */
    public static function comparison(int $value, ?int $previous, bool $noCurrent = false): array
    {
        if ($previous === null && ! $noCurrent && func_num_args() < 3) {
            return ['comparison' => null];
        }
        $state = match (true) {
            $noCurrent || ($value === 0 && ($previous ?? 0) === 0) => 'NO_DATA',
            ($previous ?? 0) === 0 => 'NEW',
            $value > $previous => 'UP',
            $value < $previous => 'DOWN',
            default => 'FLAT',
        };

        return ['comparison' => ['previous' => $previous, 'change_pct' => in_array($state, ['UP', 'DOWN', 'FLAT'], true) ? round(($value - $previous) * 100 / $previous, 1) : null, 'state' => $state]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(string $key): array
    {
        $definition = MetricCatalog::find($key) ?? throw new \LogicException("The metric [{$key}] has no definition in the catalog.");

        return ['key' => $key, 'label' => $definition['name'], 'unit' => $definition['unit'], 'freshness' => $definition['freshness']];
    }
}
