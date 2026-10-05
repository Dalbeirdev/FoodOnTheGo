<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ChecksOptionalCustomer;
use App\Services\Journey\JourneyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `journey`: resolves {journey} for its owner only — the signed-in customer who planned it, or the holder of
 * the guest token it was issued with (header X-Journey-Token). Anything else is a 404: a journey id is never
 * enough to read a journey, and no journey is listed anywhere. The journey is the request attribute "journey".
 */
class ResolveJourney
{
    use ChecksOptionalCustomer;

    public const TOKEN_HEADER = 'X-Journey-Token';

    public function __construct(private readonly JourneyService $journeys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $customer = $this->optionalCustomer($request);
        $journey = $this->journeys->find((string) $request->route('journey'), $customer, $request->header(self::TOKEN_HEADER));

        $request->attributes->set('customer', $customer);
        $request->attributes->set('journey', $journey);

        return $next($request);
    }
}
