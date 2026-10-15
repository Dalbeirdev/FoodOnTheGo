<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Foundation\ReliabilityStatus;
use Illuminate\Http\JsonResponse;

/**
 * Operational health for platform administrators (`can:admin.system.view`, Module 40): the database, Redis, the queues,
 * failed jobs and the domain signals, with the alerts their thresholds raise. Counts, ages and flags only — read only.
 */
class ReliabilityController extends Controller
{
    public function index(ReliabilityStatus $status): JsonResponse
    {
        return response()->json($status->report());
    }
}
