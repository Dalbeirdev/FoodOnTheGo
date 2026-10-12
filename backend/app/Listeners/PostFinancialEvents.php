<?php

namespace App\Listeners;

use App\Events\Order\OrderEvent;
use App\Events\Payment\PaymentVerified;
use App\Events\Refund\RefundEvent;
use App\Jobs\Finance\PostFinancialEventJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the finance domain that a payment was verified, an order was created or ended, or a refund or a dispute
 * changed (Module 36). It only queues the posting (queue "finance"): the payment, order and refund paths never wait
 * for the ledger and never fail because of it. An event delivered twice posts once; an event that is lost is found
 * by the scheduled sweep.
 */
final class PostFinancialEvents
{
    /** The order events that matter to money: the allocation is frozen at creation and settled at the end. */
    private const ORDER_EVENTS = [OrderEvent::ORDER_CREATED, OrderEvent::COMPLETED, OrderEvent::RESTAURANT_REJECTED, OrderEvent::CANCELLED];

    public function handlePaymentVerified(PaymentVerified $event): void
    {
        $this->queue(PostFinancialEventJob::PAYMENT, $event->paymentId);
    }

    public function handleOrderEvent(OrderEvent $event): void
    {
        if ($event->orderKey !== null && in_array($event->type, self::ORDER_EVENTS, true)) {
            $this->queue(PostFinancialEventJob::ORDER, (string) $event->orderKey);
        }
    }

    public function handleRefundEvent(RefundEvent $event): void
    {
        if ($event->name === RefundEvent::DISPUTE_RECORDED) {
            $this->queue(PostFinancialEventJob::DISPUTES, null);
        } elseif ($event->refundKey !== null && in_array($event->name, [RefundEvent::REQUIRED, RefundEvent::REQUESTED, RefundEvent::COMPLETED, RefundEvent::CANCELLED], true)) {
            $this->queue(PostFinancialEventJob::REFUND, (string) $event->refundKey);
        }
    }

    private function queue(string $kind, ?string $reference): void
    {
        try {
            PostFinancialEventJob::dispatch($kind, $reference);
        } catch (Throwable $e) {
            Log::error('finance.posting_not_queued', ['kind' => $kind, 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
            if (app()->environment('testing')) {
                throw $e; // a test must see the bug; production leaves the event for the sweep
            }
        }
    }
}
