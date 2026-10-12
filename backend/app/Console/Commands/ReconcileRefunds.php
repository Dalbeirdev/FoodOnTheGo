<?php

namespace App\Console\Commands;

use App\Services\Refund\RefundReconciliationService;
use App\Services\Refund\RefundService;
use Illuminate\Console\Command;

/**
 * The scheduled look at refunds nobody is looking at (Module 35).
 *
 *  - refunds the system owes that the policy never looked at (a lost after-commit step): the policy decides;
 *  - approved refunds that are due to be sent (approved by the policy, or waiting after a try the provider certainly
 *    did not carry out): handed to a worker;
 *  - refunds the provider may hold — sent without an answer, or accepted and not confirmed — that are due: the
 *    provider is asked, with growing waits and a limit, after which the refund needs a person.
 *
 * Safe to run at any time and by two schedulers at once: every refund is looked at under its own lock, and nothing
 * here decides a refund — the provider's record does.
 */
class ReconcileRefunds extends Command
{
    protected $signature = 'refunds:reconcile {--limit=100 : The most refunds to ask the provider about in one run}';

    protected $description = 'Apply the refund policy to refunds that wait for it, send approved refunds that are due, and ask the provider about unsettled refunds';

    public function handle(RefundService $refunds, RefundReconciliationService $reconciliation): int
    {
        $swept = $refunds->sweep();
        $looked = $reconciliation->sweep(max(1, (int) $this->option('limit')));
        $this->line(json_encode(['policy' => $swept['policy'], 'submitted' => $swept['submitted'], 'reconciled' => $looked['looked'], 'failed' => $looked['failed']]));

        return $looked['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
