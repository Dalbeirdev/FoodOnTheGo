<?php

namespace App\Events;

use App\Auth\Principal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Audit hook: a future audit listener records who changed which account's status and why.
 */
class AccountStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Principal $account,
        public readonly string $from,
        public readonly string $to,
        public readonly ?Principal $actor,
        public readonly ?string $reason,
    ) {}
}
