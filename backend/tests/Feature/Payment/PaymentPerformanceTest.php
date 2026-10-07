<?php

namespace Tests\Feature\Payment;

use App\Models\PaymentAttempt;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * What a payment costs, and what the database guarantees (Module 30). Measured, not promised: the number of queries
 * must not grow with the cart; the timings are written to a report when asked for (PAYMENT_PERF_REPORT=<file>).
 * The fake provider answers in-process, so the provider's own latency is NOT part of these numbers.
 */
class PaymentPerformanceTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /**
     * @return array{0: int, 1: float, 2: TestResponse}
     */
    private function measure(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $request();
        $ms = (hrtime(true) - $started) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, round($ms, 1), $response];
    }

    /**
     * A confirmed checkout with $lines different dishes for a fresh customer.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function readyCheckoutOf(int $lines, string $phone, string $slot): array
    {
        [, $headers] = $this->signedIn($phone);
        $this->addBurger($headers, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();
        foreach (range(1, $lines - 1) as $n) {
            if ($lines === 1) {
                break;
            }
            $item = $this->item($this->mains, 'Dish '.$phone.$n, 10000 + $n * 100, $this->standardGroups());
            $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']], 1), $this->using($headers))->assertCreated();
        }
        $this->selectSlot($this->using($headers), $slot)->assertOk();
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');

        return [$headers, $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout')];
    }

    public function test_a_payment_costs_the_same_queries_for_a_small_and_a_large_cart(): void
    {
        $this->settings(['capacity_per_slot' => 50]);
        // Warm the framework (routes, container) with a payment that is not measured.
        [, $warm, , $warmPayment] = $this->openPayment('+919800000001');
        $this->completePayment($warm, $warmPayment['id'], ['outcome' => 'cancelled'])->assertOk();

        $run = function (int $lines, string $phone, string $slot): array {
            [$headers, $checkout] = $this->readyCheckoutOf($lines, $phone, $slot);
            [$start, $msStart, $started] = $this->measure(fn () => $this->startPayment($headers, $checkout)->assertCreated());
            $payment = $started->json('payment');
            [$again, $msAgain] = $this->measure(fn () => $this->startPayment($headers, $checkout)->assertOk());
            $this->travel(11)->seconds();
            [$read, $msRead] = $this->measure(fn () => $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'PROVIDER_READY'));
            [$readQuiet, $msReadQuiet] = $this->measure(fn () => $this->showPayment($headers, $payment['id'])->assertOk());   // within the interval: no look at the provider
            $paid = $this->providerPays($payment['id']);
            [$complete, $msComplete] = $this->measure(fn () => $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'VERIFIED'));
            [$webhook, $msWebhook] = $this->measure(fn () => $this->providerSends('payment.captured', $paid)->assertOk());
            [$readPaid, $msReadPaid] = $this->measure(fn () => $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED'));

            return [
                'queries' => ['start' => $start, 'start_again' => $again, 'read_open' => $read, 'read_open_within_interval' => $readQuiet, 'complete_verified' => $complete, 'webhook_after_verified' => $webhook, 'read_verified' => $readPaid],
                'ms' => ['start' => $msStart, 'start_again' => $msAgain, 'read_open' => $msRead, 'read_open_within_interval' => $msReadQuiet, 'complete_verified' => $msComplete, 'webhook_after_verified' => $msWebhook, 'read_verified' => $msReadPaid],
            ];
        };
        $small = $run(1, '+919800000002', '13:15');
        $large = $run(20, '+919800000003', '13:30');

        if (($report = env('PAYMENT_PERF_REPORT')) !== null && $report !== false) {
            file_put_contents((string) $report, json_encode([
                'module' => 'M30',
                'note' => 'Local machine, PostgreSQL test database inside one transaction, PHP CLI without OPcache, in-process requests (no HTTP server, no network). The FAKE provider answers in-process: the provider\'s own latency is NOT in these numbers and has not been measured against Razorpay (no credentials). Timings are measurements, not targets.',
                'queries' => ['one_line' => $small['queries'], 'twenty_lines' => $large['queries']],
                'timings_ms' => ['one_line' => $small['ms'], 'twenty_lines' => $large['ms']],
                'cache' => 'none for payments — PostgreSQL is the truth; the cache holds rate limits, a short lock per attempt while the provider is asked, and event counters',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        }

        // No N+1: twenty lines cost what one line costs.
        $this->assertSame($small['queries'], $large['queries'], 'a payment must not cost more queries for a larger cart');
        $this->assertLessThanOrEqual(110, $small['queries']['start']);
        $this->assertLessThanOrEqual(25, $small['queries']['read_open_within_interval']);
        $this->assertLessThanOrEqual(25, $small['queries']['read_verified']);
        $this->assertLessThanOrEqual(110, $small['queries']['complete_verified']);
        $this->assertSame(3, PaymentAttempt::query()->where('status', 'VERIFIED')->count() + 1);

    }

    public function test_every_lookup_of_the_payment_domain_has_an_index_and_the_tables_have_their_rules(): void
    {
        $indexes = fn (string $table): array => collect(DB::select('select indexname, indexdef from pg_indexes where schemaname = ? and tablename = ?', ['public', $table]))->pluck('indexdef', 'indexname')->all();
        $attempts = $indexes('payment_attempts');

        foreach ([
            'payment_attempts_public_id_unique', 'payment_attempts_provider_receipt_unique', 'payment_attempts_checkout_public_id_attempt_number_unique',
            'payment_attempts_checkout_session_id_index', 'payment_attempts_customer_id_created_at_index', 'payment_attempts_market_id_created_at_index', 'payment_attempts_location_id_index',
            'payment_attempts_status_next_reconcile_at_index', 'payment_attempts_fulfillment_status_verified_at_index', 'payment_attempts_created_at_index', 'payment_attempts_verified_at_index',
            'payment_attempts_one_open_per_checkout', 'payment_attempts_one_verified_per_checkout', 'payment_attempts_provider_payment_unique', 'payment_attempts_provider_order_unique',
        ] as $name) {
            $this->assertArrayHasKey($name, $attempts);
        }
        $this->assertStringContainsString("WHERE ((status)::text = 'VERIFIED'::text)", $attempts['payment_attempts_one_verified_per_checkout']);
        $this->assertStringContainsString('UNIQUE', $attempts['payment_attempts_one_open_per_checkout']);
        $this->assertStringContainsString('WHERE (provider_payment_reference IS NOT NULL)', $attempts['payment_attempts_provider_payment_unique']);

        $webhooks = $indexes('payment_webhook_events');
        foreach (['payment_webhook_events_provider_provider_event_reference_unique', 'payment_webhook_events_processing_status_received_at_index', 'payment_webhook_events_provider_order_reference_index', 'payment_webhook_events_provider_payment_reference_index', 'payment_webhook_events_payment_attempt_id_index'] as $name) {
            $this->assertArrayHasKey($name, $webhooks);
        }
        $this->assertArrayHasKey('payment_attempt_events_payment_attempt_id_id_index', $indexes('payment_attempt_events'));

        $checks = collect(DB::select("select conname from pg_constraint where conrelid in ('payment_attempts'::regclass, 'payment_attempt_events'::regclass, 'payment_webhook_events'::regclass) and contype = 'c'"))->pluck('conname')->all();
        foreach (['payment_attempts_status_check', 'payment_attempts_fulfillment_check', 'payment_attempts_mode_check', 'payment_attempts_currency_check', 'payment_attempts_amount_check', 'payment_attempts_number_check', 'payment_attempts_verified_check', 'payment_attempts_fulfillment_state_check', 'payment_attempt_events_source_check', 'payment_webhook_events_status_check'] as $check) {
            $this->assertContains($check, $checks);
        }
        // A payment outlives its checkout, its customer and its restaurant: every link to them is set to null, never cascaded.
        $rules = collect(DB::select("select a.attname as col, c.confdeltype as rule from pg_constraint c join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey) where c.conrelid = 'payment_attempts'::regclass and c.contype = 'f'"))->pluck('rule', 'col')->all();
        $this->assertEquals(['checkout_session_id' => 'n', 'customer_id' => 'n', 'market_id' => 'n', 'location_id' => 'n'], $rules);
    }

    public function test_a_payment_survives_the_cleanup_of_its_checkout_and_cart(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled'])->assertOk();
        $session = $this->sessionModel($checkout['id']);

        // The checkout runs out and its retention passes: housekeeping keeps it, because somebody tried to pay it.
        $this->travel(40)->days();
        $headers = $this->sessionOf($this->attempt($payment['id'])->customer);   // the session of forty days ago has run out
        $this->artisan('checkouts:prune')->assertSuccessful();
        $this->assertDatabaseHas('checkout_sessions', ['id' => $session->getKey()]);
        // Even when the cart — and with it the checkout — is removed, the payment record stays, standing on its own.
        DB::table('carts')->where('id', $session->cart_id)->delete();
        $this->assertDatabaseMissing('checkout_sessions', ['id' => $session->getKey()]);

        $attempt = $this->attempt($payment['id']);
        $this->assertNull($attempt->checkout_session_id);
        $this->assertSame([$checkout['id'], 50000, 'INR', 'Burger Hub', 'CANCELLED'], [$attempt->checkout_public_id, $attempt->amount_minor, $attempt->currency, $attempt->restaurant_name, $attempt->status->value]);
        $this->assertSame(64, strlen($attempt->pricing_fingerprint));
        $this->showPayment($headers, $payment['id'])->assertOk()->assertJsonPath('payment.status', 'CANCELLED')->assertJsonPath('payment.next_action', 'RETURN_TO_CHECKOUT');
    }

    public function test_unsettled_payments_are_looked_at_on_a_schedule(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'payments:reconcile'));

        $this->assertNotNull($event, 'payments:reconcile must be scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->artisan('payments:reconcile')->expectsOutputToContain('Looked at 0 payment attempt(s)')->assertSuccessful();
    }
}
