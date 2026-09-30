<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Connectivity probes for the platform dependencies. Results are plain flags ("ok" / "error"):
 * hosts, credentials and error text never leave this class.
 */
final class DependencyChecks
{
    /**
     * @return array{database: string, postgis: string, redis: string}
     */
    public function run(): array
    {
        return [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'postgis' => $this->probe(fn () => DB::select('select postgis_lib_version()')),
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
        ];
    }

    public function healthy(): bool
    {
        return ! in_array('error', $this->run(), true);
    }

    private function probe(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }
}
