<?php

namespace App\Listeners;

use App\Events\Payment\PaymentEvent;
use App\Events\Refund\RefundEvent;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Support\SystemCaseService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens a support case when an operational condition needs a person (Module 37): a verified payment that could not
 * become an order, a refund that went to review. The events arrive after the commit and may arrive twice — the case
 * service opens ONE case per condition. A failure here never disturbs the payment or the refund: it is logged, and
 * the scheduled sweep (support:sweep) opens the case later.
 */
final class OpenSystemSupportCases
{
    public function __construct(private readonly SystemCaseService $cases) {}

    public function handlePaymentEvent(PaymentEvent $event): void
    {
        if ($event->name !== PaymentEvent::RECOVERY_REQUIRED) {
            return;
        }
        $this->guarded('payment', (string) $event->paymentId, function () use ($event): void {
            $attempt = PaymentAttempt::query()->where('public_id', $event->paymentId)->first();
            if ($attempt !== null) {
                $this->cases->paymentRecovery($attempt, reported: true);
            }
        });
    }

    public function handleRefundEvent(RefundEvent $event): void
    {
        if ($event->name !== RefundEvent::REVIEW_REQUIRED || $event->refundKey === null) {
            return;
        }
        $this->guarded('refund', (string) $event->refundId, function () use ($event): void {
            $refund = Refund::query()->find($event->refundKey);
            if ($refund !== null) {
                $this->cases->refundReview($refund, reported: true);
            }
        });
    }

    private function guarded(string $what, string $reference, callable $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            Log::error('support.system_case_failed', ['about' => $what, 'reference' => $reference, 'class' => $e::class]);
            if (app()->environment('testing')) {
                throw $e; // a test must see the bug; production logs it and the sweep opens the case
            }
        }
    }
}
