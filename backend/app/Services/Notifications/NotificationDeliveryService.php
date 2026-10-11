<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailNotificationProvider;
use App\Contracts\Notifications\PushNotificationProvider;
use App\Contracts\Notifications\SmsNotificationProvider;
use App\Enums\CustomerDeviceRevokeReason;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationErrorCategory;
use App\Jobs\Notifications\SendNotificationDeliveryJob;
use App\Models\CustomerDevice;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryAttempt;
use App\Services\Notifications\Providers\EmailMessage;
use App\Services\Notifications\Providers\ProviderResult;
use App\Services\Notifications\Providers\PushMessage;
use App\Services\Notifications\Providers\SmsMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends one delivery and records the outcome. Lease: a conditional UPDATE moves QUEUED / RETRY_SCHEDULED → SENDING (a
 * second worker finds nothing to lease and stops). Every provider call is an attempt row. Outcomes: accepted → SENT;
 * invalid destination → INVALID_DESTINATION and the device revoked; a credential failure → FAILED and a short pause of
 * the push provider (no storm); transient / rate-limited / unknown → RETRY_SCHEDULED with bounded exponential backoff
 * and jitter (FCM 429: at least a minute) until the attempt cap, then FAILED. Nothing here ever touches an order.
 */
final class NotificationDeliveryService
{
    public const AUTH_PAUSE_KEY = 'notifications:push:auth-paused';

    public function __construct(
        private readonly NotificationTemplates $templates,
        private readonly NotificationMetrics $metrics,
        private readonly CustomerDeviceService $devices,
    ) {}

