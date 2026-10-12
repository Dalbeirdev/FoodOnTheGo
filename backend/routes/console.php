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

// Refunds (Module 35): refunds the policy never looked at, approved refunds due to be sent, and refunds the provider
// may hold that are due for a look (with growing waits and a limit — then a person).
Schedule::command('refunds:reconcile')->everyMinute()->withoutOverlapping();

// Module 33: stranded or lost notification deliveries are re-queued; retention runs nightly.
Schedule::command('notifications:recover')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:prune')->daily();

// Module 36 — finance. Each command is idempotent and reads or posts only what the records already say; none of them
// sends money. Settlement generation and the provider import run on a schedule only when their switch is on
// (config/finance.php): no production cadence was approved.
Schedule::command('finance:post-pending')->everyMinute()->withoutOverlapping();
Schedule::command('finance:verify-ledger')->hourly()->withoutOverlapping();
Schedule::command('finance:reconcile-internal')->hourly()->withoutOverlapping();
Schedule::command('finance:reconcile-provider')->dailyAt('03:30')->withoutOverlapping()->when(fn (): bool => (bool) config('finance.provider_reconciliation.scheduled'));
Schedule::command('finance:generate-settlements')->dailyAt('04:30')->withoutOverlapping()->when(fn (): bool => (bool) config('finance.settlements.scheduled'));
