<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use SensitiveParameter;

/**
 * Who a cart request is made by: a signed-in customer, a guest holding a cart token, or nobody yet.
 *
 * A signed-in customer is always the customer — a guest token sent along is ignored (it only counts when the
 * customer deliberately claims that guest cart). Every query for a cart goes through constrain(): there is no
 * way to reach a cart by its id alone.
 */
final readonly class CartOwner
{
    private function __construct(
        public ?Customer $customer,
        public ?string $tokenHash,
        public ?CarbonImmutable $tokenIssuedAt,
        // Set when the token was minted for this very request: the client must be told it, once.
        public ?string $mintedToken,
    ) {}

    public static function customer(Customer $customer): self
    {
        return new self($customer, null, null, null);
    }

    public static function guest(string $tokenHash, CarbonImmutable $issuedAt, #[SensitiveParameter] ?string $mintedToken = null): self
    {
        return new self(null, $tokenHash, $issuedAt, $mintedToken);
    }

    public static function nobody(): self
    {
        return new self(null, null, null, null);
    }

    public function isCustomer(): bool
    {
        return $this->customer !== null;
    }

    public function isGuest(): bool
    {
        return $this->customer === null && $this->tokenHash !== null;
    }

    public function hasIdentity(): bool
    {
        return $this->customer !== null || $this->tokenHash !== null;
    }

    /**
     * A stable, non-secret name for rate limits and idempotency scopes.
     */
    public function actorKey(): string
    {
        return match (true) {
            $this->customer !== null => 'customer:'.$this->customer->getKey(),
            $this->tokenHash !== null => 'guest:'.substr($this->tokenHash, 0, 32),
            default => 'nobody',
        };
    }

    public function kind(): string
    {
        return $this->customer !== null ? 'customer' : 'guest';
    }

    /**
     * @param  Builder<Cart>  $query
     */
    public function constrain(Builder $query): void
    {
        match (true) {
            $this->customer !== null => $query->where('carts.customer_id', $this->customer->getKey()),
            $this->tokenHash !== null => $query->whereNull('carts.customer_id')->where('carts.guest_token_hash', $this->tokenHash),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function owns(Cart $cart): bool
    {
        return match (true) {
            $this->customer !== null => $cart->customer_id !== null && (int) $cart->customer_id === (int) $this->customer->getKey(),
            $this->tokenHash !== null => $cart->customer_id === null && $cart->guest_token_hash !== null && hash_equals((string) $cart->guest_token_hash, $this->tokenHash),
            default => false,
        };
    }
}
