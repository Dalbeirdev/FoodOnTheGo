<?php

namespace App\Services\Notifications;

use App\Enums\CustomerDeviceRevokeReason;
use App\Enums\PushPermissionState;
use App\Enums\SecurityEventType;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Services\Auth\SecurityEventRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Device binding. One ACTIVE row per installation and per token; the customer always comes from the authenticated
 * principal. A different customer on the same installation revokes the old binding (REBOUND) before the new one exists;
 * the same token appearing on another installation revokes the older holder (TOKEN_REASSIGNED): a shared phone never
 * keeps receiving the previous customer's orders. Logout revokes (LOGOUT). Rows are never deleted.
 */
final class CustomerDeviceService
{
    public function __construct(private readonly NotificationMetrics $metrics, private readonly SecurityEventRecorder $security) {}

    /**
     * Register or refresh the caller's current installation (idempotent).
     *
     * @param  array{installation_id: string, platform: string, push_provider: string, push_token: string, app_version?: string|null, device_locale?: string|null, timezone?: string|null, notifications_permission_state?: string|null}  $input
     */
    public function bind(Customer $customer, array $input): CustomerDevice
    {
        $token = (string) $input['push_token'];
        $hash = CustomerDevice::hashToken($token);
        $installation = (string) $input['installation_id'];

        return DB::transaction(function () use ($customer, $input, $token, $hash, $installation): CustomerDevice {
            // Serialise bindings of this installation and of this token.
            $existing = CustomerDevice::query()->where('installation_id', $installation)->whereNull('revoked_at')->lockForUpdate()->first();
            $holders = CustomerDevice::query()->where('push_token_hash', $hash)->whereNull('revoked_at')->lockForUpdate()->get();

            foreach ($holders as $holder) {
                if ($existing !== null && $holder->is($existing)) {
                    continue;
                }
                // The same token on another installation (or another customer): the older binding ends.
                $this->revokeRow($holder, CustomerDeviceRevokeReason::TokenReassigned);
                $this->metrics->record('device_token_reassigned');
                if ((int) $holder->customer_id !== (int) $customer->getKey()) {
                    $this->security->record(SecurityEventType::DeviceRebound, $customer, ['device' => $holder->public_id, 'reason' => 'TOKEN_REASSIGNED']);
                }
            }

            if ($existing !== null && (int) $existing->customer_id !== (int) $customer->getKey()) {
                // Another customer signed in on this installation: the previous customer's binding ends first.
                $this->revokeRow($existing, CustomerDeviceRevokeReason::Rebound);
                $this->metrics->record('device_rebound');
                $this->security->record(SecurityEventType::DeviceRebound, $customer, ['device' => $existing->public_id, 'reason' => 'REBOUND']);
                $existing = null;
            }

            $attributes = [
                'platform' => strtoupper((string) $input['platform']),
                'push_provider' => strtoupper((string) $input['push_provider']),
                'app_version' => $this->clip($input['app_version'] ?? null, 40),
                'device_locale' => $this->clip($input['device_locale'] ?? null, 16),
                'timezone' => $this->clip($input['timezone'] ?? null, 64),
                'notifications_permission_state' => PushPermissionState::tryFrom(strtoupper((string) ($input['notifications_permission_state'] ?? 'UNKNOWN'))) ?? PushPermissionState::Unknown,
                'last_seen_at' => now(),
            ];

            if ($existing !== null) {
                if ($existing->push_token_hash !== $hash) {
                    $attributes['push_token'] = $token;
                    $attributes['push_token_hash'] = $hash;
                    $attributes['token_updated_at'] = now();
                }
                $existing->forceFill($attributes)->save();

                return $existing->refresh();
            }

            $this->enforceLimit($customer);
            $device = new CustomerDevice;
            $device->forceFill($attributes + [
                'customer_id' => $customer->getKey(),
                'installation_id' => $installation,
                'push_token' => $token,
                'push_token_hash' => $hash,
                'token_updated_at' => now(),
            ])->save();
            $this->metrics->record('device_registered');

            return $device->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(CustomerDevice $device, array $input): CustomerDevice
    {
        return DB::transaction(function () use ($device, $input): CustomerDevice {
            $locked = CustomerDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $attributes = ['last_seen_at' => now()];
            foreach (['app_version' => 40, 'device_locale' => 16, 'timezone' => 64] as $field => $max) {
                if (array_key_exists($field, $input)) {
                    $attributes[$field] = $this->clip($input[$field], $max);
                }
            }
            if (array_key_exists('notifications_permission_state', $input)) {
                $attributes['notifications_permission_state'] = PushPermissionState::tryFrom(strtoupper((string) $input['notifications_permission_state'])) ?? PushPermissionState::Unknown;
            }
            if (array_key_exists('push_token', $input) && is_string($input['push_token']) && $input['push_token'] !== '') {
                $hash = CustomerDevice::hashToken($input['push_token']);
                if ($hash !== $locked->push_token_hash) {
                    foreach (CustomerDevice::query()->where('push_token_hash', $hash)->whereNull('revoked_at')->whereKeyNot($locked->getKey())->lockForUpdate()->get() as $holder) {
                        $this->revokeRow($holder, CustomerDeviceRevokeReason::TokenReassigned);
                        $this->metrics->record('device_token_reassigned');
                    }
                    $attributes['push_token'] = $input['push_token'];
                    $attributes['push_token_hash'] = $hash;
                    $attributes['token_updated_at'] = now();
                }
            }
            $locked->forceFill($attributes)->save();

            return $locked->refresh();
        });
    }

    public function revoke(CustomerDevice $device, CustomerDeviceRevokeReason $reason): CustomerDevice
    {
        return DB::transaction(function () use ($device, $reason): CustomerDevice {
            $locked = CustomerDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at === null) {
                $this->revokeRow($locked, $reason);
            }

            return $locked->refresh();
        });
    }

    /** Logout of one installation: the binding ends; the token stays on the phone (Firebase is not touched). */
    public function revokeInstallation(Customer $customer, string $installationId, CustomerDeviceRevokeReason $reason): int
    {
        return DB::transaction(function () use ($customer, $installationId, $reason): int {
            $rows = CustomerDevice::query()->where('customer_id', $customer->getKey())->where('installation_id', $installationId)->whereNull('revoked_at')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->revokeRow($row, $reason);
            }

            return $rows->count();
        });
    }

    public function revokeAll(Customer $customer, CustomerDeviceRevokeReason $reason): int
    {
        return DB::transaction(function () use ($customer, $reason): int {
            $rows = CustomerDevice::query()->where('customer_id', $customer->getKey())->whereNull('revoked_at')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->revokeRow($row, $reason);
            }

            return $rows->count();
        });
    }

