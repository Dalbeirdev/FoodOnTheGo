<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pickup\PickupMetrics;
use Illuminate\Http\JsonResponse;

/**
 * Numbers about pickup availability for platform administrators (`can:admin.system.view`): how often it was asked
 * for, how often nothing could be offered, how often ASAP was possible, how many choices were made, refused or
 * became invalid. Counts only — no cart, no customer, no chosen time, no journey. Changing a restaurant's pickup
 * settings is not possible from here.
 */
class PickupMetricsController extends Controller
{
    public function index(PickupMetrics $metrics): JsonResponse
    {
        return response()->json($metrics->report());
    }
}
