<?php

namespace Tests\Feature\Foundation;

use App\Enums\PaymentFulfillmentStatus;
use App\Enums\Permission;
use App\Jobs\Foundation\QueueSmokeJob;
use App\Models\FinanceRun;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\BuildsOrders;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * GET /admin/system/reliability and `php artisan ops:reliability` (Module 40): the database, Redis, the queues, failed
 * jobs and the domain signals, with the alerts their thresholds raise — read only, counts and flags only.
 */
class ReliabilityStatusTest extends TestCase
{
    use BuildsOrders, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    protected function tearDown(): void
    {
        $this->redisOn((int) env('REDIS_PORT', 6379));
        Redis::connection()->flushdb();

        parent::tearDown();
    }

    public function test_only_administrators_with_the_system_permission_see_it(): void
    {
        $this->getJson('/api/v1/admin/system/reliability')->assertUnauthorized();

        $this->actingAsPrincipal($this->adminWith([Permission::AdminOrdersView, Permission::AdminFinanceView]));
        $this->getJson('/api/v1/admin/system/reliability')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $report = $this->getJson('/api/v1/admin/system/reliability')->assertOk();
        $this->assertMatchesSchema('ReliabilityStatus', $report->json());
    }

    public function test_a_healthy_platform_reports_every_section_and_says_what_it_has_never_checked(): void
    {
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $report = $this->getJson('/api/v1/admin/system/reliability')->assertOk();

        $this->assertSame(['database' => 'ok', 'postgis' => 'ok', 'redis' => 'ok'], $report->json('dependencies'));
        $this->assertGreaterThanOrEqual(1, $report->json('database.connections'));
        $this->assertGreaterThan(0, $report->json('database.max_connections'));
        $this->assertSame(0, $report->json('database.waiting_on_lock'));
        $this->assertGreaterThan(0, $report->json('redis.connected_clients'));
        $this->assertSame(['driver' => 'sync'], $report->json('queues'));   // the test queue runs jobs in the request
        $this->assertSame(['total' => 0, 'last_24h' => 0], $report->json('failed_jobs'));
        $this->assertSame(0, $report->json('domain.payments_verified_without_order'));
        $this->assertSame(0, $report->json('domain.refunds_stuck'));
        $this->assertNull($report->json('domain.ledger_verification'));
        // A ledger nobody has verified yet is not called healthy.
        $this->assertContains('ledger_verification_missing', array_column($report->json('alerts'), 'key'));
        $this->assertSame('warning', $report->json('status'));
        // No query text, host, password or identifier of a customer — counts and flags only.
        $body = (string) json_encode($report->json());
        foreach (['select ', '127.0.0.1', (string) config('database.connections.pgsql.password'), '+91'] as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
    }

    public function test_waiting_jobs_and_their_age_are_counted_per_queue_and_raise_alerts(): void
    {
        config(['queue.default' => 'redis', 'observability.alerts.queue_depth' => [1, 2]]);
        foreach (['orders', 'orders', 'notifications'] as $queue) {
            Queue::connection('redis')->push(new QueueSmokeJob((string) Str::uuid()), '', $queue);
        }
        $this->travel(10)->minutes();

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $report = $this->getJson('/api/v1/admin/system/reliability')->assertOk();

        $this->assertSame(2, $report->json('queues.orders.waiting'));
        $this->assertSame(1, $report->json('queues.notifications.waiting'));
        $this->assertSame(0, $report->json('queues.payments.waiting'));
        $this->assertNull($report->json('queues.payments.oldest_waiting_s'));
        $this->assertGreaterThanOrEqual(600, $report->json('queues.orders.oldest_waiting_s'));
        $alerts = collect($report->json('alerts'))->keyBy('key');
        $this->assertSame(['critical', 2, 2], [$alerts['queue_depth']['severity'], $alerts['queue_depth']['value'], $alerts['queue_depth']['threshold']]);
        $this->assertSame('critical', $alerts['queue_oldest_s']['severity']);
        $this->assertSame('critical', $report->json('status'));
    }

    public function test_money_signals_raise_critical_alerts_and_the_command_fails_on_them(): void
    {
        [, , , $payment] = $this->paidOrder();
        // The ledger was verified recently and was clean: no ledger alert.
        $this->ledgerRun(problems: 0, minutesAgo: 30);
        $this->assertSame(0, Artisan::call('ops:reliability', ['--alerts' => true]));
        $clean = json_decode(Artisan::output(), true);
        $this->assertSame([], array_values(array_filter($clean['alerts'], fn (array $a): bool => str_starts_with($a['key'], 'ledger') || str_starts_with($a['key'], 'payments'))));

        // A verified payment whose order was never created, past the grace period; a ledger run that found problems;
        // a job that failed for good.
        PaymentAttempt::query()->where('public_id', $payment['id'])->update(['fulfillment_status' => PaymentFulfillmentStatus::RecoveryRequired->value, 'verified_at' => now()->subMinutes(10)]);
        $this->ledgerRun(problems: 3, minutesAgo: 5);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'orders', 'payload' => '{}', 'exception' => 'test', 'failed_at' => now()]);

