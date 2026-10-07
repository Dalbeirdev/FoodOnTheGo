<?php

namespace Tests\Feature\Pickup;

use App\Services\Pickup\PickupCapacityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * What a pickup answer costs. Measured, not promised: the number of queries must not grow with the cart, with the
 * number of slots or with the number of reservations; the timings are written to a report when asked for
 * (PICKUP_PERF_REPORT=<file>).
 */
class PickupPerformanceTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPickupWorld('2026-10-07 12:00');
    }

    public function test_an_availability_answer_costs_the_same_queries_for_a_small_and_a_large_cart_few_and_many_slots(): void
    {
        $this->settings(['capacity_per_slot' => 5]);
        // Every line is configured alike (a variant and a modifier), so only the NUMBER of lines differs below.
        $guest = $this->guest();
        $this->addBurger($guest, 1, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']])->assertCreated();
        $this->pickup($guest)->assertOk(); // warm (routes, container)

        // One line, 24 hours of 15-minute slots.
        [$queriesSmall, $msSmall, $small] = $this->measure(fn () => $this->pickup($guest)->assertOk());
        $slotsDay = count($small->json('pickup.slots'));

        // Twenty lines with their own preparation times, and 400 reservations spread over the coming week.
        foreach (range(1, 19) as $n) {
            $item = $this->item($this->mains, 'Dish '.$n, 10000 + $n * 100, $this->standardGroups(), ['preparation_minutes' => 10 + $n]);
            $this->postJson('/api/v1/cart/items', $this->addBody($item, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']], 1), $guest)->assertCreated();
        }
        $rows = [];
        foreach (range(0, 399) as $i) {
            $rows[] = ['public_id' => (string) Str::uuid(), 'location_id' => $this->burgerHub->id, 'slot_starts_at' => $this->localInstant('13:00')->addMinutes(15 * ($i % 200))->utc(), 'units' => 1, 'source' => 'FIXTURE', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('pickup_slot_reservations')->insert($rows);
        [$queriesLarge, $msLarge, $large] = $this->measure(fn () => $this->pickup($guest)->assertOk());
        // Slowest item 29 minutes (never the sum of twenty), plus one step for 20 units: 34.
        $large->assertJsonPath('pickup.preparation.estimated_minutes', 34)->assertJsonPath('pickup.cart_version', 20);

        // A week of 5-minute slots: the answer is bounded, the queries are the same.
        $this->settings(['schedule_horizon_minutes' => 10080, 'slot_interval_minutes' => 5]);
        [$queriesWeek, $msWeek, $week] = $this->measure(fn () => $this->pickup($guest)->assertOk());
        $slotsWeek = count($week->json('pickup.slots'));
        $this->assertSame((int) config('pickup.limits.max_slots'), $slotsWeek);
        $week->assertJsonPath('pickup.scheduled.truncated', true);

        $this->assertSame($queriesSmall, $queriesLarge, 'the number of queries must not grow with the cart or the reservations (N+1)');
        $this->assertSame($queriesSmall, $queriesWeek, 'the number of queries must not grow with the number of slots');
        $this->assertLessThanOrEqual(32, $queriesSmall);

        // Choosing and reading the choice.
        $key = $week->json('pickup.slots.40.slot_key');
        [$queriesSelect, $msSelect] = $this->measure(fn () => $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $key], $guest)->assertOk());
        [$queriesRead, $msRead] = $this->measure(fn () => $this->getJson('/api/v1/cart/pickup-selection', $guest)->assertOk()->assertJsonPath('selection.status', 'SELECTED'));
        [$queriesBoth, $msBoth] = $this->measure(fn () => $this->pickup($guest)->assertOk()->assertJsonPath('pickup.selection.status', 'SELECTED'));
        $this->assertLessThanOrEqual(45, $queriesSelect);
        $this->assertLessThanOrEqual($queriesSmall + 2, $queriesRead);
        // The page's one call (availability + selection) costs no more than availability alone plus the selection row.
        $this->assertLessThanOrEqual($queriesSmall + 1, $queriesBoth);

        // The capacity lookup itself: one aggregated statement, however many reservations there are.
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PickupCapacityRepository::class)->usage($this->burgerHub, now(), now()->addDays(7));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();

        if (($report = env('PICKUP_PERF_REPORT')) !== null && $report !== false) {
            file_put_contents((string) $report, json_encode([
                'module' => 'M28',
                'note' => 'Local machine, PostgreSQL test database inside one transaction, PHP CLI without OPcache, in-process requests (no HTTP server, no network). Timings are measurements, not targets.',
                'queries' => [
                    'availability_1_line_'.$slotsDay.'_slots' => $queriesSmall, 'availability_20_lines_400_reservations' => $queriesLarge, 'availability_week_'.$slotsWeek.'_slots' => $queriesWeek,
                    'select_slot' => $queriesSelect, 'read_selection' => $queriesRead, 'availability_with_selection' => $queriesBoth, 'capacity_lookup' => 1,
                ],
                'timings_ms' => [
                    'availability_1_line_'.$slotsDay.'_slots' => round($msSmall, 1), 'availability_20_lines_400_reservations' => round($msLarge, 1), 'availability_week_'.$slotsWeek.'_slots' => round($msWeek, 1),
                    'select_slot' => round($msSelect, 1), 'read_selection' => round($msRead, 1), 'availability_with_selection' => round($msBoth, 1),
                ],
                'slots' => ['one_day_15_minutes' => $slotsDay, 'one_week_5_minutes_bounded' => $slotsWeek, 'max_slots' => (int) config('pickup.limits.max_slots')],
                'cache' => 'none — every answer is calculated; Redis holds rate limits and event counters only',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_every_lookup_of_the_pickup_domain_has_an_index_and_the_tables_have_their_rules(): void
    {
        $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename in ('cart_pickup_selections', 'pickup_slot_reservations')"))->keyBy('indexname');
        foreach ([
            'cart_pickup_selections_public_id_unique', 'cart_pickup_selections_cart_id_unique', 'cart_pickup_selections_status_expires_at_index', 'cart_pickup_selections_pickup_at_index',
            'pickup_slot_reservations_public_id_unique', 'pickup_slot_reservations_slot_index', 'pickup_slot_reservations_status_expires_at_index',
        ] as $name) {
            $this->assertTrue($indexes->has($name), $name);
        }
        // One selection per cart is a database fact.
        $this->assertStringContainsString('UNIQUE', $indexes['cart_pickup_selections_cart_id_unique']->indexdef);

        $fks = collect(DB::select("select conrelid::regclass::text as tbl, confrelid::regclass::text as ref, confdeltype from pg_constraint where contype = 'f' and conrelid::regclass::text in ('cart_pickup_selections', 'pickup_slot_reservations')"))
            ->map(fn (object $r): string => $r->tbl.'>'.$r->ref.':'.$r->confdeltype)->sort()->values()->all();
        // (Module 29: a hold names its checkout; when the checkout row goes, the reservation stays without it.)
        $this->assertSame(['cart_pickup_selections>carts:c', 'pickup_slot_reservations>checkout_sessions:n', 'pickup_slot_reservations>restaurant_locations:c'], $fks);

        $checks = collect(DB::select("select conname from pg_constraint where contype = 'c' and conrelid::regclass::text in ('cart_pickup_selections', 'pickup_slot_reservations')"))->pluck('conname')->sort()->values()->all();
        $this->assertSame([
            'cart_pickup_selections_method_check', 'cart_pickup_selections_mode_check', 'cart_pickup_selections_slot_check', 'cart_pickup_selections_status_check',
            'pickup_slot_reservations_hold_check', 'pickup_slot_reservations_source_check', 'pickup_slot_reservations_status_check', 'pickup_slot_reservations_units_check',
        ], $checks);

        // The rules hold against direct writes too.
        $guest = $this->guestWithBurger();
        $this->selectSlot($guest, '13:15')->assertOk();
        $cartId = DB::table('carts')->value('id');
        $row = (array) DB::table('cart_pickup_selections')->first();
        unset($row['id']);
        foreach ([
            'a second selection for the same cart' => fn () => DB::table('cart_pickup_selections')->insert([...$row, 'public_id' => (string) Str::uuid()]),
            'an unknown mode' => fn () => DB::table('cart_pickup_selections')->where('cart_id', $cartId)->update(['mode' => 'DELIVERY']),
            'a status that claims a reservation' => fn () => DB::table('cart_pickup_selections')->where('cart_id', $cartId)->update(['status' => 'RESERVED']),
            'a scheduled selection without its slot' => fn () => DB::table('cart_pickup_selections')->where('cart_id', $cartId)->update(['slot_key' => null]),
            'a hold without an end' => fn () => DB::table('pickup_slot_reservations')->insert(['public_id' => (string) Str::uuid(), 'location_id' => $this->burgerHub->id, 'slot_starts_at' => now(), 'units' => 1, 'source' => 'HOLD', 'status' => 'ACTIVE']),
            'zero units' => fn () => DB::table('pickup_slot_reservations')->insert(['public_id' => (string) Str::uuid(), 'location_id' => $this->burgerHub->id, 'slot_starts_at' => now(), 'units' => 0, 'source' => 'FIXTURE', 'status' => 'ACTIVE']),
        ] as $what => $write) {
            try {
                DB::transaction($write);
                $this->fail("The database accepted {$what}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // The capacity lookup is an index lookup.
        DB::statement('set local enable_seqscan = off');
        $plan = collect(DB::select("explain select slot_starts_at, sum(units) from pickup_slot_reservations where location_id = 1 and status = 'ACTIVE' and slot_starts_at >= now() and slot_starts_at < now() + interval '1 day' group by slot_starts_at"))->pluck('QUERY PLAN')->implode(' | ');
        $this->assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap Index Scan/', $plan);
        $this->assertStringNotContainsString('Seq Scan', $plan);
    }

    /**
     * @return array{0: int, 1: float, 2: TestResponse} queries, milliseconds, response
     */
    private function measure(callable $request): array
    {
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $request();
        $ms = (hrtime(true) - $started) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $ms, $response];
    }
}
