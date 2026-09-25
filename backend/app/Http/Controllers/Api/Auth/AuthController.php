<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token-based authentication (Laravel Sanctum personal access tokens) shared by
 * the Customer Web and the Android app. Every response uses the public UUID only.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $identity = $request->resolvedIdentity();

        $user = User::query()->create([
            'name' => trim((string) $request->input('name')),
            $identity['column'] => $identity['value'],
            'password' => (string) $request->input('password'),
            'role' => User::ROLE_CUSTOMER,
        ]);

        return $this->tokenResponse($user, (string) $request->input('device_name', 'web'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $resolved = Identity::resolve((string) $request->input('identity'));
        $user = $resolved === null ? null : User::query()->where($resolved['column'], $resolved['value'])->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'identity' => ['The email/phone or password is incorrect.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, (string) $request->input('device_name', 'web'));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken|null $token */
        $token = $request->user()?->currentAccessToken();
        $token?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function logoutEverywhere(Request $request): JsonResponse
    {
        $request->user()?->tokens()->delete();

        return response()->json(['message' => 'Signed out of all devices.']);
    }

    private function tokenResponse(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken(substr($deviceName, 0, 100))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], $status);
    }
}
