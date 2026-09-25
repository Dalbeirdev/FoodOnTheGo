<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Email-based password reset. Locally the mail driver is "log", so the reset link
 * is written to storage/logs/laravel.log. Phone-only accounts need OTP (later module).
 */
class PasswordResetController extends Controller
{
    private const GENERIC = 'If an account exists for that email, a reset link has been sent.';

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));

        if (User::query()->where('email', $email)->exists()) {
            Password::sendResetLink(['email' => $email]);
        }

        // Always the same answer so the endpoint cannot be used to enumerate accounts.
        return response()->json(['message' => self::GENERIC]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => strtolower(trim((string) $request->input('email'))),
                'token' => (string) $request->input('token'),
                'password' => (string) $request->input('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete(); // sign out every device after a reset
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }

        return response()->json(['message' => 'Your password has been reset. Please sign in again.']);
    }
}
