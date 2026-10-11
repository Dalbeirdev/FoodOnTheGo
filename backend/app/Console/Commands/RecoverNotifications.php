<?php

namespace App\Console\Commands;

use App\Enums\NotificationDeliveryStatus;
use App\Jobs\Notifications\SendNotificationDeliveryJob;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationMetrics;
use Illuminate\Console\Command;

/**
 * The safety net under the queue: deliveries left SENDING by a crashed worker (past the lease) go back to RETRY_SCHEDULED
 * and are queued again; RETRY_SCHEDULED rows whose delayed job never fired, and QUEUED rows older than five minutes,
 * are queued again. Idempotent: the job is unique per delivery and the send leases the row.
 */
class RecoverNotifications extends Command
{
    protected $signature = 'notifications:recover {--limit=200}';

    protected $description = 'Re-queue stranded notification deliveries (crashed worker, lost job)';

    public function handle(NotificationMetrics $metrics): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $lease = max(30, (int) config('notifications.retry.sending_lease_seconds'));

        $stranded = NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Sending->value)
            ->where('sending_started_at', '<', now()->subSeconds($lease))->orderBy('id')->limit($limit)->get();
        foreach ($stranded as $delivery) {
            $delivery->forceFill(['status' => NotificationDeliveryStatus::RetryScheduled, 'next_retry_at' => now(), 'last_error_code' => 'STRANDED'])->save();
            SendNotificationDeliveryJob::releaseLock($delivery->getKey());
            SendNotificationDeliveryJob::dispatch($delivery->getKey());
            $metrics->record('recovered_stranded');
        }

        $due = NotificationDelivery::query()->where('status', NotificationDeliveryStatus::RetryScheduled->value)
            ->where('next_retry_at', '<', now()->subSeconds(30))->orderBy('id')->limit($limit)->get();
        foreach ($due as $delivery) {
            SendNotificationDeliveryJob::releaseLock($delivery->getKey());
            SendNotificationDeliveryJob::dispatch($delivery->getKey());
        }

        $lost = NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Queued->value)
            ->where('created_at', '<', now()->subMinutes(5))->orderBy('id')->limit($limit)->get();
        foreach ($lost as $delivery) {
            SendNotificationDeliveryJob::releaseLock($delivery->getKey());
            SendNotificationDeliveryJob::dispatch($delivery->getKey());
        }

        $this->info(sprintf('notifications:recover — stranded %d, due %d, lost %d', $stranded->count(), $due->count(), $lost->count()));

        return self::SUCCESS;
    }
}
