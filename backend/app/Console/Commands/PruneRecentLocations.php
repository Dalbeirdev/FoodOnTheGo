<?php

namespace App\Console\Commands;

use App\Services\Customer\RecentLocationService;
use Illuminate\Console\Command;

/**
 * Retention of recent locations (Module 25): rows not used within customer.recent_locations_retention_days are
 * removed. Scheduled daily; safe to run any time.
 */
class PruneRecentLocations extends Command
{
    protected $signature = 'customer:prune-recent-locations';

    protected $description = 'Remove customer recent locations older than the configured retention';

    public function handle(RecentLocationService $recent): int
    {
        $removed = $recent->prune();
        $this->info("Removed {$removed} recent location(s) older than ".config('customer.recent_locations_retention_days').' days.');

        return self::SUCCESS;
    }
}
