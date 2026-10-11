<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationPolicyResult;
use App\Jobs\Notifications\SendNotificationDeliveryJob;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Domain event → notification. Resolves the type, renders the in-app snapshot, asks the policy per channel, writes the
 * logical notification with its deliveries in one transaction and queues the sends after the commit. Idempotent on the
 * dedupe key: a domain event delivered twice yields one notification. Never touches the order.
 */
final class NotificationOrchestrator
{
    public function __construct(
        private readonly NotificationPolicyService $policy,
        private readonly NotificationTemplates $templates,
        private readonly CustomerDeviceService $devices,
        private readonly NotificationMetrics $metrics,
    ) {}

    /**
     * @param  array{type: string, public_id?: string|null, reference?: string|null}  $subject
     * @param  array<string, string|null>  $vars  template variables (allow-listed)
     */
    public function notify(NotificationType $type, Customer $customer, array $subject, array $vars, string $dedupeKey, ?string $locale = null): ?CustomerNotification
    {
        $existing = CustomerNotification::query()->where('dedupe_key', $dedupeKey)->first();
        if ($existing !== null) {
            $this->metrics->record('notification_deduplicated');

            return $existing;
        }

        $locale = $locale ?? $customer->preferred_locale ?? $customer->market?->default_locale ?? (string) config('notifications.default_locale');
        $inApp = $this->templates->render($type->key, NotificationChannel::InApp, $locale, $vars)
            ?? $this->templates->render($type->key, NotificationChannel::Push, $locale, $vars);
        if ($inApp === null) {
            return null; // a type without any template notifies nobody (configuration error, logged by the registry test)
        }

        $matrix = $this->policy->matrix($customer);
        $targets = $this->devices->targets($customer);
        $emailAvailable = $this->emailAvailable($customer);
        $smsAvailable = $customer->phone_verified_at !== null && (string) $customer->phone_e164 !== '';

        $queued = [];
        $notification = DB::transaction(function () use ($type, $customer, $subject, $vars, $dedupeKey, $inApp, $matrix, $targets, $emailAvailable, $smsAvailable, &$queued): ?CustomerNotification {
            $retentionDays = $type->category->value === 'ACCOUNT_SECURITY'
                ? (int) config('notifications.retention.security_notifications_days')
                : (int) config('notifications.retention.notifications_days');
            $notification = new CustomerNotification;
            $notification->forceFill([
                'customer_id' => $customer->getKey(),
                'category' => $type->category,
                'event_type' => $type->key,
                'template_key' => $type->key,
                'template_version' => $inApp['version'],
                'subject_type' => $subject['type'],
                'subject_public_id' => $subject['public_id'] ?? null,
                'subject_reference' => $subject['reference'] ?? null,
                'dedupe_key' => $dedupeKey,
                'locale' => $inApp['locale'],
                'priority' => $type->priority,
                'transactional' => $type->transactional(),
                'title' => $inApp['title'],
                'body' => $inApp['body'],
                'action_target' => $type->target,
                'action_reference' => $subject['reference'] ?? null,
                'data' => array_intersect_key($vars, array_flip(NotificationTemplates::VARIABLES)),
                'expires_at' => now()->addDays(max(1, $retentionDays)),
            ]);
            try {
                $notification->save();
            } catch (QueryException $e) {
                // A concurrent handler of the same event won the unique dedupe key.
                return null;
            }
            $this->metrics->record('notification_created');

            foreach ([NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email, NotificationChannel::Sms] as $channel) {
                $destination = match ($channel) {
                    NotificationChannel::InApp => true,
                    NotificationChannel::Push => $targets->isNotEmpty(),
                    NotificationChannel::Email => $emailAvailable,
                    NotificationChannel::Sms => $smsAvailable,
                };
                $result = $this->policy->decide($type, $channel, $customer, $matrix, $destination);
                if ($result === NotificationPolicyResult::Unsupported) {
                    continue;
                }
                if (! $result->sends()) {
                    $this->recordSkip($notification, $channel, $result);

                    continue;
                }
                if ($channel === NotificationChannel::InApp) {
                    $this->delivery($notification, $channel, null, 'database', NotificationDeliveryStatus::Sent, $result, ['sent_at' => now()]);
                    $this->metrics->record('in_app_created');

                    continue;
                }
                if ($channel === NotificationChannel::Push) {
                    foreach ($targets as $device) {
                        $queued[] = $this->delivery($notification, $channel, $device->getKey(), strtolower((string) $device->push_provider), NotificationDeliveryStatus::Queued, $result);
                        $this->metrics->record('push_queued');
                    }

                    continue;
                }
                $provider = (string) config('notifications.channels.'.$channel->value.'.driver');
                $queued[] = $this->delivery($notification, $channel, null, $provider, NotificationDeliveryStatus::Queued, $result);
                $this->metrics->record($channel === NotificationChannel::Email ? 'email_queued' : 'sms_queued');
            }

            return $notification;
        });

        if ($notification === null) {
            $this->metrics->record('notification_deduplicated');

            return CustomerNotification::query()->where('dedupe_key', $dedupeKey)->first();
        }

        foreach ($queued as $delivery) {
            SendNotificationDeliveryJob::dispatch($delivery->getKey());
        }

        return $notification;
    }

    /** E-mail needs a verified address; the customer account has no e-mail verification yet, so no e-mail is sent today. */
    private function emailAvailable(Customer $customer): bool
    {
        $email = trim((string) $customer->email);
        if ($email === '') {
            return false;
        }
        if ((bool) config('notifications.channels.EMAIL.require_verified', true)) {
            return (bool) ($customer->email_verified_at ?? false);
        }

        return true;
    }

    private function recordSkip(CustomerNotification $notification, NotificationChannel $channel, NotificationPolicyResult $result): void
    {
        $this->delivery($notification, $channel, null, 'none', NotificationDeliveryStatus::Skipped, $result);
        $this->metrics->record(match ($result) {
            NotificationPolicyResult::SkipUserPreference => 'skipped_preference',
            NotificationPolicyResult::SkipNoDestination => 'skipped_no_destination',
            NotificationPolicyResult::SkipChannelDisabled => 'skipped_channel_disabled',
            NotificationPolicyResult::SkipOsPermissionDenied => 'skipped_os_permission',
            default => 'skipped_customer_status',
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function delivery(CustomerNotification $notification, NotificationChannel $channel, ?int $deviceId, string $provider, NotificationDeliveryStatus $status, NotificationPolicyResult $result, array $extra = []): NotificationDelivery
    {
        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'notification_id' => $notification->getKey(),
            'channel' => $channel,
            'customer_device_id' => $deviceId,
            'provider' => mb_substr($provider, 0, 24),
            'status' => $status,
            'policy_result' => $result,
            'attempt_count' => 0,
        ] + $extra)->save();

        return $delivery;
    }
}
