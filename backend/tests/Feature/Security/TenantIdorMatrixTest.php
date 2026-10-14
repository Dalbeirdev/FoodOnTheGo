<?php

namespace Tests\Feature\Security;

use App\Auth\Principal;
use App\Auth\Scope;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantLocation;
use App\Models\Role;
use App\Models\SupportCase;
use App\Services\Rbac\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\Concerns\WalksRoutes;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 39 — the tenant IDOR master tests (M39-021 restaurant, M39-022 location, M39-023 admin market scope). The
 * victim's real identifiers are put into EVERY route of the surface, for every method, and the attacker — who is fully
 * entitled inside their own tenant — must get no 2xx and no 5xx. A positive control (the victim's own principal reading
 * the same GET routes) proves the identifiers are real, so a 404 cannot pass for a wrong id.
 */
class TenantIdorMatrixTest extends TestCase
{
    use BuildsSupport, RefreshDatabase, WalksRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /**
     * @param  array<string, string>  $values  route parameter → victim identifier
     * @param  list<string>  $classes
     * @return array{walked: list<string>, wrong: list<string>, control: int}
     */
    private function walk(array $values, array $classes, Principal $attacker, ?Principal $victim): array
    {
        $walked = [];
        $wrong = [];
        $control = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $row = $this->inventory()[($route->methods()[0]).' /'.$route->uri()] ?? null;
            if (! in_array($row['class'] ?? null, $classes, true) || ! preg_match_all('/\{(\w+)\}/', $route->uri(), $m) || array_diff($m[1], array_keys($values)) !== []) {
                continue;
            }
            $url = '/'.preg_replace_callback('/\{(\w+)\}/', fn (array $p): string => (string) $values[$p[1]], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if ($victim !== null && $method === 'GET') {
                    Cache::flush();
                    $this->actingAsPrincipal($victim);
                    $control += $this->getJson($url)->status() === 200 ? 1 : 0;
                }
                Cache::flush();
                $this->actingAsPrincipal($attacker);
                $status = $this->json($method, $url)->status();
                $walked[] = "$method /{$route->uri()}";
                if ($status < 300 || $status >= 500) {
                    $wrong[] = "$method /{$route->uri()} → $status";
                }
            }
        }

