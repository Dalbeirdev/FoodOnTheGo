<?php

namespace App\Console\Commands;

use App\Services\Journey\JourneyService;
use Illuminate\Console\Command;

/**
 * Journey contexts are ephemeral (Module 26): rows expired longer ago than journey.prune_grace_minutes are
 * removed. Scheduled hourly; safe to run any time. Their cached discovery results expire on their own.
 */
class PruneJourneys extends Command
{
    protected $signature = 'journeys:prune';

    protected $description = 'Remove expired journey contexts (no permanent journey history is kept)';

    public function handle(JourneyService $journeys): int
    {
        $removed = $journeys->prune();
        $this->info("Removed {$removed} expired journey context(s).");

        return self::SUCCESS;
    }
}
