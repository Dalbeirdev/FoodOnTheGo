<?php

namespace App\Http\Middleware;

use App\Enums\PrincipalType;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for a security context, e.g. `principal:ADMIN_USER`. A customer token can never reach a
 * restaurant or admin route, whatever permissions exist.
 */
class EnsurePrincipalType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if ($user->principal_type !== PrincipalType::from($type)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
