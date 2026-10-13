<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Analytics\Pseudonymizer;
use App\Services\Analytics\Reports\ReportExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retention of the analytics layer (Module 38): raw behavioural events are short-lived (the aggregates built from
 * them stay), expired export files are deleted, old run and check records are forgotten.
 *
 * With --forget-customer the behavioural trace of ONE customer is detached: their events and sessions lose the
 * pseudonym and become anonymous. Orders, payments and the ledger are other domains' records and are not touched.
 */
class AnalyticsPrune extends Command
{
    protected $signature = 'analytics:prune {--forget-customer= : public id of a customer whose behavioural events are to be made anonymous}';

    protected $description = 'Delete raw analytics events past their retention, expire export files, forget old run records.';

    public function handle(ReportExportService $exports, Pseudonymizer $pseudonyms): int
    {
        $now = CarbonImmutable::now('UTC');
        $result = [];
        if ($this->option('forget-customer')) {
            $id = (string) $this->option('forget-customer');
            $customer = Str::isUuid($id) ? Customer::query()->where('public_id', $id)->first() : null;
            if ($customer === null) {
                $this->error('This customer does not exist.');

                return self::FAILURE;
            }
            $key = $pseudonyms->customer((int) $customer->getKey());
            $result['forgotten'] = [
                'events' => DB::table('analytics_events')->where('customer_key', $key)->update(['customer_key' => null]),
                'sessions' => DB::table('analytics_session_facts')->where('customer_key', $key)->update(['customer_key' => null]),
            ];
        }
        $result['raw_events_deleted'] = DB::table('analytics_events')->where('occurred_at', '<', $now->subDays((int) config('analytics.retention.raw_event_days')))->delete();
        $result['sessions_deleted'] = DB::table('analytics_session_facts')->where('first_event_at', '<', $now->subDays((int) config('analytics.retention.session_days')))->delete();
        $result['runs_deleted'] = DB::table('analytics_runs')->where('started_at', '<', $now->subDays((int) config('analytics.retention.run_days')))->delete();
        $result['exports_expired'] = $exports->expire();
        $this->line((string) json_encode($result));

        return self::SUCCESS;
    }
}
