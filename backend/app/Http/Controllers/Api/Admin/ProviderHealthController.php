<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Journey;
use App\Services\Journey\Providers\ProviderManager;
use Illuminate\Http\JsonResponse;

/**
 * Health of the places and routing providers for platform administrators (`can:admin.system.view`): the
 * configured driver and mode (development / live), whether the live provider has what it needs, and the
 * state DERIVED from the platform's own recent calls — never a claim about a provider nobody has called.
 * Not an analytics dashboard: counts and latencies of the last few minutes, plus how many journeys are alive.
 */
class ProviderHealthController extends Controller
{
    public function index(ProviderManager $providers): JsonResponse
    {
        return response()->json([
            'checked_at' => now()->toIso8601String(),
            'fake_allowed' => $providers->fakeAllowed(),
            'providers' => $providers->report(),
            'journeys' => [
                'active' => Journey::query()->where('journeys.expires_at', '>', now())->count(),
                'awaiting_prune' => Journey::query()->expired()->count(),
                'ttl_minutes' => (int) config('journey.ttl_minutes'),
            ],
        ]);
    }
}