        $this->assertSame(1, Artisan::call('ops:reliability', ['--alerts' => true]));
        $report = json_decode(Artisan::output(), true);
        $alerts = collect($report['alerts'])->keyBy('key');
        $this->assertSame('critical', $report['status']);
        $this->assertSame(['critical', 1], [$alerts['payments_verified_without_order']['severity'], $alerts['payments_verified_without_order']['value']]);
        $this->assertSame(['critical', 3], [$alerts['ledger_problems']['severity'], $alerts['ledger_problems']['value']]);
        $this->assertSame(['warning', 1], [$alerts['failed_jobs_24h']['severity'], $alerts['failed_jobs_24h']['value']]);
        // Read only: nothing was repaired.
        $this->assertSame(PaymentFulfillmentStatus::RecoveryRequired, PaymentAttempt::query()->where('public_id', $payment['id'])->firstOrFail()->fulfillment_status);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_redis_that_may_evict_queued_jobs_is_a_critical_alert(): void
    {
        // The shared local Redis is never reconfigured by a test: its INFO answer is stood in for.
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $info = fn (string $policy): array => ['Memory' => ['used_memory' => 104857600, 'used_memory_peak' => 209715200, 'maxmemory' => 4294967296, 'maxmemory_policy' => $policy], 'Stats' => ['evicted_keys' => 0], 'Clients' => ['connected_clients' => 3]];
        $connection = \Mockery::mock();
        $connection->shouldReceive('ping')->andReturn('PONG');
        $connection->shouldReceive('command')->with('info')->andReturn($info('allkeys-lru'), $info('noeviction'));
        Redis::shouldReceive('connection')->andReturn($connection);

        $report = $this->getJson('/api/v1/admin/system/reliability')->assertOk();
        $this->assertEquals([100, 4096, 2.4, 'allkeys-lru'], [$report->json('redis.used_memory_mb'), $report->json('redis.maxmemory_mb'), $report->json('redis.memory_pct'), $report->json('redis.maxmemory_policy')]);
        $alert = collect($report->json('alerts'))->firstWhere('key', 'redis_eviction_policy');
        $this->assertSame(['critical', 'allkeys-lru'], [$alert['severity'], $alert['value']]);

        $safe = $this->getJson('/api/v1/admin/system/reliability')->assertOk();
        $this->assertNull(collect($safe->json('alerts'))->firstWhere('key', 'redis_eviction_policy'));
    }

    public function test_an_unreachable_redis_is_a_critical_alert_and_does_not_take_the_view_down(): void
    {
        $this->redisOn(1);

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $report = $this->getJson('/api/v1/admin/system/reliability')->assertOk();

        $this->assertSame('error', $report->json('dependencies.redis'));
        $this->assertNull($report->json('redis'));
        $this->assertNull($report->json('queues'));
        $this->assertSame('ok', $report->json('dependencies.database'));
        $this->assertIsInt($report->json('database.connections'));
        $this->assertContains('dependency.redis', array_column($report->json('alerts'), 'key'));
        $this->assertSame('critical', $report->json('status'));
    }

    /** The Redis manager keeps the configuration it was built with: build a new one for the new port. */
    private function redisOn(int $port): void
    {
        config(['database.redis.default.port' => $port, 'database.redis.default.timeout' => 1]);
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
        Redis::clearResolvedInstances();
    }

    private function ledgerRun(int $problems, int $minutesAgo): void
    {
        DB::table('finance_runs')->insert([
            'public_id' => (string) Str::uuid(), 'kind' => FinanceRun::VERIFY_LEDGER, 'status' => $problems ? 'PROBLEMS' : 'OK', 'triggered_by' => FinanceRun::BY_COMMAND,
            'dry_run' => false, 'parameters' => '{}', 'result' => '{}', 'problems' => $problems,
            'started_at' => now()->subMinutes($minutesAgo + 1), 'finished_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
