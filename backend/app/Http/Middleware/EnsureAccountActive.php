<?php

namespace App\Http\Middleware;

use App\Auth\Principal;
use App\Exceptions\ApiException;
use App\Models\AccessToken;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after authentication on every protected route (`active`, or `active:enroll`).
 *
 * - The account state is read on every request: a suspended / disabled / deactivated account is refused
 *   even if one of its tokens still exists.
 * - A token issued only for MFA enrolment cannot use the platform; `active:enroll` marks the few routes
 *   such a token may call.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next, string $mode = 'access'): Response
    {
        $principal = $request->user();

        if (! $principal instanceof Principal) {
            throw new AuthenticationException;
        }

        if (! $principal->canAuthenticate()) {
            throw new ApiException(403, 'account_not_active', 'This account cannot be used right now.');
        }

        if (! $principal->tokenCan(AccessToken::ACCESS) && ! ($mode === 'enroll' && $principal->tokenCan(AccessToken::ENROLL))) {
            throw new ApiException(403, 'mfa_enrollment_required', 'Set up multi-factor authentication to continue.');
        }

        Context::add('actor', $principal->principalType()->morphAlias().':'.$principal->public_id);

        return $next($request);
    }
}
