<?php

namespace Tests\Feature\Checkout;

use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use App\Services\Checkout\PickupSlotHoldService;
use App\Services\Pickup\DatabasePickupCapacityRepository;
use App\Services\Pickup\PickupCapacityRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * The pickup time held for a confirmed checkout (Module 29). A selection holds nothing; a CONFIRMED checkout holds
 * one place in its slot until it expires, is cancelled, is replaced or goes stale. PostgreSQL decides who gets the
 * last place.
 */
class CheckoutSlotHoldTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->settings(['capacity_per_slot' => 1]);
    }

    /**
     * A customer with a DRAFT checkout for a slot.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function draftFor(string $phone, ?string $slot = '13:15'): array
    {
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], $slot, $phone);

        return [$headers, $this->openCheckout($headers)->assertCreated()->json('checkout')];
    }

    private function holds(string $status = 'ACTIVE'): int
    {
        return PickupSlotReservation::query()->where('source', PickupSlotReservation::SOURCE_HOLD)->where('status', $status)->count();
    }

    public function test_a_draft_holds_nothing_and_a_confirmed_checkout_holds_one_place_until_it_expires(): void
    {
        [$asha, $draft] = $this->draftFor('+919876543210');
        $this->assertSame(0, PickupSlotReservation::query()->count(), 'opening a checkout is not a hold');
        $this->assertFalse($draft['pickup']['hold']['held']);

        $ready = $this->confirmCheckout($asha, $draft)->assertOk()->json('checkout');

        $hold = PickupSlotReservation::query()->sole();
        $this->assertSame(['HOLD', 'ACTIVE', 1], [$hold->source, $hold->status, (int) $hold->units]);
        $this->assertTrue($hold->slot_starts_at->equalTo($this->localInstant('13:15')));
        $this->assertSame((int) $this->sessionModel($ready['id'])->getKey(), (int) $hold->checkout_session_id);
        // The hold lives exactly as long as the checkout: ten minutes from the confirmation, by the server clock.
        $this->assertTrue($hold->expires_at->equalTo(now()->addMinutes(10)));
        $this->assertSame(['held' => true, 'expires_at' => $ready['expires_at']], $ready['pickup']['hold']);
    }

    public function test_the_last_place_goes_to_the_customer_who_confirms_first(): void
    {
        // Both chose 13:15 and both have a checkout: choosing and opening reserved nothing.
        [$asha, $ashaDraft] = $this->draftFor('+919876543210');
        [$ravi, $raviDraft] = $this->draftFor('+919876543211');

        $this->confirmCheckout($asha, $ashaDraft)->assertOk()->assertJsonPath('checkout.status', 'READY');
        $refused = $this->confirmCheckout($ravi, $raviDraft)->assertConflict();

        $refused->assertJsonPath('error.code', 'checkout_stale')->assertJsonPath('error.details.reason', 'PICKUP_SLOT_FULL')->assertJsonPath('error.details.issues.0.recover', 'pickup');
        $this->assertSame(1, $this->holds(), 'one place, one hold');
        // The refusal is remembered: the checkout is stale in the database, not only in that answer.
        $this->assertSame('STALE', $this->sessionModel($raviDraft['id'])->status->value);

        // Ravi cannot start again with that time …
        $this->openCheckout($ravi)->assertConflict()->assertJsonPath('error.code', 'pickup_slot_full')->assertJsonPath('error.details.issues.0.recover', 'pickup');
        // … and can with another.
        $this->selectSlot($this->using($ravi), '13:30')->assertOk();
        $this->confirmCheckout($ravi, $this->openCheckout($ravi)->assertCreated()->json('checkout'))->assertOk()->assertJsonPath('checkout.pickup.hold.held', true);
        $this->assertSame(2, $this->holds());
    }

    public function test_the_place_a_customer_holds_is_not_taken_away_from_that_customer(): void
    {
        [$asha, $draft] = $this->draftFor('+919876543210');
        $ready = $this->confirmCheckout($asha, $draft)->assertOk()->json('checkout');

        // Asha still sees her own time as hers: her checkout stays READY and her selection valid.
        $this->showCheckout($asha, $ready['id'])->assertOk()->assertJsonPath('checkout.status', 'READY')->assertJsonPath('checkout.issues', []);
        $this->getJson('/api/v1/cart/pickup-selection', $this->using($asha))->assertOk()->assertJsonPath('selection.valid', true);
        $own = collect($this->pickup($this->using($asha))->assertOk()->json('pickup.slots'))->firstWhere('local_starts_at', '2026-10-07T13:15:00+05:30');
        $this->assertTrue($own['available']);

        // Everybody else sees it full — as a state, never as a number.
        [, $ravi] = $this->signedIn('+919876543211');
        $this->addBurger($ravi)->assertCreated();
        $theirs = collect($this->pickup($ravi)->assertOk()->json('pickup.slots'))->firstWhere('local_starts_at', '2026-10-07T13:15:00+05:30');
        $this->assertSame([false, 'FULL'], [$theirs['available'], $theirs['capacity_state']]);
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $theirs['slot_key']], $ravi)->assertConflict()->assertJsonPath('error.code', 'slot_full');
    }

    public function test_a_hold_that_runs_out_frees_the_place_at_that_moment_without_any_cleanup(): void
    {
        [$asha, $draft] = $this->draftFor('+919876543210');
        $ready = $this->confirmCheckout($asha, $draft)->assertOk()->json('checkout');
        [, $ravi] = $this->signedIn('+919876543211');
        $this->addBurger($ravi)->assertCreated();
        $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $this->slotKey($ravi, '13:30')], $ravi)->assertOk();

        // Nine minutes in, the place is still Asha's.
        $this->travel(9)->minutes();
        $this->assertFalse(collect($this->pickup($this->using($ravi))->json('pickup.slots'))->firstWhere('local_starts_at', '2026-10-07T13:15:00+05:30')['available']);

        // At ten minutes it is free: nothing was cleaned up, the hold simply stopped counting.
        $this->travel(1)->minutes();
        $this->assertSame('ACTIVE', PickupSlotReservation::query()->sole()->status, 'no job ran');
        $this->selectSlot($this->using($ravi), '13:15')->assertOk();
        $this->confirmCheckout($ravi, $this->openCheckout($ravi)->assertCreated()->json('checkout'))->assertOk();

        // Asha's checkout has expired; she is told so and cannot use it.
        $this->showCheckout($asha, $ready['id'])->assertOk()->assertJsonPath('checkout.status', 'EXPIRED')->assertJsonPath('checkout.pickup.hold.held', false)->assertJsonPath('checkout.payment', null);
        $this->confirmCheckout($asha, $ready)->assertStatus(410)->assertJsonPath('error.code', 'checkout_expired');
    }

    public function test_cancelling_replacing_or_changing_the_cart_gives_the_place_back_at_once(): void
    {
        [$asha, $draft] = $this->draftFor('+919876543210');
        $ready = $this->confirmCheckout($asha, $draft)->assertOk()->json('checkout');
        $this->assertSame(1, $this->holds());

        // Cancelled by the customer.
        $this->postJson('/api/v1/checkouts/'.$ready['id'].'/cancel', [], $this->using($asha))->assertOk()->assertJsonPath('checkout.status', 'CANCELLED');
        $this->assertSame([0, 1], [$this->holds(), $this->holds('RELEASED')]);

        // Confirmed again, then the cart changes: the hold is released by the change itself, before anybody reads the checkout.
        $again = $this->confirmCheckout($asha, $this->openCheckout($asha)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->assertSame(1, $this->holds());
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($asha))->assertCreated();
        $this->assertSame(0, $this->holds());
        $this->assertSame('STALE', $this->sessionModel($again['id'])->status->value);

        // Confirmed a third time, then another pickup time is chosen: the old slot is free, the new one is not held yet.
        $third = $this->confirmCheckout($asha, $this->openCheckout($asha)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->selectSlot($this->using($asha), '14:00')->assertOk();
        $this->assertSame(0, $this->holds());
        $this->assertSame(['STALE', 'PICKUP_CHANGED'], [$this->sessionModel($third['id'])->status->value, $this->sessionModel($third['id'])->status_reason]);
    }

    public function test_an_asap_checkout_holds_the_slot_its_pickup_time_falls_in(): void
    {
        // 12:00, twenty minutes of preparation: ASAP is 12:20, inside the 12:15 slot.
        [, $asha] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543210');
        $this->confirmCheckout($asha, $this->openCheckout($asha)->assertCreated()->json('checkout'))->assertOk()->assertJsonPath('checkout.pickup.mode', 'ASAP');
        $first = PickupSlotReservation::query()->sole();
        $this->assertTrue($first->slot_starts_at->equalTo($this->localInstant('12:15')));

        // That slot is now full, so the next customer's "as soon as possible" is 12:30 — and that is what they hold.
        [, $ravi] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $ready = $this->confirmCheckout($ravi, $this->openCheckout($ravi)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->assertSame('2026-10-07T12:30:00+05:30', $ready['pickup']['local_pickup_at']);
        $second = PickupSlotReservation::query()->whereKeyNot($first->getKey())->sole();
        $this->assertTrue($second->slot_starts_at->equalTo($this->localInstant('12:30')));
    }

    public function test_when_two_confirmations_race_the_database_gives_the_place_to_one(): void
    {
        // The race: Ravi's request looked at the slot BEFORE Asha's hold was committed, so to his request the slot
        // looked free. Simulated by a capacity repository whose READS can be made blind — its reservation is the real
        // one, with the real lock and the real count.
        $capacity = new class(new DatabasePickupCapacityRepository) implements PickupCapacityRepository
        {
            public bool $blind = false;

            public function __construct(private readonly DatabasePickupCapacityRepository $real) {}

            public function usage(RestaurantLocation $location, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $at = null, ?int $exceptCartId = null): array
            {
                return $this->blind ? [] : $this->real->usage($location, $from, $to, $at, $exceptCartId);
            }

            public function reserve(RestaurantLocation $location, CarbonInterface $slotStart, CarbonInterface $slotEnd, ?int $capacity, int $units, string $source, ?string $reference = null, ?CarbonInterface $expiresAt = null): ?PickupSlotReservation
            {
                return $this->real->reserve($location, $slotStart, $slotEnd, $capacity, $units, $source, $reference, $expiresAt);
            }

            public function release(PickupSlotReservation $reservation): void
            {
                $this->real->release($reservation);
            }
        };
        $this->app->instance(PickupCapacityRepository::class, $capacity);

        [$asha, $ashaDraft] = $this->draftFor('+919876543210');
        [$ravi, $raviDraft] = $this->draftFor('+919876543211');
        $this->confirmCheckout($asha, $ashaDraft)->assertOk();

        $capacity->blind = true;
        $refused = $this->confirmCheckout($ravi, $raviDraft)->assertConflict();

        $refused->assertJsonPath('error.code', 'pickup_slot_unavailable')->assertJsonPath('error.details.issues.0.code', 'PICKUP_SLOT_FULL')->assertJsonPath('error.details.issues.0.recover', 'pickup');
        $this->assertSame(1, $this->holds(), 'the place was given once');
        $this->assertSame((int) $this->sessionModel($ashaDraft['id'])->getKey(), (int) PickupSlotReservation::query()->sole()->checkout_session_id);
        // Nothing of the refused confirmation stayed: no READY checkout, no acceptance, no promotion use.
        $this->assertSame('DRAFT', $this->sessionModel($raviDraft['id'])->status->value);
        $this->assertSame(0, DB::table('checkout_legal_acceptances')->where('checkout_session_id', $this->sessionModel($raviDraft['id'])->getKey())->count());
    }

    public function test_a_hold_waits_for_another_connection_that_is_inside_the_same_slot(): void
    {
        // What makes "count, then insert" one step is the slot's advisory lock. Another connection takes it; the hold
        // of this checkout must WAIT for it (shown by letting the wait time out) and succeeds once it is free.
        [, $draft] = $this->draftFor('+919876543210');
        $session = $this->sessionModel($draft['id']);
        $key = [(int) $this->burgerHub->id, intdiv($this->localInstant('13:15')->getTimestamp(), 60)];
        config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_second');
        $holds = app(PickupSlotHoldService::class);

        try {
            $this->assertTrue((bool) $other->selectOne('select pg_try_advisory_lock(?, ?) as got', $key)->got);
            try {
                DB::transaction(function () use ($holds, $session): void {
                    DB::statement("set local lock_timeout = '300ms'");
                    $holds->hold($session, $this->burgerHub, now()->addMinutes(10));
                });
                $this->fail('The hold did not wait for the slot lock.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('lock timeout', $e->getMessage());
            }
            $this->assertSame(0, $this->holds());

            $other->select('select pg_advisory_unlock(?, ?)', $key);
            $this->assertNotNull(DB::transaction(fn () => $holds->hold($session, $this->burgerHub, now()->addMinutes(10))));
            $this->assertSame(1, $this->holds());
        } finally {
            $other->disconnect();
        }
    }

    public function test_an_order_can_take_over_the_place_the_checkout_held(): void
    {
        // Module 31 will call this; Module 29 proves the hand-over exists and is exact.
        [$asha, $draft] = $this->draftFor('+919876543210');
        $ready = $this->confirmCheckout($asha, $draft)->assertOk()->json('checkout');
        [$ravi, $raviDraft] = $this->draftFor('+919876543211', '13:30');
        $raviReady = $this->confirmCheckout($ravi, $raviDraft)->assertOk()->json('checkout');
        $holds = app(PickupSlotHoldService::class);

        $taken = $holds->consume($this->sessionModel($ready['id']), 'order-reference-1');

        $this->assertSame(['ORDER', 'ACTIVE', 'order-reference-1', null], [$taken->source, $taken->status, $taken->reference, $taken->expires_at]);
        $this->assertSame(2, PickupSlotReservation::query()->count(), 'the same row: the place is never free in between');
        // It no longer runs out with the checkout …
        $this->travel(30)->minutes();
        $slots = collect($this->pickup($this->using($ravi))->json('pickup.slots'))->keyBy('local_starts_at');
        $this->assertFalse($slots['2026-10-07T13:15:00+05:30']['available']);
        // … while a hold nobody took over ran out, and cannot be taken over any more: that order reserves again.
        $this->assertTrue($slots['2026-10-07T13:30:00+05:30']['available']);
        $this->assertNull($holds->consume($this->sessionModel($raviReady['id']), 'order-reference-2'));
    }

    public function test_without_a_capacity_limit_a_hold_is_still_recorded_and_never_refused(): void
    {
        $this->settings(['capacity_per_slot' => null]);
        foreach (['+919876543210', '+919876543211', '+919876543212'] as $phone) {
            [$headers, $draft] = $this->draftFor($phone);
            $this->confirmCheckout($headers, $draft)->assertOk()->assertJsonPath('checkout.pickup.hold.held', true);
        }

        $this->assertSame(3, $this->holds());
    }
}
