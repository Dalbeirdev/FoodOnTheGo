<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\OptionalCustomer;
use App\Http\Middleware\RequestMetrics;
use App\Http\Middleware\RequireAdminStepUp;
use App\Http\Middleware\ResolveCartOwner;
use App\Http\Middleware\ResolveJourney;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Stack traces must never carry call arguments: they would put one-time codes, passwords and tokens
// into the log whenever an exception passes through an authentication method.
ini_set('zend.exception_ignore_args', '1');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Module 39: routes/channels.php is loaded by AppServiceProvider WITHOUT Laravel's default /broadcasting/auth web
        // route — that route skipped the active-account check and the channel-auth limit and accepted GET. Channels are
        // authorized only through /api/v1/customer/broadcasting/auth and /api/v1/restaurant/broadcasting/auth.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so error responses produced before the route middleware runs carry them too.
        $middleware->prepend([AssignRequestId::class, ApiSecurityHeaders::class, RequestMetrics::class]);
        // Module 39: one size limit for every API body that is not a file upload (413 before decoding).
        $middleware->append(LimitRequestBody::class);
        // Module 39: only the proxies named in TRUSTED_PROXIES may set X-Forwarded-* (none by default, so a client cannot
        // choose its own IP address for the rate limiters); outside local / testing only the hosts of APP_URL and
        // TRUSTED_HOSTS are answered (links built from the request, such as media URLs, cannot be pointed elsewhere).
        // (the proxy list itself is applied in AppServiceProvider::boot, once the configuration is loaded)
        $middleware->trustHosts(at: fn (): array => config('security.trusted_hosts'), subdomains: false);
        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'idempotent' => EnforceIdempotency::class,
            'customer.optional' => OptionalCustomer::class,
            'journey' => ResolveJourney::class,
            'cart.owner' => ResolveCartOwner::class,
            // Module 41: recent re-authentication before a high-risk administrator action.
            'step-up' => RequireAdminStepUp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected client errors (wrong code, invalid credentials, forbidden…) are answers, not incidents:
        // they are recorded as security events where relevant, not written to the error log.
        $exceptions->dontReport(ApiException::class);
        $exceptions->report(function (ApiException $e): void {
            if ($e->status >= 500) {
                Log::error('api.failure', ['code' => $e->errorCode, 'status' => $e->status]);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? app(ApiExceptionRenderer::class)->render($e, $request) : null;
        });
    })->create();
