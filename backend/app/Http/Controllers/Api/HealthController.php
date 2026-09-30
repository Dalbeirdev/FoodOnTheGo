<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Services\Foundation\DependencyChecks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    /**
     * Public health: availability only. No environment, hosts or dependency names.
     */
    public function health(DependencyChecks $checks): JsonResponse
    {
        $healthy = $checks->healthy();

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => config('app.name'),
            'version' => config('app.version'),
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Readiness diagnostics per dependency. Open only where config api.readiness.public allows it
     * (local / testing); everywhere else it needs an admin holding admin.system.view.
     */
    public function ready(Request $request, DependencyChecks $checks): JsonResponse
    {
        if (! config('api.readiness.public')) {
            $admin = $request->user('admin') ?? throw new AuthenticationException;

            if (! $admin->canAuthenticate() || ! $admin->tokenCan(AccessToken::ACCESS) || $admin->cannot(Permission::AdminSystemView->value)) {
                throw new AuthorizationException;
            }
        }

        $results = $checks->run();
        $healthy = ! in_array('error', $results, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'environment' => config('app.env'),
            'checks' => ['application' => 'ok'] + $results,
            'version' => config('app.version'),
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
