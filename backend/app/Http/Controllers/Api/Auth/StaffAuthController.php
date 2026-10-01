<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\PrincipalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MfaVerifyRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Resources\Auth\PrincipalResource;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\StaffLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Password sign-in, MFA completion and password reset for one staff context. The context is fixed by the
 * route (`/auth/restaurant/...` or `/auth/admin/...`), never by a field in the request.
 */
class StaffAuthController extends Controller
{
    public function login(StaffLoginRequest $request, StaffLoginService $service): JsonResponse
    {
        $result = $service->attempt($this->context($request), (string) $request->validated('email'), (string) $request->validated('password'), $request->validated('device_name'));

        if ($result['mfa_challenge'] !== null) {
            return response()->json(['mfa_required' => true, 'mfa_challenge' => $result['mfa_challenge'], 'methods' => ['totp', 'recovery_code']]);
        }

        return $this->tokenResponse($result['user'], $result['token'], $result['mfa_enrollment_required']);
    }

    public function verifyMfa(MfaVerifyRequest $request, StaffLoginService $service): JsonResponse
    {
        [$user, $token] = $service->completeMfa($this->context($request), (string) $request->validated('mfa_challenge'), $request->validated('code'), $request->validated('recovery_code'));

        return $this->tokenResponse($user, $token, false);
    }

    /**
     * Always answers the same way, whether or not the e-mail belongs to an account.
     */
    public function forgotPassword(Request $request, PasswordResetService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $service->request($this->context($request), $data['email']);

        return response()->json(['message' => __('If an account exists for this e-mail, a reset link has been sent.')]);
    }

    public function resetPassword(ResetPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->reset($this->context($request), (string) $request->validated('token'), (string) $request->validated('password'));

        return response()->json(['message' => __('Your password has been changed. Please sign in again.')]);
    }

    private function context(Request $request): PrincipalType
    {
        return PrincipalType::from((string) $request->route()->defaults['principal']);
    }

    private function tokenResponse(AdminUser|RestaurantUser $user, NewAccessToken $token, bool $enrolOnly): JsonResponse
    {
        return response()->json([
            'mfa_required' => false,
            'mfa_enrollment_required' => $enrolOnly,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'principal' => new PrincipalResource($user),
        ]);
    }
}
