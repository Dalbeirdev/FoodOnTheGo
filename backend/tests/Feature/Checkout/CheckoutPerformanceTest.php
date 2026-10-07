<?php

namespace Tests\Feature\Checkout;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * What a checkout answer costs, and what the database guarantees (Module 29). Measured, not promised: the number
 * of queries must not grow with the cart or with the number of rules; the timings are written to a report when
 * asked for (CHECKOUT_PERF_REPORT=<file>).
 */
class CheckoutPerformanceTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

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

        return [$queries, $ms, $response];
    }

    public function test_a_checkout_costs_the_same_queries_for_a_small_and_a_large_cart_and_few_and_many_rules(): void
    {
        $this->settings(['capacity_per_slot' => 50]);
        $this->promotion(['code' => 'SAVE100']);
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 250], ['TEST_TAX_B', 'Test tax B', 250]]);
        $this->feeRule();
        // One line. Every line below is configured alike (a variant and a modifier): only their NUMBER differs.
        [, $small] = $this->signedIn('+919876543210');
        $this->addBurger($small, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();
        $this->selectSlot($small, '13:15')->assertOk();
        $warm = $this->openCheckout($small, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout');   // warm (routes, container)
        $this->postJson('/api/v1/checkouts/'.$warm['id'].'/cancel', [], $this->using($small))->assertOk();

        [$openSmall, $msOpenSmall, $opened] = $this->measure(fn () => $this->openCheckout($small, ['promotion_code' => 'SAVE100'])->assertCreated());
        $draft = $opened->json('checkout');
        [$readSmall, $msReadSmall] = $this->measure(fn () => $this->showCheckout($small, $draft['id'])->assertOk()->assertJsonPath('checkout.status', 'DRAFT'));
        [$reuseSmall, $msReuseSmall] = $this->measure(fn () => $this->openCheckout($small)->assertOk());
        [$confirmSmall, $msConfirmSmall, $confirmed] = $this->measure(fn () => $this->confirmCheckout($small, $draft)->assertOk());
        [$readReadySmall, $msReadReadySmall] = $this->measure(fn () => $this->showCheckout($small, $draft['id'])->assertOk()->assertJsonPath('checkout.status', 'READY'));
        [$promoSmall, $msPromoSmall] = $this->measure(fn () => $this->deleteJson('/api/v1/checkouts/'.$draft['id'].'/promotion', [], $this->using($small))->assertOk());

        // Twenty lines, 400 reservations in the coming week, 40 more rules that do not apply (ended, other scope).
        [, $large] = $this->signedIn('+919876543211');
        $this->addBurger($large, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();
        foreach (range(1, 19) as $n) {
            $item = $this->item($this->mains, 'Dish '.$n, 10000 + $n * 100, $this->standardGroups());
            $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']], 1), $this->using($large))->assertCreated();
        }
        $this->selectSlot($this->using($large), '13:30')->assertOk();
        $rows = [];
        foreach (range(0, 399) as $i) {
            $rows[] = ['public_id' => (string) Str::uuid(), 'location_id' => $this->burgerHub->id, 'slot_starts_at' => $this->localInstant('14:00')->addMinutes(15 * ($i % 200))->utc(), 'units' => 1, 'source' => 'FIXTURE', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('pickup_slot_reservations')->insert($rows);
        [$pizza] = $this->otherRestaurant();
        foreach (range(1, 20) as $n) {
            $this->taxRule([['OLD_TAX_'.$n, 'Old test tax', 100]], ['effective_from' => now()->subDays(60 + $n), 'effective_until' => now()->subDays(30)]);
            $this->feeRule(['code' => 'OTHER_FEE_'.$n, 'location_id' => $pizza->getKey()]);
        }

        [$openLarge, $msOpenLarge, $openedLarge] = $this->measure(fn () => $this->openCheckout($large, ['promotion_code' => 'SAVE100'])->assertCreated());
        $draftLarge = $openedLarge->json('checkout');
        $this->assertSame([20, 20], [count($draftLarge['items']), $draftLarge['item_count']]);
        $this->assertCount(2, $draftLarge['pricing']['taxes']);
        $this->assertCount(1, $draftLarge['pricing']['fees']);
        [$readLarge, $msReadLarge] = $this->measure(fn () => $this->showCheckout($large, $draftLarge['id'])->assertOk()->assertJsonPath('checkout.status', 'DRAFT'));
        [$confirmLarge, $msConfirmLarge] = $this->measure(fn () => $this->confirmCheckout($large, $draftLarge)->assertOk());
        [$readReadyLarge, $msReadReadyLarge] = $this->measure(fn () => $this->showCheckout($large, $draftLarge['id'])->assertOk()->assertJsonPath('checkout.status', 'READY'));

        // No N+1: twenty lines cost what one line costs, and rules that do not apply cost nothing.
        $this->assertSame($openSmall, $openLarge, 'opening must not cost more queries for a larger cart');
        $this->assertSame($readSmall, $readLarge, 'reading must not cost more queries for a larger cart');
        $this->assertSame($confirmSmall, $confirmLarge, 'confirming must not cost more queries for a larger cart');
        $this->assertSame($readReadySmall, $readReadyLarge);
        $this->assertLessThanOrEqual(75, $openSmall);
        $this->assertLessThanOrEqual(70, $readSmall);
        $this->assertLessThanOrEqual(95, $confirmSmall);
        $this->assertSame('READY', $confirmed->json('checkout.status'));

        if (($report = env('CHECKOUT_PERF_REPORT')) !== null && $report !== false) {
            file_put_contents((string) $report, json_encode([
                'module' => 'M29',
                'note' => 'Local machine, PostgreSQL test database inside one transaction, PHP CLI without OPcache, in-process requests (no HTTP server, no network). Timings are measurements, not targets. Each request validates the cart and the pickup time again and prices from the current menu and rules; nothing is cached.',
                'queries' => [
                    'open_1_line' => $openSmall, 'open_20_lines_40_other_rules_400_reservations' => $openLarge, 'open_again_reused' => $reuseSmall,
                    'read_draft_1_line' => $readSmall, 'read_draft_20_lines' => $readLarge, 'read_ready_1_line' => $readReadySmall, 'read_ready_20_lines' => $readReadyLarge,
                    'confirm_1_line' => $confirmSmall, 'confirm_20_lines' => $confirmLarge, 'remove_promotion' => $promoSmall,
                ],
                'timings_ms' => [
                    'open_1_line' => round($msOpenSmall, 1), 'open_20_lines' => round($msOpenLarge, 1), 'open_again_reused' => round($msReuseSmall, 1),
                    'read_draft_1_line' => round($msReadSmall, 1), 'read_draft_20_lines' => round($msReadLarge, 1), 'read_ready_1_line' => round($msReadReadySmall, 1), 'read_ready_20_lines' => round($msReadReadyLarge, 1),
                    'confirm_1_line' => round($msConfirmSmall, 1), 'confirm_20_lines' => round($msConfirmLarge, 1), 'remove_promotion' => round($msPromoSmall, 1),
                ],
                'cache' => 'none — every answer is validated and priced again; Redis holds rate limits and event counters only',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_every_lookup_of_the_checkout_domain_has_an_index_and_the_tables_have_their_rules(): void
    {
        $tables = ['checkout_sessions', 'checkout_legal_acceptances', 'promotions', 'promotion_reservations', 'tax_rules', 'tax_rule_components', 'fee_rules'];
        $in = "'".implode("', '", $tables)."'";
        $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename in ({$in}, 'pickup_slot_reservations')"))->keyBy('indexname');
        foreach ([
            'checkout_sessions_public_id_unique', 'checkout_sessions_one_live_per_cart', 'promotions_public_id_unique', 'promotions_code_unique', 'tax_rules_public_id_unique', 'tax_rules_scope_index',
            'fee_rules_public_id_unique', 'fee_rules_scope_index', 'promotion_reservations_checkout_session_id_unique', 'pickup_slot_reservations_one_hold_per_checkout', 'tax_rule_components_tax_rule_id_code_unique',
        ] as $name) {
            $this->assertTrue($indexes->has($name), $name);
        }
        // What the hot paths filter on is indexed: a customer's checkouts, expiry for the clean-up, a promotion's uses.
        $definitions = $indexes->pluck('indexdef')->implode("\n");
        foreach (['checkout_sessions USING btree (customer_id', 'checkout_sessions USING btree (status, expires_at', 'promotion_reservations USING btree (promotion_id', 'checkout_legal_acceptances USING btree (checkout_session_id'] as $needle) {
            $this->assertStringContainsString($needle, $definitions, $needle);
        }

        $checks = collect(DB::select("select conname from pg_constraint where contype = 'c' and conrelid::regclass::text in ({$in})"))->pluck('conname')->sort()->values()->all();
        foreach ([
            'checkout_sessions_total_check', 'checkout_sessions_payment_check', 'checkout_sessions_ready_check', 'checkout_sessions_status_check', 'checkout_sessions_currency_check',
            'promotions_funding_check', 'promotions_limits_check', 'tax_rules_window_check', 'tax_rules_approval_check', 'tax_rule_components_rate_check',
            'fee_rules_code_check', 'fee_rules_value_check', 'fee_rules_bounds_check', 'fee_rules_range_check', 'fee_rules_window_check',
        ] as $check) {
            $this->assertContains($check, $checks);
        }

        // Every amount is a whole number of minor units and every rate a whole number of basis points: there is no
        // floating-point or decimal column anywhere in the money tables.
        $types = collect(DB::select("select table_name, column_name, data_type from information_schema.columns where table_schema = current_schema() and table_name in ({$in})"));
        $this->assertSame([], $types->filter(fn (object $c): bool => in_array($c->data_type, ['real', 'double precision', 'numeric', 'money'], true))->map(fn (object $c): string => $c->table_name.'.'.$c->column_name)->values()->all());
        $money = $types->filter(fn (object $c): bool => str_ends_with($c->column_name, '_minor') || str_ends_with($c->column_name, '_basis_points'));
        $this->assertGreaterThanOrEqual(15, $money->count());
        $this->assertSame([], $money->reject(fn (object $c): bool => in_array($c->data_type, ['bigint', 'integer'], true))->map(fn (object $c): string => $c->table_name.'.'.$c->column_name.':'.$c->data_type)->values()->all());
        // Times are instants with a zone, never a wall-clock guess.
        $this->assertSame([], $types->filter(fn (object $c): bool => $c->data_type === 'timestamp without time zone')->map(fn (object $c): string => $c->table_name.'.'.$c->column_name)->values()->all());
    }

    public function test_run_out_checkouts_are_cleaned_up_on_a_schedule(): void
    {
        [, $asha, $ready] = $this->readyCheckout();
        $this->travel(11)->minutes();

        $this->assertSame(0, Artisan::call('checkouts:prune'));
        $this->assertStringContainsString('Expired 1 checkout(s)', Artisan::output());
        $this->assertSame('EXPIRED', $this->sessionModel($ready['id'])->status->value);
        // Running it again finds nothing: it is safe to repeat.
        Artisan::call('checkouts:prune');
        $this->assertStringContainsString('Expired 0 checkout(s) and 0 pickup hold(s)', Artisan::output());

        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'checkouts:prune'));
        $this->assertNotNull($event, 'the clean-up is scheduled');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertNotNull($asha);
    }
}
