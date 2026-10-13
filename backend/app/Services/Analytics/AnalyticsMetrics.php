<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Counts of what the analytics layer itself did lately — numbers only (Module 38), in hour buckets in the cache, for
 * the administrators' system page. No business figure comes from here: those are read from the read models.
 */
final class AnalyticsMetrics
{
    private const PREFIX = 'analytics:metrics:';

    public const EVENTS = [
        'events_accepted', 'events_duplicate', 'events_rejected', 'events_server', 'ingest_requests', 'ingest_disabled', 'ingest_consent_withheld', 'ingest_failed',
        'aggregation_ok', 'aggregation_failed', 'rebuild', 'quality_run', 'cache_hit', 'cache_miss',
        'export_requested', 'export_ready', 'export_failed', 'export_downloaded', 'export_refused', 'export_expired', 'scope_refused',
    ];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('analytics.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    public function sum(string $event, int $hours = 24): int
    {
        $hours = max(1, min($hours, (int) config('analytics.metrics.retention_hours')));
        $now = CarbonImmutable::now('UTC');
        $total = 0;
        for ($i = 0; $i < $hours; $i++) {
            $total += (int) Cache::get(self::PREFIX.$event.':'.$now->subHours($i)->format('YmdH'), 0);
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function events(int $hours = 24): array
    {
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = $this->sum($event, $hours);
        }

        return $events;
    }
}
