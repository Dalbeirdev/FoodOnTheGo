<?php

namespace App\Console\Commands;

use App\Services\Finance\InternalReconciliationService;
use Illuminate\Console\Command;

/**
 * Compares the platform's own financial records with each other (Module 36): paid orders and their payments, orphan
 * payments, refunds against payments, payouts against settlements, events without a posting, orders that wait for
 * their terms. It reads and reports; it changes no payment, order, refund or payout.
 */
class FinanceReconcileInternal extends Command
{
    protected $signature = 'finance:reconcile-internal';

    protected $description = 'Reconcile payments, orders, refunds, payouts and the ledger with each other';

    public function handle(InternalReconciliationService $reconciliation): int
    {
        $run = $reconciliation->run();
        $this->line(json_encode(['status' => $run->status, 'problems' => $run->problems, ...(array) $run->result]));

        return self::SUCCESS;
    }
}
