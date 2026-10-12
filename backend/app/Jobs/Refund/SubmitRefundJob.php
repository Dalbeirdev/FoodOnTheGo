<?php

namespace App\Jobs\Refund;

use App\Models\RefundEvent;
use App\Services\Refund\RefundSubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one approved refund to the provider, off the request path (Module 35).
 *
 * Safe to run any number of times and by two workers at once: the submission takes a lock, sends only an APPROVED
 * refund, and every try of one provider refund carries the same idempotency key. The job itself is never retried by
 * the queue — a submission without an answer is settled by looking the refund up (refunds:reconcile), not by
 * sending it again.
 */
final class SubmitRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $refundId)
    {
        $this->onQueue((string) config('refunds.submission.queue'));
        // The approval must be visible to the worker: wait for the transaction that wrote it.
        $this->afterCommit();
    }

    public function handle(RefundSubmissionService $submission): void
    {
        $submission->submit($this->refundId, RefundEvent::SOURCE_JOB);
    }
}
