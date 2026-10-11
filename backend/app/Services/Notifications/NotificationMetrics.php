<?php

namespace App\Services\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Counters only (hourly buckets in the cache, like the order metrics). "push_sent" is the provider's acceptance — never
 * a read rate; the in-app read rate is a separate number computed from the table.
 */
final class NotificationMetrics
{
    private const PREFIX = 'notifications:metrics:';

    public const EVENTS = [
        'notification_created', 'notification_deduplicated', 'in_app_created',
        'push_queued', 'push_sent', 'push_failed', 'push_retry', 'push_invalid_token', 'push_rate_limited', 'push_auth_failed',
        'email_queued', 'email_sent', 'email_failed', 'sms_queued', 'sms_sent', 'sms_failed',
        'skipped_preference', 'skipped_no_destination', 'skipped_channel_disabled', 'skipped_os_permission', 'skipped_customer_status',
        'device_registered', 'device_rebound', 'device_token_reassigned', 'device_revoked',
        'provider_ms_total', 'provider_calls', 'recovered_stranded',
    ];

    public function record(string $event, int $by = 1): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('notifications.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    public function recordProviderLatency(int $ms): void
    {
        $this->record('provider_calls');
        $this->record('provider_ms_total', max(0, $ms));
    }

    /** @return array<string, int|float|null> */
    public function report(int $hours = 24): array
    {
        $now = Carbon::now();
        $out = [];
        foreach (self::EVENTS as $name) {
            $total = 0;
            for ($i = 0; $i < $hours; $i++) {
                $total += (int) Cache::get(self::PREFIX.$name.':'.$now->copy()->subHours($i)->format('YmdH'), 0);
            }
            $out[$name] = $total;
        }
        $out['provider_avg_ms'] = $out['provider_calls'] > 0 ? (int) round($out['provider_ms_total'] / $out['provider_calls']) : null;
        unset($out['provider_ms_total']);

        return $out;
    }
}
