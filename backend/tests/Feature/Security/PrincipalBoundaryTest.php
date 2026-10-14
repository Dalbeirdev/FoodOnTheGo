<?php

namespace Tests\Feature\Security;

use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Security\Concerns\WalksRoutes;
use Tests\TestCase;

/**
 * Module 39 — walks EVERY protected route of the inventory:
 *  - without a token: 401;
 *  - with a REAL bearer token of each OTHER kind of principal (a customer on restaurant and admin routes, a restaurant
 *    user on customer and admin routes, an administrator on customer and restaurant routes): 401 — a token of one kind
 *    never opens another kind's API, whatever the id it carries;
 * and the staff walk: an administrator / restaurant user whose role holds NO permission gets nothing (never 2xx) from
 * any staff route.
 */
class PrincipalBoundaryTest extends TestCase
{
    use RefreshDatabase, WalksRoutes;

    private const PROTECTED = ['CUSTOMER', 'RESTAURANT', 'ADMIN', 'ANY_PRINCIPAL', 'ANY_STAFF'];

    /**
     * Staff routes that answer any signed-in member: their own context and catalogues. The listed paths of the answer
     * must be empty for a member who belongs to no organization.
     */
    private const SELF_SERVICE = [
        'GET /api/v1/restaurant/context' => ['memberships', 'organizations', 'locations'],
        'GET /api/v1/restaurant/taxonomy' => [],
        'GET /api/v1/restaurant/roles' => [],
        'GET /api/v1/restaurant/support/catalog' => [],
        'GET /api/v1/restaurant/analytics/exports' => ['data'],
    ];

    public function test_no_protected_route_answers_without_a_token(): void
    {
        $wrong = [];
        $walked = 0;
        foreach ($this->registeredRoutes() as $r) {
            if (! in_array($r['row']['class'] ?? null, self::PROTECTED, true)) {
                continue;
            }
            $this->call401Free();
            $status = $this->json($r['method'], $r['url'])->status();
            $walked++;
            if ($status !== 401) {
                $wrong[] = "{$r['method']} {$r['uri']} → {$status}";
            }
        }
        $this->assertGreaterThan(350, $walked);
        $this->assertSame([], $wrong);
    }

    public function test_a_token_of_one_kind_of_principal_never_opens_another_kinds_routes(): void
    {
        $this->seed(RoleSeeder::class);
        $customer = Customer::factory()->create();
        $staff = RestaurantUser::factory()->create();
        $admin = AdminUser::factory()->create();
        $tokens = ['customer' => $this->bearer($customer), 'restaurant' => $this->bearer($staff), 'admin' => $this->bearer($admin)];
        $forbiddenFor = ['CUSTOMER' => ['restaurant', 'admin'], 'RESTAURANT' => ['customer', 'admin'], 'ADMIN' => ['customer', 'restaurant'], 'ANY_STAFF' => ['customer']];

        $wrong = [];
        $walked = 0;
        foreach ($this->registeredRoutes() as $r) {
            $class = $r['row']['class'] ?? null;
            foreach ($forbiddenFor[$class] ?? [] as $kind) {
                $this->call401Free();
                $this->app['auth']->forgetGuards();
                $status = $this->json($r['method'], $r['url'], [], $tokens[$kind])->status();
                $walked++;
                if ($status !== 401) {
                    $wrong[] = "{$kind} token on {$r['method']} {$r['uri']} → {$status}";
                }
            }
        }
        $this->assertGreaterThan(700, $walked);
        $this->assertSame([], $wrong);
    }

    public function test_staff_whose_role_holds_no_permission_get_nothing_from_any_staff_route(): void
    {
        $this->seed(RoleSeeder::class);
        $roles = app(RoleService::class);
        $admin = AdminUser::factory()->create();
        $roles->assign($admin, $roles->define(PrincipalType::AdminUser, 'T_NONE_'.Str::upper(Str::random(6)), 'No permission', []));
        $staff = RestaurantUser::factory()->create();
        $headers = ['ADMIN' => $this->bearer($admin), 'RESTAURANT' => $this->bearer($staff)];

        $wrong = [];
        $answers = [];
        foreach ($this->registeredRoutes() as $r) {
            $class = $r['row']['class'] ?? null;
            if (! isset($headers[$class])) {
                continue;
            }
            $this->call401Free();
            $this->app['auth']->forgetGuards();
            $response = $this->json($r['method'], $r['url'], [], $headers[$class]);
            $status = $response->status();
            $answers[$class][$status] = ($answers[$class][$status] ?? 0) + 1;
            $key = "{$r['method']} {$r['uri']}";
            if ($status === 200 && isset(self::SELF_SERVICE[$key])) {
                // A self-service read: the caller's own (empty) context or a catalogue — never another tenant's data.
                $this->assertSame([], array_values(array_filter(self::SELF_SERVICE[$key], fn (string $path): bool => ! empty(data_get($response->json(), $path)))), "$key returned tenant data");

                continue;
            }
            // Allowed: refused (403), not found for this caller (404), or an incomplete request refused by validation
            // before any record is read (422). Never data, never a crash.
            if (! in_array($status, [403, 404, 422], true)) {
                $wrong[] = "{$class} {$key} → {$status}";
            }
        }
        $this->assertSame([], $wrong, json_encode($answers));
        $this->assertGreaterThan(80, $answers['ADMIN'][403] ?? 0);   // 83 when written: the rest are 404 (no such record) or 422 (incomplete request)
    }
}
