<?php

namespace App\Services\Auth;

use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\AccessToken;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use SensitiveParameter;

/**
 * E-mail + password sign-in for restaurant users and admin users (never customers).
 *
 * - One generic failure for "unknown e-mail" and "wrong password"; the same work is done in both cases.
 * - A non-active account is reported only to someone who proved the password.
 * - With MFA enabled, the password alone yields a short-lived challenge, not a token.
 * - With MFA mandatory but not yet enrolled, the token can only be used to enrol.
 */
final class StaffLoginService
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly MfaService $mfa,
        private readonly SecurityEventRecorder $events,
    ) {}

    /**
     * @return array{user: AdminUser|RestaurantUser, token: NewAccessToken|null, mfa_challenge: string|null, mfa_enrollment_required: bool}
     */
    public function attempt(PrincipalType $type, string $email, #[SensitiveParameter] string $password, ?string $device): array
    {
        $email = self::normaliseEmail($email);
        /** @var AdminUser|RestaurantUser|null $user */
        $user = $type->model()::query()->where('email', $email)->first();

        $passwordOk = Hash::check($password, $user?->password ?? $this->dummyHash());

        if ($user === null || $user->password === null || ! $passwordOk) {
            $this->events->record(SecurityEventType::LoginFailed, $user, ['method' => 'password', 'context' => $type->guard(), 'reason' => 'invalid_credentials'], $email);

            throw new ApiException(401, 'invalid_credentials', 'The e-mail or password is incorrect.');
        }

        if (! $user->canAuthenticate()) {
            $this->events->record(SecurityEventType::LoginFailed, $user, ['method' => 'password', 'reason' => 'account_'.strtolower($user->status->value)]);

            throw new ApiException(403, 'account_not_active', 'This account cannot sign in. Please contact your administrator.');
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        if ($user->hasMfaEnabled()) {
            return ['user' => $user, 'token' => null, 'mfa_challenge' => $this->startMfaChallenge($user, $device), 'mfa_enrollment_required' => false];
        }

        $enrolOnly = $this->mfa->requiredFor($user);

        return [
            'user' => $user,
            'token' => $this->complete($user, $device, $enrolOnly ? [AccessToken::ENROLL] : [AccessToken::ACCESS], 'password'),
            'mfa_challenge' => null,
            'mfa_enrollment_required' => $enrolOnly,
        ];
    }

    /**
     * Second step of a sign-in with MFA: exchanges the challenge plus a valid second factor for a token.
     *
     * @return array{0: AdminUser|RestaurantUser, 1: NewAccessToken}
     */
    public function completeMfa(PrincipalType $type, #[SensitiveParameter] string $challenge, #[SensitiveParameter] ?string $code, #[SensitiveParameter] ?string $recoveryCode): array
    {
        $key = $this->challengeKey($challenge);
        $state = Cache::get($key);

        if (! is_array($state) || $state['type'] !== $type->value) {
            throw new ApiException(401, 'mfa_challenge_invalid', 'This sign-in attempt has expired. Please sign in again.');
        }

        /** @var AdminUser|RestaurantUser|null $user */
        $user = $type->model()::query()->find($state['id']);

        if ($user === null || ! $user->canAuthenticate() || ! $user->hasMfaEnabled()) {
            Cache::forget($key);

            throw new ApiException(401, 'mfa_challenge_invalid', 'This sign-in attempt has expired. Please sign in again.');
        }

        if (! $this->mfa->verifySecondFactor($user, $code, $recoveryCode)) {
            $state['attempts']++;
            $this->events->record(SecurityEventType::MfaChallengeFailed, $user, ['attempt' => $state['attempts']]);

            if ($state['attempts'] >= (int) config('auth_security.mfa.challenge_max_attempts')) {
                Cache::forget($key);

                throw new ApiException(429, 'mfa_attempts_exceeded', 'Too many incorrect codes. Please sign in again.');
            }

            Cache::put($key, $state, $state['expires_at'] - now()->getTimestamp());

            throw new ApiException(422, 'mfa_code_invalid', 'That code is not correct.');
        }

        Cache::forget($key);

        return [$user, $this->complete($user, $state['device'], [AccessToken::ACCESS], $recoveryCode ? 'password+recovery_code' : 'password+totp')];
    }

    /**
     * A real hash of a random value, checked when the e-mail is unknown so both paths do the same work.
     */
    private function dummyHash(): string
    {
        return Cache::rememberForever('auth:dummy-password-hash', fn (): string => Hash::make(Str::random(40)));
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param  list<string>  $abilities
     */
    private function complete(AdminUser|RestaurantUser $user, ?string $device, array $abilities, string $method): NewAccessToken
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $this->tokens->issue($user, $device, $abilities);

        $this->events->record(SecurityEventType::LoginSuccess, $user, ['method' => $method, 'session' => $token->accessToken->public_id, 'enrol_only' => $abilities === [AccessToken::ENROLL]]);

        return $token;
    }

    /**
     * The challenge is a random value known only to the client; the cache holds its hash, so reading the
     * cache does not reveal a usable challenge.
     */
    private function startMfaChallenge(AdminUser|RestaurantUser $user, ?string $device): string
    {
        $challenge = Str::random(64);
        $ttl = (int) config('auth_security.mfa.challenge_ttl_seconds');

        Cache::put($this->challengeKey($challenge), [
            'type' => $user->principalType()->value,
            'id' => $user->getKey(),
            'device' => $device,
            'attempts' => 0,
            'expires_at' => now()->getTimestamp() + $ttl,
        ], $ttl);

        return $challenge;
    }

    private function challengeKey(#[SensitiveParameter] string $challenge): string
    {
        return 'auth:mfa-challenge:'.hash('sha256', $challenge);
    }
}
