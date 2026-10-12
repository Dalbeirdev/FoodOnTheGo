<?php

namespace App\Jobs\Finance;

use App\Services\Finance\Payout\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one payout to the payout provider, off the request path (Module 36).
 *
 * Safe to run any number of times and by two workers at once: the submission takes a lock and sends only a payout
 * that is REQUIRED, and every try carries the same idempotency key. The job is never retried by the queue — a
 * submission without an answer is settled by asking the provider what it has (finance:payouts), not by sending again.
 */
final class SubmitPayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $payoutId)
    {
        $this->onQueue((string) config('finance.queue'));
        // The payout must be visible to the worker: wait for the transaction that made it.
        $this->afterCommit();
    }

    public function handle(PayoutService $payouts): void
    {
        $payouts->submit($this->payoutId);
    }
}
