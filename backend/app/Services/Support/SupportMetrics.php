<?php

namespace App\Services\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Counts of what happens in the support domain — numbers only (Module 37), in hour buckets in the cache, for the
 * administrators' system page. Every figure ABOUT cases (open, unassigned, waiting, breached, response times) is read
 * from the cases themselves; these counters only say how often something happened lately.
 */
final class SupportMetrics
{
    private const PREFIX = 'support:metrics:';

    public const EVENTS = [
        'case_created', 'case_created_customer', 'case_created_restaurant', 'case_created_admin', 'case_created_system', 'system_case_repeated',
        'message_customer', 'message_restaurant', 'message_support', 'message_repeated', 'note_added',
        'assigned', 'priority_changed', 'escalated', 'resolved', 'closed', 'reopened', 'cancelled', 'step_refused', 'version_conflict',
        'link_added', 'link_refused', 'domain_action', 'domain_action_refused',
        'sla_at_risk', 'sla_breached', 'auto_closed',
        'access_refused', 'restricted_refused', 'notification_sent', 'attachment_added', 'attachment_refused',
    ];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('support.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    public function sum(string $event, int $hours = 24): int
    {
        $hours = max(1, min($hours, (int) config('support.metrics.retention_hours')));
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
