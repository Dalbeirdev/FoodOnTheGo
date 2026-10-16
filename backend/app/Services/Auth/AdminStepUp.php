<?php

namespace App\Services\Auth;

use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\AccessToken;
use App\Models\AdminUser;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Module 41 — step-up re-authentication of administrators (closes the Module 39 blocker B-1).
 *
 * A high-risk action (see config/auth_security.php `step_up`) needs a recent authentication on THIS session: a token
 * issued within `fresh_token_minutes`, or a step-up confirmed within `window_minutes` (the password, and the current
 * MFA code or a recovery code when MFA is on). The mark is kept per token, so another device of the same administrator
 * does not inherit it, and it expires by itself. Customers have their own recent-authentication rule (Module 25).
 */
final class AdminStepUp
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('auth_security.step_up.enabled');
    }

    /** When the current session's authentication stops counting as recent (null: not recent now). */
    public function recentUntil(Request $request): ?CarbonInterface
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof AccessToken) {
            return null;
        }
        $until = [];
        if ($token->created_at !== null) {
            $until[] = $token->created_at->copy()->addMinutes((int) config('auth_security.step_up.fresh_token_minutes'));
        }
        $marked = Cache::get($this->key($token));
        if (is_string($marked)) {
            $until[] = now()->parse($marked);
        }
        $latest = collect($until)->filter(fn (CarbonInterface $t): bool => $t->isFuture())->sortDesc()->first();

        return $latest;
    }

    public function assert(Request $request): void
    {
        if (! $this->enabled() || ! $request->user() instanceof AdminUser) {
            return;
        }
        if ($this->recentUntil($request) === null) {
            throw new ApiException(403, 'step_up_required', 'Please confirm it is you before this action: enter your password again.', ['step_up' => ['mfa' => $request->user()->hasMfaEnabled()]]);
        }
    }

    /**
     * Confirms the administrator on this session: the password and, with MFA on, the authenticator code or a recovery
     * code. A wrong answer is a security event and the same generic refusal (nothing says which part was wrong).
     */
    public function confirm(Request $request, #[SensitiveParameter] string $password, #[SensitiveParameter] ?string $code, #[SensitiveParameter] ?string $recoveryCode): CarbonInterface
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $token = $admin->currentAccessToken();
        $passwordOk = Hash::check($password, (string) $admin->password);
        $secondOk = ! $admin->hasMfaEnabled() || ($passwordOk && $this->mfa->verifySecondFactor($admin, $code, $recoveryCode));
        if (! $passwordOk || ! $secondOk) {
            $this->events->record($passwordOk ? SecurityEventType::MfaChallengeFailed : SecurityEventType::LoginFailed, $admin, ['method' => 'step_up', 'context' => 'admin', 'reason' => $passwordOk ? 'second_factor' : 'invalid_credentials']);

            throw new ApiException(422, 'step_up_failed', 'That did not match. Please try again.');
        }
        $until = now()->addMinutes((int) config('auth_security.step_up.window_minutes'));
        Cache::put($this->key($token), $until->toIso8601String(), $until);
        $this->events->record(SecurityEventType::Reauthenticated, $admin, ['method' => $admin->hasMfaEnabled() ? 'password_mfa' : 'password', 'context' => 'admin_step_up']);

        return $until;
    }

    private function key(AccessToken $token): string
    {
        return 'admin:step-up:token:'.$token->getKey();
    }
}
