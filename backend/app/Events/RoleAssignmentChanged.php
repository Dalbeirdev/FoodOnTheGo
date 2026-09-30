<?php

namespace App\Events;

use App\Auth\Principal;
use App\Auth\Scope;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Audit hook: a role was given to, or taken from, a restaurant user or admin user.
 */
class RoleAssignmentChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Principal $account,
        public readonly string $roleCode,
        public readonly ?Scope $scope,
        public readonly bool $assigned,
        public readonly ?Principal $actor,
    ) {}
}
