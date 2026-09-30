<?php

namespace App\Services\Auth;

use App\Auth\Principal;
use App\Enums\SecurityEventType;
use App\Events\MfaChanged;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * TOTP multi-factor authentication for restaurant and admin users.
 *
 * - The secret is encrypted at rest and shown exactly once, during setup.
 * - A code is accepted once: the time step of the last accepted code is stored.
 * - Recovery codes are single use and stored hashed.
 * - Turning MFA off needs the password and a valid code; there is no "disable by e-mail" path.
 */
final class MfaService
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function requiredFor(Principal $principal): bool
    {
        return (bool) config('auth_security.mfa.required.'.$principal->principalType()->guard(), false);
    }

    /**
     * Starts enrolment: stores a new pending secret and returns it for the authenticator app.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function beginSetup(AdminUser|RestaurantUser $user): array
    {
        if ($user->hasMfaEnabled()) {
            throw ApiException::conflict('mfa_already_enabled', 'Multi-factor authentication is already enabled.');
        }

        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => null, 'mfa_last_used_step' => null])->save();

        return ['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($secret, $user->email, (string) config('app.name'))];
    }

    /**
     * Confirms enrolment with a first valid code and returns the recovery codes (shown once).
     *
     * @return list<string>
     */
    public function confirmSetup(AdminUser|RestaurantUser $user, #[SensitiveParameter] string $code): array
    {
        if ($user->hasMfaEnabled() || $user->mfa_secret === null) {
            throw ApiException::conflict('mfa_setup_not_started', 'Start the multi-factor setup first.');
        }

        if (! $this->consumeTotp($user, $code)) {
            throw new ApiException(422, 'mfa_code_invalid', 'That code is not correct.');
        }

        $codes = [];
        for ($i = 0; $i < (int) config('auth_security.mfa.recovery_codes'); $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }

        $user->forceFill([
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => array_map(fn (string $c): string => hash('sha256', $c), $codes),
        ])->save();

        $this->events->record(SecurityEventType::MfaEnabled, $user, ['method' => 'totp']);
        MfaChanged::dispatch($user, true, $user);

        return $codes;
    }

    /**
     * Checks a second factor during sign-in: a TOTP code or an unused recovery code.
     */
    public function verifySecondFactor(AdminUser|RestaurantUser $user, #[SensitiveParameter] ?string $code, #[SensitiveParameter] ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->consumeTotp($user, $code);
        }

        return $recoveryCode !== null && $recoveryCode !== '' && $this->consumeRecoveryCode($user, $recoveryCode);
    }

    public function disable(AdminUser|RestaurantUser $user, #[SensitiveParameter] string $code, ?Principal $actor = null): void
    {
        if (! $user->hasMfaEnabled()) {
            throw ApiException::conflict('mfa_not_enabled', 'Multi-factor authentication is not enabled.');
        }

        if ($this->requiredFor($user)) {
            throw ApiException::conflict('mfa_required', 'Multi-factor authentication is mandatory for this account.');
        }

        if (! $this->consumeTotp($user, $code)) {
            throw new ApiException(422, 'mfa_code_invalid', 'That code is not correct.');
        }

        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_used_step' => null])->save();

        $this->events->record(SecurityEventType::MfaDisabled, $user, ['method' => 'totp']);
        MfaChanged::dispatch($user, false, $actor ?? $user);
    }

    /**
     * Accepts a TOTP code at most once (row lock + last used step), so a captured code cannot be replayed.
     */
    private function consumeTotp(AdminUser|RestaurantUser $user, #[SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $step = $locked->mfa_secret === null ? null : Totp::verify($locked->mfa_secret, $code, now()->getTimestamp());

            if ($step === null || ($locked->mfa_last_used_step !== null && $step <= $locked->mfa_last_used_step)) {
                return false;
            }

            $locked->forceFill(['mfa_last_used_step' => $step])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function consumeRecoveryCode(AdminUser|RestaurantUser $user, #[SensitiveParameter] string $recoveryCode): bool
    {
        return DB::transaction(function () use ($user, $recoveryCode): bool {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $hashes = $locked->mfa_recovery_codes ?? [];
            $candidate = hash('sha256', strtolower(trim($recoveryCode)));

            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $candidate)) {
                    unset($hashes[$index]);
                    $locked->forceFill(['mfa_recovery_codes' => array_values($hashes)])->save();
                    $user->setRawAttributes($locked->getAttributes(), true);

                    return true;
                }
            }

            return false;
        });
    }
}
