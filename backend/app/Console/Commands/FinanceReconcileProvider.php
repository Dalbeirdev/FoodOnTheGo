<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\Finance\ProviderSettlement\ProviderSettlementReconciliationService;
use App\Services\Finance\ProviderSettlement\ProviderSettlementSources;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Imports the payment provider's settlement records and reconciles them with the platform's (Module 36).
 *
 * Without --day it imports the last days (finance.provider_reconciliation.lookback_days) ending yesterday — the same
 * days again on the next run, which is safe: a provider record is one row however often it is read. When no provider
 * settlement source can be used here it says so and does nothing.
 */
class FinanceReconcileProvider extends Command
{
    protected $signature = 'finance:reconcile-provider {--day= : One day to import (YYYY-MM-DD, UTC)} {--no-import : Only compare what was already imported}';

    protected $description = 'Import the payment provider\'s settlement records and reconcile them with internal payments and refunds';

    public function handle(ProviderSettlementReconciliationService $reconciliation, ProviderSettlementSources $sources): int
    {
        $source = $sources->current();
        if ($source === null) {
            $this->line(json_encode(['status' => 'NOT_AVAILABLE', 'source' => $sources->report()]));

            return self::SUCCESS;
        }
        if ((bool) $this->option('no-import')) {
            $this->line(json_encode(['status' => 'RECONCILED', ...$reconciliation->reconcile($source->name())]));

            return self::SUCCESS;
        }
        $days = [];
        if (is_string($day = $this->option('day')) && $day !== '') {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');
            if ($parsed === false) {
                $this->error('The day must be YYYY-MM-DD.');

                return self::INVALID;
            }
            $days[] = $parsed;
        } else {
            for ($i = max(1, (int) config('finance.provider_reconciliation.lookback_days')); $i >= 1; $i--) {
                $days[] = CarbonImmutable::now('UTC')->startOfDay()->subDays($i);
            }
        }
        $runs = [];
        foreach ($days as $day) {
            try {
                $run = $reconciliation->import($day);
                $runs[] = ['day' => $day->toDateString(), 'status' => $run->status, 'problems' => $run->problems];
            } catch (ApiException $e) {
                $runs[] = ['day' => $day->toDateString(), 'status' => 'SKIPPED', 'reason' => $e->errorCode ?? 'conflict'];
            }
        }
        $this->line(json_encode(['status' => 'IMPORTED', 'provider' => $source->name(), 'runs' => $runs]));

        return self::SUCCESS;
    }
}
