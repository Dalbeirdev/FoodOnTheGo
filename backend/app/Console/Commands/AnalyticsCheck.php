<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\DataQualityService;
use Illuminate\Console\Command;

/**
 * Runs the integrity checks of the analytics layer (Module 38) and stores what they found. The checks compare and
 * report; they change no order, payment, refund or ledger line.
 */
class AnalyticsCheck extends Command
{
    protected $signature = 'analytics:check';

    protected $description = 'Run the analytics data-quality checks (HEALTHY / WARNING / ERROR).';

    public function handle(DataQualityService $quality, AnalyticsMetrics $metrics): int
    {
        $result = $quality->run();
        $metrics->record('quality_run');
        $this->line((string) json_encode(['status' => $result['status'], 'counts' => $result['counts'], 'problems' => array_values(array_map(fn (array $c): string => $c['check'].'='.$c['status'], array_filter($result['checks'], fn (array $c): bool => $c['status'] !== 'HEALTHY')))]));

        return $result['status'] === 'ERROR' ? self::FAILURE : self::SUCCESS;
    }
}
