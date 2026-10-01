<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\Auth\PrincipalResource;
use App\Models\AccessToken;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Services\Auth\MfaService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TokenIssuer;
use App\Support\NoticeLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use SensitiveParameter;

/**
 * Credential management of the signed-in restaurant or admin user: password change, MFA and e-mail language.
 * Sensitive changes re-check the current password (re-authentication).
 */
class StaffSecurityController extends Controller
{
    public function changePassword(ChangePasswordRequest $request, PasswordResetService $passwords): JsonResponse
    {
        $user = $this->staff($request);
        $this->confirmPassword($user, (string) $request->validated('current_password'));

        $passwords->change($user, (string) $request->validated('password'), $user->currentAccessToken()->getKey());

        return response()->json(['message' => __('Password changed. Other devices have been signed out.')]);
    }

    /**
     * The language of the e-mails this person receives; null = no preference (every available language).
     */
    public function updateLanguage(Request $request): PrincipalResource
    {
        $input = $request->validate(['locale' => ['present', 'nullable', 'string', Rule::in(NoticeLocales::available())]]);
        $user = $this->staff($request);
        $user->forceFill(['preferred_locale' => $input['locale']])->save();

        return new PrincipalResource($user);
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
