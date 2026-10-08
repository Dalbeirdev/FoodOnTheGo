<?php

namespace Tests\Feature\Order;

use App\Enums\CheckoutStatus;
use App\Enums\Permission;
use App\Events\Order\OrderEvent;
use App\Events\Payment\PaymentVerified;
use App\Exceptions\ApiException;
use App\Jobs\Order\FulfillCheckoutJob;
use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use App\Services\Checkout\PickupSlotHoldService;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Pickup\PickupCapacityRepository;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * Payment VERIFIED and order NOT CREATED is a state the system represents, retries, surfaces and lets a person
 * resolve (Module 31). The money is never touched: a verified payment stays verified, whatever happens to the order.
 */
class OrderRecoveryTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /**
     * A verified payment whose order creation has NOT run yet: the event is faked, so nothing listens.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function verifiedWithoutOrder(string $phone = '+919876543210'): array
    {
        $real = Event::getFacadeRoot();
        Event::fake([PaymentVerified::class]);
        [, $headers, $checkout, $payment] = $this->openPayment($phone);
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        $this->assertNull($this->attempt($payment['id'])->order, 'nothing listened: no order yet');
        Event::swap($real);   // from here everything is live again

        return [$headers, $checkout, $payment];
    }

    public function test_a_verified_payment_nobody_turned_into_an_order_is_picked_up_by_the_sweep(): void
    {
        [$headers, $checkout, $payment] = $this->verifiedWithoutOrder();
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.fulfillment.status', 'ORDER_PENDING')->assertJsonPath('payment.fulfillment.order_number', null)
            ->assertJsonPath('payment.message', 'Payment received. We\'re confirming your order.');

        // Too early: the job may still be on its way.
        $this->artisan('orders:recover')->expectsOutputToContain('retried 0, picked up 0')->assertSuccessful();
        $this->travel(61)->seconds();
        $this->artisan('orders:recover')->expectsOutputToContain('retried 0, picked up 1')->assertSuccessful();

        $this->assertSame(1, Order::query()->count());
        $fulfillment = OrderFulfillment::query()->sole();
        $this->assertSame(['SUCCEEDED', 'RECOVERY'], [$fulfillment->status->value, $fulfillment->trigger]);
        $this->assertSame('ORDER_CREATED', $this->attempt($payment['id'])->fulfillment_status->value);
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.fulfillment.status', 'ORDER_CREATED')->assertJsonPath('payment.fulfillment.order_number', Order::query()->sole()->order_number);
        $this->assertSame(CheckoutStatus::Converted, $this->sessionModel($checkout['id'])->status);
        $this->artisan('orders:recover')->expectsOutputToContain('retried 0, picked up 0')->assertSuccessful();
    }

    public function test_a_late_payment_whose_pickup_hold_ran_out_takes_the_slot_again_or_is_handed_to_a_person(): void
    {
        $this->settings(['capacity_per_slot' => 1]);
        [$headers, $checkout, $payment] = $this->verifiedWithoutOrder();
        // The hold runs out (the checkout's expiry) before the order is created.
        $session = $this->sessionModel($checkout['id']);
        PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(PickupSlotHoldService::class)->expireRunOut());
        $this->assertSame(0, PickupSlotReservation::query()->where('status', 'ACTIVE')->count());

        // The slot is still free: the order takes it again — the same pickup time, never silently another one.
        app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        $order = Order::query()->sole();
        $this->assertSame($checkout['pickup']['pickup_at'], $order->pickup_at->utc()->toIso8601String());
        $reservation = PickupSlotReservation::query()->where('reference', $order->public_id)->sole();
        $this->assertSame(['ORDER', 'ACTIVE'], [$reservation->source, $reservation->status]);
        $this->assertNull($reservation->checkout_session_id);

        // Somebody else took the last place meanwhile: RECOVERY REQUIRED — the pickup time is not moved, the money not touched.
        // (ASAP moved this customer to the next free slot, so the place that is taken is THAT slot's.)
        [$h2, $c2, $p2] = $this->verifiedWithoutOrder('+919811122233');
        PickupSlotReservation::query()->where('checkout_session_id', $this->sessionModel($c2['id'])->getKey())->update(['status' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $slotOf = fn (string $checkoutId): string => \Carbon\CarbonImmutable::instance($this->sessionModel($checkoutId)->pickup_slot_starts_at)->setTimezone('Asia/Kolkata')->format('H:i');
        $this->reserve($slotOf($c2['id']));
        $fulfillment = app(OrderFulfillmentService::class)->fulfillPayment($p2['id']);
        $this->assertSame(['REVIEW_REQUIRED', 'ORDER_PICKUP_CAPACITY_UNAVAILABLE', null], [$fulfillment->status->value, $fulfillment->last_error_code, $fulfillment->order_id]);
        $attempt = $this->attempt($p2['id']);
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED', 'ORDER_PICKUP_CAPACITY_UNAVAILABLE'], [$attempt->status->value, $attempt->fulfillment_status->value, $attempt->fulfillment_reason]);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(CheckoutStatus::PaymentPending, $this->sessionModel($c2['id'])->status);
        $this->showPayment($h2, $p2['id'])->assertOk()->assertJsonPath('payment.message', 'Payment received. We could not confirm your order yet — you do not need to pay again.')->assertJsonPath('payment.next_action', 'PROCEED_TO_ORDER');
        // The sweep does not try a REVIEW_REQUIRED fulfilment again by itself.
        $this->travel(2)->minutes();
        $this->artisan('orders:recover')->expectsOutputToContain('retried 0, picked up 0')->assertSuccessful();
    }

    public function test_a_payment_that_does_not_match_its_checkout_never_becomes_an_order(): void
    {
        [, $checkout, $payment] = $this->verifiedWithoutOrder();
        // Something impossible happened: the verified amount is not the checkout's total.
        DB::table('payment_attempts')->where('public_id', $payment['id'])->update(['amount_minor' => 49900]);

        $fulfillment = app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        $this->assertSame(['REVIEW_REQUIRED', 'ORDER_PAYMENT_MISMATCH'], [$fulfillment->status->value, $fulfillment->last_error_code]);
        $this->assertSame(0, Order::query()->count());
        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED', 'ORDER_PAYMENT_MISMATCH'], [$attempt->status->value, $attempt->fulfillment_status->value, $attempt->fulfillment_reason]);

        // A payment that is not verified creates nothing, whoever asks.
        [, $h2, , $p2] = $this->openPayment('+919811122233');
        $this->providerPays($p2['id'], 'failed');
        $this->completePayment($h2, $p2['id'], ['outcome' => 'failed'])->assertOk()->assertJsonPath('payment.status', 'FAILED');
        $unverified = app(OrderFulfillmentService::class)->fulfillPayment($p2['id']);
        $this->assertSame(['REVIEW_REQUIRED', 'ORDER_PAYMENT_NOT_VERIFIED'], [$unverified->status->value, $unverified->last_error_code]);
        $this->assertSame('FAILED', $this->attempt($p2['id'])->status->value);
        $this->assertSame('NOT_APPLICABLE', $this->attempt($p2['id'])->fulfillment_status->value);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame($checkout['id'], (string) $fulfillment->checkout_public_id);
    }

    public function test_a_technical_failure_is_retried_with_a_growing_wait_and_then_handed_to_a_person_without_touching_the_money(): void
    {
        [, , $payment] = $this->verifiedWithoutOrder();
        config(['orders.fulfillment.max_attempts' => 3, 'orders.fulfillment.backoff_seconds' => [30, 60, 300]]);
        // The database refuses to store the order: a capacity repository that throws stands in for it.
        $this->app->instance(PickupCapacityRepository::class, new class implements PickupCapacityRepository
        {
            public function usage(RestaurantLocation $location, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $at = null, ?int $exceptCartId = null): array
            {
                return [];
            }

            public function reserve(RestaurantLocation $location, CarbonInterface $slotStart, CarbonInterface $slotEnd, ?int $capacity, int $units, string $source, ?string $reference = null, ?CarbonInterface $expiresAt = null): ?PickupSlotReservation
            {
                throw new \RuntimeException('database gone');
            }

            public function release(PickupSlotReservation $reservation): void {}
        });
        PickupSlotReservation::query()->update(['status' => 'EXPIRED', 'expires_at' => now()->subMinute()]);   // the hold ran out, so the slot has to be reserved — and that throws

        $first = app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        $this->assertSame(['RETRYING', 1, 'ORDER_CREATION_FAILED'], [$first->status->value, (int) $first->attempts, $first->last_error_code]);
        $this->assertStringContainsString('database gone', (string) $first->last_error_detail);
        $this->assertSame(30, (int) round(now()->diffInSeconds($first->next_attempt_at)));
        $this->assertSame('ORDER_PENDING', $this->attempt($payment['id'])->fulfillment_status->value);   // still waiting, not yet a person's problem

        // Not due yet: the sweep leaves it. Due: tried again, waits longer.
        $this->artisan('orders:recover')->expectsOutputToContain('retried 0, picked up 0')->assertSuccessful();
        $this->travel(31)->seconds();
        $this->artisan('orders:recover')->expectsOutputToContain('retried 1')->assertSuccessful();
        $second = OrderFulfillment::query()->sole();
        $this->assertSame(['RETRYING', 2], [$second->status->value, (int) $second->attempts]);
        $this->assertSame(60, (int) round(now()->diffInSeconds($second->next_attempt_at)));

        // The last try: REVIEW REQUIRED, the payment RECOVERY REQUIRED — still VERIFIED, never FAILED.
        $this->travel(61)->seconds();
        $this->artisan('orders:recover')->expectsOutputToContain('retried 1')->assertSuccessful();
        $third = OrderFulfillment::query()->sole();
        $this->assertSame(['REVIEW_REQUIRED', 3, null], [$third->status->value, (int) $third->attempts, $third->next_attempt_at]);
        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED', 'ORDER_CREATION_FAILED'], [$attempt->status->value, $attempt->fulfillment_status->value, $attempt->fulfillment_reason]);
        $this->assertSame(0, Order::query()->count());
        $this->travel(10)->minutes();
        $this->artisan('orders:recover')->expectsOutputToContain('retried 0, picked up 0')->assertSuccessful();
    }

    public function test_an_administrator_sees_the_paid_checkouts_without_an_order_and_can_ask_for_the_creation_again(): void
    {
        $this->settings(['capacity_per_slot' => 1]);
        [$headers, $checkout, $payment] = $this->verifiedWithoutOrder();
        Event::fake([OrderEvent::class]);
        $session = $this->sessionModel($checkout['id']);
        PickupSlotReservation::query()->where('checkout_session_id', $session->getKey())->update(['status' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $blocker = $this->reserve(\Carbon\CarbonImmutable::instance($session->pickup_slot_starts_at)->setTimezone('Asia/Kolkata')->format('H:i'));   // somebody else's order holds the last place of this checkout's slot
        $blocked = app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        $this->assertSame('REVIEW_REQUIRED', $blocked->status->value);

        $admin = $this->adminFor();
        // The list an operator must never lose sight of.
        $page = $this->adminGet('/order-fulfillments?filter[attention]=1')->assertOk()->json();
        $this->assertCount(1, $page['data']);
        $row = $page['data'][0];
        $this->assertSame(['REVIEW_REQUIRED', 'ORDER_PICKUP_CAPACITY_UNAVAILABLE', true, $payment['id'], $checkout['id'], null], [$row['status'], $row['last_error_code'], $row['needs_attention'], $row['payment_id'], $row['checkout_id'], $row['order_number']]);
        $this->assertSame([$row['id']], array_column($this->adminGet('/order-fulfillments?filter[payment]='.$payment['id'])->assertOk()->json('data'), 'id'));
        // The payment side says the same thing.
        $this->adminGet('/payments/'.$payment['id'])->assertForbidden();   // orders permissions are not payment permissions
        $this->assertSame(1, $this->adminGet('/system/orders')->assertOk()->json('verified_payments_without_order'));
        $this->assertSame(1, $this->adminGet('/system/orders')->json('fulfillment.review_required'));

        // Asking again while the slot is still full: judged again, still blocked — nothing is created by hand.
        $this->adminPost('/order-fulfillments/'.$row['id'].'/recover')->assertStatus(422);   // a reason is required
        $this->adminPost('/order-fulfillments/'.$row['id'].'/recover', ['reason' => 'Customer called', 'order_number' => 'FOTG-20261007-AAAAAA'])->assertStatus(422);
        $again = $this->adminPost('/order-fulfillments/'.$row['id'].'/recover', ['reason' => 'Customer called'])->assertOk()->json('fulfillment');
        $this->assertSame(['REVIEW_REQUIRED', 'ORDER_PICKUP_CAPACITY_UNAVAILABLE', 2], [$again['status'], $again['last_error_code'], $again['attempts']]);
        $this->assertSame(0, Order::query()->count());

        // The place frees up; the administrator asks again: the order is created, from the verified payment, audited.
        app(PickupCapacityRepository::class)->release($blocker);
        $created = $this->adminPost('/order-fulfillments/'.$row['id'].'/recover', ['reason' => 'Slot freed; customer waiting'])->assertOk()->json('fulfillment');
        $this->assertSame(['SUCCEEDED', 'RECOVERY', 3], [$created['status'], $created['trigger'], $created['attempts']]);
        $this->assertNotNull($created['order_number']);
        $this->assertSame('ORDER_CREATED', $this->attempt($payment['id'])->fulfillment_status->value);
        $this->showOrder($headers, $created['order_number'])->assertOk()->assertJsonPath('order.payment.id', $payment['id']);
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.fulfillment.order_number', $created['order_number']);
        $audit = AuditEvent::query()->where('action', 'order.recovery_attempted')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame((string) $admin->public_id, (string) $audit[1]->actor_public_id);
        $this->assertSame('Slot freed; customer waiting', $audit[1]->reason);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::ORDER_CREATED && $e->detail['trigger'] === 'RECOVERY');
        // Done is done: a third request answers 409.
        $this->adminPost('/order-fulfillments/'.$row['id'].'/recover', ['reason' => 'again'])->assertStatus(409)->assertJsonPath('error.code', 'order_already_exists');

        // Without the recover permission: 403. Without any orders permission: 403 on the list too.
        $this->asActor($this->adminWith([Permission::AdminOrdersView]));
        $this->adminGet('/order-fulfillments')->assertOk();
        $this->adminPost('/order-fulfillments/'.$row['id'].'/recover', ['reason' => 'x'])->assertForbidden();
        $this->asActor($this->adminWith([Permission::AdminPaymentsView]));
        $this->adminGet('/order-fulfillments')->assertForbidden();
        $this->adminGet('/orders')->assertForbidden();
    }

    public function test_the_sweep_runs_every_minute_and_the_creation_job_is_queued_after_the_commit(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'orders:recover'));
        $this->assertNotNull($event, 'orders:recover must be scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        $job = new FulfillCheckoutJob('00000000-0000-4000-8000-000000000000');
        $this->assertSame('orders', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(3, $job->tries);
        // A payment that does not exist: the job fails loudly rather than inventing anything.
        $this->assertThrows(fn () => app(OrderFulfillmentService::class)->fulfillPayment('00000000-0000-4000-8000-000000000000'), ApiException::class);
    }
}
