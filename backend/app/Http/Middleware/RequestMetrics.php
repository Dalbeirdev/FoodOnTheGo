<?php

namespace App\Http\Middleware;

use App\Auth\Principal;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 40 — measures every API request: its duration and the number and time of its database queries. The figures
 * go to an optional Server-Timing header (load tests, local diagnosis) and to a structured log line for slow requests
 * and server errors (config/observability.php). Nothing personal is recorded: the route is its pattern
 * (api/v1/customer/orders/{order}), the caller only its kind.
 */
class RequestMetrics
{
    /** @var array{queries: int, db_ms: float}|null the counters of the request being measured */
    private static ?array $current = null;

    /** the event dispatcher the listener is registered on (a new application — a new test — has a new one) */
    private static ?\WeakReference $listeningOn = null;

    public function handle(Request $request, Closure $next): Response
    {
        $timing = (bool) config('observability.server_timing');
        $log = (string) config('observability.request_log');
        if (! $request->is('api/*') || (! $timing && $log === 'off')) {
            return $next($request);
        }

        // One listener per process (a long-lived worker or a test run serves many requests); it counts into the
        // counters of the request being measured, if any.
        if (self::$listeningOn?->get() !== app('events')) {
            DB::listen(function (QueryExecuted $query): void {
                if (self::$current !== null) {
                    self::$current['queries']++;
                    self::$current['db_ms'] += $query->time;
                }
            });
            self::$listeningOn = \WeakReference::create(app('events'));
        }
        $started = hrtime(true);
        self::$current = ['queries' => 0, 'db_ms' => 0.0];
        try {
            $response = $next($request);
        } finally {
            $measured = self::$current;
            self::$current = null;
        }

        $ms = (hrtime(true) - $started) / 1e6;
        if ($timing) {
            $response->headers->set('Server-Timing', sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $ms, $measured['db_ms'], $measured['queries']));
        }
        $status = $response->getStatusCode();
        if ($log === 'all' || ($log === 'slow' && ($ms >= (int) config('observability.slow_ms') || $status >= 500))) {
            // The guards that already know their user (asking an unresolved guard would run its own query).
            $actor = 'GUEST';
            foreach (['customer', 'restaurant', 'admin'] as $guard) {
                if (auth()->guard($guard)->hasUser() && ($user = auth()->guard($guard)->user()) instanceof Principal) {
                    $actor = $user->principalType()->value;
                    break;
                }
            }
            // A server error is logged as an ERROR once, by the exception handler, with its trace; this line is its
            // request summary — a WARNING, so one failure is never two error entries.
            Log::log($status >= 500 ? 'warning' : 'info', 'http.request', [
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? 'unmatched',
                'status' => $status,
                'duration_ms' => round($ms, 1),
                'db_queries' => $measured['queries'],
                'db_ms' => round($measured['db_ms'], 1),
                'actor_type' => $actor,
            ]);
        }

        return $response;
    }
}
