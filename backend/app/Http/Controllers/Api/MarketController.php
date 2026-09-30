<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Market\CurrentMarketRequest;
use App\Http\Resources\MarketResource;
use App\Services\Auth\TruecallerVerifier;
use App\Services\Market\MarketContext;
use App\Services\Otp\OtpDelivery;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    /**
     * The market serving this client: the configured default, or the one for ?country= when it serves customers.
     */
    public function current(CurrentMarketRequest $request, MarketContext $markets): MarketResource
    {
        return new MarketResource($markets->current($request->country()));
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
