<?php

namespace App\Http\Resources\Notifications;

use App\Models\CustomerDevice;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryAttempt;

/**
 * Shapes of the notification domain in API answers. The customer sees the in-app record and its action; the admin sees
 * the deliveries and attempts with normalised diagnostics. No view ever carries a push token, an e-mail address or a phone.
 */
final class NotificationPresenter
{
    public function forCustomer(CustomerNotification $n): array
    {
        return [
            'id' => (string) $n->public_id,
            'category' => $n->category->value,
            'type' => (string) $n->event_type,
            'priority' => (string) $n->priority,
            'transactional' => (bool) $n->transactional,
            'title' => (string) $n->title,
            'body' => (string) $n->body,
            'action' => ['target' => $n->action_target->value, 'reference' => $n->action_reference],
            'subject' => ['type' => $n->subject_type, 'id' => $n->subject_public_id, 'reference' => $n->subject_reference],
            'read' => $n->read_at !== null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    public function forAdmin(CustomerNotification $n, bool $withDeliveries = false): array
    {
        $customer = $n->customer;
        $out = $this->forCustomer($n) + [
            'template' => ['key' => (string) $n->template_key, 'version' => (int) $n->template_version, 'locale' => (string) $n->locale],
            'dedupe_key' => (string) $n->dedupe_key,
            'expires_at' => $n->expires_at?->toIso8601String(),
            'customer' => $customer === null ? null : ['id' => (string) $customer->public_id, 'display_name' => $customer->displayName()],
            'delivery_summary' => $this->summary($n),
        ];
        if ($withDeliveries) {
            $out['deliveries'] = $n->deliveries->map(fn (NotificationDelivery $d): array => $this->delivery($d, true))->values()->all();
        }

        return $out;
    }

    public function delivery(NotificationDelivery $d, bool $withAttempts = false): array
    {
        $device = $d->device;
        $out = [
            'id' => (string) $d->public_id,
            'channel' => $d->channel->value,
            'provider' => (string) $d->provider,
            'status' => $d->status->value,
            'policy_result' => $d->policy_result?->value,
            'attempt_count' => (int) $d->attempt_count,
            'next_retry_at' => $d->next_retry_at?->toIso8601String(),
            'last_error' => $d->last_error_code === null ? null : ['code' => (string) $d->last_error_code, 'category' => $d->last_error_category?->value],
            'provider_message_reference' => $d->provider_message_reference,
            'sent_at' => $d->sent_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'failed_at' => $d->failed_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'device' => $device === null ? null : $this->deviceSummary($device),
            'retryable' => $this->retryable($d),
        ];
        if ($withAttempts) {
            $out['attempts'] = $d->attempts->map(fn (NotificationDeliveryAttempt $a): array => [
                'number' => (int) $a->attempt_number,
                'result' => (string) $a->result,
                'provider_code' => $a->provider_code,
                'error_category' => $a->error_category?->value,
                'safe_error' => $a->safe_error,
                'latency_ms' => $a->latency_ms,
                'payload_bytes' => $a->payload_bytes,
                'started_at' => $a->started_at?->toIso8601String(),
                'finished_at' => $a->finished_at?->toIso8601String(),
            ])->values()->all();
        }

        return $out;
    }

    public function retryable(NotificationDelivery $d): bool
    {
        if ($d->status->value !== 'FAILED') {
            return false;
        }
        if ($d->channel->value === 'PUSH') {
            $device = $d->device;

            return $device !== null && $device->isActive();
        }

        return true;
    }

    /** The customer's own device list: never the token, never the hash. */
    public function device(CustomerDevice $device, ?string $currentInstallation = null): array
    {
        return $this->deviceSummary($device) + [
            'app_version' => $device->app_version,
            'device_locale' => $device->device_locale,
            'timezone' => $device->timezone,
            'notifications_permission_state' => $device->notifications_permission_state->value,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'token_updated_at' => $device->token_updated_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
            'current' => $currentInstallation !== null && hash_equals($currentInstallation, (string) $device->installation_id),
        ];
    }

    public function deviceSummary(CustomerDevice $device): array
    {
        return [
            'id' => (string) $device->public_id,
            'platform' => (string) $device->platform,
            'push_provider' => (string) $device->push_provider,
            'active' => $device->isActive(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'revoke_reason' => $device->revoke_reason?->value,
            'token_state' => $device->revoked_at !== null ? ($device->revoke_reason?->value === 'INVALID_TOKEN' ? 'INVALID' : 'INACTIVE') : ($device->push_token_hash === null ? 'NONE' : 'ACTIVE'),
        ];
    }

    private function summary(CustomerNotification $n): array
    {
        $summary = [];
        foreach ($n->deliveries as $d) {
            $summary[$d->channel->value] = ($summary[$d->channel->value] ?? []) + [];
            $summary[$d->channel->value][$d->status->value] = ($summary[$d->channel->value][$d->status->value] ?? 0) + 1;
        }

        return $summary;
    }
}
