<?php

namespace App\Services\Checkout;

use App\Models\CheckoutSession;

/**
 * A checkout as it stands right now: the session, what is wrong with it (if anything) and what the customer must be
 * told changed since they last looked.
 */
final readonly class CheckoutView
{
    /**
     * @param  list<array{code: string, message: string, recover: string, details: array<string, mixed>}>  $issues
     * @param  list<array<string, mixed>>  $changes
     */
    public function __construct(
        public CheckoutSession $session,
        public array $issues = [],
        public array $changes = [],
        public bool $created = false,
    ) {}
}
