<?php

namespace App\Services\Auth;

use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use Illuminate\Support\Facades\Cache;

/**
 * Module 39 — a durable answer to guessing a staff password or a second factor: failures are counted per ACCOUNT (not
 * per address or per challenge), inside a window; at the limit the account refuses that step for a while, whatever the
 * address, the device or the number of fresh challenges. The counters live in the cache (Redis); losing them only
 * resets a count.
 *
 * Kinds: `password` is keyed by the normalised e-mail (it must also hold for e-mails that have no account, so the
 * answer does not tell which exist); `mfa` by the account.
 */
final class AccountLockout
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function assertOpen(string $kind, PrincipalType $type, string $identity): void
    {
        if (Cache::has($this->lockKey($kind, $type, $identity))) {
            throw new ApiException(429, 'account_temporarily_locked', 'Too many failed attempts. Please wait a while before you try again.');
        }
    }

    /**
     * Counts one failure. Returns true when this failure locked the account.
     */
    public function failed(string $kind, PrincipalType $type, string $identity, AdminUser|RestaurantUser|null $user = null): bool
    {
        $settings = (array) config("auth_security.lockout.{$kind}");
        $counter = $this->countKey($kind, $type, $identity);
        Cache::add($counter, 0, now()->addMinutes((int) $settings['window_minutes']));
        $failures = (int) Cache::increment($counter);

        if ($failures < (int) $settings['failures']) {
            return false;
        }

        Cache::put($this->lockKey($kind, $type, $identity), true, now()->addMinutes((int) $settings['lock_minutes']));
        Cache::forget($counter);
        $this->events->record(SecurityEventType::AccountLocked, $user, ['step' => $kind, 'context' => $type->guard(), 'failures' => $failures, 'lock_minutes' => (int) $settings['lock_minutes']], $kind === 'password' ? $identity : null);

        return true;
    }

    public function clear(string $kind, PrincipalType $type, string $identity): void
    {
        Cache::forget($this->countKey($kind, $type, $identity));
    }

    private function countKey(string $kind, PrincipalType $type, string $identity): string
    {
        return "lockout:{$kind}:{$type->value}:".hash('sha256', $identity).':failures';
    }

    private function lockKey(string $kind, PrincipalType $type, string $identity): string
    {
        return "lockout:{$kind}:{$type->value}:".hash('sha256', $identity).':locked';
    }
}
