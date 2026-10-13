<?php

namespace App\Services\Analytics;

/**
 * Bands (Module 38): a detour, a journey's length and a journey's duration are kept as a band, never as the number.
 * The bands are configuration (`analytics.bands`): [label, upper bound], the last one open-ended.
 */
final class Bands
{
    public static function detour(?int $seconds): ?string
    {
        return $seconds === null ? null : self::of('detour_minutes', $seconds / 60);
    }

    public static function distance(?int $meters): ?string
    {
        return $meters === null ? null : self::of('journey_km', $meters / 1000);
    }

    public static function duration(?int $seconds): ?string
    {
        return $seconds === null ? null : self::of('journey_minutes', $seconds / 60);
    }

    /**
     * @return list<string>
     */
    public static function labels(string $kind): array
    {
        return array_map(fn (array $band): string => (string) $band[0], (array) config('analytics.bands.'.$kind));
    }

    /**
     * The band of a number of seconds as an SQL CASE expression — the same bands, for an INSERT … SELECT.
     */
    public static function detourSql(string $secondsExpression): string
    {
        $case = 'case';
        foreach ((array) config('analytics.bands.detour_minutes') as [$label, $upper]) {
            $label = preg_replace('/[^0-9A-Za-z+\-]/', '', (string) $label);
            $case .= $upper === null ? " else '{$label}'" : ' when ('.$secondsExpression.') < '.((int) $upper * 60)." then '{$label}'";
        }

        return '(case when ('.$secondsExpression.') is null then null else ('.$case.' end) end)';
    }

    private static function of(string $kind, float $value): ?string
    {
        $bands = (array) config('analytics.bands.'.$kind);
        foreach ($bands as [$label, $upper]) {
            if ($upper === null || $value < $upper) {
                return (string) $label;
            }
        }

        return $bands === [] ? null : (string) $bands[array_key_last($bands)][0];
    }
}
