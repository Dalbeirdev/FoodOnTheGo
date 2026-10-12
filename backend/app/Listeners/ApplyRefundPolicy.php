<?php

namespace App\Listeners;

use App\Events\Refund\RefundEvent;
use App\Services\Refund\RefundService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A refund the system owes was recorded (after its commit): the refund policy looks at it (Module 35). When the
 * policy approves it, it is sent to the provider; otherwise it waits for a person. The event delivered twice changes
 * nothing — the policy decides once per refund — and a failure here leaves the refund REQUIRED for the scheduled
 * sweep, never half-decided.
 */
final class ApplyRefundPolicy
{
    public function __construct(private readonly RefundService $refunds) {}

    public function handle(RefundEvent $event): void
    {
        if ($event->name !== RefundEvent::REQUIRED || $event->refundKey === null) {
            return;
        }
        try {
            $this->refunds->applyPolicy($event->refundKey);
        } catch (Throwable $e) {
            Log::error('refund.policy_failed', ['refund' => $event->refundId, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
            if (app()->environment('testing')) {
                throw $e; // a test must see the bug; production leaves the refund for the sweep
            }
        }
    }
}
