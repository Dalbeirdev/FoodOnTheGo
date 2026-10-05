<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ChecksOptionalCustomer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `customer.optional`: a route open to guests that recognises a signed-in customer when a valid customer token
 * is sent. The resolved customer (or null) is available as the request attribute "customer".
 */
class OptionalCustomer
{
    use ChecksOptionalCustomer;

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('customer', $this->optionalCustomer($request));

        return $next($request);
    }
}
