<?php

namespace App\Console\Commands;

use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The scheduled look at payments nobody is looking at (Module 30).
 *
 *  - attempts that may still end in a payment and are due: the provider is asked (with growing waits, and a limit —
 *    after which the attempt needs a person);
 *  - webhook events that were accepted and never processed: handed to a worker again;
 *  - verified payments whose checkout can no longer become an order as it stands: marked as needing recovery.
 *
 * Safe to run at any time and by two schedulers at once: each attempt is looked at by one of them, and nothing here
 * decides a payment — the provider's record does.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=200 : The most attempts to look at in one run}';

    protected $description = 'Ask the provider about unsettled payment attempts, re-queue unprocessed webhook events, and flag verified payments that need recovery';

    public function handle(PaymentReconciliationService $reconciliation, PaymentWebhookService $webhooks): int
    {
        $now = now();
        $limit = max(1, (int) $this->option('limit'));
        $looked = $failed = 0;
        PaymentAttempt::query()->whereIn('status', PaymentStatus::openValues())
            ->where(fn ($q) => $q->where('next_reconcile_at', '<=', $now)->orWhere(fn ($w) => $w->whereNull('next_reconcile_at')->where('expires_at', '<=', $now)))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (PaymentAttempt $attempt) use ($reconciliation, &$looked, &$failed): void {
                try {
                    $reconciliation->reconcile($attempt, PaymentAttemptEvent::SOURCE_JOB);
                    $looked++;
                } catch (Throwable $e) {
                    $failed++;
                    report($e); // one attempt that cannot be looked at must not stop the others
                }
            });

        $recovery = 0;
        PaymentAttempt::query()->where('status', PaymentStatus::Verified->value)->where('fulfillment_status', PaymentFulfillmentStatus::OrderPending->value)
            ->orderBy('id')->limit($limit)->get()
            ->each(function (PaymentAttempt $attempt) use ($reconciliation, &$recovery): void {
                if ($reconciliation->reviewFulfillment($attempt, PaymentAttemptEvent::SOURCE_JOB)->fulfillment_status === PaymentFulfillmentStatus::RecoveryRequired) {
                    $recovery++;
                }
            });

        $requeued = $webhooks->sweep();
        $this->info("Looked at {$looked} payment attempt(s) ({$failed} could not be looked at); re-queued {$requeued} webhook event(s); {$recovery} verified payment(s) now need recovery.");

        return self::SUCCESS;
    }
}
