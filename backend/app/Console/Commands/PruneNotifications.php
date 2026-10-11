<?php

namespace App\Console\Commands;

use App\Models\CustomerDevice;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryAttempt;
use Illuminate\Console\Command;

/**
 * Retention: notifications past their expiry (deliveries and attempts cascade), attempt rows of finished deliveries older
 * than the delivery retention, and the token of devices revoked long ago (the row stays for the delivery history, the
 * token is purged). Order history (order_events) is never touched.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--batch=500}';

    protected $description = 'Apply the notification retention (expired notifications, old attempts, old revoked tokens)';

    public function handle(): int
    {
        $batch = max(50, (int) $this->option('batch'));
        $deleted = 0;
        do {
            $ids = CustomerNotification::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->orderBy('id')->limit($batch)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += CustomerNotification::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        $attemptDays = max(1, (int) config('notifications.retention.deliveries_days'));
        $attempts = NotificationDeliveryAttempt::query()->where('finished_at', '<', now()->subDays($attemptDays))
            ->whereIn('delivery_id', NotificationDelivery::query()->select('id')->whereNotIn('status', ['QUEUED', 'SENDING', 'RETRY_SCHEDULED']))->delete();

        $deviceDays = max(1, (int) config('notifications.retention.revoked_devices_days'));
        $purged = CustomerDevice::query()->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays($deviceDays))->whereNotNull('push_token')
            ->update(['push_token' => null, 'push_token_hash' => null]);

        $this->info(sprintf('notifications:prune — notifications %d, attempts %d, tokens purged %d', $deleted, $attempts, $purged));

        return self::SUCCESS;
    }
}