    /** Devices a push may target now: active, with a token, and not reported as OS-denied. */
    public function targets(Customer $customer): Collection
    {
        return CustomerDevice::query()->where('customer_id', $customer->getKey())->whereNull('revoked_at')->whereNotNull('push_token_hash')
            ->where('notifications_permission_state', '!=', PushPermissionState::Denied->value)->orderBy('id')->get();
    }

    public function active(Customer $customer): Collection
    {
        return CustomerDevice::query()->where('customer_id', $customer->getKey())->whereNull('revoked_at')->orderByDesc('last_seen_at')->get();
    }

    /** Cheap presence update (at most one write per ten minutes per device). */
    public function touch(CustomerDevice $device): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(10))) {
            CustomerDevice::query()->whereKey($device->getKey())->update(['last_seen_at' => now()]);
        }
    }

    private function enforceLimit(Customer $customer): void
    {
        $limit = max(1, (int) config('notifications.limits.devices_per_customer'));
        $active = CustomerDevice::query()->where('customer_id', $customer->getKey())->whereNull('revoked_at')->orderBy('last_seen_at')->orderBy('id')->lockForUpdate()->get();
        while ($active->count() >= $limit) {
            $oldest = $active->shift();
            $this->revokeRow($oldest, CustomerDeviceRevokeReason::Replaced);
        }
    }

    private function revokeRow(CustomerDevice $device, CustomerDeviceRevokeReason $reason): void
    {
        $device->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
        $this->metrics->record('device_revoked');
    }

    private function clip(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }
}
