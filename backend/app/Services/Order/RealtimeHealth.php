<?php

namespace App\Services\Order;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the realtime layer (Laravel Reverb) can be reached right now (Module 32). Asked by the admin System ›
 * Realtime screen; the answer is cached for a few seconds so that a page of administrators does not become a probe.
 * OPERATIONAL = the broadcaster answered its channel listing; DEGRADED = it answered with an error; UNAVAILABLE = no
 * connection or realtime switched off. None of it changes what orders do: REST and polling do not depend on it.
 */
final class RealtimeHealth
{
    public const OPERATIONAL = 'OPERATIONAL';

    public const DEGRADED = 'DEGRADED';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const OFF = 'OFF';

    /**
     * @return array{status: string, driver: string, checked_at: string, latency_ms: int|null, detail: string|null}
     */
    public function check(bool $fresh = false): array
    {
        $key = 'orders:realtime:health';
        if (! $fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $driver = (string) config('broadcasting.default');
        $result = ['status' => self::UNAVAILABLE, 'driver' => $driver, 'checked_at' => now()->toIso8601String(), 'latency_ms' => null, 'detail' => null];
        if (! (bool) config('orders.realtime.enabled')) {
            $result['status'] = self::OFF;
            $result['detail'] = 'ORDER_REALTIME_ENABLED is false: clients poll.';
        } elseif (! in_array($driver, ['reverb', 'pusher'], true)) {
            $result['status'] = self::OFF;
            $result['detail'] = 'No WebSocket broadcaster is configured (BROADCAST_CONNECTION='.$driver.'): clients poll.';
        } else {
            $started = hrtime(true);
            try {
                $broadcaster = Broadcast::driver($driver);
                $pusher = method_exists($broadcaster, 'getPusher') ? $broadcaster->getPusher() : null;
                if ($pusher === null) {
                    $result['status'] = self::DEGRADED;
                    $result['detail'] = 'The broadcaster exposes no server API.';
                } else {
                    $answer = $pusher->get('/channels');
                    $result['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
                    $result['status'] = is_object($answer) || is_array($answer) ? self::OPERATIONAL : self::DEGRADED;
                }
            } catch (\Throwable $e) {
                $result['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
                $result['status'] = self::UNAVAILABLE;
                $result['detail'] = 'The realtime server did not answer ('.class_basename($e).').';
            }
        }
        Cache::put($key, $result, 10);

        return $result;
    }
}
