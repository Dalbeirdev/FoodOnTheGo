<?php

namespace App\Console\Commands;

use App\Jobs\Foundation\QueueSmokeJob;
use App\Models\Market;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

#[Signature('foundation:verify')]
#[Description('Verify the platform dependencies against the configured environment: PostgreSQL, PostGIS, Redis, cache, queue, market seed')]
class VerifyFoundation extends Command
{
    public function handle(): int
    {
        $this->line('environment: '.config('app.env').' | php '.PHP_VERSION.' | laravel '.app()->version());

        $checks = [
            'postgresql' => fn (): string => (string) DB::selectOne('show server_version')->server_version,
            'database encoding' => fn (): string => (string) DB::selectOne('show server_encoding')->server_encoding,
            'session time zone' => fn (): string => $this->expect((string) DB::selectOne('show timezone')->TimeZone, 'UTC'),
            'postgis' => fn (): string => (string) DB::selectOne('select postgis_lib_version() as v')->v,
            'postgis distance (Noida → Chandigarh, m)' => fn (): string => (string) round((float) DB::selectOne(
                'select ST_Distance(ST_SetSRID(ST_MakePoint(77.3649, 28.6280), 4326)::geography, ST_SetSRID(ST_MakePoint(76.7794, 30.7333), 4326)::geography) as m'
            )->m),
            'redis' => fn (): string => (string) (Redis::connection()->info()['Server']['redis_version'] ?? Redis::connection()->info()['redis_version'] ?? 'connected'),
            'cache ('.config('cache.default').')' => fn (): string => $this->cacheRoundTrip(),
            'queue ('.config('queue.default').')' => fn (): string => $this->queueRoundTrip(),
            'failed jobs store' => fn (): string => config('queue.failed.driver').' / '.DB::table(config('queue.failed.table'))->count().' failed',
            'active markets' => fn (): string => Market::query()->servingCustomers()->pluck('country_code')->implode(', ') ?: throw new \RuntimeException('no active market — run db:seed'),
        ];

        $failed = 0;
        foreach ($checks as $name => $check) {
            try {
                $this->line(sprintf('[PASS] %s: %s', $name, $check()));
            } catch (Throwable $e) {
                $failed++;
                $this->line(sprintf('[FAIL] %s: %s', $name, $e->getMessage()));
            }
        }

        $this->line($failed === 0 ? 'FOUNDATION VERIFY = PASS' : "FOUNDATION VERIFY = FAIL ({$failed})");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function expect(string $actual, string $expected): string
    {
        if ($actual !== $expected) {
            throw new \RuntimeException("expected {$expected}, got {$actual}");
        }

        return $actual;
    }

    private function cacheRoundTrip(): string
    {
        $key = 'foundation:cache-smoke:'.Str::random(8);
        Cache::put($key, 'ok', 30);
        $value = Cache::pull($key);

        return $this->expect((string) $value, 'ok').' (write/read/delete)';
    }

    private function queueRoundTrip(): string
    {
        $token = (string) Str::uuid();
        QueueSmokeJob::dispatch($token)->onQueue('foundation-smoke');
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'foundation-smoke', '--quiet' => true]);

        if (Cache::pull(QueueSmokeJob::markerKey($token)) === null) {
            throw new \RuntimeException('job was dispatched but not processed');
        }

        return 'dispatched → worked → completed';
    }
}
