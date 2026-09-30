<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request correlation: accepts a well-formed X-Request-Id from the client (or gateway), otherwise creates
 * one. The id is returned on the response, included in error bodies, attached to every log line and
 * carried into queued jobs through Laravel's Context.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        Context::add('request_id', $requestId);
        Context::add('operation', $request->method().' '.$request->path());

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
