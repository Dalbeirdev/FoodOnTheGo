<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutLegalAcceptance;
use App\Models\CheckoutSession;
use App\Models\PickupSlotReservation;
use App\Models\PromotionReservation;
use App\Models\RestaurantLocation;
use App\Services\Pickup\DatabasePickupCapacityRepository;
use App\Services\Pickup\PickupCapacityRepository;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * Double taps, retries, two devices and two customers (Module 29): a checkout is created once, confirmed once, and
 * the last use of a promotion goes to one customer.
 */
class CheckoutConcurrencyTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_repeated_request_with_the_same_idempotency_key_is_answered_once(): void
    {
        [, $asha] = $this->customerReadyForCheckout();
        $key = ['Idempotency-Key' => 'open-7f3a9c1e-0001-4000-8000-aaaaaaaaaaaa'];

        $first = $this->openCheckout([...$asha, ...$key])->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
        // The retry of a request whose answer was lost: the first answer again (the same JSON; key order is not part of it).
        $again = $this->openCheckout([...$asha, ...$key])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertEquals($first->json(), $again->json());
        $this->assertSame(1, CheckoutSession::query()->count());
        // The key belongs to that request: with another body it is refused, and a malformed key is not a key.
        $this->openCheckout([...$asha, ...$key], ['promotion_code' => 'SAVE100'])->assertConflict()->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->openCheckout([...$asha, 'Idempotency-Key' => 'short'])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

        $draft = $first->json('checkout');
        $confirmKey = ['Idempotency-Key' => 'confirm-7f3a9c1e-0002-4000-8000-bbbbbbbbbbbb'];
        $ready = $this->confirmCheckout([...$asha, ...$confirmKey], $draft)->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $replayed = $this->confirmCheckout([...$asha, ...$confirmKey], $draft)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertEquals($ready->json(), $replayed->json());
        $this->assertSame([1, 3, 2], [PickupSlotReservation::query()->count(), CheckoutLegalAcceptance::query()->count(), (int) $this->sessionModel($draft['id'])->version]);
        // Without a key a double tap is harmless too: nothing is held or recorded twice, nothing is prolonged.
        $third = $this->confirmCheckout($asha, $draft)->assertOk()->json('checkout');
        $this->assertSame([$ready->json('checkout.version'), $ready->json('checkout.expires_at'), $ready->json('checkout.payment.idempotency_key')], [$third['version'], $third['expires_at'], $third['payment']['idempotency_key']]);
        $this->assertSame([1, 3], [PickupSlotReservation::query()->count(), CheckoutLegalAcceptance::query()->count()]);
    }

    public function test_two_devices_of_one_customer_share_one_checkout(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        [$customer, $phone] = $this->customerReadyForCheckout();
        $tablet = $this->sessionOf($customer, 'tablet');

        $onPhone = $this->openCheckout($phone)->assertCreated()->json('checkout');
        $onTablet = $this->openCheckout($tablet)->assertOk()->json('checkout');
        $this->assertSame($onPhone['id'], $onTablet['id']);

        // The tablet applies a code. The phone still shows ₹500 and tries to confirm that.
        $this->postJson('/api/v1/checkouts/'.$onTablet['id'].'/promotion', ['code' => 'SAVE100'], $this->using($tablet))->assertOk();
        $refused = $this->confirmCheckout($phone, $onPhone)->assertConflict();
        $refused->assertJsonPath('error.code', 'checkout_stale')->assertJsonPath('error.details.reason', 'PRICING_CHANGED');
        $this->assertSame('DRAFT', $this->sessionModel($onPhone['id'])->status->value, 'nothing was confirmed for a price the customer did not see');

        // After looking again the phone confirms the price that is true, and the tablet sees the same confirmed checkout.
        $current = $this->showCheckout($phone, $onPhone['id'])->json('checkout');
        $this->assertSame([$refused->json('error.details.pricing_fingerprint'), $this->inr(40000)], [$current['pricing']['fingerprint'], $current['pricing']['total']]);
        $ready = $this->confirmCheckout($phone, $current)->assertOk()->json('checkout');
        $this->assertSame(['READY', $ready['payment']], [$this->openCheckout($tablet)->assertOk()->json('checkout.status'), $this->showCheckout($tablet, $ready['id'])->json('checkout.payment')]);
        $this->assertSame([1, 1], [CheckoutSession::query()->count(), PickupSlotReservation::query()->count()]);

        // A change of the cart on one device ends the checkout on both.
        $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($tablet))->assertCreated();
        $this->showCheckout($phone, $ready['id'])->assertJsonPath('checkout.status', 'STALE')->assertJsonPath('checkout.issues.0.code', 'CART_CHANGED')->assertJsonPath('checkout.issues.0.recover', 'refresh');
        $this->confirmCheckout($phone, $ready)->assertConflict()->assertJsonPath('error.code', 'checkout_stale');
    }

    public function test_a_checkout_opened_from_an_out_of_date_cart_is_refused(): void
    {
        [, $asha] = $this->customerReadyForCheckout();
        $version = (int) $this->cartOf($this->using($asha))->json('cart.version');

        $this->openCheckout($asha, ['cart_version' => $version - 1])->assertConflict()->assertJsonPath('error.code', 'cart_version_conflict')->assertJsonPath('error.details.current_version', $version);
        $this->assertSame(0, CheckoutSession::query()->count());
        $this->openCheckout($asha, ['cart_version' => $version])->assertCreated()->assertJsonPath('checkout.cart_version', $version);
    }

    public function test_the_database_itself_keeps_one_live_checkout_per_cart_and_a_ready_one_confirmed(): void
    {
        [, $asha] = $this->customerReadyForCheckout();
        $id = $this->openCheckout($asha)->assertCreated()->json('checkout.id');
        $row = (array) DB::table('checkout_sessions')->where('public_id', $id)->first();
        unset($row['id']);
        $insert = fn (array $overrides) => DB::transaction(fn () => DB::table('checkout_sessions')->insert([...$row, 'public_id' => (string) Str::uuid7(), ...$overrides]));
        $refused = function (array $overrides, string $constraint) use ($insert): void {
            try {
                $insert($overrides);
                $this->fail('The database stored a row that breaks '.$constraint.'.');
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        };

        // A second live checkout for the same cart, in any live state.
        $refused([], 'checkout_sessions_one_live_per_cart');
        $refused(['status' => 'READY', 'confirmed_at' => now()], 'checkout_sessions_one_live_per_cart');
        $refused(['status' => 'PAYMENT_PENDING', 'confirmed_at' => now()], 'checkout_sessions_one_live_per_cart');
        // Closed ones are history and may be many.
        $insert(['status' => 'STALE']);
        $insert(['status' => 'CANCELLED']);
        $this->assertSame(3, CheckoutSession::query()->count());

        DB::table('checkout_sessions')->where('public_id', $id)->update(['status' => 'CANCELLED']);
        // READY without a confirmation, "no payment" for a payable total, an unknown state, a currency that is not one.
        $refused(['status' => 'READY'], 'checkout_sessions_ready_check');
        $refused(['payment_required' => false], 'checkout_sessions_payment_check');
        $refused(['status' => 'PAID'], 'checkout_sessions_status_check');
        $refused(['currency' => 'inr'], 'checkout_sessions_currency_check');
        $refused(['total_minor' => 1], 'checkout_sessions_total_check');
        $refused(['discount_minor' => (int) $row['subtotal_minor'] + 1, 'total_minor' => 0, 'payment_required' => false], 'checkout_sessions_total_check');
    }

    public function test_the_last_use_of_a_promotion_goes_to_the_customer_who_confirms_first(): void
    {
        $this->promotion(['code' => 'LASTONE', 'usage_limit' => 1]);
        [, $asha] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543210');
        [, $ravi] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        // While nobody has confirmed, both are shown the discount: a draft holds no use.
        $ashaDraft = $this->openCheckout($asha, ['promotion_code' => 'LASTONE'])->assertCreated()->json('checkout');
        $raviDraft = $this->openCheckout($ravi, ['promotion_code' => 'LASTONE'])->assertCreated()->json('checkout');
        $this->assertSame([$this->inr(40000), $this->inr(40000), 0], [$ashaDraft['pricing']['total'], $raviDraft['pricing']['total'], PromotionReservation::query()->count()]);

        $this->confirmCheckout($asha, $ashaDraft)->assertOk()->assertJsonPath('checkout.payment.amount', $this->inr(40000));
        $refused = $this->confirmCheckout($ravi, $raviDraft)->assertConflict();

        $refused->assertJsonPath('error.code', 'checkout_stale')->assertJsonPath('error.details.reason', 'PROMOTION_CHANGED');
        $this->assertSame(1, PromotionReservation::query()->where('status', 'ACTIVE')->count());
        // Ravi sees the new total before he can pay it: full price, and why.
        $next = $this->openCheckout($ravi)->assertCreated()->json('checkout');
        $this->assertSame([$this->inr(50000), null], [$next['pricing']['total'], $next['promotion']]);
        $this->assertSame(['code' => 'PROMOTION_CHANGED', 'promotion_code' => 'LASTONE', 'reason' => 'promotion_limit_reached'], collect($next['changes'])->firstWhere('code', 'PROMOTION_CHANGED'));
    }

    public function test_when_two_confirmations_race_for_the_last_use_the_locked_promotion_decides(): void
    {
        // The race: Asha's confirmation is committed AFTER Ravi's request judged his checkout and BEFORE it takes the
        // use. Her held use is put in at exactly that point — the capacity reservation runs between the two steps.
        $promotion = $this->promotion(['code' => 'LASTONE', 'usage_limit' => 1]);
        $capacity = new class(new DatabasePickupCapacityRepository) implements PickupCapacityRepository
        {
            public ?Closure $meanwhile = null;

            public function __construct(private readonly DatabasePickupCapacityRepository $real) {}

            public function usage(RestaurantLocation $location, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $at = null, ?int $exceptCartId = null): array
            {
                return $this->real->usage($location, $from, $to, $at, $exceptCartId);
            }

            public function reserve(RestaurantLocation $location, CarbonInterface $slotStart, CarbonInterface $slotEnd, ?int $capacity, int $units, string $source, ?string $reference = null, ?CarbonInterface $expiresAt = null): ?PickupSlotReservation
            {
                ($this->meanwhile ?? fn () => null)();

                return $this->real->reserve($location, $slotStart, $slotEnd, $capacity, $units, $source, $reference, $expiresAt);
            }

            public function release(PickupSlotReservation $reservation): void
            {
                $this->real->release($reservation);
            }
        };
        $this->app->instance(PickupCapacityRepository::class, $capacity);
        [$other] = $this->signedIn('+919876543210');
        [, $ravi] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $draft = $this->openCheckout($ravi, ['promotion_code' => 'LASTONE'])->assertCreated()->json('checkout');
        $capacity->meanwhile = fn () => (new PromotionReservation)->forceFill(['promotion_id' => $promotion->getKey(), 'customer_id' => $other->getKey(), 'status' => 'ACTIVE', 'expires_at' => now()->addMinutes(10)])->save();

        $refused = $this->confirmCheckout($ravi, $draft)->assertConflict();

        $refused->assertJsonPath('error.code', 'promotion_limit_reached')->assertJsonPath('error.details.promotion_code', 'LASTONE')->assertJsonPath('error.details.reason', 'PROMOTION_CHANGED');
        // Nothing of the refused confirmation stayed: no READY checkout for ₹400, no hold, no acceptance.
        $session = $this->sessionModel($draft['id']);
        $this->assertSame(['DRAFT', null], [$session->status->value, $session->confirmed_at]);
        $this->assertSame([0, 0, 0], [PickupSlotReservation::query()->count(), CheckoutLegalAcceptance::query()->count(), PromotionReservation::query()->where('checkout_session_id', $session->getKey())->count()]);
    }

    public function test_promotion_codes_cannot_be_guessed_at_speed(): void
    {
        [, $asha] = $this->customerReadyForCheckout();
        $id = $this->openCheckout($asha)->assertCreated()->json('checkout.id');
        $limit = (int) config('rate_limits.promotion_apply');
        $this->assertSame(10, $limit);

        for ($i = 1; $i <= $limit; $i++) {
            $this->postJson('/api/v1/checkouts/'.$id.'/promotion', ['code' => 'GUESS'.$i], $this->using($asha))->assertUnprocessable()->assertJsonPath('error.code', 'promotion_not_found');
        }
        $this->postJson('/api/v1/checkouts/'.$id.'/promotion', ['code' => 'GUESS11'], $this->using($asha))->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        // The limit is the customer's own: somebody else is not slowed down by it.
        [, $ravi] = $this->customerReadyForCheckout(1, ['Size' => ['Regular']], null, '+919876543211');
        $other = $this->openCheckout($ravi)->assertCreated()->json('checkout.id');
        $this->postJson('/api/v1/checkouts/'.$other.'/promotion', ['code' => 'GUESS1'], $this->using($ravi))->assertUnprocessable();
    }
}
