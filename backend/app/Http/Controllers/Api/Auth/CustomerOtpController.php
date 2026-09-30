<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Resources\Auth\PrincipalResource;
use App\Services\Auth\CustomerLoginService;
use App\Services\Auth\DevelopmentOtp;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;

class CustomerOtpController extends Controller
{
    /**
     * Sends a one-time code. The response is the same for a new and for an existing customer and never
     * contains the code.
     */
    public function request(OtpRequestRequest $request, OtpService $otp, DevelopmentOtp $development): JsonResponse
    {
        $phone = $request->phone();
        $challenge = $otp->request($phone, $request->market());

        return response()->json([
            'challenge_id' => $challenge->public_id,
            'phone_masked' => $phone->masked(),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_at' => $challenge->sent_at->addSeconds((int) config('otp.resend_cooldown_seconds'))->toIso8601String(),
            'attempts_allowed' => $challenge->max_attempts,
            'server_time' => now()->toIso8601String(),
            'delivery' => $development->enabled() ? 'development' : 'sms',
        ]);
    }

    /**
     * Verifies the code and signs the customer in (creating the account on first use).
     */
    public function verify(OtpVerifyRequest $request, OtpService $otp, CustomerLoginService $login): JsonResponse
    {
        $phone = $request->phone();
        $challenge = $otp->verify((string) $request->validated('challenge_id'), $phone, (string) $request->validated('code'));

        [$customer, $token, $created] = $login->signIn($challenge, $phone, $request->market(), $request->validated('device_name'));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'new_account' => $created,
            'principal' => new PrincipalResource($customer),
        ], $created ? 201 : 200);
    }
}
