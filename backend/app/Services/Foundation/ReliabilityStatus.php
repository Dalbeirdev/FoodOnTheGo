<?php

namespace App\Services\Foundation;

use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\RefundStatus;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportSlaStatus;
use App\Models\FinanceRun;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RestaurantPayout;
use App\Models\SupportCase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Module 40 — one view of the platform's operational health for administrators and on-call engineers: the database
 * (connections, lock waits, the longest statement and transaction, deadlocks, cache hit ratio, size), Redis (memory,
 * evictions, clients), every queue (waiting, delayed, reserved, age of the oldest waiting job) and failed jobs, and the
 * domain signals that mean money or a customer is waiting (a verified payment with no order, a refund in flight for
 * too long, a payout needing attention, a failed provider webhook, the latest ledger verification, urgent and breached
 * support cases). Each figure is compared with its [warning, critical] thresholds (config/observability.php) and the
 * alerts it raises are listed.
 *
 * READ ONLY: it changes nothing, repairs nothing and starts nothing — the domain commands do that. It returns counts,
 * ages and flags only: no query text, no host, no credential, no identifier of a customer.
 */
final class ReliabilityStatus
{
    public function __construct(private readonly DependencyChecks $dependencies) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $dependencies = $this->dependencies->run();
        $database = $dependencies['database'] === 'ok' ? $this->guard(fn () => $this->database()) : null;
        $redis = $dependencies['redis'] === 'ok' ? $this->guard(fn () => $this->redis()) : null;
        $queues = $dependencies['redis'] === 'ok' ? $this->guard(fn () => $this->queues()) : null;
        $failedJobs = $dependencies['database'] === 'ok' ? $this->guard(fn () => $this->failedJobs()) : null;
        $domain = $dependencies['database'] === 'ok' ? $this->guard(fn () => $this->domain()) : null;

        $alerts = $this->alerts($dependencies, $database, $redis, $queues, $failedJobs, $domain);
        $severities = array_column($alerts, 'severity');

