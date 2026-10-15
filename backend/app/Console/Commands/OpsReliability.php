<?php

namespace App\Console\Commands;

use App\Services\Foundation\ReliabilityStatus;
use Illuminate\Console\Command;

/**
 * The reliability view (Module 40) on the command line, for an on-call engineer or a monitoring probe: the database,
 * Redis, the queues, failed jobs and the domain signals, with the alerts their thresholds raise. Read only — it
 * repairs nothing. Exits 1 when an alert is critical (with --strict, also on a warning).
 */
class OpsReliability extends Command
{
    protected $signature = 'ops:reliability {--strict : exit 1 on a warning as well} {--alerts : print only the status and the alerts}';

    protected $description = 'Report database, Redis, queue and domain health with the alerts raised by their thresholds (read only).';

    public function handle(ReliabilityStatus $status): int
    {
        $report = $status->report();
        $this->line((string) json_encode($this->option('alerts') ? ['status' => $report['status'], 'alerts' => $report['alerts']] : $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['status'] === 'critical' || ($this->option('strict') && $report['status'] === 'warning') ? self::FAILURE : self::SUCCESS;
    }
}
