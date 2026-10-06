<?php

namespace Tests\Feature\Cart;

use App\Enums\MenuItemStatus;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\MenuOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every cart endpoint (Module 27) answers with the shape documented in openapi/openapi.json — successes and every
 * documented error. A property that is not documented fails the test.
 */
class CartOpenApiContractTest extends TestCase
{
    use BuildsCarts, RefreshDatabase, ValidatesOpenApi;

    public function test_the_cart_endpoints_match_their_documented_schemas(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
        $this->setUpMenuWorld();
        [$pizzaPoint, $pizza] = $this->otherRestaurant();
        $plain = $this->item($this->mains, 'Paneer Roll', 20000);

        // ── Reading: nobody (token issued), a guest without a cart, a guest with one
        $nobody = $this->getJson('/api/v1/cart')->assertOk();
        $this->assertMatchesSchema('CartEnvelope', $nobody->json());
        $guest = ['X-Cart-Token' => $nobody->json('guest_token')];
        $this->assertMatchesSchema('CartEnvelope', $this->getJson('/api/v1/cart', $guest)->assertOk()->json());

        // ── Adding: with options and a note, from a journey, the same configuration again, without identity
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        $added = $this->addBurger([...$guest, ...$journey['headers']], 2, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese', 'Olives']], ['special_instructions' => 'No onions', 'journey_id' => $journey['id']])->assertCreated();
        $this->assertMatchesSchema('CartChangeResult', $added->json());
        $this->assertNotNull($added->json('cart.journey.detour'));
        $this->assertMatchesSchema('CartChangeResult', $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Olives', 'Extra Cheese']], ['special_instructions' => 'no onions'])->assertCreated()->assertJsonPath('line.merged', true)->json());
        $this->assertMatchesSchema('CartChangeResult', $this->postJson('/api/v1/cart/items', $this->addBody($plain))->assertCreated()->assertJsonStructure(['guest_token'])->json());
        $plainLine = $this->postJson('/api/v1/cart/items', $this->addBody($plain), $guest)->assertCreated()->json('line.id');
        $this->assertMatchesSchema('CartEnvelope', $this->getJson('/api/v1/cart', $guest)->assertOk()->assertJsonPath('cart.line_count', 2)->json());

        // ── Errors of adding
        $errors = [
            $this->postJson('/api/v1/cart/items', $this->addBody($this->burger), $guest)->assertStatus(422)->assertJsonPath('error.code', 'invalid_customization'),
            $this->addBurger($guest, 1, ['Size' => ['Regular'], 'Add-ons' => ['Fried Egg']])->assertStatus(409)->assertJsonPath('error.code', 'option_unavailable'),
            $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 21), $guest)->assertStatus(422)->assertJsonPath('error.code', 'quantity_limit'),
            $this->postJson('/api/v1/cart/items', [...$this->addBody($this->fries), 'unit_price_minor' => 1], $guest)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed'),
            $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_restaurant_conflict'),
            $this->postJson('/api/v1/cart/items', ['restaurant_id' => $this->burgerHub->public_id, 'item_id' => $pizza->public_id], $guest)->assertNotFound()->assertJsonPath('error.code', 'item_not_found'),
            $this->postJson('/api/v1/cart/items', ['restaurant_id' => '00000000-0000-4000-8000-000000000000', 'item_id' => $pizza->public_id], $guest)->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found'),
            $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['cart_version' => 99]), $guest)->assertStatus(409)->assertJsonPath('error.code', 'cart_version_conflict'),
            $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), [...$guest, 'Idempotency-Key' => 'x'])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required'),
        ];
        foreach ($errors as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }

        // ── Changing, removing
        $line = $added->json('line.id');
        $this->assertMatchesSchema('CartChangeResult', $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2, 'selections' => $this->choose($this->burger, ['Size' => ['Regular']]), 'special_instructions' => null], $guest)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->patchJson('/api/v1/cart/items/'.$line, [], $guest)->assertStatus(422)->json());
        $this->assertMatchesSchema('Error', $this->patchJson('/api/v1/cart/items/00000000-0000-4000-8000-000000000000', ['quantity' => 2], $guest)->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 2])->assertNotFound()->assertJsonPath('error.code', 'cart_not_found')->json());

        // ── What changed afterwards: price, sold out, option gone, restaurant paused — and the accepted refresh
        $plain->forceFill(['base_price_minor' => 22000])->save();
        $this->fries->forceFill(['status' => MenuItemStatus::Active])->save();
        $friesLine = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated()->json('line.id');
        $this->fries->forceFill(['status' => MenuItemStatus::SoldOut])->save();
        $withCheese = $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated()->json('line.id');
        MenuOption::query()->whereKey($this->groupAndOption($this->burger, 'Add-ons', 'Extra Cheese')['option']->id)->update(['status' => 'DISABLED']);
        $this->burgerHub->forceFill(['accepting_orders' => false])->save();
        $this->travel(31)->minutes();
        $troubled = $this->getJson('/api/v1/cart', $guest)->assertOk();
        $this->assertMatchesSchema('CartEnvelope', $troubled->json());
        $this->assertEqualsCanonicalizing(['RESTAURANT_NOT_ACCEPTING', 'JOURNEY_STALE', 'PRICE_CHANGED', 'ITEM_SOLD_OUT', 'OPTION_UNAVAILABLE'], array_column($troubled->json('cart.validation.issues'), 'code'));
        $this->assertMatchesSchema('Error', $this->patchJson('/api/v1/cart/items/'.$friesLine, ['quantity' => 2], $guest)->assertStatus(409)->assertJsonPath('error.code', 'item_unavailable')->json());
        $this->assertMatchesSchema('Error', $this->patchJson('/api/v1/cart/items/'.$withCheese, ['quantity' => 2], $guest)->assertStatus(409)->assertJsonPath('error.code', 'option_unavailable')->json());
        $this->assertMatchesSchema('CartChangeResult', $this->postJson('/api/v1/cart/refresh', [], $guest)->assertOk()->assertJsonPath('result.price_changes_accepted', 1)->json());
        $this->burgerHub->forceFill(['accepting_orders' => true])->save();
        $this->assertMatchesSchema('CartChangeResult', $this->deleteJson('/api/v1/cart/items/'.$plainLine, [], $guest)->assertOk()->json());

        // ── Journey link
        $again = $this->planGuest();
        $this->assertMatchesSchema('CartChangeResult', $this->putJson('/api/v1/cart/journey', ['journey_id' => $again['id']], [...$guest, ...$again['headers']])->assertOk()->assertJsonPath('cart.journey.status', 'ACTIVE')->json());
        $this->assertMatchesSchema('Error', $this->putJson('/api/v1/cart/journey', ['journey_id' => '00000000-0000-4000-8000-000000000000'], $guest)->assertNotFound()->json());
        $this->assertMatchesSchema('CartChangeResult', $this->deleteJson('/api/v1/cart/journey', [], $guest)->assertOk()->json());

        // ── The confirmed switch
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/cart/switch-restaurant', ['restaurant_id' => $pizzaPoint->public_id], $guest)->assertStatus(422)->json());
        $switched = $this->postJson('/api/v1/cart/switch-restaurant', ['confirm' => true, 'restaurant_id' => $pizzaPoint->public_id, 'item' => ['item_id' => $pizza->public_id, 'quantity' => 2]], $guest)->assertCreated();
        $this->assertMatchesSchema('CartChangeResult', $switched->assertJsonStructure(['replaced', 'line'])->json());

        // ── Sign-in: conflict, resolution, merge, nothing to claim, not signed in
        [, $session] = $this->signedIn();
        $this->assertMatchesSchema('CartChangeResult', $this->addBurger($session, 1)->assertCreated()->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$guest])->assertStatus(409)->assertJsonPath('error.code', 'cart_merge_conflict')->json());
        $this->assertMatchesSchema('CartClaimResult', $this->postJson('/api/v1/cart/claim', ['resolution' => 'keep_account'], [...$this->using($session), ...$guest])->assertOk()->json());
        $second = $this->guest();
        $this->addBurger($second, 20)->assertCreated();
        $this->assertMatchesSchema('CartClaimResult', $this->postJson('/api/v1/cart/claim', [], [...$this->using($session), ...$second])->assertOk()->assertJsonPath('claim.outcome', 'merged')->assertJsonCount(1, 'claim.adjusted')->json());
        $this->assertMatchesSchema('CartClaimResult', $this->postJson('/api/v1/cart/claim', [], $this->using($session))->assertOk()->assertJsonPath('claim.outcome', 'nothing_to_claim')->json());
        $this->app['auth']->forgetGuards();
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/cart/claim', [], $second)->assertStatus(401)->json());

        // ── Expired, then cleared
        $this->travel(31)->days();
        $expired = $this->getJson('/api/v1/cart', $this->sessionOf(Customer::query()->firstOrFail()))->assertOk()->assertJsonPath('cart.status', 'EXPIRED');
        $this->assertMatchesSchema('CartEnvelope', $expired->json());
        $session = $this->sessionOf(Customer::query()->firstOrFail());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/cart/refresh', [], $session)->assertStatus(410)->assertJsonPath('error.code', 'cart_expired')->json());
        $this->assertMatchesSchema('CartEnvelope', $this->deleteJson('/api/v1/cart', [], $session)->assertOk()->json());

        // ── A restaurant that is no longer visible: the cart keeps its name only
        $third = $this->guest();
        $this->postJson('/api/v1/cart/items', $this->addBody($pizza), $third)->assertCreated();
        $pizzaPoint->forceFill(['status' => 'SUSPENDED'])->save();
        $hidden = $this->getJson('/api/v1/cart', $third)->assertOk()->assertJsonPath('cart.restaurant.visible', false);
        $this->assertMatchesSchema('CartEnvelope', $hidden->json());

        // ── Administrators: numbers
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $this->assertMatchesSchema('CartMetrics', $this->getJson('/api/v1/admin/system/carts')->assertOk()->json());
    }

    public function test_every_cart_route_is_documented_and_only_cart_routes_use_the_cart_schemas(): void
    {
        $paths = $this->openApi()['paths'];
        $documented = [];
        foreach (['/cart', '/cart/items', '/cart/items/{line}', '/cart/refresh', '/cart/switch-restaurant', '/cart/journey', '/cart/claim', '/admin/system/carts'] as $path) {
            $this->assertArrayHasKey($path, $paths);
            foreach (array_keys($paths[$path]) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'v1/cart') || str_contains($r->uri(), 'system/carts'))
            ->flatMap(fn ($r) => array_map(fn (string $m): string => $m.' /'.preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])))->values()->all();
        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(11, $routes);

        // No cart operation takes a cart id, and the token is a header.
        foreach ($documented as $operation) {
            $this->assertStringNotContainsString('{cart', $operation);
        }
        $this->assertSame('header', $this->openApi()['components']['parameters']['CartToken']['in']);
    }
}
