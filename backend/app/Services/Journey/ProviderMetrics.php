<?php

namespace App\Services\Journey;

use App\Enums\ProviderHealthState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Counts every call to a places / routing provider in one-minute buckets (cache store, short lived): calls,
 * failures, timeouts, quota events, "not found" answers and latency. Health is DERIVED from these real calls
 * over a window — nothing is claimed for a provider the platform has not spoken to.
 *
 * Counters are kept per capability AND driver ("places:google", "routing:google"): the same vendor serving both
 * must not hide a failing route service behind a healthy place search.
 */
final class ProviderMetrics
{
    private const PREFIX = 'journey:provider:';

    private const OUTCOMES = ['ok', 'failures', 'timeouts', 'rate_limited', 'not_found'];

    /**
     * @param  string  $operation  "<capability>.<what>", e.g. places.search, routing.detours
     */
    public function record(string $provider, string $operation, string $outcome, int $latencyMs): void
    {
        $provider = self::key(explode('.', $operation)[0], $provider);
        $ttl = $this->retentionSeconds();
        $bucket = self::PREFIX.$provider.':'.now()->utc()->format('YmdHi');

        foreach (['calls', $outcome, 'latency_ms'] as $counter) {
            Cache::add("{$bucket}:{$counter}", 0, $ttl);
        }
        Cache::increment("{$bucket}:calls");
        Cache::increment("{$bucket}:{$outcome}");
        Cache::increment("{$bucket}:latency_ms", max(0, $latencyMs));
        Cache::put(self::PREFIX.$provider.':last_call', now()->utc()->toIso8601String(), 86400);
        Cache::put(self::PREFIX.$provider.':last_operation', $operation, 86400);
    }

    /**
     * @return array{state: string, window_minutes: int, calls: int, ok: int, failures: int, timeouts: int, rate_limited: int, not_found: int, avg_latency_ms: int|null, last_call_at: string|null}
     */
    public function health(string $capability, string $driver): array
    {
        $provider = self::key($capability, $driver);
        $window = max(1, (int) config('journey.providers.health_window_minutes'));
        $totals = array_fill_keys(['calls', ...self::OUTCOMES, 'latency_ms'], 0);
        $minute = CarbonImmutable::now('UTC');

        for ($i = 0; $i < $window; $i++) {
            $bucket = self::PREFIX.$provider.':'.$minute->subMinutes($i)->format('YmdHi');
            foreach (array_keys($totals) as $counter) {
                $totals[$counter] += (int) Cache::get("{$bucket}:{$counter}", 0);
            }
        }

        return [
            'state' => $this->state($totals)->value,
            'window_minutes' => $window,
            'calls' => $totals['calls'],
            'ok' => $totals['ok'],
            'failures' => $totals['failures'],
            'timeouts' => $totals['timeouts'],
            'rate_limited' => $totals['rate_limited'],
            'not_found' => $totals['not_found'],
            'avg_latency_ms' => $totals['calls'] > 0 ? (int) round($totals['latency_ms'] / $totals['calls']) : null,
            'last_call_at' => Cache::get(self::PREFIX.$provider.':last_call'),
        ];
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function state(array $totals): ProviderHealthState
    {
        if ($totals['calls'] === 0) {
            return ProviderHealthState::Unknown;
        }
        $hard = $totals['failures'] + $totals['timeouts'] + $totals['rate_limited'];
        $ratio = $hard / $totals['calls'];

        return match (true) {
            $ratio >= 0.5 => ProviderHealthState::Outage,
            $ratio > 0.1 || $totals['timeouts'] > 0 => ProviderHealthState::Degraded,
            default => ProviderHealthState::Operational,
        };
    }

    private static function key(string $capability, string $driver): string
    {
        return $capability.':'.$driver;
    }

    private function retentionSeconds(): int
    {
        return (max(1, (int) config('journey.providers.health_window_minutes')) + 5) * 60;
    }
}
