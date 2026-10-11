<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailNotificationProvider;
use App\Contracts\Notifications\PushNotificationProvider;
use App\Contracts\Notifications\SmsNotificationProvider;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the admin System page shows: the push provider's own probe (never "green because configured"), the e-mail / SMS
 * provider state, the queue backlog (open deliveries, stranded SENDING rows, overdue retries) and the counters.
 */
final class NotificationHealth
{
    public function __construct(private readonly NotificationMetrics $metrics) {}

    public function report(bool $fresh = false): array
    {
        $push = $fresh ? $this->pushHealth() : Cache::remember('notifications:health:push', 10, fn (): array => $this->pushHealth());
        $email = $this->channelHealth('EMAIL', EmailNotificationProvider::class);
        $sms = $this->channelHealth('SMS', SmsNotificationProvider::class);

        $lease = max(30, (int) config('notifications.retry.sending_lease_seconds'));
        $queue = [
            'queued' => NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Queued->value)->count(),
            'sending' => NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Sending->value)->count(),
            'retry_scheduled' => NotificationDelivery::query()->where('status', NotificationDeliveryStatus::RetryScheduled->value)->count(),
            'stranded' => NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Sending->value)->where('sending_started_at', '<', now()->subSeconds($lease))->count(),
            'overdue_retries' => NotificationDelivery::query()->where('status', NotificationDeliveryStatus::RetryScheduled->value)->where('next_retry_at', '<', now()->subSeconds(60))->count(),
            'oldest_open_seconds' => $this->oldestOpenSeconds(),
            'queue_name' => (string) config('notifications.queue'),
            'paused_after_auth_failure' => Cache::has(NotificationDeliveryService::AUTH_PAUSE_KEY),
        ];

        $status = $push['status'];
        if ($status === 'OPERATIONAL' && ($queue['stranded'] > 0 || $queue['overdue_retries'] > 0 || $queue['paused_after_auth_failure'])) {
            $status = 'DEGRADED';
        }

        return [
            'status' => $status,
            'push' => $push,
            'email' => $email,
            'sms' => $sms,
            'queue' => $queue,
            'counters' => $this->metrics->report(24),
            'retention' => (array) config('notifications.retention'),
            'checked_at' => now()->toIso8601String(),
            'notes' => [
                'push' => (string) config('notifications.channels.PUSH.driver') === 'fcm' ? 'FCM HTTP v1 (service account).' : 'Development provider — REAL FCM = PENDING FIREBASE CONFIGURATION.',
                'email' => 'REAL EMAIL PROVIDER = PENDING (local mailer); e-mail requires a verified address — none is verified yet.',
                'sms' => 'REAL SMS PROVIDER = PENDING; the notification SMS channel is '.((string) config('notifications.channels.SMS.driver') === 'off' ? 'OFF' : 'the development provider').'.',
                'sent_means' => 'SENT = the provider accepted the message; it is not a read or a display.',
            ],
        ];
    }

    private function pushHealth(): array
    {
        $driver = (string) config('notifications.channels.PUSH.driver');
        if ($driver === '' || $driver === 'off') {
            return ['status' => 'OFF', 'driver' => 'off', 'detail' => 'The push channel is disabled on this server.', 'latency_ms' => null];
        }

        return app(PushNotificationProvider::class)->health();
    }

    private function channelHealth(string $channel, string $contract): array
    {
        $driver = (string) config('notifications.channels.'.$channel.'.driver');
        if ($driver === '' || $driver === 'off') {
            return ['status' => 'OFF', 'driver' => 'off', 'detail' => 'The '.strtolower($channel).' channel is disabled on this server.', 'latency_ms' => null];
        }

        return app($contract)->health();
    }

    private function oldestOpenSeconds(): ?int
    {
        $oldest = NotificationDelivery::query()->whereIn('status', [NotificationDeliveryStatus::Queued->value, NotificationDeliveryStatus::Sending->value, NotificationDeliveryStatus::RetryScheduled->value])->min('created_at');

        return $oldest === null ? null : max(0, now()->diffInSeconds(Carbon::parse($oldest), false) * -1);
    }
}
