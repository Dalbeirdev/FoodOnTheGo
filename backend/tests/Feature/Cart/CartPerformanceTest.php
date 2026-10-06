<?php

namespace Tests\Feature\Cart;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCarts;
use Tests\TestCase;

/**
 * Reading and validating a cart must not cost a query per line, per variant or per modifier, and every lookup the
 * cart domain makes has an index. With CART_PERF_REPORT=<file> the real query counts and timings of this machine
 * are written out as evidence — measurements, not targets.
 */
class CartPerformanceTest extends TestCase
{
    use BuildsCarts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Kolkata'));
        $this->setUpCartWorld();
    }

    public function test_reading_a_cart_costs_the_same_number_of_queries_for_one_line_and_for_twenty(): void
    {
        // Twenty different items, each with a required variant group and a modifier group (two modifiers chosen).
        $items = [];
        foreach (range(1, 20) as $n) {
            $items[] = $this->item($this->mains, 'Dish '.$n, 10000 + $n * 100, $this->standardGroups());
        }
        $guest = $this->guest();
        $add = fn (int $index) => $this->postJson('/api/v1/cart/items', $this->addBody($items[$index], ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese', 'Olives']], 2), $guest)->assertCreated();

        $add(0);
        $this->cartOf($guest); // warm (routes, container)
        [$queriesOne, $msOne] = $this->measure(fn () => $this->cartOf($guest)->assertJsonPath('cart.line_count', 1));

        $addTimes = [];
        foreach (range(1, 19) as $index) {
            $started = hrtime(true);
            $add($index);
            $addTimes[] = (hrtime(true) - $started) / 1e6;
        }
        [$queriesTwenty, $msTwenty] = $this->measure(fn () => $this->cartOf($guest)->assertJsonPath('cart.line_count', 20)->assertJsonPath('cart.validation.status', 'VALID')
            ->assertJsonPath('cart.subtotal_minor', array_sum(array_map(fn (int $n): int => 2 * (10000 + $n * 100 + 5000 + 2000 + 1500), range(1, 20)))));

        $this->assertSame($queriesOne, $queriesTwenty, 'the number of queries must not grow with the number of lines (N+1)');
        $this->assertLessThanOrEqual(30, $queriesTwenty);

        // One more add and one change on the 20-line cart: bounded too.
        [$queriesAdd, $msAdd] = $this->measure(fn () => $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $guest)->assertCreated());
        $line = DB::table('cart_items')->orderBy('id')->value('public_id');
        [$queriesUpdate, $msUpdate] = $this->measure(fn () => $this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 3], $guest)->assertOk());
        [$queriesRefresh, $msRefresh] = $this->measure(fn () => $this->postJson('/api/v1/cart/refresh', [], $guest)->assertOk());
        $this->assertLessThanOrEqual(70, $queriesAdd);
        $this->assertLessThanOrEqual(70, $queriesUpdate);

        if (($report = env('CART_PERF_REPORT')) !== null && $report !== false) {
            sort($addTimes);
            file_put_contents((string) $report, json_encode([
                'module' => 'M27',
                'note' => 'Local machine, PostgreSQL test database inside one transaction, PHP CLI without OPcache, in-process requests (no HTTP server, no network). Timings are measurements, not targets.',
                'cart' => ['lines' => 20, 'selected_options_per_line' => 3],
                'queries' => ['get_cart_1_line' => $queriesOne, 'get_cart_20_lines' => $queriesTwenty, 'add_item_to_20_line_cart' => $queriesAdd, 'update_quantity_on_21_line_cart' => $queriesUpdate, 'refresh_21_line_cart' => $queriesRefresh],
                'timings_ms' => [
                    'get_cart_1_line' => round($msOne, 1), 'get_cart_20_lines_with_validation' => round($msTwenty, 1), 'add_item_median_of_19' => round($addTimes[(int) floor(count($addTimes) / 2)], 1),
                    'add_item_to_20_line_cart' => round($msAdd, 1), 'update_quantity_on_21_line_cart' => round($msUpdate, 1), 'refresh_21_line_cart' => round($msRefresh, 1),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_every_lookup_of_the_cart_domain_has_an_index(): void
    {
        $indexes = collect(DB::select("select tablename, indexname, indexdef from pg_indexes where tablename in ('carts', 'cart_items', 'cart_item_options')"))->keyBy('indexname');

        foreach ([
            'carts_public_id_unique', 'carts_one_active_per_customer', 'carts_one_active_per_guest', 'carts_spent_token_hash_index', 'carts_status_expires_at_index', 'carts_status_closed_at_index',
            'carts_restaurant_location_id_status_index', 'carts_customer_id_status_index', 'carts_guest_token_hash_status_index',
            'cart_items_public_id_unique', 'cart_items_cart_id_signature_unique', 'cart_items_menu_item_id_index',
            'cart_item_options_cart_item_id_option_id_unique', 'cart_item_options_group_id_index', 'cart_item_options_option_id_index',
        ] as $name) {
            $this->assertTrue($indexes->has($name), $name);
        }
        $this->assertStringContainsString("WHERE (((status)::text = 'ACTIVE'::text) AND (customer_id IS NOT NULL))", $indexes['carts_one_active_per_customer']->indexdef);
        $this->assertStringContainsString('UNIQUE', $indexes['carts_one_active_per_guest']->indexdef);

        // Foreign keys: cart → customer / restaurant, line → cart / menu item, option → line / group / option.
        $fks = collect(DB::select("select conrelid::regclass::text as tbl, confrelid::regclass::text as ref, confdeltype from pg_constraint where contype = 'f' and conrelid::regclass::text in ('carts', 'cart_items', 'cart_item_options')"))
            ->map(fn (object $r): string => $r->tbl.'>'.$r->ref.':'.$r->confdeltype)->sort()->values()->all();
        $this->assertSame([
            'cart_item_options>cart_items:c', 'cart_item_options>menu_option_groups:r', 'cart_item_options>menu_options:r',
            'cart_items>carts:c', 'cart_items>menu_items:r', 'carts>customers:c', 'carts>restaurant_locations:r',
        ], $fks, 'c = cascade (a cart takes its lines with it), r = restrict (a menu row or a restaurant is never deleted from under a cart)');

        // The owner's-cart lookups are index lookups (which of the suitable indexes the planner picks on a small table
        // is its own business — the dedicated partial indexes above are what a large table uses).
        DB::statement('set local enable_seqscan = off');
        foreach (["customer_id = 1 and status = 'ACTIVE'", "guest_token_hash = 'x' and customer_id is null and status = 'ACTIVE'", "spent_token_hash = 'x'"] as $lookup) {
            $plan = collect(DB::select('explain select * from carts where '.$lookup))->pluck('QUERY PLAN')->implode(' | ');
            $this->assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap Index Scan/', $plan, $lookup);
            $this->assertStringNotContainsString('Seq Scan', $plan, $lookup);
        }
    }

    /**
     * @return array{0: int, 1: float} queries, milliseconds
     */
    private function measure(callable $request): array
    {
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $request();
        $ms = (hrtime(true) - $started) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $ms];
    }
}
