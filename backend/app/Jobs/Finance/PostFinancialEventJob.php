<?php

namespace App\Jobs\Finance;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\Refund;
use App\Services\Finance\FinancialPostingService;
use App\Services\Finance\OrderAllocationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings the ledger up to date with one payment, order, refund or dispute, off the request path (Module 36).
 *
 * Safe to run any number of times and by two workers at once: every posting has a key that makes it happen once. A
 * record that no longer exists is skipped. A failure leaves the event for the scheduled sweep (finance:post-pending),
 * which finds whatever has no posting — so the queue never has to retry.
 */
final class PostFinancialEventJob implements ShouldQueue
{
    use Queueable;

    public const PAYMENT = 'payment';

    public const ORDER = 'order';

    public const REFUND = 'refund';

    public const DISPUTES = 'disputes';

    public int $tries = 1;

    public function __construct(public readonly string $kind, public readonly ?string $reference = null)
    {
        $this->onQueue((string) config('finance.queue'));
        // The payment, order or refund must be visible to the worker: wait for the transaction that wrote it.
        $this->afterCommit();
    }

    public function handle(FinancialPostingService $postings): void
    {
        match ($this->kind) {
            self::PAYMENT => ($payment = PaymentAttempt::query()->where('public_id', $this->reference)->first()) === null ? null : $postings->paymentVerified($payment),
            self::ORDER => ($order = Order::query()->find((int) $this->reference)) === null ? null : $postings->orderChanged($order, OrderAllocationService::BASIS_ORDER),
            self::REFUND => ($refund = Refund::query()->find((int) $this->reference)) === null ? null : $postings->refundChanged($refund),
            self::DISPUTES => PaymentDispute::query()->where('updated_at', '>=', now()->subDay())->orderBy('id')->limit(200)->get()->each(fn (PaymentDispute $d) => $postings->disputeChanged($d)),
            default => null,
        };
    }
}
