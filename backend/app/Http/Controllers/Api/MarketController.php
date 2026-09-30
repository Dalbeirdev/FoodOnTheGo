<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Market\CurrentMarketRequest;
use App\Http\Resources\MarketResource;
use App\Services\Auth\TruecallerVerifier;
use App\Services\Market\MarketContext;
use App\Services\Market\MarketCoverage;
use App\Services\Otp\OtpDelivery;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    /**
     * The market serving this client: the configured default, or the one for ?country= when it serves customers.
     */
    public function current(CurrentMarketRequest $request, MarketContext $markets): JsonResponse
    {
        return response()->json($markets->publicPayload($request->country()));
    }

    /**
     * Markets open to customers. Draft, paused and closed markets are never listed.
     */
    public function index(MarketContext $markets): JsonResponse
    {
        return response()->json(['data' => $markets->publicMarkets()]);
    }

    /**
     * Where the market operates: regions, cities, live service areas and corridors — display data for maps
     * and city pickers. Whether a location is serviceable is answered by POST /availability/location.
     */
    public function coverage(CurrentMarketRequest $request, MarketContext $markets, MarketCoverage $coverage): JsonResponse
    {
        return response()->json($coverage->for($markets->current($request->country())));
    }

    /**
     * Client bootstrap: everything a client may need before its first screen, and nothing internal.
     */
    public function config(CurrentMarketRequest $request, MarketContext $markets, OtpDelivery $delivery, TruecallerVerifier $truecaller): JsonResponse
    {
        $market = $markets->current($request->country());

        return response()->json([
            'api' => ['version' => 'v1'],
            'app' => ['name' => config('app.name'), 'version' => config('app.version')],
            'market' => new MarketResource($market),
            // How a customer can prove their phone number here: shown as options by the clients, decided by the backend.
            'auth' => [
                'otp_channels' => $delivery->channels(),
                'truecaller' => $truecaller->enabled(),
            ],
        ]);
    }
}
