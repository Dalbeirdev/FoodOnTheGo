<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Market;
use App\Services\Auth\TokenIssuer;

/**
 * Customers for the Module 25 tests: an ACTIVE, verified customer of the given market (India unless stated),
 * and a real session token when a test needs "recent authentication" or several sessions.
 */
trait BuildsCustomers
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function customer(Market $market, array $attributes = []): Customer
    {
        return Customer::factory()->create(['market_id' => $market->getKey(), 'preferred_locale' => 'en-IN', ...$attributes])->refresh();
    }

    /**
     * A real (non-transient) session: the Bearer header to send, so the token has an age and can be revoked.
     *
     * @return array{Authorization: string}
     */
    protected function sessionOf(Customer $customer, ?string $device = 'test'): array
    {
        return $this->using(['Authorization' => 'Bearer '.app(TokenIssuer::class)->issue($customer, $device)->plainTextToken]);
    }

    /**
     * The headers of a session, with the guards reset: in tests the guard keeps the user of the previous request,
     * so every request that switches session (or whose token changed) goes through here.
     *
     * @param  array{Authorization: string}  $session
     * @return array{Authorization: string}
     */
    protected function using(array $session): array
    {
        $this->app['auth']->forgetGuards();

        return $session;
    }
}
