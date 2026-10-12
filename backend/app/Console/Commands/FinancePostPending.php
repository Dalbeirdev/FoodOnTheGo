<?php

namespace App\Console\Commands;

use App\Models\FinanceRun;
use App\Services\Finance\FinancialPostingService;
use App\Services\Finance\OrderAllocationService;
use App\Services\Finance\Payout\PayoutService;
use Illuminate\Console\Command;

/**
 * The scheduled look at financial events that are not in the ledger yet (Module 36) — and, with --backfill, the
 * explicit posting of what existed before this module.
 *
 * It posts verified payments, allocates orders, recognizes completed ones, voids rejected and cancelled ones, posts
 * completed refunds and lost disputes, and releases holds whose refund or dispute is over. Each kind is found by the
 * ABSENCE of its posting, so running it twice — or beside the queue — posts nothing twice.
 *
 * A backfilled order is allocated with the commission plan that applied when it was placed. When no plan applied, the
 * allocation waits for a finance decision: no financial history is made up for it.
 */
class FinancePostPending extends Command
{
    protected $signature = 'finance:post-pending {--backfill : Work through everything that has no posting, in batches, and record the counts}
        {--limit= : The most records of each kind in one batch}';

    protected $description = 'Post financial events that are not in the ledger yet (also the idempotent backfill of earlier orders)';

    public function handle(FinancialPostingService $postings, PayoutService $payouts): int
    {
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));
        $backfill = (bool) $this->option('backfill');
        $started = now();
        $total = ['payments' => 0, 'allocations' => 0, 'recognized' => 0, 'voided' => 0, 'refunds' => 0, 'disputes' => 0, 'holds_released' => 0, 'failed' => 0];
        // A backfill repeats until a batch finds nothing new; the scheduled run does one batch.
        for ($round = 0; $round < ($backfill ? 500 : 1); $round++) {
            $done = $postings->sweep($limit, OrderAllocationService::BASIS_BACKFILL);
            foreach ($done as $name => $count) {
                $total[$name] += $count;
            }
            if ($done['payments'] + $done['allocations'] + $done['recognized'] + $done['voided'] + $done['refunds'] + $done['disputes'] === 0) {
                break;
            }
        }
        $swept = $payouts->sweep();
        if ($backfill) {
            (new FinanceRun)->forceFill(['kind' => FinanceRun::BACKFILL, 'status' => $total['failed'] === 0 ? 'OK' : 'PROBLEMS', 'triggered_by' => FinanceRun::BY_COMMAND, 'result' => $total, 'problems' => $total['failed'], 'started_at' => $started, 'finished_at' => now()])->save();
        }
        $this->line(json_encode([...$total, 'payouts_submitted' => $swept['submitted'], 'payouts_checked' => $swept['checked']]));

        return $total['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
