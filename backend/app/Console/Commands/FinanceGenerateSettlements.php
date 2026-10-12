<?php

namespace App\Console\Commands;

use App\Models\FinanceRun;
use App\Models\RestaurantLocation;
use App\Services\Finance\SettlementCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Generates restaurant settlements up to a cutoff (Module 36). A settlement is a statement of what is owed: this
 * command sends no money and makes no payout. With --dry-run nothing at all is written — the calculation is printed.
 *
 * Running it twice settles nothing twice: a payable line belongs to one settlement.
 */
class FinanceGenerateSettlements extends Command
{
    protected $signature = 'finance:generate-settlements {--until= : The cutoff as a day (YYYY-MM-DD): lines that became available before that day\'s start in the market\'s time zone. Default: the end of the last finished period}
        {--location= : Only this location (its public id)} {--dry-run : Calculate and print; write nothing}';

    protected $description = 'Calculate restaurant settlements for the payable lines that are due (no money is sent)';

    public function handle(SettlementCalculationService $settlements): int
    {
        $locations = RestaurantLocation::query()->whereIn('id', fn ($q) => $q->select('location_id')->from('restaurant_payable_items')->where('status', 'AVAILABLE'))
            ->when(is_string($this->option('location')) && $this->option('location') !== '', fn ($q) => $q->where('public_id', $this->option('location')))->orderBy('id')->get();
        $until = null;
        if (is_string($day = $this->option('until')) && $day !== '') {
            $zone = $locations->isEmpty() ? 'UTC' : $settlements->timezoneOf($locations->first());
            $until = CarbonImmutable::createFromFormat('!Y-m-d', $day, $zone);
            if ($until === false || $until->greaterThan(now())) {
                $this->error('--until must be a day (YYYY-MM-DD) that has started.');

                return self::INVALID;
            }
        }
        $outcome = $settlements->generate($locations, $until, (bool) $this->option('dry-run'), null, FinanceRun::BY_COMMAND);
        $this->line(json_encode(['dry_run' => (bool) $this->option('dry-run'), 'summary' => $outcome['summary'], 'results' => $outcome['results']]));

        return self::SUCCESS;
    }
}
