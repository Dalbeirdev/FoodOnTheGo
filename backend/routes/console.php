<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Module 40 — every entry runs on ONE server (onOneServer: a lock in the shared cache store, so a second scheduler host
// does not run it again), and the overlap lock of a frequent sweep EXPIRES: 10 minutes for the per-minute and
// five-minute sweeps, 2 hours for the hourly ones (the framework default is 24 hours — a scheduler killed in the middle
// of payments:reconcile would otherwise stop the money sweeps for a day). Each sweep is idempotent and takes its own
// row / cache locks, so an expired overlap lock never lets the same work be done twice.

// Module 39: every Prunable model's expired rows (OTP challenges, idempotency records, phone-change requests, reset
// tokens, staff invitations), expired access tokens, and failed jobs older than 30 days.
Schedule::command('model:prune')->daily()->withoutOverlapping()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();

// Module 25: recent locations are kept only for the configured retention.
Schedule::command('customer:prune-recent-locations')->daily()->onOneServer();

// Module 26: journey contexts expire; expired rows are removed after a grace period.
Schedule::command('journeys:prune')->hourly()->onOneServer();

// Module 27: carts expire; expired carts are closed after a grace period and closed carts deleted after retention.
Schedule::command('carts:prune')->hourly()->onOneServer();
// Module 29: checkouts and the pickup times held for them are short-lived; what ran out is closed promptly.
Schedule::command('checkouts:prune')->everyFiveMinutes()->onOneServer();
// Payments (Module 30): unsettled attempts, unprocessed webhook events and verified payments that need recovery.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping(10)->onOneServer();
// Orders (Module 31): order creation that is due again, and verified payments that nobody turned into an order.
Schedule::command('orders:recover')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Refunds (Module 35): refunds the policy never looked at, approved refunds due to be sent, and refunds the provider
// may hold that are due for a look (with growing waits and a limit — then a person).
Schedule::command('refunds:reconcile')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Module 33: stranded or lost notification deliveries are re-queued; retention runs nightly.
Schedule::command('notifications:recover')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('notifications:prune')->daily()->onOneServer();

// Module 36 — finance. Each command is idempotent and reads or posts only what the records already say; none of them
// sends money. Settlement generation and the provider import run on a schedule only when their switch is on
// (config/finance.php): no production cadence was approved.
Schedule::command('finance:post-pending')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('finance:verify-ledger')->hourly()->withoutOverlapping(120)->onOneServer();
Schedule::command('finance:reconcile-internal')->hourly()->withoutOverlapping(120)->onOneServer();
Schedule::command('finance:reconcile-provider')->dailyAt('03:30')->withoutOverlapping()->onOneServer()->when(fn (): bool => (bool) config('finance.provider_reconciliation.scheduled'));
Schedule::command('finance:generate-settlements')->dailyAt('04:30')->withoutOverlapping()->onOneServer()->when(fn (): bool => (bool) config('finance.settlements.scheduled'));

// Module 37 — support cases: the system cases a listener missed, SLA states, and the auto-close rules (off until the
// business sets the days). Idempotent; it changes no order, payment, refund or money.
Schedule::command('support:sweep')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Module 38 — analytics: the read models (what changed since the last run + the most recent days), the integrity
// checks, and retention. Every one of them reads the source domains and writes only analytics tables; none changes
// an order, a payment, a refund or the ledger.
Schedule::command('analytics:aggregate')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer()->when(fn (): bool => (bool) config('analytics.aggregation.scheduled'));
Schedule::command('analytics:check')->hourly()->withoutOverlapping(120)->onOneServer()->when(fn (): bool => (bool) config('analytics.quality.scheduled'));
Schedule::command('analytics:prune')->daily()->withoutOverlapping()->onOneServer();
