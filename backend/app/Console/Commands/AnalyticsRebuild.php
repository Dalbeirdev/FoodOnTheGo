<?php

namespace App\Console\Commands;

use App\Models\Market;
use App\Services\Analytics\Aggregation\AggregationService;
use Illuminate\Console\Command;

/**
 * Builds analytics read models again from the source domains (Module 38) — a range of local days, some metric
 * groups, one market; or, without a range, everything the domains still hold (the backfill).
 *
 * Safe to run any number of times: a day is deleted and written again, so nothing is counted twice. It rebuilds what
 * the DOMAINS know — orders, payments, refunds, the ledger, cases, reviews, notifications. Behavioural events that
 * were never recorded are not invented: before Module 38 there is no funnel and no journey demand.
 */
class AnalyticsRebuild extends Command
{
    protected $signature = 'analytics:rebuild
        {--from= : first local day (YYYY-MM-DD); without it: from the first record the source domains hold}
        {--to= : last local day (YYYY-MM-DD); without it: today}
        {--group=* : metric group(s) to rebuild; without it: all of them}
        {--market= : market slug; without it: every market}
        {--dry-run : calculate and print, write nothing}';

    protected $description = 'Rebuild analytics read models for a range of days from the source domains (idempotent).';

    public function handle(AggregationService $aggregation): int
    {
        $day = fn (?string $value): bool => $value === null || (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)));
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;
        if (! $day($from) || ! $day($to) || ($from !== null && $to !== null && $from > $to)) {
            $this->error('--from and --to are days (YYYY-MM-DD), the first not after the last.');

            return self::FAILURE;
        }
        $known = (array) config('analytics.aggregation.groups');
        $groups = array_values(array_filter((array) $this->option('group')));
        if (array_diff($groups, $known) !== []) {
            $this->error('Unknown group. Groups: '.implode(', ', $known));

            return self::FAILURE;
        }
        $markets = null;
        if ($this->option('market')) {
            $market = Market::query()->where('slug', (string) $this->option('market'))->first();
            if ($market === null) {
                $this->error('This market does not exist.');

                return self::FAILURE;
            }
            $markets = [(int) $market->getKey()];
        }
        $result = $aggregation->run($from === null ? 'BACKFILL' : 'REBUILD', $groups === [] ? null : $groups, $from, $to, $markets, (bool) $this->option('dry-run'));
        $this->line((string) json_encode($result));

        return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
