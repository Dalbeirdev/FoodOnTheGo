<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Safe readiness probe for local Web/Android bootstrap checks.
 * Reports only status flags — never hosts, credentials or connection strings.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->probe(fn () => DB::connection()->getPdo());
        $redis = $this->probe(fn () => Redis::connection()->ping());

        $healthy = $database === 'ok' && $redis === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'database' => $database,
            'redis' => $redis,
            'version' => config('app.version'),
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Run a connectivity check and collapse any failure to a plain "error" flag.
     */
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
