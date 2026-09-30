<?php

namespace Tests\Feature\Foundation;

use App\Jobs\Foundation\QueueSmokeJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Talks to the real local Redis (logical databases 14 / 15 reserved for tests) — no mocks.
 */
class RedisAndQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();

        parent::tearDown();
    }

    public function test_redis_answers_and_tests_use_their_own_logical_databases(): void
    {
        $this->assertEquals('PONG', (string) Redis::connection()->ping());
        $this->assertSame(14, (int) config('database.redis.default.database'));
        $this->assertSame(15, (int) config('database.redis.cache.database'));
    }

    public function test_redis_cache_store_writes_reads_expires_and_deletes(): void
    {
        $cache = Cache::store('redis');
        $key = 'foundation:test:'.Str::random(8);

        $this->assertTrue($cache->put($key, ['market' => 'IN'], 60));
        $this->assertSame(['market' => 'IN'], $cache->get($key));
        $this->assertTrue($cache->forget($key));
        $this->assertNull($cache->get($key));
    }

    public function test_redis_lock_is_exclusive_until_released(): void
    {
        $first = Cache::store('redis')->lock('foundation:lock:pickup-slot', 10);
        $second = Cache::store('redis')->lock('foundation:lock:pickup-slot', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());

        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }

    public function test_job_is_dispatched_to_redis_and_completed_by_a_worker(): void
    {
        config(['cache.default' => 'redis']);
        $token = (string) Str::uuid();

        Queue::connection('redis')->push(new QueueSmokeJob($token), '', 'foundation-smoke');
        $this->assertSame(1, Queue::connection('redis')->size('foundation-smoke'));
        $this->assertNull(Cache::get(QueueSmokeJob::markerKey($token)));

        Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => 'foundation-smoke']);

        $this->assertNotNull(Cache::get(QueueSmokeJob::markerKey($token)));
        $this->assertSame(0, Queue::connection('redis')->size('foundation-smoke'));
    }

    public function test_a_failing_job_is_recorded_in_failed_jobs_instead_of_being_lost(): void
    {
        Queue::connection('redis')->push(new FailingSmokeJob, '', 'foundation-smoke');

        Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => 'foundation-smoke', '--tries' => 1]);

        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertSame('redis', $failed[0]->connection);
        $this->assertStringContainsString('foundation smoke failure', $failed[0]->exception);
    }
}

class FailingSmokeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('foundation smoke failure');
    }
}
