<?php

namespace Tests\Feature\Pickup;

use App\Enums\MenuItemStatus;
use App\Enums\Permission;
use App\Models\Cart;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every pickup endpoint (Module 28) answers with the shape documented in openapi/openapi.json — in every state:
 * open, closed, paused, fully booked, with and without a journey, valid and invalid selections, and each error.
 * A property that is not documented fails the test.
 */
class PickupOpenApiContractTest extends TestCase
{
    use BuildsPickup, RefreshDatabase, ValidatesOpenApi;

    public function test_the_pickup_endpoints_match_their_documented_schemas(): void
    {
        $this->setUpPickupWorld('2026-10-07 12:40');
        $this->fakeRouting();
        $this->fakePlaces();
        $guest = $this->guestWithBurger();
        $select = fn (array $body, array $headers = []) => $this->postJson('/api/v1/cart/pickup-selection', $body, [...$guest, ...$headers]);

        // ── Availability: open, with capacity (a full and a limited slot), with a journey
        $this->settings(['capacity_per_slot' => 4]);
        $this->reserve('13:30', 4);
        $this->reserve('13:45', 3);
        $open = $this->pickup($guest)->assertOk();
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $open->json());
        $this->assertSame(['AVAILABLE', 'FULL', 'LIMITED'], collect($open->json('pickup.slots'))->pluck('capacity_state')->unique()->sort()->values()->all());
        $this->arriveAt($guest, '13:25');
        $journey = $this->pickup($guest, ['method' => 'COUNTER'])->assertOk()->assertJsonPath('pickup.journey.status', 'ACTIVE')->assertJsonPath('pickup.recommended.basis', 'ARRIVAL');
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $journey->json());

        // ── Selection: none, ASAP, scheduled, replayed, read, with a stale journey
        $this->assertMatchesSchema('PickupSelectionEnvelope', $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection', null)->json());
        $this->assertMatchesSchema('PickupSelectionEnvelope', $select(['mode' => 'ASAP', 'method' => 'COUNTER'])->assertOk()->json());
        $key = $this->slotKey($guest, '14:00');
        $chosen = $select(['mode' => 'SCHEDULED', 'slot_key' => $key, 'cart_version' => 1], ['Idempotency-Key' => 'pickup-contract-0001'])->assertOk();
        $this->assertMatchesSchema('PickupSelectionEnvelope', $chosen->json());
        $this->assertMatchesSchema('PickupSelectionEnvelope', $select(['mode' => 'SCHEDULED', 'slot_key' => $key, 'cart_version' => 1], ['Idempotency-Key' => 'pickup-contract-0001'])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->json());
        $this->assertMatchesSchema('PickupSelectionEnvelope', $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.status', 'SELECTED')->json());
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.selection.mode', 'SCHEDULED')->json());

        // ── Errors of choosing
        $errors = [
            $select([])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed'),
            $select(['mode' => 'ASAP', 'pickup_at' => '2026-10-07T07:00:00Z'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed'),
            $select(['mode' => 'SCHEDULED', 'slot_key' => 'ps1_zzzzzz_15_0123456789ab'])->assertStatus(422)->assertJsonPath('error.code', 'slot_invalid'),
            $select(['mode' => 'SCHEDULED', 'slot_key' => $this->slotKey($guest, '13:30')])->assertStatus(409)->assertJsonPath('error.code', 'slot_full'),
            $select(['mode' => 'ASAP', 'method' => 'CURBSIDE'])->assertStatus(422)->assertJsonPath('error.code', 'pickup_method_unavailable'),
            $select(['mode' => 'ASAP', 'cart_version' => 99])->assertStatus(409)->assertJsonPath('error.code', 'cart_version_conflict'),
            $select(['mode' => 'ASAP'], ['Idempotency-Key' => 'x'])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required'),
            $select(['mode' => 'ASAP'], ['Idempotency-Key' => 'pickup-contract-0001'])->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused'),
            $this->pickup($guest, ['method' => 'CURBSIDE'])->assertStatus(422)->assertJsonPath('error.code', 'pickup_method_unavailable'),
            $this->pickup($this->guest())->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found'),
            $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'ASAP'])->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found'),
        ];

        // ── States: the selection goes invalid (slot full), stale journey, closed, near closing, paused, cart invalid, expired
        $this->reserve('14:00', 4);
        $this->assertMatchesSchema('PickupSelectionEnvelope', $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.status', 'INVALID')->json());
        $this->clock('2026-10-07 13:40');
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.journey.status', 'STALE')->json());
        $this->clock('2026-10-07 14:10');
        $this->assertMatchesSchema('PickupSelectionEnvelope', $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.status', 'EXPIRED')->json());
        $this->clock('2026-10-07 21:50');
        $closing = $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.reason', 'CLOSES_TOO_SOON');
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $closing->json());
        $errors[] = $select(['mode' => 'ASAP'])->assertStatus(409)->assertJsonPath('error.code', 'asap_unavailable');
        $errors[] = $select(['mode' => 'SCHEDULED', 'slot_key' => $key])->assertStatus(409)->assertJsonPath('error.code', 'slot_expired');
        $this->clock('2026-10-07 23:30');
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.asap.reason', 'RESTAURANT_CLOSED')->json());
        $this->settings(['scheduled_enabled' => false]);
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.can_select', false)->json());
        $errors[] = $select(['mode' => 'SCHEDULED', 'slot_key' => $key])->assertStatus(422)->assertJsonPath('error.code', 'pickup_mode_unavailable');

        $this->clock('2026-10-07 12:40');
        $this->settings(['scheduled_enabled' => true]);
        $this->burgerHub->forceFill(['accepting_orders' => false, 'paused_until' => now()->addHour()])->save();
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.issues.0.code', 'RESTAURANT_NOT_ACCEPTING')->json());
        $errors[] = $select(['mode' => 'ASAP'])->assertStatus(409)->assertJsonPath('error.code', 'restaurant_not_accepting');
        $this->burgerHub->forceFill(['accepting_orders' => true, 'paused_until' => null])->save();
        $this->settings(['pickup_enabled' => false]);
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.issues.0.code', 'PICKUP_DISABLED')->json());
        $errors[] = $select(['mode' => 'ASAP'])->assertStatus(409)->assertJsonPath('error.code', 'pickup_disabled');
        $this->settings(['pickup_enabled' => true]);

        app(MenuService::class)->setItemStatus($this->burger, MenuItemStatus::SoldOut, $this->staff());
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.issues.0.code', 'CART_INVALID')->json());
        $errors[] = $select(['mode' => 'ASAP'])->assertStatus(409)->assertJsonPath('error.code', 'cart_invalid');
        app(MenuService::class)->setItemStatus($this->burger->refresh(), MenuItemStatus::Active, $this->staff());

        Cart::query()->active()->update(['expires_at' => now()->subMinute()]);
        $this->assertMatchesSchema('PickupAvailabilityEnvelope', $this->pickup($guest)->assertOk()->assertJsonPath('pickup.issues.0.code', 'CART_EXPIRED')->json());
        $errors[] = $select(['mode' => 'ASAP'])->assertStatus(410)->assertJsonPath('error.code', 'cart_expired');
        $this->assertMatchesSchema('PickupSelectionEnvelope', $this->deleteJson('/api/v1/cart/pickup-selection', [], $guest)->assertOk()->json());

        foreach ($errors as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }

        // ── Restaurant dashboard preview and admin counters
        $this->actingAsPrincipal($this->staff());
        $preview = $this->getJson('/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/pickup-preview')->assertOk();
        $this->assertMatchesSchema('PickupPreviewEnvelope', $preview->json());
        $this->settings(['default_prep_minutes' => 240, 'capacity_per_slot' => null]);
        $this->assertMatchesSchema('PickupPreviewEnvelope', $this->getJson('/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/pickup-preview')->assertOk()->json());

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $metrics = $this->getJson('/api/v1/admin/system/pickup')->assertOk();
        $this->assertMatchesSchema('PickupMetrics', $metrics->json());
        $this->assertGreaterThan(0, $metrics->json('events.availability_requests'));
        $this->assertGreaterThan(0, $metrics->json('events.refused'));
    }

    public function test_every_pickup_route_is_documented_and_none_takes_a_cart_or_a_restaurant(): void
    {
        $paths = $this->openApi()['paths'];
        $documented = [];
        foreach (['/cart/pickup-availability', '/cart/pickup-selection', '/restaurant/locations/{location}/pickup-preview', '/admin/system/pickup'] as $path) {
            $this->assertArrayHasKey($path, $paths);
            foreach (array_keys($paths[$path]) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'pickup-availability') || str_contains($r->uri(), 'pickup-selection') || str_contains($r->uri(), 'pickup-preview') || str_contains($r->uri(), 'system/pickup'))
            ->flatMap(fn ($r) => array_map(fn (string $m): string => $m.' /'.preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])))->values()->all();
        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(6, $routes);

        // The customer operations take no path parameter at all: the cart, its restaurant and its owner come from the caller.
        foreach (['/cart/pickup-availability', '/cart/pickup-selection'] as $path) {
            $this->assertStringNotContainsString('{', $path);
            foreach ($paths[$path] as $operation) {
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $name = $parameter['name'] ?? basename($parameter['$ref']);
                    $this->assertContains($name, ['CartToken', 'IdempotencyKeyOptional', 'method'], "unexpected parameter {$name}");
                }
            }
        }
        // The request body cannot carry a time, a restaurant, a customer, a capacity or a validity.
        $request = $this->openApi()['components']['schemas']['PickupSelectRequest'];
        $this->assertSame(['mode', 'method', 'slot_key', 'cart_version'], array_keys($request['properties']));
        $this->assertFalse($request['additionalProperties']);
        // A selection can never be documented — or returned — as reserved.
        $this->assertSame([false], $this->openApi()['components']['schemas']['PickupSelection']['properties']['reserved']['enum']);
        $this->assertNotContains('RESERVED', $this->openApi()['components']['schemas']['PickupSelection']['properties']['status']['enum']);
    }
}
