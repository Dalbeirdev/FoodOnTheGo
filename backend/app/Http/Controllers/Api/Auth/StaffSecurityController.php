<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\Auth\PrincipalResource;
use App\Models\AccessToken;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Services\Auth\AdminStepUp;
use App\Services\Auth\MfaService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Credential management of the signed-in restaurant or admin user: password change and MFA.
 * Sensitive changes re-check the current password (re-authentication).
 */
class StaffSecurityController extends Controller
{
    public function changePassword(ChangePasswordRequest $request, PasswordResetService $passwords): JsonResponse
    {
        $user = $this->staff($request);
        $this->confirmPassword($user, (string) $request->validated('current_password'));

        $passwords->change($user, (string) $request->validated('password'), $user->currentAccessToken()->getKey());

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }

    public function setupMfa(Request $request, MfaService $mfa): JsonResponse
    {
        return response()->json($mfa->beginSetup($this->staff($request)));
    }

    /**
     * Completes enrolment. A token that could only enrol is replaced by a full one.
     */
    public function confirmMfa(Request $request, MfaService $mfa, TokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $this->staff($request);
        $recoveryCodes = $mfa->confirmSetup($user, $data['code']);

        $response = ['recovery_codes' => $recoveryCodes, 'principal' => new PrincipalResource($user)];

        $current = $user->currentAccessToken();
        if (! $current->can(AccessToken::ACCESS)) {
            $user->tokens()->whereKey($current->getKey())->delete();
            $token = $tokens->issue($user, $current->name);
            $response += ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $token->accessToken->expires_at?->toIso8601String()];
        }

        return response()->json($response);
    }

    public function disableMfa(Request $request, MfaService $mfa): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $user = $this->staff($request);
        $this->confirmPassword($user, $data['password']);

        $mfa->disable($user, $data['code']);

        return response()->json(null, 204);
    }

    /**
     * Module 41: step-up of an administrator's session before a high-risk action (password, and the MFA code or a
     * recovery code when MFA is on). Answers until when the session counts as recently authenticated.
     */
    public function stepUp(Request $request, AdminStepUp $stepUp): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);
        $until = $stepUp->confirm($request, $data['password'], $data['code'] ?? null, $data['recovery_code'] ?? null);

        return response()->json(['data' => ['step_up_until' => $until->toIso8601String()]]);
    }

    private function staff(Request $request): AdminUser|RestaurantUser
    {
        return $request->user();
    }

    private function confirmPassword(AdminUser|RestaurantUser $user, #[SensitiveParameter] string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw new ApiException(422, 'validation_failed', 'The submitted data is invalid.', ['fields' => ['current_password' => ['The current password is not correct.']]]);
        }
    }
}
