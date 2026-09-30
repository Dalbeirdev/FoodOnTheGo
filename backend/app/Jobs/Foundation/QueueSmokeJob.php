<?php

namespace App\Jobs\Foundation;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the queue pipeline end to end (dispatch → broker → worker → completion) without touching any
 * business data: the worker writes a marker that the dispatcher can read back.
 */
class QueueSmokeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token) {}

    public static function markerKey(string $token): string
    {
        return 'foundation:queue-smoke:'.$token;
    }

    public function handle(): void
    {
        Cache::put(self::markerKey($this->token), now()->toIso8601String(), 300);
    }
}
