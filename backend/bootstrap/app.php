<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\SetApiLocale;
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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so error responses produced before the route middleware runs carry them too.
        $middleware->prepend([AssignRequestId::class, ApiSecurityHeaders::class, SetApiLocale::class]);
        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'idempotent' => EnforceIdempotency::class,
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
