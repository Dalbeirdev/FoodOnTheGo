<?php

namespace Tests\Feature\Order;

use App\Enums\Permission;
use App\Events\Order\OrderEvent;
use App\Models\Order;
use App\Models\OrderPickupCredential;
use App\Models\OrderPickupVerificationAttempt as Attempt;
use App\Models\SecurityEvent;
use App\Services\Order\OrderCompletionService;
use App\Services\Order\OrderMetrics;
use App\Services\Order\OrderStateMachine;
use App\Services\Order\OrderStore;
use App\Services\Order\PickupVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * Secure pickup verification (Module 32): the QR secret and the manual code against the real order, by authorized
 * staff at the order's location, only when READY — consumed exactly once, replay refused, wrong restaurant / location
 * / state refused without a leak, failures counted and locked, every attempt recorded without the secret — and the
 * one transition it makes: READY_FOR_PICKUP → PICKED_UP, then COMPLETED.
 */
class PickupVerificationTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_customer_credential_is_a_high_entropy_opaque_qr_payload_and_a_code_that_name_nothing(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $credential = $this->credentialOf($headers, $order['order_number']);

        $this->assertSame('ACTIVE', $credential['status']);
        $this->assertMatchesRegularExpression('/^FOTG1\.[A-Za-z0-9_-]{43}$/', $credential['qr_payload']);   // 32 random bytes, base64url
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $credential['code']);
        $this->assertSame(1, $credential['format_version']);
        $this->assertNotNull($credential['expires_at']);
        foreach ([$order['order_number'], $order['id'], '98765', 'INR', 'Asha'] as $never) {
            $this->assertStringNotContainsString($never, $credential['qr_payload']);
            $this->assertStringNotContainsString($never, $credential['code']);
        }
        // The same credential on every read (not a new one each time); only the hash is stored next to the ciphertext.
        $this->assertSame($credential['qr_payload'], $this->credentialOf($headers, $order['order_number'])['qr_payload']);
        $row = OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->firstOrFail();
        $this->assertSame(hash('sha256', substr($credential['qr_payload'], 6)), $row->token_hash);
        $this->assertStringNotContainsString(substr($credential['qr_payload'], 6), (string) $row->getRawOriginal('token_secret'));
        $this->assertStringNotContainsString($credential['code'], (string) $row->getRawOriginal('display_code'));
        // Two credentials never share a secret or a code hash (randomness, not a formula).
        [, $headers2, $order2] = $this->readyOrder('+919811122233', '13:15');
        $other = $this->credentialOf($headers2, $order2['order_number']);
        $this->assertNotSame($credential['qr_payload'], $other['qr_payload']);
        $this->assertNotSame($credential['code'], $other['code']);
    }

    public function test_a_valid_qr_scanned_by_authorized_staff_at_the_orders_location_picks_the_order_up_consumes_the_credential_and_completes_it(): void
    {
        [, $headers, $order, $staff] = $this->readyOrder();
        $credential = $this->credentialOf($headers, $order['order_number']);
        $real = Event::getFacadeRoot();
        Event::fake([OrderEvent::class]);

        $answer = $this->verifyQr($credential['qr_payload'])->assertOk()->json();
        $this->assertSame('PICKUP_VERIFIED', $answer['result']);
        $this->assertSame($order['order_number'], $answer['order']['order_number']);
        // Completed right after the pickup (V1: no step in between); both lines are on the timeline.
        $this->assertSame('COMPLETED', $answer['order']['status']);
        $this->assertSame(['VERIFIED', 'QR'], [$answer['verification']['status'], $answer['verification']['method']]);
        $this->assertSame((string) $staff->public_id, $answer['verification']['verified_by']['id']);
        $this->assertNotNull($answer['verification']['verified_at']);
        $this->assertNotNull($answer['verification']['completed_at']);
        $this->assertSame(['ORDER_CREATED', 'RESTAURANT_ACCEPTED', 'PREPARATION_STARTED', 'READY_FOR_PICKUP', 'CUSTOMER_PICKED_UP', 'PICKUP_VERIFIED', 'ORDER_COMPLETED'], array_column($answer['order']['timeline'], 'type'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($answer['order']['timeline'], 'sequence'));

        $model = $this->orderModel($order['order_number']);
        $this->assertNotNull($model->picked_up_at);
        $this->assertNotNull($model->completed_at);
        $this->assertSame(6, (int) $model->version);   // two state changes after READY (4): PICKED_UP, COMPLETED; the PICKUP_VERIFIED line is not a state
        $row = OrderPickupCredential::query()->where('order_id', $model->id)->firstOrFail();
        $this->assertSame(['CONSUMED', 'QR', (int) $staff->id], [$row->status->value, $row->consumed_method, (int) $row->consumed_by_restaurant_user_id]);
        $this->assertNotNull($row->consumed_at);
        $this->assertSame(['SUCCESS'], Attempt::query()->pluck('result')->all());
        // The domain announced both steps after their commits — what the realtime layer and Module 33 listen to.
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::PICKED_UP && $e->orderNumber === $order['order_number']);
        Event::assertDispatched(OrderEvent::class, fn (OrderEvent $e): bool => $e->type === OrderEvent::COMPLETED);
        Event::swap($real);

        // The customer sees the end of the order and no credential any more; the money is untouched.
        $seen = $this->showOrder($headers, $order['order_number'])->assertOk()->json('order');
        $this->assertSame(['COMPLETED', 'PAID', 'NONE', 'DONE'], [$seen['status'], $seen['payment_state'], $seen['refund_status'], $seen['next_action']]);
        $this->assertNull($seen['pickup_credential']);
        $this->assertSame('VERIFIED', $seen['pickup_verification']['status']);
        $this->assertArrayNotHasKey('verified_by', $seen['pickup_verification']);   // the staff member is the restaurant's business
        $this->assertSame('CUSTOMER_PICKED_UP', $seen['timeline'][4]['type']);
        $this->assertNotContains('PICKUP_VERIFIED', array_column($seen['timeline'], 'type'));   // the internal line stays internal
    }

    public function test_the_same_qr_a_second_time_is_a_replay_already_verified_not_a_second_pickup(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        $this->verifyQr($payload)->assertOk();

        $again = $this->verifyQr($payload)->assertStatus(409)->json('error');
        $this->assertSame(PickupVerificationService::CODE_ALREADY_VERIFIED, $again['code']);
        $this->assertSame([$order['order_number'], 'COMPLETED', true], [$again['details']['order_number'], $again['details']['status'], $again['details']['verified_by_you']]);
        $this->assertSame(['SUCCESS', 'ALREADY_USED'], Attempt::query()->orderBy('id')->pluck('result')->all());
        $this->assertSame(1, SecurityEvent::query()->where('event', 'PICKUP_REPLAY_ATTEMPT')->count());
        $this->assertSame(6, (int) $this->orderModel($order['order_number'])->version);   // nothing moved again
    }

    public function test_the_manual_code_with_the_order_number_works_as_the_fallback_and_a_wrong_code_or_order_leaks_nothing(): void
    {
        [, $headers, $order] = $this->readyOrder();
        [, $headers2, $order2] = $this->readyOrder('+919811122233', '13:15');
        $code = $this->credentialOf($headers, $order['order_number'])['code'];
        $code2 = $this->credentialOf($headers2, $order2['order_number'])['code'];

        // The right code against the wrong order: invalid, nothing about the other order.
        $wrongOrder = $this->verifyCode($order2['order_number'], $code)->assertStatus(422)->json('error');
        $this->assertSame(PickupVerificationService::CODE_INVALID, $wrongOrder['code']);
        $this->assertArrayNotHasKey('details', array_filter($wrongOrder, fn ($v) => $v !== [] && $v !== null));
        // A wrong code; an unknown order number: the same answer.
        $this->verifyCode($order['order_number'], 'ZZZZZZ')->assertStatus(422)->assertJsonPath('error.code', PickupVerificationService::CODE_INVALID);
        $this->verifyCode('FOTG-20261007-NOPE00', $code)->assertStatus(422)->assertJsonPath('error.code', PickupVerificationService::CODE_INVALID);
        // Spaces, dashes and lower case are tolerated; the characters themselves are never "corrected".
        $spaced = strtolower(substr($code, 0, 3)).' - '.substr($code, 3);
        $answer = $this->verifyCode($order['order_number'], $spaced)->assertOk()->json();
        $this->assertSame(['PICKUP_VERIFIED', 'MANUAL_CODE'], [$answer['result'], $answer['verification']['method']]);
        $this->assertSame('MANUAL_CODE', OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->value('consumed_method'));
        $this->assertSame(['INVALID_CREDENTIAL', 'INVALID_CREDENTIAL', 'INVALID_CREDENTIAL', 'SUCCESS'], Attempt::query()->orderBy('id')->pluck('result')->all());
        // The second order is still ready with its own code.
        $this->verifyCode($order2['order_number'], $code2)->assertOk();
    }

    public function test_wrong_codes_are_counted_and_lock_the_credential_for_a_while_then_the_right_code_works_again(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $code = $this->credentialOf($headers, $order['order_number'])['code'];
        config(['orders.pickup_credential.lockout_after_failures' => 3, 'orders.pickup_credential.lockout_minutes' => 15]);

        for ($i = 0; $i < 3; $i++) {
            $this->verifyCode($order['order_number'], 'ABCDE'.chr(50 + $i))->assertStatus(422);
        }
        $locked = $this->verifyCode($order['order_number'], $code)->assertStatus(429)->json('error');
        $this->assertSame(PickupVerificationService::CODE_ATTEMPTS, $locked['code']);
        $this->assertGreaterThan(0, $locked['details']['retry_after_seconds']);
        $row = OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->firstOrFail();
        $this->assertSame([3, 'ACTIVE'], [(int) $row->failed_attempts, $row->status->value]);
        $this->assertNotNull($row->locked_until);
        $this->assertSame('LOCKED', Attempt::query()->latest('id')->value('result'));

        $this->travel(16)->minutes();
        $this->verifyCode($order['order_number'], $code)->assertOk();   // the customer is not stranded
    }

    public function test_the_staff_of_another_restaurant_cannot_verify_and_learns_nothing_even_with_a_valid_qr(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        $otherOrganization = $this->organization('Pizza People');
        $otherLocation = $this->eligible('Pizza Point', 28.56, 77.36, organization: $otherOrganization);
        $this->asActor($this->member($otherOrganization, 'OWNER'));

        $refused = $this->verifyQr($payload, (string) $otherLocation->public_id)->assertStatus(422)->json('error');
        $this->assertSame(PickupVerificationService::CODE_INVALID, $refused['code']);
        $this->assertStringNotContainsString($order['order_number'], json_encode($refused));
        $this->assertStringNotContainsString('Burger', json_encode($refused));
        $this->assertSame('READY_FOR_PICKUP', $this->orderModel($order['order_number'])->status->value);
        $this->assertSame('INVALID_CREDENTIAL', Attempt::query()->latest('id')->value('result'));
        // Their location is not even one they can name on our order's scanner context: refused as unknown.
        $this->verifyQr($payload)->assertNotFound();
    }

    public function test_staff_of_the_same_organization_at_another_location_are_refused_until_they_switch_to_the_orders_location(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        $second = $this->eligible('Burger Hub Gurgaon', 28.46, 77.03);
        // A member of the whole organization scanning from the second location's dashboard.
        $this->asActor($this->member($this->organization, 'MANAGER'));

        $refused = $this->verifyQr($payload, (string) $second->public_id)->assertStatus(403)->json('error');
        $this->assertSame(PickupVerificationService::CODE_LOCATION, $refused['code']);
        $this->assertSame((string) $this->burgerHub->public_id, $refused['details']['order_location']);   // their own organization: naming the location is safe
        $this->assertSame('WRONG_LOCATION', Attempt::query()->latest('id')->value('result'));
        $this->assertSame(1, SecurityEvent::query()->where('event', 'PICKUP_LOCATION_UNAUTHORIZED')->count());
        $this->verifyQr($payload)->assertOk();   // switched to Burger Hub

        // Staff restricted to the second location only cannot reach the order at all: invalid, no leak.
        [, $headers3, $order3] = $this->readyOrder('+919811122233', '13:15');
        $payload3 = $this->credentialOf($headers3, $order3['order_number'])['qr_payload'];
        $this->asActor($this->member($this->organization, 'ORDER_STAFF', [$second]));
        $this->verifyQr($payload3, (string) $second->public_id)->assertStatus(422)->assertJsonPath('error.code', PickupVerificationService::CODE_INVALID);
        $this->verifyQr($payload3)->assertNotFound();   // Burger Hub is not theirs to scan from
    }

    public function test_only_staff_with_the_verify_permission_can_verify_and_a_customer_cannot_mark_their_own_pickup(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $credential = $this->credentialOf($headers, $order['order_number']);
        $this->asActor($this->member($this->organization, 'VIEWER'));
        $this->verifyQr($credential['qr_payload'])->assertForbidden();
        $this->asActor($this->member($this->organization, 'MENU_MANAGER'));
        $this->verifyCode($order['order_number'], $credential['code'])->assertForbidden();
        $this->assertSame(0, Attempt::query()->count());   // refused before anything was looked up

        // No customer route marks a pickup; the restaurant route refuses a customer token.
        $this->postJson('/api/v1/restaurant/pickup/verify', ['location' => (string) $this->burgerHub->public_id, 'method' => 'QR', 'payload' => $credential['qr_payload']], $this->using($headers))->assertUnauthorized();
        $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup/verify', ['payload' => $credential['qr_payload']], $this->using($headers))->assertNotFound();
        $this->assertSame('READY_FOR_PICKUP', $this->orderModel($order['order_number'])->status->value);
        $this->assertSame(['restaurant.pickup.verify'], array_values(array_filter(array_map(fn (Permission $p): string => $p->value, Permission::cases()), fn (string $p): bool => str_contains($p, 'pickup.verify'))));
    }

    public function test_an_order_that_is_not_ready_rejected_cancelled_or_completed_cannot_be_picked_up(): void
    {
        [, $headers, , , $order] = $this->paidOrder();
        $staff = $this->orderStaff();
        $credential = $this->credentialOf($headers, $order['order_number']);
        $notReady = $this->verifyQr($credential['qr_payload'])->assertStatus(409)->json('error');
        $this->assertSame([PickupVerificationService::CODE_NOT_READY, 'WAITING_FOR_RESTAURANT'], [$notReady['code'], $notReady['details']['status']]);
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->verifyCode($order['order_number'], $credential['code'])->assertStatus(409)->assertJsonPath('error.code', PickupVerificationService::CODE_NOT_READY);
        $this->assertSame('ACTIVE', OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->value('status')->value);

        // Rejected: the credential was revoked with the order; the refund is required; no pickup.
        [, $headers2, , , $rejected] = $this->paidOrder('+919811122233', '13:15');
        $credential2 = $this->credentialOf($headers2, $rejected['order_number']);
        $this->step($rejected['order_number'], 'reject', ['reason' => 'ITEM_UNAVAILABLE'])->assertOk();
        $refused = $this->verifyQr($credential2['qr_payload'])->assertStatus(409)->json('error');
        $this->assertSame(PickupVerificationService::CODE_REVOKED, $refused['code']);
        $this->assertNull($this->showOrder($headers2, $rejected['order_number'])->json('order.pickup_credential'));
        $this->assertSame('NOT_POSSIBLE', $this->showOrder($headers2, $rejected['order_number'])->json('order.pickup_verification.status'));

        // Cancelled by an administrator: the same.
        [, $headers3, , , $cancelled] = $this->paidOrder('+919822233344', '13:30');
        $credential3 = $this->credentialOf($headers3, $cancelled['order_number']);
        $this->adminFor();
        $this->adminPost('/orders/'.$cancelled['order_number'].'/cancel', ['reason' => 'Customer asked by phone'])->assertOk();
        $this->asActor($staff);
        $this->verifyQr($credential3['qr_payload'])->assertStatus(409)->assertJsonPath('error.code', PickupVerificationService::CODE_REVOKED);
        $this->assertSame(['NOT_READY', 'NOT_READY', 'REVOKED', 'REVOKED'], Attempt::query()->orderBy('id')->pluck('result')->all());
    }

    public function test_an_expired_credential_is_refused_and_so_is_a_rotated_one_while_the_new_one_works(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $old = $this->credentialOf($headers, $order['order_number']);
        // The customer asks for a new code (their own order only; rate-limited): the old QR and code die at once.
        $reissued = $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup-credential/reissue', [], $this->using($headers))->assertCreated()->json('pickup_credential');
        $this->assertNotSame($old['qr_payload'], $reissued['qr_payload']);
        $this->assertNotSame($old['code'], $reissued['code']);
        $this->assertSame(['REVOKED', 'ACTIVE'], OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->orderBy('id')->pluck('status')->map(fn ($s) => $s->value)->all());
        $this->assertSame('ROTATED', OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->orderBy('id')->value('revoke_reason'));
        $this->assertContains('PICKUP_CREDENTIAL_REISSUED', array_column($this->showOrder($headers, $order['order_number'])->json('order.timeline'), 'type'));
        $this->assertSame(1, SecurityEvent::query()->where('event', 'PICKUP_CREDENTIAL_REISSUED')->count());
        $this->verifyQr($old['qr_payload'])->assertStatus(409)->assertJsonPath('error.code', PickupVerificationService::CODE_REVOKED);
        $this->verifyCode($order['order_number'], $old['code'])->assertStatus(422)->assertJsonPath('error.code', PickupVerificationService::CODE_INVALID);   // the old code hash is on a revoked row; the active one does not match

        // Too many rotations in an hour.
        config(['orders.pickup_credential.reissue_per_hour' => 1]);
        $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup-credential/reissue', [], $this->using($headers))->assertStatus(429);

        // Expired: the clock passes the absolute expiry (pickup time + 24 h); the new credential is refused and marked.
        $this->travel(25)->hours();
        $expired = $this->verifyQr($reissued['qr_payload'])->assertStatus(409)->json('error');
        $this->assertSame(PickupVerificationService::CODE_EXPIRED, $expired['code']);
        $this->assertSame('EXPIRED', OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->latest('id')->value('status')->value);
        $this->assertSame('READY_FOR_PICKUP', $this->orderModel($order['order_number'])->status->value);
        // Another customer cannot rotate this order's credential.
        [, $headersOther] = $this->customerReadyForCheckout(phone: '+919811122233');
        $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup-credential/reissue', [], $this->using($headersOther))->assertNotFound();
    }

    public function test_malformed_oversized_or_foreign_qr_payloads_are_invalid_and_nothing_is_consumed(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        foreach ([
            'fotg:pickup:'.substr(hash('sha256', 'x'), 0, 32),   // the Module 31 placeholder format
            $order['order_number'],
            $order['id'],
            '+919876543210',
            'FOTG1.'.Str::random(42),
            'FOTG1.'.Str::random(44),
            'FOTG2.'.substr($payload, 6),
            str_repeat('A', 129),
            '',
            'FOTG1.'.strrev(substr($payload, 6)),   // a wrong secret of the right shape
        ] as $bad) {
            $response = $this->verifyQr($bad);
            $this->assertContains($response->status(), [422], 'payload '.substr($bad, 0, 20));
            $this->assertContains($response->json('error.code'), [PickupVerificationService::CODE_INVALID, 'validation_failed']);
        }
        $this->assertSame('ACTIVE', OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->value('status')->value);
        $this->assertSame(0, Attempt::query()->where('result', 'SUCCESS')->count());
        $this->assertNotContains('SUCCESS', Attempt::query()->pluck('result')->all());
        $this->verifyQr($payload)->assertOk();
    }

    public function test_attempts_per_staff_member_and_per_location_are_limited(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        config(['orders.pickup_credential.attempts_per_staff_minute' => 2, 'orders.pickup_credential.attempts_per_location_minute' => 100]);
        $this->verifyQr('FOTG1.'.Str::random(43))->assertStatus(422);
        $this->verifyQr('FOTG1.'.Str::random(43))->assertStatus(422);
        $this->assertSame(2, RateLimiter::attempts('pickup-verify:user:'.$this->actor->getKey()), 'both attempts counted');
        $limited = $this->verifyQr($payload)->assertStatus(429)->json('error');
        $this->assertSame(PickupVerificationService::CODE_ATTEMPTS, $limited['code']);
        $this->assertSame(1, SecurityEvent::query()->where('event', 'PICKUP_ATTEMPTS_EXCEEDED')->count());
        $this->assertSame('ACTIVE', OrderPickupCredential::query()->where('order_id', $this->orderModel($order['order_number'])->id)->value('status')->value);
        // A colleague is not limited by this staff member's attempts — but the location limit covers everybody.
        config(['orders.pickup_credential.attempts_per_staff_minute' => 100, 'orders.pickup_credential.attempts_per_location_minute' => 2]);   // the location already saw two attempts
        $this->asActor($this->member($this->organization, 'ORDER_STAFF'));
        $this->verifyQr($payload)->assertStatus(429)->assertJsonPath('error.code', PickupVerificationService::CODE_ATTEMPTS);
        $this->travel(61)->seconds();
        $this->verifyQr($payload)->assertOk();
    }

    public function test_a_failure_inside_the_verification_transaction_leaves_the_order_ready_and_the_credential_active(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        // The completion is told to blow up AFTER the pickup commit (its own transaction): the pickup stands.
        $this->app->bind(OrderCompletionService::class, function ($app) {
            return new class($app->make(OrderStore::class), $app->make(OrderStateMachine::class), $app->make(OrderMetrics::class)) extends OrderCompletionService
            {
                public function complete(Order $order, string $trigger = self::TRIGGER_AFTER_PICKUP, ?CarbonImmutable $at = null): Order
                {
                    throw new \RuntimeException('completion down');
                }
            };
        });
        $answer = $this->verifyQr($payload)->assertOk()->json();
        $this->assertSame('PICKED_UP', $answer['order']['status']);   // picked up, not completed
        $model = $this->orderModel($order['order_number']);
        $this->assertSame(['PICKED_UP', 'CONSUMED'], [$model->status->value, OrderPickupCredential::query()->where('order_id', $model->id)->value('status')->value]);
        $this->assertNull($model->completed_at);
        // The replay is still refused: a consumed credential never comes back.
        $this->verifyQr($payload)->assertStatus(409)->assertJsonPath('error.code', PickupVerificationService::CODE_ALREADY_VERIFIED);

        // The sweep completes it later with the real service.
        $this->app->forgetInstance(OrderCompletionService::class);
        $this->app->offsetUnset(OrderCompletionService::class);
        $this->travel(2)->minutes();
        $this->artisan('orders:recover')->assertSuccessful();
        $this->assertSame('COMPLETED', $this->orderModel($order['order_number'])->status->value);
        $this->assertSame(['CUSTOMER_PICKED_UP', 'PICKUP_VERIFIED', 'ORDER_COMPLETED'], array_slice(array_column($this->restaurantOrder($order['order_number'])->json('order.timeline'), 'type'), 4));
        $this->assertSame('SWEEP', $this->restaurantOrder($order['order_number'])->json('order.timeline.6.detail.trigger'));
    }

    public function test_a_failure_before_the_commit_rolls_everything_back_the_order_stays_ready_and_the_credential_active(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        // The database refuses the consumption (here: a model hook standing in for a lost connection / a constraint).
        OrderPickupCredential::saving(function (OrderPickupCredential $c): void {
            if ($c->isDirty('status') && $c->status->value === 'CONSUMED') {
                throw new \RuntimeException('database gone');
            }
        });
        try {
            $this->withoutExceptionHandling()->verifyQr($payload);
            $this->fail('the failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('database gone', $e->getMessage());
        }
        $model = $this->orderModel($order['order_number']);
        $this->assertSame(['READY_FOR_PICKUP', 4], [$model->status->value, (int) $model->version]);
        $row = OrderPickupCredential::query()->where('order_id', $model->id)->firstOrFail();
        $this->assertSame(['ACTIVE', null, null], [$row->status->value, $row->consumed_at, $row->consumed_method]);
        $this->assertSame(4, $model->events()->count());   // no PICKED_UP line survived the rollback
        $this->assertNotContains('SUCCESS', Attempt::query()->pluck('result')->all());

        OrderPickupCredential::flushEventListeners();
        $this->verifyQr($payload)->assertOk()->assertJsonPath('order.status', 'COMPLETED');   // the same QR works once the database is back
    }

    public function test_zero_total_orders_verify_exactly_like_paid_ones(): void
    {
        [, $headers, , $order] = $this->zeroTotalOrder();
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertOk();
        $code = $this->credentialOf($headers, $order['order_number'])['code'];
        $answer = $this->verifyCode($order['order_number'], $code)->assertOk()->json();
        $this->assertSame(['COMPLETED', 'PAYMENT_NOT_REQUIRED', 'NONE'], [$answer['order']['status'], $answer['order']['payment_state'], $answer['order']['refund_status']]);
    }
}
