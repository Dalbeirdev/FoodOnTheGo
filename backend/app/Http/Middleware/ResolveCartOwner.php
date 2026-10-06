<?php

namespace App\Http\Middleware;

use App\Enums\CartStatus;
use App\Http\Middleware\Concerns\ChecksOptionalCustomer;
use App\Services\Cart\CartOwner;
use App\Services\Cart\GuestCartToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `cart.owner`: decides whose cart a request is about and puts the answer in the request attribute "cartOwner".
 *
 *   a signed-in customer   their own cart — a guest token sent along is ignored
 *   a guest with a token   the cart that token belongs to; the token must be one this server minted (signature),
 *                          must not have been spent by a sign-in, and may start a NEW cart only while it is young
 *   anybody else           nobody: there is no cart to read, and the first change is answered with a fresh token
 *
 * A cart id plays no part: it never authorizes anything.
 */
class ResolveCartOwner
{
    use ChecksOptionalCustomer;

    public function __construct(private readonly GuestCartToken $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $customer = $this->optionalCustomer($request);
        $request->attributes->set('customer', $customer);
        $owner = $customer !== null ? CartOwner::customer($customer) : $this->guest($request);
        $request->attributes->set('cartOwner', $owner);
        // Idempotency keys of cart changes are scoped to the cart's owner, not to an IP address.
        $request->attributes->set('idempotency_actor', $owner->actorKey());

        return $next($request);
    }

    private function guest(Request $request): CartOwner
    {
        $token = $this->tokens->parse($request->header(GuestCartToken::HEADER));
        if ($token === null) {
            return CartOwner::nobody();
        }
        $carts = DB::table('carts')->where(fn ($q) => $q->where('spent_token_hash', $token['hash'])
            ->orWhere(fn ($active) => $active->where('guest_token_hash', $token['hash'])->where('status', CartStatus::Active->value)))
            ->get(['spent_token_hash', 'status']);

        if ($carts->contains(fn (object $c): bool => $c->spent_token_hash === $token['hash'])) {
            return CartOwner::nobody();
        }
        $young = $token['issued_at']->greaterThan(now()->subDays(max(1, (int) config('cart.guest.token_ttl_days')))) && $token['issued_at']->lessThanOrEqualTo(now()->addMinutes(5));

        return $carts->isNotEmpty() || $young ? CartOwner::guest($token['hash'], $token['issued_at']) : CartOwner::nobody();
    }
}
