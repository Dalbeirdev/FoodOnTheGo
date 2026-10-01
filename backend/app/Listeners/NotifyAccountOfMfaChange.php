<?php

namespace App\Listeners;

use App\Events\MfaChanged;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Notifications\MfaChangedNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E-mails the account owner whenever their multi-factor authentication changes. Sent only after the change
 * is committed, and a mail failure never undoes or blocks the change itself — it is reported, and the
 * security event remains the record.
 */
class NotifyAccountOfMfaChange
{
    public function handle(MfaChanged $event): void
    {
        $account = $event->account;
        if (! $account instanceof AdminUser && ! $account instanceof RestaurantUser) {
            return;
        }

        $byOwner = $event->actor === null || ($account::class === $event->actor::class && $event->actor->getAuthIdentifier() === $account->getAuthIdentifier());
        $change = $event->enabled ? MfaChangedNotification::ENABLED : ($byOwner ? MfaChangedNotification::DISABLED : MfaChangedNotification::RESET_BY_ADMINISTRATOR);
        $at = now();

        DB::afterCommit(function () use ($account, $change, $at): void {
            try {
                $account->notify(new MfaChangedNotification($change, $at));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
