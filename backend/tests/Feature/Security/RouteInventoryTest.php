<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\Concerns\WalksRoutes;
use Tests\TestCase;

/**
 * Module 39 — "no unknown endpoints": every registered route has an owner, a class and an expected authorization in
 * docs/security/route-inventory.json, and its middleware keeps the rule of its class. A new route that is not in the
 * inventory, or a staff route without a permission, fails here.
 */
class RouteInventoryTest extends TestCase
{
    use RefreshDatabase, WalksRoutes;

    private const GUARD = ['CUSTOMER' => 'customer', 'RESTAURANT' => 'restaurant', 'ADMIN' => 'admin'];

    /**
     * API routes without a rate limit, each with its reason. Module 40: liveness must answer whatever the limiter's
     * store (Redis) does — with the limiter in front of it, a Redis outage answered 500 to every liveness probe and an
     * orchestrator would restart every healthy process. The route touches nothing (no query, no cache).
     */
    private const UNTHROTTLED = ['GET /api/v1/live' => 'liveness: touches no dependency, not even the limiter store'];

    public function test_every_registered_route_is_in_the_inventory_and_keeps_the_rule_of_its_class(): void
    {
        $problems = [];
        $seen = [];
        foreach ($this->registeredRoutes() as $r) {
            $key = $r['method'].' '.$r['uri'];
            $seen[$key] = true;
            $row = $r['row'];
            if ($row === null) {
                $problems[] = "$key: not in docs/security/route-inventory.json";

                continue;
            }
            $class = $row['class'];
            if (isset(self::GUARD[$class]) && $row['guard'] !== self::GUARD[$class]) {
                $problems[] = "$key: a {$class} route must authenticate with auth:".self::GUARD[$class].', it has '.var_export($row['guard'], true);
            }
            if (in_array($class, ['CUSTOMER', 'RESTAURANT', 'ADMIN', 'ANY_PRINCIPAL', 'ANY_STAFF', 'DEVELOPMENT_ONLY'], true) && $row['guard'] !== null && ! $row['active'] && ! str_starts_with($r['uri'], '/_boost')) {
                $problems[] = "$key: a signed-in route must re-check the account on every request (active)";
            }
            if (in_array($class, ['RESTAURANT', 'ADMIN'], true) && ($row['permission'] === null || $row['permission'] === '')) {
                $problems[] = "$key: a staff route needs a recorded permission";
            }
            if (str_starts_with($r['uri'], '/api/') && $row['throttle'] === [] && ! isset(self::UNTHROTTLED[$key])) {
                $problems[] = "$key: no rate limit";
            }
        }
        // Inventory rows that are not registered here are allowed only for routes that exist in some environments only.
        foreach ($this->inventory() as $key => $row) {
            if (! isset($seen[$key]) && $row['class'] !== 'DEVELOPMENT_ONLY') {
                $problems[] = "$key: in the inventory but not registered (regenerate the inventory)";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_every_api_route_really_runs_a_rate_limiter_except_the_listed_ones(): void
    {
        // The inventory records what route:list said; this checks the middleware the router actually runs.
        $router = app('router');
        $missing = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/')) {
                continue;
            }
            $throttled = collect($router->gatherRouteMiddleware($route))->contains(fn ($m): bool => is_string($m) && str_contains($m, 'ThrottleRequests'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $listed = isset(self::UNTHROTTLED[$method.' '.$uri]);
                if ($throttled === $listed) {
                    $missing[] = $method.' '.$uri.($listed ? ': listed as unthrottled but has a limiter' : ': no rate limiter');
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_the_class_rules_cover_the_whole_api_and_the_dangerous_framework_routes_are_gone(): void
    {
        $classes = array_count_values(array_column($this->inventory(), 'class'));
        $this->assertGreaterThan(200, $classes['ADMIN']);
        $this->assertGreaterThan(70, $classes['RESTAURANT']);
        $this->assertGreaterThan(60, $classes['CUSTOMER']);
        $uris = array_column($this->registeredRoutes(), 'uri');
        // Module 39: the default web broadcasting route, the served private disk and the Sanctum CSRF-cookie route.
        foreach (['/broadcasting/auth', '/storage/{path}', '/sanctum/csrf-cookie'] as $gone) {
            $this->assertNotContains($gone, $uris, "$gone must not be registered");
        }
        // Every API route is versioned: no unversioned or legacy API.
        foreach ($uris as $uri) {
            if (str_starts_with($uri, '/api/')) {
                $this->assertStringStartsWith('/api/v1/', $uri);
            }
        }
    }

    public function test_development_routes_exist_only_where_fake_providers_are_allowed(): void
    {
        $dev = array_values(array_filter($this->inventory(), fn (array $row): bool => $row['class'] === 'DEVELOPMENT_ONLY' && str_starts_with($row['uri'], '/api/v1/dev/')));
        $this->assertCount(6, $dev);
        // The registration condition is the allow-list of config/payments.php: read the file as other environments would.
        foreach (['local' => true, 'testing' => true, 'production' => false, 'staging' => false, 'prod' => false, 'Production' => false] as $env => $expected) {
            $this->assertSame($expected, $this->allowFakeIn($env), "APP_ENV={$env}");
        }
    }

    private function allowFakeIn(string $env): bool
    {
        $before = [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null];
        putenv("APP_ENV={$env}");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
        try {
            return (bool) (require base_path('config/payments.php'))['providers']['allow_fake'];
        } finally {
            $before[0] === false ? putenv('APP_ENV') : putenv('APP_ENV='.$before[0]);
            $_ENV['APP_ENV'] = $before[1];
            $_SERVER['APP_ENV'] = $before[2];
        }
    }
}
