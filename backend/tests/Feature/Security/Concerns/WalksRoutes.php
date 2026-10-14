<?php

namespace Tests\Feature\Security\Concerns;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Module 39 — helpers for tests that walk EVERY registered route: the checked-in inventory
 * (docs/security/route-inventory.json) and a concrete URL for each route whose parameters satisfy the route's own
 * patterns (so a request reaches the middleware instead of falling through to "route not found").
 */
trait WalksRoutes
{
    /** @return array<string, array<string, mixed>> keyed "METHOD /uri" */
    protected function inventory(): array
    {
        $doc = json_decode((string) file_get_contents(base_path('../docs/security/route-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($doc['routes'] as $row) {
            $out[$row['method'].' '.$row['uri']] = $row;
        }

        return $out;
    }

    /**
     * Every registered route as METHOD + concrete URL + inventory row.
     *
     * @return list<array{method: string, uri: string, url: string, row: array<string, mixed>|null}>
     */
    protected function registeredRoutes(): array
    {
        $inventory = $this->inventory();
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $uri = '/'.ltrim($route->uri(), '/');
                $out[] = ['method' => $method, 'uri' => $uri, 'url' => $this->concreteUrl($route), 'row' => $inventory[$method.' '.$uri] ?? null];
            }
        }

        return $out;
    }

    /**
     * A URL for the route with sample parameters that match the route's patterns (uuid, order number, case number …).
     */
    protected function concreteUrl(RoutingRoute $route): string
    {
        $patterns = $route->wheres + Route::getPatterns();
        $candidates = ['00000000-0000-4000-8000-000000000000', 'FOTG-20261014-ABCD23', 'CS-ABCDEFGH', '1', 'fake', 'razorpay', 'burger-hub', 'classic-burger', 'abcdefgh12', 'x'];

        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($patterns, $candidates): string {
            $pattern = $patterns[$m[1]] ?? null;
            if ($pattern === null) {
                return $candidates[0];
            }
            foreach ($candidates as $candidate) {
                if (preg_match('#^(?:'.$pattern.')$#', $candidate) === 1) {
                    return $candidate;
                }
            }

            return $candidates[0];
        }, ltrim($route->uri(), '/'));
    }

    protected function call401Free(): void
    {
        Cache::flush();   // the walks make hundreds of requests; no limiter of one walk may answer the next one
    }
}
