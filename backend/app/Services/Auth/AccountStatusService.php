<?php

namespace App\Services\Auth;

use App\Auth\Principal;
use App\Enums\CustomerStatus;
use App\Enums\SecurityEventType;
use App\Enums\StaffStatus;
use App\Events\AccountStatusChanged;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The only way an account's status changes. When the new status cannot authenticate, every session of the
 * account is revoked immediately — and independently of that, every request re-checks the status, so a
 * suspended account has no access even if a token were to survive.
 */
final class AccountStatusService
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function change(Principal&Model $account, CustomerStatus|StaffStatus $status, ?Principal $actor = null, ?string $reason = null): void
    {
        if (($account instanceof Customer) !== ($status instanceof CustomerStatus)) {
            throw new InvalidArgumentException('Status does not belong to this account type.');
        }

        $previous = $account->status;
        if ($previous === $status) {
            return;
        }

        $account->forceFill(['status' => $status])->save();
        $revoked = $status->canAuthenticate() ? 0 : $this->tokens->revokeAll($account);

        $this->events->record(SecurityEventType::AccountStatusChanged, $account, [
            'from' => $previous->value,
            'to' => $status->value,
            'sessions_revoked' => $revoked,
            'actor' => $actor?->public_id,
        ]);
        AccountStatusChanged::dispatch($account, $previous->value, $status->value, $actor, $reason);
    }
}
