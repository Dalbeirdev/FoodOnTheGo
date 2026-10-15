<?php

namespace Tests\Feature\Foundation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * Module 40 — request observability: the liveness probe, the optional Server-Timing header, the structured log line of
 * a slow request or a server error (route pattern, status, duration, query count, kind of caller — nothing personal),
 * and the request id reaching the queued jobs a request starts.
 */
class RequestObservabilityTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public static ?string $seenRequestId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();

        parent::tearDown();
    }

    public function test_liveness_answers_without_touching_a_dependency(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/v1/live')->assertOk()->assertJsonPath('status', 'ok')->assertJsonStructure(['status', 'time']);
        $this->assertSame(0, $queries);
    }

    public function test_server_timing_is_off_by_default_and_counts_the_queries_when_on(): void
    {
        $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk()->assertHeaderMissing('Server-Timing');

        config(['observability.server_timing' => true]);
        $header = (string) $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk()->headers->get('Server-Timing');
        $this->assertMatchesRegularExpression('/^app;dur=[0-9.]+, db;dur=[0-9.]+;desc="([0-9]+) queries"$/', $header);
        preg_match('/desc="([0-9]+) queries"/', $header, $m);
        $this->assertGreaterThan(0, (int) $m[1]);
        // A second request counts its own queries, not the first one's as well.
        $again = (string) $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->headers->get('Server-Timing');
        preg_match('/desc="([0-9]+) queries"/', $again, $n);
        $this->assertLessThanOrEqual((int) $m[1], (int) $n[1]);
    }

    public function test_a_slow_request_is_logged_by_its_route_pattern_without_anything_personal(): void
    {
        config(['observability.request_log' => 'slow', 'observability.slow_ms' => 0]);
        [, $customer, , , $order] = $this->paidOrder();
        Log::spy();

        $this->getJson('/api/v1/customer/orders/'.$order['order_number'], $this->using($customer))->assertOk();

        Log::shouldHaveReceived('log')->withArgs(function (string $level, string $message, array $context) use ($order): bool {
            if ($message !== 'http.request' || $context['route'] !== 'api/v1/customer/orders/{order}') {
                return false;
            }
            $this->assertSame('info', $level);
            $this->assertSame(['method', 'route', 'status', 'duration_ms', 'db_queries', 'db_ms', 'actor_type'], array_keys($context));
            $this->assertSame(['GET', 200, 'CUSTOMER'], [$context['method'], $context['status'], $context['actor_type']]);
            $this->assertGreaterThan(0, $context['db_queries']);
            $this->assertStringNotContainsString($order['order_number'], (string) json_encode($context));

            return true;
        })->once();
    }

    public function test_quick_requests_are_not_logged_and_logging_can_be_switched_off(): void
    {
        config(['observability.request_log' => 'slow', 'observability.slow_ms' => 60000]);
        Log::spy();
        $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk();
        Log::shouldNotHaveReceived('log', fn (string $level, string $message): bool => $message === 'http.request');

        config(['observability.request_log' => 'off', 'observability.slow_ms' => 0]);
        $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk();
        Log::shouldNotHaveReceived('log', fn (string $level, string $message): bool => $message === 'http.request');
    }

    public function test_the_request_id_reaches_the_jobs_the_request_queues(): void
    {
        self::$seenRequestId = null;
        Route::middleware('api')->get('/api/v1/__m40-dispatch', function () {
            ObservedJob::dispatch()->onConnection('redis')->onQueue('m40-observed');

            return response()->json(['ok' => true]);
        });

        $this->getJson('/api/v1/__m40-dispatch', ['X-Request-Id' => 'trace-m40-0001'])->assertOk()->assertHeader('X-Request-Id', 'trace-m40-0001');
        $this->assertNull(self::$seenRequestId);   // queued, not run

        // A worker runs it later, outside the request: the id comes from the job's payload, not from this process.
        Context::flush();
        Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => 'm40-observed']);

        $this->assertSame('trace-m40-0001', self::$seenRequestId);
    }
}

/** A queued job that records the request id it sees in the Context the queue restored for it. */
class ObservedJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        RequestObservabilityTest::$seenRequestId = Context::get('request_id');
    }
}
