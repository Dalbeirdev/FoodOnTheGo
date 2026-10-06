<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartMetrics;
use Illuminate\Http\JsonResponse;

/**
 * Numbers about carts for platform administrators (`can:admin.system.view`): how many are active, how many are
 * guests', how many wait for the cleanup, and how often conflicts, switches, merges and accepted price changes
 * happened in the last hours. Counts only — nobody reads a customer's cart here, and there is no endpoint that
 * returns one: restaurant staff and administrators have no access to carts.
 */
class CartMetricsController extends Controller
{
    public function index(CartMetrics $metrics): JsonResponse
    {
        return response()->json($metrics->report());
    }
}
