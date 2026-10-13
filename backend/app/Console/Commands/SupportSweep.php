<?php

namespace App\Console\Commands;

use App\Services\Support\SupportSweepService;
use Illuminate\Console\Command;

/**
 * What the clock does to support cases (Module 37): opens the system cases a listener missed, brings SLA states up to
 * date (at risk / breached — from real timestamps), and, only when the business has set the days, resolves cases
 * nobody answered and closes cases that stayed resolved. Idempotent. It changes no order, payment, refund or money.
 */
class SupportSweep extends Command
{
    protected $signature = 'support:sweep';

    protected $description = 'Open missed system cases, update SLA states and apply the configured auto-close rules';

    public function handle(SupportSweepService $sweep): int
    {
        $this->line(json_encode($sweep->sweep()));

        return self::SUCCESS;
    }
}
