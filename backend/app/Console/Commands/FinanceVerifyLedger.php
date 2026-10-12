<?php

namespace App\Console\Commands;

use App\Services\Finance\LedgerIntegrityService;
use Illuminate\Console\Command;

/**
 * Checks the ledger against itself and against the records it mirrors (Module 36): balanced transactions, currencies,
 * duplicate and orphan postings, the restaurants' payables against the ledger, inverted clearing accounts and states
 * that contradict each other. It repairs nothing: a finding is a difference in the finance queue, and an imbalance is
 * logged as critical. Exit code 1 when anything was found.
 */
class FinanceVerifyLedger extends Command
{
    protected $signature = 'finance:verify-ledger';

    protected $description = 'Verify the integrity of the financial ledger (balanced, single-currency, no orphans, payables agree)';

    public function handle(LedgerIntegrityService $integrity): int
    {
        $run = $integrity->verify();
        $this->line(json_encode(['status' => $run->status, 'problems' => $run->problems, ...(array) $run->result]));

        return $run->status === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
