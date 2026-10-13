<?php

namespace App\Console\Commands;

use App\Services\Analytics\Aggregation\AggregationService;
use Illuminate\Console\Command;

/**
 * The recurring aggregation (Module 38): copies what changed since the last run into the analytics read models and
 * writes the changed days again. Reads the source domains; writes only analytics tables.
 */
class AnalyticsAggregate extends Command
{
    protected $signature = 'analytics:aggregate';

    protected $description = 'Bring the analytics read models up to date with what changed since the last run.';

    public function handle(AggregationService $aggregation): int
    {
        $result = $aggregation->run('SCHEDULED');
        $this->line((string) json_encode($result));

        return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
