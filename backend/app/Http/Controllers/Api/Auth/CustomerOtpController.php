<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Resources\Auth\PrincipalResource;
use App\Models\Customer;
use App\Services\Auth\CustomerLoginService;
use App\Services\Auth\DevelopmentOtp;
use App\Services\Auth\OtpService;
use App\Services\Auth\TruecallerVerifier;
use App\Services\Market\MarketContext;
use App\Services\Otp\OtpDelivery;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

class CustomerOtpController extends Controller
{
    /**
     * Sends a one-time code. The response is the same for a new and for an existing customer and never
     * contains the code.
     */
    public function request(OtpRequestRequest $request, OtpService $otp, OtpDelivery $delivery, DevelopmentOtp $development): JsonResponse
    {
        $phone = $request->phone();
        $challenge = $otp->request($phone, $request->market(), channel: $request->validated('channel'));

        return response()->json([
            'challenge_id' => $challenge->public_id,
            'phone_masked' => $phone->masked(),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_at' => $challenge->sent_at->addSeconds((int) config('otp.resend_cooldown_seconds'))->toIso8601String(),
            'attempts_allowed' => $challenge->max_attempts,
            'server_time' => now()->toIso8601String(),
            // development = nothing was sent (local / testing); live = a real message went out on `channel`.
            'delivery' => $development->enabled() ? 'development' : 'live',
            'channel' => $challenge->channel,
            'resend_channel' => $delivery->nextChannel($otp->lastSendNumber + 1),
            'channels' => $delivery->channels(),
        ]);
    }

    /**
     * Verifies the code and signs the customer in (creating the account on first use).
     */
    public function verify(OtpVerifyRequest $request, OtpService $otp, CustomerLoginService $login): JsonResponse
    {
        $phone = $request->phone();
        $challenge = $otp->verify((string) $request->validated('challenge_id'), $phone, (string) $request->validated('code'));

        [$customer, $token, $created] = $login->signIn($challenge->verified_at, 'phone_otp', $phone, $request->market(), $request->validated('device_name'));

        return $this->session($customer, $token, $created);
    }

    /**
     * Truecaller one-tap: the app sends the authorization code it got from the Truecaller SDK; the phone
     * number comes from Truecaller's answer, never from the app.
     */
    public function truecaller(Request $request, TruecallerVerifier $truecaller, MarketContext $markets, CustomerLoginService $login): JsonResponse
    {
        $data = $request->validate([
            'authorization_code' => ['required', 'string', 'max:2048'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'device_name' => ['sometimes', 'string', 'max:60'],
        ]);

        $market = $markets->current($data['country'] ?? null);
        $e164 = $truecaller->verifiedPhone($data['authorization_code'], $data['code_verifier']);

        try {
            $phone = PhoneNumber::parse($e164, $market);
        } catch (InvalidArgumentException) {
            throw new ApiException(422, 'truecaller_verification_failed', 'This number cannot be used in this country. Please sign in with a code instead.');
        }

        [$customer, $token, $created] = $login->signIn(now(), 'truecaller', $phone, $market, $data['device_name'] ?? null);

        return $this->session($customer, $token, $created);
    }

    private function session(Customer $customer, NewAccessToken $token, bool $created): JsonResponse
    {
        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'new_account' => $created,
            'principal' => new PrincipalResource($customer),
        ], $created ? 201 : 200);
    }
}
