<?php

namespace App\Services\Auth;

use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\CredentialResetToken;
use App\Models\RestaurantUser;
use App\Notifications\CredentialResetNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Password reset for restaurant and admin users.
 *
 * - The response to a request is identical whether or not the account exists.
 * - The token is random (256 bits), stored only as SHA-256, expires, and works once.
 * - A successful reset invalidates every other reset token and every session of the account.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function request(PrincipalType $type, string $email): void
    {
        $email = StaffLoginService::normaliseEmail($email);
        /** @var AdminUser|RestaurantUser|null $user */
        $user = $type->model()::query()->where('email', $email)->first();

        $this->events->record(SecurityEventType::PasswordResetRequested, $user, ['context' => $type->guard(), 'account_found' => $user !== null], $email);

        if ($user === null || ! $user->canAuthenticate()) {
            return;
        }

        $token = Str::random(64);
        $minutes = (int) config('auth_security.password.reset_ttl_minutes');

        CredentialResetToken::query()->create([
            'principal_type' => $type->morphAlias(),
            'principal_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($minutes),
            'created_at' => now(),
        ]);

        $path = $type === PrincipalType::AdminUser ? '/admin/reset-password' : '/restaurant-dashboard/reset-password';
        $user->notify(new CredentialResetNotification(rtrim((string) config('app.frontend_url'), '/').$path.'#'.$token, $minutes));
    }

    public function reset(PrincipalType $type, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($type, $token, $password): void {
            $record = CredentialResetToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->where('principal_type', $type->morphAlias())
                ->lockForUpdate()
                ->first();

            /** @var AdminUser|RestaurantUser|null $user */
            $user = $record === null ? null : $type->model()::query()->find($record->principal_id);

            if ($record === null || $record->used_at !== null || $record->expires_at->isPast() || $user === null || ! $user->canAuthenticate()) {
                throw new ApiException(422, 'reset_token_invalid', 'This reset link is invalid or has expired. Request a new one.');
            }

            $record->forceFill(['used_at' => now()])->save();
            CredentialResetToken::query()
                ->where('principal_type', $record->principal_type)->where('principal_id', $record->principal_id)
                ->whereNull('used_at')->update(['used_at' => now()]);

            $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            $revoked = $this->tokens->revokeAll($user);

            $this->events->record(SecurityEventType::PasswordChanged, $user, ['via' => 'reset', 'sessions_revoked' => $revoked]);
        });
    }

    /**
     * Authenticated password change: the caller has already proved the current password. Every other
     * session is signed out; the one making the change stays.
     */
    public function change(AdminUser|RestaurantUser $user, #[SensitiveParameter] string $password, ?int $keepTokenId): void
    {
        $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
        $revoked = $this->tokens->revokeAll($user, $keepTokenId);

        $this->events->record(SecurityEventType::PasswordChanged, $user, ['via' => 'change', 'sessions_revoked' => $revoked]);
    }
}
