<?php

namespace App\Http\Middleware\Concerns;

use App\Exceptions\ApiException;
use App\Models\AccessToken;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Journey routes serve guests AND signed-in customers. When a customer token is present it must be a usable
 * one (active account, access ability) — exactly the checks `auth:customer` + `active` make — but its absence
 * is not an error: the caller is then a guest.
 */
trait ChecksOptionalCustomer
{
    protected function optionalCustomer(Request $request): ?Customer
    {
        $user = $request->user('customer');
        if (! $user instanceof Customer) {
            return null;
        }
        if (! $user->canAuthenticate()) {
            throw new ApiException(403, 'account_not_active', 'This account cannot be used right now.');
        }
        if (! $user->tokenCan(AccessToken::ACCESS)) {
            throw new ApiException(403, 'forbidden', 'This token cannot be used here.');
        }
        Context::add('actor', $user->principalType()->morphAlias().':'.$user->public_id);

        return $user;
    }
}
