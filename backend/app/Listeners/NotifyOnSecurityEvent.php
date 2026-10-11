<?php

namespace App\Listeners;

use App\Events\SecurityEventRecorded;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Security event → customer notice (registry `security_events`). Customers only; the notice carries no secret detail,
 * only that the change happened. Written after the recorder's transaction (if any) commits.
 */
final class NotifyOnSecurityEvent
{
    public function __construct(private readonly NotificationRegistry $registry, private readonly NotificationOrchestrator $orchestrator) {}

    public function handle(SecurityEventRecorded $recorded): void
    {
        $principal = $recorded->principal;
        if (! $principal instanceof Customer) {
            return;
        }
        $type = $this->registry->forSecurityEvent($recorded->event->event->value);
        if ($type === null) {
            return;
        }
        $run = function () use ($type, $principal, $recorded): void {
            try {
                $this->orchestrator->notify($type, $principal, ['type' => CustomerNotification::SUBJECT_ACCOUNT, 'public_id' => (string) $principal->public_id, 'reference' => null], [], 'security:'.$recorded->event->getKey());
            } catch (Throwable $e) {
                Log::error('notifications.security_listener_failed', ['event' => $recorded->event->getKey(), 'class' => $e::class]);
                if (app()->environment('testing')) {
                    throw $e; // a test must see a notification bug; production only logs it
                }
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }
}
