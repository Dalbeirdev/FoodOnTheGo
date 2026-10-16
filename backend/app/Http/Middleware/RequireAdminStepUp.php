<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminStepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 41: `step-up` on a high-risk administrator route — the action runs only after a recent authentication of
 * this session (App\Services\Auth\AdminStepUp); otherwise 403 step_up_required, which the Platform Admin answers by
 * asking for the password (and the MFA code) and repeating the request.
 */
class RequireAdminStepUp
{
    public function __construct(private readonly AdminStepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->stepUp->assert($request);

        return $next($request);
    }
}