    public function process(NotificationDelivery $delivery): void
    {
        $leased = NotificationDelivery::query()->whereKey($delivery->getKey())
            ->whereIn('status', [NotificationDeliveryStatus::Queued->value, NotificationDeliveryStatus::RetryScheduled->value])
            ->update(['status' => NotificationDeliveryStatus::Sending->value, 'sending_started_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        if ($leased !== 1) {
            return;
        }
        $delivery = $delivery->fresh(['notification.customer', 'device']);
        $notification = $delivery->notification;
        $customer = $notification?->customer;
        if ($notification === null || $customer === null) {
            $this->finish($delivery, NotificationDeliveryStatus::Cancelled, 'SUBJECT_GONE');

            return;
        }

        $channel = $delivery->channel;
        $device = $delivery->device;
        if ($channel === NotificationChannel::Push && ($device === null || ! $device->isActive())) {
            $this->finish($delivery, NotificationDeliveryStatus::Cancelled, 'DEVICE_REVOKED');

            return;
        }

        $started = microtime(true);
        $attemptNumber = (int) $delivery->attempt_count;
        if ($channel === NotificationChannel::Push && Cache::has(self::AUTH_PAUSE_KEY)) {
            $result = ProviderResult::failed(NotificationErrorCategory::Transient, 'PROVIDER_PAUSED', 'The push provider is paused after a credential failure.', (int) config('notifications.retry.auth_failure_pause_seconds'));
            $payloadBytes = null;
        } else {
            [$result, $payloadBytes] = $this->send($delivery, $channel, $device);
        }
        $latency = (int) round((microtime(true) - $started) * 1000);
        $this->metrics->recordProviderLatency($latency);

        $this->recordAttempt($delivery, $attemptNumber, $result, $latency, $payloadBytes);
        $this->applyResult($delivery, $channel, $device, $result, $attemptNumber);
    }

    /** Admin resend / sweep: a new attempt on a FAILED or INVALID_DESTINATION push is a new delivery row (history kept). */
    public function requeue(NotificationDelivery $delivery): NotificationDelivery
    {
        $delivery->forceFill(['status' => NotificationDeliveryStatus::RetryScheduled, 'next_retry_at' => now(), 'failed_at' => null])->save();
        SendNotificationDeliveryJob::releaseLock($delivery->getKey());
        SendNotificationDeliveryJob::dispatch($delivery->getKey());

        return $delivery->refresh();
    }

    /** @return array{0: ProviderResult, 1: int|null} */
    private function send(NotificationDelivery $delivery, NotificationChannel $channel, ?CustomerDevice $device): array
    {
        $notification = $delivery->notification;
        $vars = (array) ($notification->data ?? []);
        $rendered = $this->templates->render($notification->template_key, $channel, $notification->locale, $vars);
        if ($rendered === null) {
            return [ProviderResult::failed(NotificationErrorCategory::Permanent, 'NO_TEMPLATE', 'No template for this channel.'), null];
        }

        switch ($channel) {
            case NotificationChannel::Push:
                $message = new PushMessage(
                    token: (string) $device->push_token,
                    title: $rendered['title'],
                    body: $rendered['body'],
                    data: $this->pushData($notification),
                    priority: (string) $notification->priority,
                    androidChannelId: (string) (config('notifications.android_channels.'.$notification->category->value) ?? 'orders'),
                    collapseKey: $notification->subject_public_id !== null ? 'subject-'.substr(hash('sha256', (string) $notification->subject_public_id), 0, 24) : 'n-'.$notification->public_id,
                    ttlSeconds: max(0, (int) config('notifications.fcm.ttl_seconds')),
                    deliveryPublicId: (string) $delivery->public_id,
                );

                return [app(PushNotificationProvider::class)->send($message), $message->payloadBytes()];
            case NotificationChannel::Email:
                $to = trim((string) $notification->customer->email);
                if ($to === '') {
                    return [ProviderResult::failed(NotificationErrorCategory::InvalidDestination, 'NO_ADDRESS', 'The customer has no e-mail address.'), null];
                }

                return [app(EmailNotificationProvider::class)->send(new EmailMessage($to, (string) $rendered['subject'], $rendered['body'], (string) $rendered['html'], (string) $delivery->public_id)), null];
            case NotificationChannel::Sms:
                $phone = (string) $notification->customer->phone_e164;
                if ($phone === '') {
                    return [ProviderResult::failed(NotificationErrorCategory::InvalidDestination, 'NO_PHONE', 'The customer has no phone number.'), null];
                }

                return [app(SmsNotificationProvider::class)->send(new SmsMessage($phone, $rendered['body'], (string) $delivery->public_id)), null];
            default:
                return [ProviderResult::sent(), null];
        }
    }

    /**
     * The minimal data payload: type, notification id, allow-listed target + reference (an order number), category and
     * the Android channel. Never a pickup secret, a code, a phone, an address, an amount or a payment reference.
     *
     * @return array<string, string>
     */
    public function pushData($notification): array
    {
        return array_filter([
            'type' => (string) $notification->event_type,
            'notification_id' => (string) $notification->public_id,
            'category' => (string) $notification->category->value,
            'target' => (string) $notification->action_target->value,
            'reference' => (string) ($notification->action_reference ?? ''),
            'subject_id' => (string) ($notification->subject_public_id ?? ''),
            'channel_id' => (string) (config('notifications.android_channels.'.$notification->category->value) ?? 'orders'),
            'created_at' => $notification->created_at?->toIso8601String() ?? '',
        ], fn (string $v): bool => $v !== '');
    }

    private function applyResult(NotificationDelivery $delivery, NotificationChannel $channel, ?CustomerDevice $device, ProviderResult $result, int $attemptNumber): void
    {
        $prefix = match ($channel) {
            NotificationChannel::Push => 'push', NotificationChannel::Email => 'email', NotificationChannel::Sms => 'sms', default => 'push'
        };
        if ($result->accepted) {
            $delivery->forceFill(['status' => NotificationDeliveryStatus::Sent, 'sent_at' => now(), 'provider_message_reference' => $result->providerReference, 'next_retry_at' => null, 'last_error_code' => null, 'last_error_category' => null])->save();
            $this->metrics->record($prefix.'_sent');

            return;
        }

        $category = $result->errorCategory ?? NotificationErrorCategory::Unknown;
        $errors = ['last_error_code' => $result->providerCode, 'last_error_category' => $category];

        if ($category === NotificationErrorCategory::InvalidDestination) {
            $delivery->forceFill(['status' => NotificationDeliveryStatus::InvalidDestination, 'failed_at' => now(), 'next_retry_at' => null] + $errors)->save();
            if ($channel === NotificationChannel::Push && $device !== null && $device->isActive()) {
                $this->devices->revoke($device, CustomerDeviceRevokeReason::InvalidToken);
                $this->metrics->record('push_invalid_token');
            }
            $this->metrics->record($prefix.'_failed');

            return;
        }

        if ($category === NotificationErrorCategory::AuthConfiguration) {
            $delivery->forceFill(['status' => NotificationDeliveryStatus::Failed, 'failed_at' => now(), 'next_retry_at' => null] + $errors)->save();
            if ($channel === NotificationChannel::Push) {
                Cache::put(self::AUTH_PAUSE_KEY, now()->toIso8601String(), max(30, (int) config('notifications.retry.auth_failure_pause_seconds')));
                $this->metrics->record('push_auth_failed');
                Log::error('notifications.push.provider_configuration_error', ['delivery' => $delivery->public_id, 'code' => $result->providerCode]);
            }
            $this->metrics->record($prefix.'_failed');

            return;
        }

        if ($category === NotificationErrorCategory::Permanent || ! $category->retryable()) {
            $delivery->forceFill(['status' => NotificationDeliveryStatus::Failed, 'failed_at' => now(), 'next_retry_at' => null] + $errors)->save();
            $this->metrics->record($prefix.'_failed');

            return;
        }

        if ($category === NotificationErrorCategory::RateLimited) {
            $this->metrics->record('push_rate_limited');
        }
        $max = max(1, (int) config('notifications.retry.max_attempts'));
        if ($attemptNumber >= $max) {
            $delivery->forceFill(['status' => NotificationDeliveryStatus::Failed, 'failed_at' => now(), 'next_retry_at' => null] + $errors)->save();
            $this->metrics->record($prefix.'_failed');

            return;
        }
        $delay = $this->backoffSeconds($attemptNumber, $category, $result->retryAfterSeconds);
        $delivery->forceFill(['status' => NotificationDeliveryStatus::RetryScheduled, 'next_retry_at' => now()->addSeconds($delay)] + $errors)->save();
        $this->metrics->record($prefix.'_retry');
        SendNotificationDeliveryJob::dispatch($delivery->getKey())->delay(now()->addSeconds($delay));
    }

    public function backoffSeconds(int $attemptNumber, NotificationErrorCategory $category, ?int $retryAfter): int
    {
        $base = max(1, (int) config('notifications.retry.base_seconds'));
        $cap = max($base, (int) config('notifications.retry.max_seconds'));
        $delay = min($cap, $base * (2 ** max(0, $attemptNumber - 1))) + random_int(0, $base);
        if ($category === NotificationErrorCategory::RateLimited) {
            $delay = max($delay, (int) config('notifications.retry.rate_limited_min_seconds'), (int) ($retryAfter ?? 0));
        } elseif ($retryAfter !== null && $retryAfter > 0) {
            $delay = max($delay, min($cap, $retryAfter));
        }

        return min(max(1, $delay), $cap * 2);
    }

    private function finish(NotificationDelivery $delivery, NotificationDeliveryStatus $status, string $code): void
    {
        $delivery->forceFill(['status' => $status, 'failed_at' => now(), 'last_error_code' => $code, 'next_retry_at' => null])->save();
    }

    private function recordAttempt(NotificationDelivery $delivery, int $attemptNumber, ProviderResult $result, int $latencyMs, ?int $payloadBytes): void
    {
        $category = $result->errorCategory;
        (new NotificationDeliveryAttempt)->forceFill([
            'delivery_id' => $delivery->getKey(),
            'attempt_number' => $attemptNumber,
            'started_at' => now()->subMilliseconds($latencyMs),
            'finished_at' => now(),
            'result' => $result->accepted ? NotificationDeliveryAttempt::SENT : ($category === NotificationErrorCategory::InvalidDestination ? NotificationDeliveryAttempt::INVALID_DESTINATION : ($category !== null && $category->retryable() ? NotificationDeliveryAttempt::RETRY : NotificationDeliveryAttempt::FAILED)),
            'provider_code' => $result->providerCode,
            'error_category' => $category,
            'safe_error' => $result->safeError,
            'latency_ms' => $latencyMs,
            'payload_bytes' => $payloadBytes,
        ])->save();
    }
}