        return [
            'checked_at' => now()->toIso8601String(),
            'status' => in_array('critical', $severities, true) ? 'critical' : (in_array('warning', $severities, true) ? 'warning' : 'ok'),
            'dependencies' => $dependencies,
            'database' => $database,
            'redis' => $redis,
            'queues' => $queues,
            'failed_jobs' => $failedJobs,
            'domain' => $domain,
            'alerts' => $alerts,
            'thresholds_note' => 'Engineering defaults PENDING BUSINESS APPROVAL — not production SLOs.',
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function database(): array
    {
        $activity = DB::selectOne(<<<'SQL'
            select count(*) as connections,
                   count(*) filter (where state = 'active') as active,
                   count(*) filter (where state like 'idle in transaction%') as idle_in_transaction,
                   count(*) filter (where wait_event_type = 'Lock') as waiting_on_lock,
                   coalesce(extract(epoch from max(now() - query_start) filter (where state = 'active' and pid <> pg_backend_pid())), 0) as longest_query_s,
                   coalesce(extract(epoch from max(now() - xact_start) filter (where pid <> pg_backend_pid())), 0) as longest_transaction_s
            from pg_stat_activity where datname = current_database() and backend_type = 'client backend'
            SQL);
        $stats = DB::selectOne('select deadlocks, blks_hit, blks_read, pg_database_size(datname) as size_bytes from pg_stat_database where datname = current_database()');
        $max = (int) DB::selectOne('show max_connections')->max_connections;
        $reads = (int) $stats->blks_hit + (int) $stats->blks_read;

        return [
            'connections' => (int) $activity->connections,
            'max_connections' => $max,
            'connections_pct' => $max > 0 ? round((int) $activity->connections * 100 / $max, 1) : null,
            'active' => (int) $activity->active,
            'idle_in_transaction' => (int) $activity->idle_in_transaction,
            'waiting_on_lock' => (int) $activity->waiting_on_lock,
            'longest_query_s' => round((float) $activity->longest_query_s, 1),
            'longest_transaction_s' => round((float) $activity->longest_transaction_s, 1),
            'deadlocks' => (int) $stats->deadlocks,
            'cache_hit_pct' => $reads > 0 ? round((int) $stats->blks_hit * 100 / $reads, 2) : null,
            'size_mb' => round((int) $stats->size_bytes / 1048576, 1),
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function redis(): array
    {
        $info = $this->flatten((array) Redis::connection()->command('info'));
        $used = (int) ($info['used_memory'] ?? 0);
        $max = (int) ($info['maxmemory'] ?? 0);

        return [
            'used_memory_mb' => round($used / 1048576, 1),
            'peak_memory_mb' => round((int) ($info['used_memory_peak'] ?? 0) / 1048576, 1),
            'maxmemory_mb' => $max > 0 ? round($max / 1048576, 1) : null,
            'memory_pct' => $max > 0 ? round($used * 100 / $max, 1) : null,
            'maxmemory_policy' => isset($info['maxmemory_policy']) ? (string) $info['maxmemory_policy'] : null,
            'evicted_keys' => (int) ($info['evicted_keys'] ?? 0),
            'connected_clients' => (int) ($info['connected_clients'] ?? 0),
        ];
    }

    /**
     * @return array<string, array{waiting: int, delayed: int, reserved: int, oldest_waiting_s: int|null}>|array{driver: string}
     */
    private function queues(): array
    {
        $queue = Queue::connection();
        if (! $queue instanceof RedisQueue) {
            return ['driver' => (string) config('queue.default')];
        }
        $out = [];
        foreach ((array) config('observability.queues') as $name) {
            $created = $queue->creationTimeOfOldestPendingJob($name);
            $out[$name] = [
                'waiting' => (int) $queue->pendingSize($name),
                'delayed' => (int) $queue->delayedSize($name),
                'reserved' => (int) $queue->reservedSize($name),
                'oldest_waiting_s' => $created ? max(0, now()->getTimestamp() - (int) $created) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{total: int, last_24h: int}
     */
    private function failedJobs(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        return [
            'total' => DB::table($table)->count(),
            'last_24h' => DB::table($table)->where('failed_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function domain(): array
    {
        $grace = now()->subMinutes((int) config('observability.payment_grace_minutes'));
        $stuckSince = now()->subHours((int) config('observability.refund_stuck_hours'));
        $inFlight = array_map(fn (RefundStatus $s): string => $s->value, array_filter(RefundStatus::cases(), fn (RefundStatus $s): bool => $s->reserves() && $s !== RefundStatus::ReviewRequired));
        $ledger = FinanceRun::query()->where('kind', FinanceRun::VERIFY_LEDGER)->whereNotNull('finished_at')->orderByDesc('finished_at')->first();
        $active = SupportCaseStatus::activeValues();

        return [
            'payments_verified_without_order' => PaymentAttempt::query()->where('status', PaymentStatus::Verified->value)
                ->whereIn('fulfillment_status', [PaymentFulfillmentStatus::OrderPending->value, PaymentFulfillmentStatus::RecoveryRequired->value])
                ->where('verified_at', '<', $grace)->count(),
            'payments_recovery_required' => PaymentAttempt::query()->where('fulfillment_status', PaymentFulfillmentStatus::RecoveryRequired->value)->count(),
            'refunds_stuck' => Refund::query()->whereIn('status', $inFlight)->where('updated_at', '<', $stuckSince)->count(),
            'refunds_review_required' => Refund::query()->where('status', RefundStatus::ReviewRequired->value)->count(),
            'payouts_needing_attention' => RestaurantPayout::query()->whereIn('status', [PayoutStatus::Failed->value, PayoutStatus::Unknown->value, PayoutStatus::ReviewRequired->value])->count(),
            'webhooks_failed_24h' => PaymentWebhookEvent::query()->where('processing_status', PaymentWebhookEvent::FAILED)->where('received_at', '>=', now()->subDay())->count(),
            'ledger_verification' => $ledger === null ? null : [
                'status' => (string) $ledger->status,
                'problems' => (int) $ledger->problems,
                'finished_at' => $ledger->finished_at?->toIso8601String(),
                'age_h' => round($ledger->finished_at->diffInMinutes(now()) / 60, 1),
            ],
            'support_urgent_open' => SupportCase::query()->whereIn('status', $active)->where('priority', SupportCasePriority::Urgent->value)->count(),
            'support_sla_breached' => SupportCase::query()->whereIn('status', $active)->where('sla_status', SupportSlaStatus::Breached->value)->count(),
        ];
    }

    /**
     * @return list<array{key: string, severity: string, value: int|float|string, threshold: int|float|null}>
     */
    private function alerts(array $dependencies, ?array $database, ?array $redis, ?array $queues, ?array $failedJobs, ?array $domain): array
    {
        $alerts = [];
        foreach ($dependencies as $name => $state) {
            if ($state !== 'ok') {
                $alerts[] = ['key' => 'dependency.'.$name, 'severity' => 'critical', 'value' => $state, 'threshold' => null];
            }
        }
        foreach (['database' => $database, 'redis' => $redis, 'queues' => $queues, 'failed_jobs' => $failedJobs, 'domain' => $domain] as $section => $values) {
            if (isset($values['error'])) {
                $alerts[] = ['key' => $section.'.unavailable', 'severity' => 'warning', 'value' => 'error', 'threshold' => null];
            }
        }
        $check = function (string $key, int|float|null $value) use (&$alerts): void {
            [$warning, $critical] = config('observability.alerts.'.$key, [null, null]);
            if ($value === null || $warning === null) {
                return;
            }
            if ($value >= $critical) {
                $alerts[] = ['key' => $key, 'severity' => 'critical', 'value' => $value, 'threshold' => $critical];
            } elseif ($value >= $warning) {
                $alerts[] = ['key' => $key, 'severity' => 'warning', 'value' => $value, 'threshold' => $warning];
            }
        };

        if ($database && ! isset($database['error'])) {
            $check('db_connections_pct', $database['connections_pct']);
            $check('db_waiting_on_lock', $database['waiting_on_lock']);
            $check('db_longest_query_s', $database['longest_query_s']);
            $check('db_longest_transaction_s', $database['longest_transaction_s']);
            $check('db_deadlocks', $database['deadlocks']);
        }
        if ($redis && ! isset($redis['error'])) {
            $check('redis_memory_pct', $redis['memory_pct']);
            $check('redis_evicted_keys', $redis['evicted_keys']);
            // Queued jobs and locks live in Redis next to the cache: with a memory limit, only 'noeviction' keeps Redis
            // from silently dropping a waiting payment or order job to make room.
            if ($redis['maxmemory_mb'] !== null && $redis['maxmemory_policy'] !== 'noeviction') {
                $alerts[] = ['key' => 'redis_eviction_policy', 'severity' => 'critical', 'value' => (string) $redis['maxmemory_policy'], 'threshold' => null];
            }
        }
        if ($queues && ! isset($queues['error']) && ! isset($queues['driver'])) {
            $check('queue_depth', max(array_column($queues, 'waiting') ?: [0]));
            $check('queue_oldest_s', max(array_map(fn (array $q): int => (int) $q['oldest_waiting_s'], $queues) ?: [0]));
        }
        if ($failedJobs && ! isset($failedJobs['error'])) {
            $check('failed_jobs_24h', $failedJobs['last_24h']);
        }
        if ($domain && ! isset($domain['error'])) {
            foreach (['payments_verified_without_order', 'refunds_stuck', 'payouts_needing_attention', 'webhooks_failed_24h', 'support_urgent_open', 'support_sla_breached'] as $key) {
                $check($key, $domain[$key]);
            }
            $ledger = $domain['ledger_verification'];
            if ($ledger === null) {
                $alerts[] = ['key' => 'ledger_verification_missing', 'severity' => 'warning', 'value' => 'never', 'threshold' => null];
            } else {
                $check('ledger_problems', $ledger['problems']);
                $check('ledger_verification_age_h', $ledger['age_h']);
            }
        }

        return $alerts;
    }

    /**
     * predis answers INFO by section (['Memory' => ['used_memory' => …]]), phpredis flat — the same keys either way.
     *
     * @return array<string, mixed>
     */
    private function flatten(array $info): array
    {
        $flat = [];
        foreach ($info as $key => $value) {
            is_array($value) ? $flat += $value : $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * A section that cannot be read says so; it never takes the whole view down.
     *
     * @return array<string, mixed>
     */
    private function guard(callable $read): array
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'unavailable'];
        }
    }
}
