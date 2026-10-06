<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\Journey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * A cart may remember the journey it was started from — as a reference and a small display summary, never the
 * route. It works without a journey, and it outlives one: an expired journey marks the summary stale and leaves
 * the cart alone.
 */
class CartJourneyTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_a_cart_started_from_route_discovery_keeps_the_journey_and_what_the_customer_was_shown(): void
    {
        $journey = $this->planGuest();
        // The customer looked at the restaurants along the route: the detours of this journey are calculated.
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        $shown = collect($this->restaurantsOf($journey['id'], $journey['headers'])->json('data'))->firstWhere('restaurant.id', $this->burgerHub->public_id);
        $this->assertNotNull($shown, 'Burger Hub lies on the standard route');

        $guest = $this->guest();
        $cart = $this->addBurger([...$guest, ...$journey['headers']], 1, ['Size' => ['Regular']], ['journey_id' => $journey['id']])->assertCreated();

        $cart->assertJsonPath('cart.journey.id', $journey['id'])->assertJsonPath('cart.journey.status', 'ACTIVE')->assertJsonPath('cart.journey.requires_refresh', false)
            ->assertJsonPath('cart.journey.origin.label', 'West gate')->assertJsonPath('cart.journey.destination.label', 'East gate')
            ->assertJsonPath('cart.journey.restaurant_on_route', true)->assertJsonPath('cart.journey.estimate_source', 'development');
        $this->assertLessThan(200, $cart->json('cart.journey.distance_from_route_meters'));
        // The detour and the arrival are the ones route discovery showed — the routing provider's figures, not a guess.
        $this->assertSame($shown['route_context']['detour']['seconds'], $cart->json('cart.journey.detour.duration_seconds'));
        $this->assertSame($shown['route_context']['detour']['distance_meters'], $cart->json('cart.journey.detour.distance_meters'));
        $this->assertSame($shown['route_context']['arrival']['estimated_at'], $cart->json('cart.journey.estimated_arrival_at'));
        $this->assertIssueCodes([], $cart);

        // What the cart keeps and returns of the journey: labels and figures — no coordinates, no address, no geometry.
        $stored = json_encode(Cart::query()->firstOrFail()->journey_summary);
        $returned = json_encode($cart->json('cart.journey'));
        foreach (['28.55', '77.3', 'polyline', 'geometry', 'lat', 'lng', 'formatted_address', 'latitude'] as $precise) {
            $this->assertStringNotContainsString($precise, $stored, $precise);
            $this->assertStringNotContainsString($precise, $returned, $precise);
        }
        $this->assertSame($journey['id'], Cart::query()->value('journey_public_id'));
    }

    public function test_a_cart_works_without_a_journey_and_does_not_invent_figures(): void
    {
        $guest = $this->guest();
        $this->addBurger($guest)->assertCreated()->assertJsonPath('cart.journey', null)->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.validation.can_proceed', true);

        // A journey whose detours were never calculated: the link is kept, the detour is "not known" — never estimated.
        $journey = $this->planGuest();
        $linked = $this->putJson('/api/v1/cart/journey', ['journey_id' => $journey['id']], [...$guest, ...$journey['headers']])->assertOk();
        $linked->assertJsonPath('cart.journey.id', $journey['id'])->assertJsonPath('cart.journey.detour', null)->assertJsonPath('cart.journey.estimated_arrival_at', null)
            ->assertJsonPath('cart.journey.restaurant_on_route', true)->assertJsonPath('cart.version', 2);

        // A restaurant far from the route is told apart (and nothing is changed for the customer).
        [$far, $pizza] = $this->otherRestaurant('Far Pizza', 28.595, 77.305);
        $other = $this->guest();
        $offRoute = $this->postJson('/api/v1/cart/items', $this->addBody($pizza, [], 1, ['journey_id' => $journey['id']]), [...$other, ...$journey['headers']])->assertCreated();
        $offRoute->assertJsonPath('cart.journey.restaurant_on_route', false)->assertJsonPath('cart.restaurant.id', $far->public_id);
        $this->assertGreaterThan(3000, $offRoute->json('cart.journey.distance_from_route_meters'));

        // Unlinking is the customer's choice too.
        $this->deleteJson('/api/v1/cart/journey', [], $guest)->assertOk()->assertJsonPath('cart.journey', null)->assertJsonPath('cart.line_count', 1);
    }

    public function test_only_the_callers_own_living_journey_can_be_linked(): void
    {
        $mine = $this->planGuest();
        $theirs = $this->planGuest();
        $guest = $this->guest();

        // Somebody else's journey id without its token, an unknown id, a malformed one: the item is added, nothing is linked.
        foreach ([[$theirs['id'], $guest], ['00000000-0000-4000-8000-000000000000', $guest], [$theirs['id'], [...$guest, ...$mine['headers']]]] as [$journeyId, $headers]) {
            $this->addBurger($headers, 1, ['Size' => ['Regular']], ['journey_id' => $journeyId])->assertCreated()->assertJsonPath('cart.journey', null);
        }
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['journey_id' => 'not-a-journey']), $guest)->assertStatus(422);
        // Asked for on purpose, the same journeys are simply not found.
        $this->putJson('/api/v1/cart/journey', ['journey_id' => $theirs['id']], [...$guest, ...$mine['headers']])->assertNotFound()->assertJsonPath('error.code', 'journey_not_found');
        $this->putJson('/api/v1/cart/journey', ['journey_id' => $theirs['id']], $guest)->assertNotFound();

        // A customer's own journey needs no token.
        [, $session] = $this->signedIn();
        $planned = $this->postJson('/api/v1/journeys/plan', $this->planBody(), $session)->assertCreated()->json('id');
        $this->addBurger($this->using($session), 1, ['Size' => ['Regular']], ['journey_id' => $planned])->assertCreated()->assertJsonPath('cart.journey.id', $planned);
        // …and another customer's journey is not theirs.
        [, $other] = $this->signedIn('+919876543299');
        $this->addBurger($this->using($other), 1, ['Size' => ['Regular']], ['journey_id' => $planned])->assertCreated()->assertJsonPath('cart.journey', null);
    }

    public function test_an_expired_journey_leaves_the_cart_usable_and_marks_the_route_figures_stale(): void
    {
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        $guest = $this->guest();
        $line = $this->addBurger([...$guest, ...$journey['headers']], 2, ['Size' => ['Regular']], ['journey_id' => $journey['id']])->assertCreated()->json('line.id');

        // 31 minutes later the journey has expired (Module 26: 30 minutes).
        $this->travel(31)->minutes();
        $stale = $this->cartOf($guest);
        $stale->assertJsonPath('cart.status', 'ACTIVE')->assertJsonPath('cart.line_count', 1)->assertJsonPath('cart.subtotal_minor', 40000)
            ->assertJsonPath('cart.journey.id', $journey['id'])->assertJsonPath('cart.journey.status', 'STALE')->assertJsonPath('cart.journey.requires_refresh', true)
            ->assertJsonPath('cart.journey.origin.label', 'West gate')
            // Not a reason to stop: the restaurant and the menu are validated on their own.
            ->assertJsonPath('cart.validation.status', 'VALID')->assertJsonPath('cart.validation.can_proceed', true);
        $this->assertIssueCodes(['JOURNEY_STALE'], $stale);
        $this->assertSame('INFO', $stale->json('cart.validation.issues.0.severity'));

        // The cart can still be changed.
        $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 3], $guest)->assertOk()->assertJsonPath('cart.items.0.quantity', 3)->assertJsonPath('cart.journey.status', 'STALE');

        // The journey row is pruned an hour later: the cart still tells the customer where they were going.
        $this->travel(2)->hours();
        $this->artisan('journeys:prune')->assertSuccessful();
        $this->assertSame(0, Journey::query()->count());
        $this->cartOf($guest)->assertJsonPath('cart.journey.status', 'STALE')->assertJsonPath('cart.journey.destination.label', 'East gate')->assertJsonPath('cart.line_count', 1);

        // Planning again and adding from the new journey replaces the dead link.
        $again = $this->planGuest();
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['journey_id' => $again['id']]), [...$guest, ...$again['headers']])->assertCreated()
            ->assertJsonPath('cart.journey.id', $again['id'])->assertJsonPath('cart.journey.status', 'ACTIVE')->assertJsonPath('cart.validation.issues', []);
    }

    public function test_a_new_journey_does_not_replace_a_living_link_until_the_customer_says_so(): void
    {
        $first = $this->planGuest();
        $guest = $this->guest();
        $this->addBurger([...$guest, ...$first['headers']], 1, ['Size' => ['Regular']], ['journey_id' => $first['id']])->assertCreated()->assertJsonPath('cart.journey.id', $first['id']);

        // The customer plans another journey and adds one more item from it: the cart keeps its journey.
        $second = $this->planGuest(['destination' => ['lat' => 28.58, 'lng' => 77.39, 'label' => 'North gate']]);
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['journey_id' => $second['id']]), [...$guest, ...$second['headers']])->assertCreated()
            ->assertJsonPath('cart.journey.id', $first['id'])->assertJsonPath('cart.journey.destination.label', 'East gate')->assertJsonPath('cart.line_count', 2);

        // "Use my current journey" is explicit.
        $this->putJson('/api/v1/cart/journey', ['journey_id' => $second['id']], [...$guest, ...$second['headers']])->assertOk()
            ->assertJsonPath('cart.journey.id', $second['id'])->assertJsonPath('cart.journey.destination.label', 'North gate')->assertJsonPath('cart.restaurant.id', $this->burgerHub->public_id)->assertJsonPath('cart.line_count', 2);
    }

    public function test_the_journey_link_survives_sign_in(): void
    {
        $journey = $this->planGuest();
        $guest = $this->guest();
        $this->addBurger([...$guest, ...$journey['headers']], 1, ['Size' => ['Regular']], ['journey_id' => $journey['id']])->assertCreated();

        // Claimed as it is.
        [, $session] = $this->signedIn();
        $this->postJson('/api/v1/cart/claim', [], [...$session, ...$guest])->assertOk()->assertJsonPath('claim.outcome', 'claimed')->assertJsonPath('cart.journey.id', $journey['id'])->assertJsonPath('cart.journey.status', 'ACTIVE');

        // Merged into an account cart that had no journey: the living link of the guest cart is taken over.
        [, $asha] = $this->signedIn('+919876543212');
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $asha)->assertCreated()->assertJsonPath('cart.journey', null);
        $guest2 = $this->guest();
        $this->addBurger([...$guest2, ...$journey['headers']], 1, ['Size' => ['Regular']], ['journey_id' => $journey['id']])->assertCreated();
        $this->postJson('/api/v1/cart/claim', [], [...$this->using($asha), ...$guest2])->assertOk()->assertJsonPath('claim.outcome', 'merged')->assertJsonPath('cart.journey.id', $journey['id'])->assertJsonPath('cart.line_count', 2);
    }
}