        return ['walked' => $walked, 'wrong' => $wrong, 'control' => $control];
    }

    /** @return array<string, mixed> */
    private function victimState(string $orderNumber, string $caseId): array
    {
        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();
        $case = SupportCase::query()->where('public_id', $caseId)->firstOrFail();
        $item = MenuItem::query()->findOrFail($this->burger->getKey());
        $location = RestaurantLocation::query()->findOrFail($this->burgerHub->getKey());

        return [
            'order' => [$order->status->value, (int) $order->version], 'case' => [$case->status->value, (int) $case->version],
            'item' => [(string) $item->updated_at, $item->getAttributes()['price_minor'] ?? $item->getAttributes()['price'] ?? null, $item->deleted_at ?? null],
            'location' => [(string) $location->updated_at, $location->status?->value ?? $location->status], 'category' => $this->mains->fresh()?->updated_at?->toIso8601String(),
        ];
    }

    public function test_a_restaurant_owner_reaches_nothing_of_another_organization(): void
    {
        [, , , , $order] = $this->paidOrder();
        $victimOwner = $this->member($this->organization, 'OWNER');
        $this->asActor($victimOwner);
        $case = $this->restaurantCase();
        $rival = $this->organization('Rival Foods');
        $this->eligible('Rival Hub', 28.56, 77.36, organization: $rival);
        $attacker = $this->member($rival, 'OWNER');

        $values = [
            'location' => (string) $this->burgerHub->public_id, 'order' => $order['order_number'], 'item' => (string) $this->burger->public_id,
            'category' => (string) $this->mains->public_id, 'organization' => (string) $this->organization->public_id,
            'membership' => (string) $this->membershipOf($victimOwner, $this->organization)->public_id, 'supportCase' => $case['id'],
        ];
        $before = $this->victimState($order['order_number'], $case['id']);
        $result = $this->walk($values, ['RESTAURANT'], $attacker, $victimOwner);

        $this->assertGreaterThanOrEqual(40, count($result['walked']), implode("\n", $result['walked']));
        $this->assertGreaterThanOrEqual(10, $result['control'], 'the victim reads its own records with the same identifiers');
        $this->assertSame([], $result['wrong']);
        $this->assertSame($before, $this->victimState($order['order_number'], $case['id']), 'nothing of the victim changed');

        // Lists cannot be pointed at the victim with a query parameter.
        $leaks = [];
        foreach ($this->registeredRoutes() as $r) {
            if ($r['method'] !== 'GET' || ($r['row']['class'] ?? null) !== 'RESTAURANT' || str_contains($r['uri'], '{')) {
                continue;
            }
            Cache::flush();
            $this->actingAsPrincipal($attacker);
            $response = $this->getJson($r['url'].'?location='.$values['location'].'&organization='.$values['organization'].'&order='.$order['order_number']);
            $body = (string) $response->getContent();
            if ($response->status() >= 500 || ($response->status() < 300 && (str_contains($body, $order['order_number']) || str_contains($body, $values['location']) || str_contains($body, $case['id'])))) {
                $leaks[] = "GET {$r['uri']} → {$response->status()}";
            }
        }
        $this->assertSame([], $leaks);
    }

    public function test_staff_limited_to_one_location_reach_nothing_of_another_location_of_the_same_organization(): void
    {
        [, , , , $order] = $this->paidOrder();
        $manager = $this->member($this->organization, 'OWNER');
        $this->asActor($manager);
        $case = $this->restaurantCase();
        $second = $this->eligible('Burger Hub Two', 28.57, 77.37);
        $attacker = $this->member($this->organization, 'MANAGER', [$second]);

        $values = ['location' => (string) $this->burgerHub->public_id, 'order' => $order['order_number'], 'supportCase' => $case['id']];
        $before = $this->victimState($order['order_number'], $case['id']);
        $result = $this->walk($values, ['RESTAURANT'], $attacker, $manager);

        $this->assertGreaterThanOrEqual(20, count($result['walked']), implode("\n", $result['walked']));
        $this->assertGreaterThanOrEqual(5, $result['control']);
        $this->assertSame([], $result['wrong']);
        $this->assertSame($before, $this->victimState($order['order_number'], $case['id']));
        // The order list of the location the member may see does not hold the other location's order.
        $this->actingAsPrincipal($attacker);
        $this->assertStringNotContainsString($order['order_number'], (string) $this->getJson('/api/v1/restaurant/orders?location='.$second->public_id)->getContent());
    }

    public function test_an_administrator_of_one_market_reaches_nothing_of_another_market(): void
    {
        [, $headers, , $payment, $order] = $this->paidOrder();
        $case = $this->customerCase($headers);
        $roles = app(RoleService::class);
        $superAdmin = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $global = AdminUser::factory()->create();
        $roles->assign($global, $superAdmin);
        $other = Market::factory()->create();
        $attacker = AdminUser::factory()->create();
        $roles->assign($attacker, $superAdmin, Scope::market($other->public_id));

        $values = [
            'order' => $order['order_number'], 'payment' => $payment['id'], 'location' => (string) $this->burgerHub->public_id,
            'organization' => (string) $this->organization->public_id, 'supportCase' => $case['id'],
        ];
        $before = $this->victimState($order['order_number'], $case['id']);
        $result = $this->walk($values, ['ADMIN'], $attacker, $global);

        $this->assertGreaterThanOrEqual(35, count($result['walked']), implode("\n", $result['walked']));   // 36 when written
        $this->assertGreaterThanOrEqual(8, $result['control'], 'a platform-wide administrator reads the same records');
        $this->assertSame([], $result['wrong']);
        $this->assertSame($before, $this->victimState($order['order_number'], $case['id']));
    }
}
