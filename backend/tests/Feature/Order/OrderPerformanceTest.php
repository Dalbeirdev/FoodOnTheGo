<?php

namespace Tests\Feature\Order;

use App\Services\Order\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * Orders stay cheap as they grow (Module 31): creation and the restaurant's queue cost a bounded number of queries
 * whatever the cart size or the queue length, every lookup has an index, and the measured figures are written out
 * when ORDER_PERF_REPORT names a file — measured, never assumed.
 */
class OrderPerformanceTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->settings(['capacity_per_slot' => 100]);
        config(['rate_limits.api' => 100000, 'rate_limits.payment' => 100000]);   // thirty orders from one address: the limits are not what is measured
    }

    protected function tearDown(): void
    {
        if (($file = (string) env('ORDER_PERF_REPORT', '')) !== '' && $this->report !== []) {
            $existing = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
            file_put_contents($file, json_encode([...$existing, ...$this->report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int, 2: mixed}
     */
    private function measure(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $result = $work();
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $ms, $result];
    }

    public function test_creating_an_order_costs_the_same_queries_for_a_small_and_a_large_cart(): void
    {
        // Warm the framework with an order that is not measured.
        $this->paidOrder('+919800000001', '13:00');

        $run = function (int $lines, string $phone, string $slot): array {
            [, $headers] = $this->signedIn($phone);
            $this->addBurger($headers, 2, ['Size' => ['Large']])->assertCreated();
            for ($i = 1; $i < $lines; $i++) {
                $this->postJson('/api/v1/cart/items', $this->addBody($this->fries, [], 1, ['special_instructions' => 'line '.$i]), $this->using($headers))->assertCreated();
            }
            $this->selectSlot($headers, $slot)->assertOk();
            $checkout = $this->confirmCheckout($headers, $this->openCheckout($headers)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
            $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
            $paid = $this->providerPays($payment['id']);
            // Verification (the payment request) and creation (the job, synchronous here) run inside one request.
            [$queries, $ms, $response] = $this->measure(fn () => $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk());
            $number = $response->json('payment.fulfillment.order_number');
            $this->assertNotNull($number);
            [$readQueries, $readMs] = $this->measure(fn () => $this->showOrder($headers, $number)->assertOk());

            return ['lines' => $lines, 'create_queries' => $queries, 'create_ms' => $ms, 'read_queries' => $readQueries, 'read_ms' => $readMs];
        };
        $small = $run(1, '+919800000002', '13:15');
        $large = $run(12, '+919800000003', '13:30');
        $this->report['order_creation'] = ['small' => $small, 'large' => $large];

        // Creation writes one row per line and option — that is the only part that grows; reading is constant.
        $this->assertLessThanOrEqual($small['create_queries'] + 2 * 11 + 4, $large['create_queries'], 'creation grows by at most the lines written');
        $this->assertSame($small['read_queries'], $large['read_queries'], 'reading an order is a fixed number of queries');
        $this->assertLessThanOrEqual(12, $small['read_queries']);
    }

    public function test_the_restaurant_queue_costs_the_same_queries_for_two_and_for_thirty_orders(): void
    {
        $this->paidOrder('+919800000001', '13:00');
        $this->paidOrder('+919800000002', '13:15');
        $this->orderStaff();
        [$fewQueries, $fewMs] = $this->measure(fn () => $this->restaurantOrders()->assertOk()->assertJsonCount(2, 'data'));

        for ($i = 3; $i <= 30; $i++) {
            $this->paidOrder(sprintf('+9198000000%02d', $i), $i % 2 === 0 ? '14:00' : '14:15');
        }
        [$manyQueries, $manyMs] = $this->measure(fn () => $this->restaurantOrders('page[size]=50')->assertOk()->assertJsonCount(30, 'data'));
        $this->report['restaurant_queue'] = ['two' => ['queries' => $fewQueries, 'ms' => $fewMs], 'thirty' => ['queries' => $manyQueries, 'ms' => $manyMs]];

        $this->assertLessThanOrEqual($fewQueries + 1, $manyQueries, 'the queue is a fixed number of queries whatever its length (one more at most: a cache or token lookup, never a per-row query)');
        $this->assertLessThanOrEqual(12, $manyQueries);
    }

    /**
     * Module 32: the cost of a pickup verification (QR and manual code), of the completion that follows, of the event
     * stream read and of the channel authorization — queries counted, time measured, never a target.
     */
    public function test_verifying_a_pickup_and_completing_the_order_cost_a_fixed_number_of_queries(): void
    {
        $this->paidOrder('+919800000001', '13:00');   // warm-up, not measured
        [, $headers, $order] = $this->readyOrder('+919800000002', '13:15');
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        [$qrQueries, $qrMs, $qr] = $this->measure(fn () => $this->verifyQr($payload)->assertOk());
        $this->assertSame('COMPLETED', $qr->json('order.status'));
        [, $headers2, $order2] = $this->readyOrder('+919800000003', '13:30');
        $code = $this->credentialOf($headers2, $order2['order_number'])['code'];
        [$codeQueries, $codeMs, $manual] = $this->measure(fn () => $this->verifyCode($order2['order_number'], $code)->assertOk());
        $this->assertSame('COMPLETED', $manual->json('order.status'));
        [$streamQueries, $streamMs] = $this->measure(fn () => $this->customerEvents($headers, $order['order_number'], 2)->assertOk());
        [$authQueries, $authMs] = $this->measure(fn () => $this->postJson('/api/v1/customer/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-customer.order.'.$order['id']], $this->using($headers))->assertOk());
        [$replayQueries, $replayMs] = $this->measure(fn () => $this->verifyQr($payload)->assertStatus(409));
        $this->report['pickup_verification'] = ['qr' => ['queries' => $qrQueries, 'ms' => $qrMs], 'manual_code' => ['queries' => $codeQueries, 'ms' => $codeMs], 'replay_refused' => ['queries' => $replayQueries, 'ms' => $replayMs], 'note' => 'verification + completion in the same request (two transactions), the attempt row and the metrics included'];
        $this->report['event_stream_read'] = ['queries' => $streamQueries, 'ms' => $streamMs];
        $this->report['channel_auth'] = ['queries' => $authQueries, 'ms' => $authMs];
        // No per-line or per-attempt loops: both methods cost about the same and stay small; the stream and the auth are a handful of queries.
        $this->assertLessThanOrEqual(72, $qrQueries);   // measured 49 on 2026-10-08 (the two transactions, the attempt row, the metrics, the answer with the full order); 66 on 2026-10-09 with the Module 33 picked-up and completed in-app notices (two notifications + their delivery rows, written after the commits)
        $this->assertLessThanOrEqual($qrQueries + 15, $codeQueries);   // the manual path first finds the order within the caller's locations
        $this->assertLessThanOrEqual(16, $streamQueries);   // measured 14: the session, the order with its relations, the lines after the cursor
        $this->assertLessThanOrEqual(8, $authQueries);
        $this->assertLessThanOrEqual(15, $replayQueries);
    }

    public function test_every_lookup_of_the_order_domain_has_an_index(): void
    {
        $indexes = fn (string $table): array => collect(DB::select('select indexname from pg_indexes where schemaname = ? and tablename = ?', ['public', $table]))->pluck('indexname')->all();
        foreach ([
            'orders_order_number_unique', 'orders_checkout_session_id_unique', 'orders_checkout_public_id_unique', 'orders_payment_attempt_id_unique',
            'orders_customer_id_placed_at_index', 'orders_location_id_status_pickup_at_index', 'orders_location_id_placed_at_index', 'orders_market_id_placed_at_index', 'orders_status_pickup_at_index', 'orders_refund_status_index', 'orders_payment_public_id_index',
        ] as $name) {
            $this->assertContains($name, $indexes('orders'), $name);
        }
        $this->assertContains('order_items_order_id_position_index', $indexes('order_items'));
        $this->assertContains('order_events_order_id_id_index', $indexes('order_events'));
        $this->assertContains('order_fulfillments_status_next_attempt_at_index', $indexes('order_fulfillments'));
        $this->assertContains('order_pickup_credentials_token_hash_unique', $indexes('order_pickup_credentials'));
        $this->assertContains('order_pickup_credentials_display_code_hash_index', $indexes('order_pickup_credentials'));
    }

    public function test_two_workers_fulfilling_the_same_payment_at_once_create_one_order(): void
    {
        // A race cannot be staged in one PHP process; what can be shown is that a second worker arriving after the
        // first (the lock makes it wait for exactly that) finds the order and creates nothing.
        [, , , $payment] = $this->paidOrder();
        $service = app(OrderFulfillmentService::class);
        $before = DB::table('orders')->count();
        for ($i = 0; $i < 5; $i++) {
            $service->fulfillPayment($payment['id']);
        }
        $this->assertSame($before, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_fulfillments')->count());
        $this->assertSame(1, (int) DB::table('order_fulfillments')->value('attempts'));
    }
}
