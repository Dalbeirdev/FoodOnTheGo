<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A short-lived cache of ANSWERS of the reporting API (Module 38).
 *
 * The key is made of everything that decides the answer — the report, the period, the market, the locations, the
 * currency, the caller's scope — and of a generation that changes whenever the read models are written again. Two
 * callers with a different scope can never share a key, so one restaurant's cached numbers are never another's.
 *
 * Only plain arrays are stored. Nothing cached here authorizes anything: a payout, a settlement and a balance are
 * always read from the ledger.
 */
final class AnalyticsCache
{
    private const GENERATION = 'analytics:generation';

    public function __construct(private readonly AnalyticsMetrics $metrics) {}

    /**
     * @param  array<string, mixed>  $scope  who may see it: the market ids or the location ids the answer is built for
     * @param  array<string, mixed>  $parameters  what was asked: period, filters, currency
     * @param  \Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public function remember(string $report, array $scope, array $parameters, \Closure $compute): array
    {
        $seconds = (int) config('analytics.cache.seconds');
        if (! (bool) config('analytics.cache.enabled') || $seconds < 1) {
            return $compute() + ['cached' => false];
        }
        $key = $this->key($report, $scope, $parameters);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            $this->metrics->record('cache_hit');

            return $hit + ['cached' => true];
        }
        $this->metrics->record('cache_miss');
        $value = $compute();
        Cache::put($key, $value, $seconds);

        return $value + ['cached' => false];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $parameters
     */
    public function key(string $report, array $scope, array $parameters): string
    {
        ksort($scope);
        ksort($parameters);

        return 'analytics:'.$this->generation().':'.$report.':'.hash('sha256', (string) json_encode([$scope, $parameters]));
    }

    /**
     * A new generation: every answer cached before it is unreachable from now on.
     */
    public function flush(): void
    {
        Cache::forever(self::GENERATION, Str::random(12));
    }

    private function generation(): string
    {
        return (string) Cache::rememberForever(self::GENERATION, fn (): string => Str::random(12));
    }
}
