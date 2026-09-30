<?php

namespace App\Events;

use App\Auth\Principal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Audit hook: MFA was enabled or disabled (or reset by an administrator) for an account.
 */
class MfaChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Principal $account,
        public readonly bool $enabled,
        public readonly ?Principal $actor,
    ) {}
}
