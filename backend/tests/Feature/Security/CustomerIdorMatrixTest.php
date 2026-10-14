<?php

namespace Tests\Feature\Security;

use App\Models\CustomerSavedLocation;
use App\Models\Order;
use App\Models\SupportCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\Concerns\WalksRoutes;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 39 — the customer IDOR master test. Customer B has real records (an order, its payment and checkout, a support
 * case, a device, a saved place, a notification, a session). Customer A, signed in, calls EVERY customer route that
 * takes an identifier with B's identifiers filled in — GET, PATCH, DELETE and actions alike. Nothing is answered with
 * B's data and nothing of B changes; list filters naming B are ignored.
 */
class CustomerIdorMatrixTest extends TestCase
{
    use BuildsSupport, RefreshDatabase, WalksRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_customer_a_reaches_nothing_of_customer_b_through_any_customer_route(): void
    {
        [, $aHeaders, , , $aOrder] = $this->paidOrder('+919876543210');
        [$b, $bHeaders, $bCheckout, $bPayment, $bOrder] = $this->paidOrder('+919876543299');
        $bCase = $this->customerCase($bHeaders);
        $device = $this->withHeaders($this->using($bHeaders))->putJson('/api/v1/customer/devices/current', ['installation_id' => 'install-b-0001', 'platform' => 'ANDROID', 'push_provider' => 'FAKE', 'push_token' => 'fake-token-of-customer-b-0001'])->assertSuccessful()->json('device');
        $place = $this->withHeaders($this->using($bHeaders))->postJson('/api/v1/customer/saved-locations', ['kind' => 'HOME', 'label' => 'Home', 'line1' => 'B-1 Test Street', 'locality' => 'Sector 62', 'city' => 'Noida', 'region' => 'Uttar Pradesh', 'postal_code' => '201309', 'country_code' => 'IN', 'formatted_address' => 'B-1 Test Street, Noida', 'lat' => 28.55, 'lng' => 77.35])->assertCreated()->json();
        $sessions = $this->withHeaders($this->using($bHeaders))->getJson('/api/v1/auth/sessions')->assertOk();
        $session = $sessions->json('data.0.id') ?? $sessions->json('0.id');
        $notification = $this->withHeaders($this->using($bHeaders))->getJson('/api/v1/customer/notifications')->assertOk()->json('data.0.id');

        $values = [
            'order' => $bOrder['order_number'], 'checkout' => $bCheckout['id'], 'payment' => $bPayment['id'], 'supportCase' => $bCase['id'],
            'device' => $device['id'], 'savedLocation' => $place['id'], 'session' => $session, 'notification' => $notification,
        ];
        $this->assertNotContains(null, $values, (string) json_encode($values));
        $before = $this->stateOf($bOrder['order_number'], $bCase['id'], $place['id']);

        $walked = [];
        $wrong = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $row = $this->inventory()[($route->methods()[0]).' /'.$route->uri()] ?? null;
            if (! in_array($row['class'] ?? null, ['CUSTOMER', 'PUBLIC_OR_CUSTOMER', 'ANY_PRINCIPAL'], true) || ! preg_match_all('/\{(\w+)\}/', $route->uri(), $m)) {
                continue;
            }
            if (array_diff($m[1], array_keys($values)) !== []) {
                continue;   // a public slug ({restaurant}) or an identifier B has no record for here ({review}, {line}, {journey}, {paymentMethod})
            }
            $url = '/'.preg_replace_callback('/\{(\w+)\}/', fn (array $p): string => (string) $values[$p[1]], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                Cache::flush();
                $status = $this->withHeaders($this->using($aHeaders))->json($method, $url)->status();
                $walked[] = "$method /{$route->uri()}";
                if ($status < 300 || $status >= 500) {
                    $wrong[] = "$method /{$route->uri()} with B's identifier → $status";
                }
            }
        }

        $this->assertGreaterThanOrEqual(25, count($walked), implode("\n", $walked));
        $this->assertSame([], $wrong);
        $this->assertSame($before, $this->stateOf($bOrder['order_number'], $bCase['id'], $place['id']), 'nothing of customer B changed');
        // B's session and device survived A's DELETE attempts.
        $this->withHeaders($this->using($bHeaders))->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeaders($this->using($bHeaders))->getJson('/api/v1/customer/devices')->assertOk()->assertJsonPath('data.0.id', $device['id']);

        // A list cannot be widened to B with a filter: unknown filters are refused, and A's own list never holds B's order.
        $list = $this->withHeaders($this->using($aHeaders))->getJson('/api/v1/customer/orders?customer_id='.$b->public_id);
        $this->assertContains($list->status(), [200, 422]);
        if ($list->status() === 200) {
            $this->assertNotContains($bOrder['order_number'], array_column((array) $list->json('data'), 'order_number'));
        }
        $this->assertContains($aOrder['order_number'], array_column((array) $this->withHeaders($this->using($aHeaders))->getJson('/api/v1/customer/orders')->assertOk()->json('data'), 'order_number'));
    }

    /** @return array<string, mixed> */
    private function stateOf(string $orderNumber, string $caseId, string $placeId): array
    {
        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();
        $case = SupportCase::query()->where('public_id', $caseId)->firstOrFail();

        return ['order' => [$order->status->value, (int) $order->version], 'case' => [$case->status->value, (int) $case->version], 'place' => CustomerSavedLocation::query()->where('public_id', $placeId)->exists()];
    }
}
