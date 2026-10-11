<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Module 25: recent locations are kept only for the configured retention.
Schedule::command('customer:prune-recent-locations')->daily();

// Module 26: journey contexts expire; expired rows are removed after a grace period.
Schedule::command('journeys:prune')->hourly();

// Module 27: carts expire; expired carts are closed after a grace period and closed carts deleted after retention.
Schedule::command('carts:prune')->hourly();
// Module 29: checkouts and the pickup times held for them are short-lived; what ran out is closed promptly.
Schedule::command('checkouts:prune')->everyFiveMinutes();
// Payments (Module 30): unsettled attempts, unprocessed webhook events and verified payments that need recovery.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
// Orders (Module 31): order creation that is due again, and verified payments that nobody turned into an order.
Schedule::command('orders:recover')->everyMinute()->withoutOverlapping();

// Module 33: stranded or lost notification deliveries are re-queued; retention runs nightly.
Schedule::command('notifications:recover')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:prune')->daily();
