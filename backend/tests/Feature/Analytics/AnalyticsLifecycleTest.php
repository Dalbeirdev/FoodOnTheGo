<?php

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Services\Analytics\Aggregation\AggregationService;
use App\Services\Analytics\DataQualityService;
use App\Services\Analytics\Pseudonymizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — the life of the read models: a late event and a correction change the past, history is rebuilt from
 * what the domains hold and nothing is invented for what was never recorded, the integrity checks report without
 * repairing, and a failing aggregation harms no transaction. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsLifecycleTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const TABLES = ['analytics_order_facts', 'analytics_session_facts', 'analytics_daily_market', 'analytics_daily_customers', 'analytics_daily_restaurant', 'analytics_daily_items', 'analytics_daily_funnel',
        'analytics_daily_routes', 'analytics_daily_finance', 'analytics_daily_support', 'analytics_daily_breakdowns'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $all = [];
        foreach (self::TABLES as $table) {
            $all[$table] = DB::table($table)->get()->map(function ($row): array {
                $row = (array) $row;
                unset($row['id'], $row['computed_at']);
                ksort($row);

                return $row;
            })->sortBy(fn (array $r): string => (string) json_encode($r))->values()->all();
        }

        return $all;
    }

    public function test_a_late_event_and_a_refund_of_an_old_order_change_the_days_they_belong_to(): void
    {
        $old = $this->completedOrderWith();
        DB::table('orders')->where('id', $old->getKey())->update(['placed_at' => CarbonImmutable::parse('2026-10-02 12:00', 'Asia/Kolkata')->utc(), 'completed_at' => CarbonImmutable::parse('2026-10-02 12:30', 'Asia/Kolkata')->utc()]);
        $this->aggregate('SCHEDULED');
        $routes = fn (string $day): int => (int) DB::table('analytics_daily_routes')->where('local_date', $day)->where('bucket', 'ALL')->value('restaurant_views');
        $restaurant = fn (string $day, string $column): int => (int) DB::table('analytics_daily_restaurant')->where('local_date', $day)->value($column);
        $this->assertSame([1, 0, 0], [$restaurant('2026-10-02', 'orders_completed'), $restaurant('2026-10-02', 'restaurant_views'), $restaurant('2026-10-02', 'cohort_refunded')]);

        // An Android phone comes back online and sends what it buffered two days ago (5 October).
        $this->travel(3)->minutes();
        $then = CarbonImmutable::parse('2026-10-05 18:00', 'Asia/Kolkata')->utc()->format('Y-m-d\TH:i:s\Z');
        $session = (string) Str::uuid();
        $this->sendEvents([$this->event('journey_search_started', [], [], $then), $this->event('journey_results_viewed', ['journey' => (string) Str::uuid()], [], $then),
            $this->event('restaurant_viewed', ['restaurant' => $this->burgerHub->public_id, 'journey' => (string) Str::uuid()], [], $then)], [], $session, 'ANDROID')->assertStatus(202)->assertJsonPath('accepted', 3);
        // …and finance refunds the order of 2 October today.
        $this->asFinance();
        $this->askRefund($old->order_number, ['type' => 'PARTIAL', 'amount_minor' => 10000, 'reason_code' => 'OTHER', 'note' => 'TEST FIXTURE: one item was wrong.'])->assertCreated();
        $refund = $this->refundOf($old->order_number);
        $this->approveAndExecute($refund);
        $this->providerSendsRefund('refund.processed', $this->providerSettlesRefund($refund))->assertOk();
        $this->travel(3)->minutes();
        $run = $this->aggregate('SCHEDULED');

        // The run did not recompute everything — it found the days that changed: the 2nd, the 5th and the most recent ones.
        $this->assertLessThanOrEqual(4, $run['markets'][0]['days']);
        $this->assertSame([1, 1], [$restaurant('2026-10-05', 'restaurant_views'), $routes('2026-10-05')]);
        $this->assertSame(['2026-10-05', 3, 'ANDROID'], array_values((array) DB::table('analytics_session_facts')->where('session_id', $session)->first(['local_date', 'stage', 'platform'])));
        $this->assertSame(1, (int) DB::table('analytics_daily_funnel')->where('local_date', '2026-10-05')->where('stage', 3)->value('sessions'));
        // The order of the 2nd now has a refund — on the 2nd, where it was placed; the money on the day it went back.
        $this->assertSame([1, 0, 10000], [$restaurant('2026-10-02', 'cohort_refunded'), $restaurant('2026-10-02', 'refunded_minor'), $restaurant('2026-10-07', 'refunded_minor')]);
        $this->assertSame([10000, 1], array_map('intval', array_values((array) DB::table('analytics_order_facts')->where('order_id', $old->getKey())->first(['refunded_minor', 'refunds_completed']))));
        // The ledger itself did not move backwards: the refund is a posting of today.
        $this->assertSame(['2026-10-07'], DB::table('analytics_daily_finance')->where('measure', 'REFUNDS')->pluck('local_date')->map(fn ($d): string => substr((string) $d, 0, 10))->all());
        // Running again changes nothing.
        $before = $this->snapshot();
        $this->aggregate('SCHEDULED');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_history_is_rebuilt_from_the_domains_and_nothing_is_invented_for_what_was_never_recorded(): void
    {
        // A month of history made before analytics existed: orders, a refund, a review — and not one behavioural event.
        $first = $this->completedOrderWith();
        foreach (['2026-09-10 13:00', '2026-09-18 19:30', '2026-09-30 21:00'] as $at) {
            $when = CarbonImmutable::parse($at, 'Asia/Kolkata')->utc();
            $this->orderCopy($first, ['placed_at' => $when, 'accepted_at' => $when->addMinute(), 'ready_at' => $when->addMinutes(10), 'picked_up_at' => $when->addMinutes(12), 'completed_at' => $when->addMinutes(12)]);
        }
        $this->refundedCompletedOrder(10000);
        $this->assertSame(0, DB::table('analytics_events')->count());

        // The backfill reconstructs every transaction figure exactly.
        $run = $this->aggregate('BACKFILL');
        $this->assertSame(5, $run['order_facts']);
        $history = $this->snapshot();
        $this->assertSame([5, 5, 50000 * 5], array_map('intval', array_values((array) DB::table('analytics_daily_market')->selectRaw('sum(orders_placed) as p, sum(orders_completed) as c, sum(gmv_minor) as g')->first())));
        $this->assertSame(['2026-09-10', '2026-09-18', '2026-09-30', '2026-10-07'], DB::table('analytics_daily_market')->orderBy('local_date')->pluck('local_date')->map(fn ($d): string => substr((string) $d, 0, 10))->all());
        // Throw every copy away and build them again — also through the command: the same rows.
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
        $this->artisan('analytics:rebuild')->assertSuccessful();
        $this->assertSame($history, $this->snapshot());
        // A range and one group, as a dry run: it says what it would write and writes nothing.
        DB::table('analytics_daily_market')->delete();
        $this->artisan('analytics:rebuild', ['--from' => '2026-09-01', '--to' => '2026-09-30', '--group' => ['orders'], '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('analytics_daily_market')->count());
        $this->artisan('analytics:rebuild', ['--from' => '2026-09-01', '--to' => '2026-10-08', '--group' => ['orders'], '--market' => 'india'])->assertSuccessful();
        $this->assertSame($history['analytics_daily_market'], $this->snapshot()['analytics_daily_market']);
        $this->artisan('analytics:rebuild', ['--from' => '2026-10-09', '--to' => '2026-10-01'])->assertFailed();
        $this->artisan('analytics:rebuild', ['--group' => ['everything']])->assertFailed();

        // Behaviour that was never recorded stays unrecorded: no session, no funnel, no journey demand, no impressions.
        $this->assertSame([0, 0, 0, 0], [DB::table('analytics_events')->count(), DB::table('analytics_session_facts')->count(), DB::table('analytics_daily_funnel')->count(), DB::table('analytics_daily_routes')->count()]);
        $admin = $this->analyst();
        $september = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $funnel = $this->adminAnalytics($admin, '/funnel', $september)->assertOk()->json();
        $this->assertSame([0, 0, true], [$funnel['stages'][0]['sessions'], $funnel['stages'][10]['sessions'], $funnel['metrics']['funnel_overall_conversion']['no_data']]);
        $this->assertStringContainsString('HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38', $funnel['historical_note']);
        $routes = $this->adminAnalytics($admin, '/routes', $september)->assertOk()->json();
        $this->assertSame([0, 0], [$routes['metrics']['journey_searches']['value'], $routes['metrics']['restaurant_impressions']['value']]);
        $this->assertStringContainsString('HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38', $routes['historical_note']);
        // While the orders of that month are all there.
        $this->assertSame(3, $this->adminAnalytics($admin, '/orders', $september)->assertOk()->json('metrics.orders_completed.value'));
        // What was SOLD is known from the order items (two real orders of two burgers each); what was looked at is not.
        $menu = $this->adminAnalytics($admin, '/menu', ['from' => '2026-09-01', 'to' => '2026-10-07'])->assertOk()->json('metrics');
        $this->assertSame([4, 0, 0], [$menu['units_sold']['value'], $menu['menu_views']['value'], $menu['cart_adds']['value']]);
    }

    public function test_the_integrity_checks_report_what_is_wrong_and_repair_nothing(): void
    {
        $order = $this->completedOrderWith();
        [$second] = $this->sessionOrder(true);
        $this->aggregate();
        $quality = app(DataQualityService::class);
        $healthy = $quality->run();
        $this->assertSame(['HEALTHY', 10, 0, 0], [$healthy['status'], $healthy['counts']['healthy'], $healthy['counts']['warning'], $healthy['counts']['error']]);
        $admin = $this->analyst();
        $this->assertSame('HEALTHY', $this->adminAnalytics($admin, '/quality')->assertOk()->json('status'));

        // Somebody breaks the copies: a fact with another total, a fact removed, a negative figure.
        DB::table('analytics_order_facts')->where('order_id', $order->getKey())->update(['total_minor' => 1]);
        DB::table('analytics_order_facts')->where('order_id', $second->getKey())->delete();
        DB::table('analytics_daily_market')->update(['gmv_minor' => -5]);
        // And the sources hold things analytics can only point at: an event without a market, a session out of order,
        // a verified payment that never became an order.
        DB::table('analytics_events')->insert(array_map(fn (int $i): array => ['event_id' => (string) Str::uuid(), 'event_name' => 'journey_search_started', 'schema_version' => 1, 'source' => 'CLIENT', 'platform' => 'WEB', 'occurred_at' => now(), 'received_at' => now(),
            'session_id' => (string) Str::uuid(), 'market_id' => null, 'properties' => '{}'], range(1, 3)));
        DB::table('analytics_session_facts')->update(['out_of_order' => true]);
        [, , , $payment] = $this->paidOrder($this->nextPhone(), '15:00');
        $ordersBefore = DB::table('orders')->orderBy('id')->get()->all();
        DB::table('payment_attempts')->where('public_id', $payment['id'])->update(['fulfillment_status' => 'RECOVERY_REQUIRED', 'verified_at' => now()->subHours(2)]);
        $paymentsBefore = DB::table('payment_attempts')->orderBy('id')->get()->all();
        // Half an hour passes without an aggregation: an order without its fact is no longer "just placed".
        $this->travel(30)->minutes();

        $result = $quality->run();
        $by = array_column($result['checks'], null, 'check');
        $this->assertSame('ERROR', $result['status']);
        $this->assertSame(['ERROR', 1], [$by['order_totals_match']['status'], $by['order_totals_match']['observed']['different_totals']]);
        // Two orders have no fact: the one whose fact was removed, and the one placed since the last aggregation.
        $this->assertSame(['ERROR', 2], [$by['order_facts_cover_orders']['status'], $by['order_facts_cover_orders']['observed']['orders_without_fact']]);
        $this->assertSame('WARNING', $by['aggregation_is_fresh']['status']);
        $this->assertSame('ERROR', $by['no_negative_counts']['status']);
        $this->assertSame(['WARNING', 3], [$by['events_have_a_market']['status'], $by['events_have_a_market']['observed']['without_market']]);
        $this->assertSame('WARNING', $by['funnel_transitions']['status']);
        $this->assertSame(['WARNING', 1], [$by['verified_payments_have_orders']['status'], $by['verified_payments_have_orders']['observed']['verified_payments_without_order']]);
        $this->assertSame(['HEALTHY', 0, true], [$by['event_ids_unique']['status'], $by['event_ids_unique']['observed']['duplicate_event_ids'], $by['event_ids_unique']['observed']['unique_index']]);
        // The checks reported. They touched no order and no payment.
        $this->assertEquals($ordersBefore, DB::table('orders')->orderBy('id')->get()->all());
        $this->assertEquals($paymentsBefore, DB::table('payment_attempts')->orderBy('id')->get()->all());
        $this->artisan('analytics:check')->assertFailed();
        $shown = $this->adminAnalytics($admin, '/quality')->assertOk()->json();
        $this->assertSame(['ERROR', 3, 4, 3], [$shown['status'], $shown['counts']['error'], $shown['counts']['warning'], $shown['counts']['healthy']]);

        // A wrong copy is repaired by building it again from its source — the order's total was never in doubt.
        DB::table('analytics_events')->whereNull('market_id')->delete();
        DB::table('analytics_session_facts')->update(['out_of_order' => false]);
        DB::table('payment_attempts')->where('public_id', $payment['id'])->update(['fulfillment_status' => 'ORDER_CREATED']);
        $this->aggregate('BACKFILL');
        $this->assertSame((int) $order->total_minor, (int) DB::table('analytics_order_facts')->where('order_id', $order->getKey())->value('total_minor'));
        $this->assertSame('HEALTHY', $quality->run()['status']);
        $this->artisan('analytics:check')->assertSuccessful();
    }

    public function test_a_failing_aggregation_harms_no_order_and_the_next_run_catches_up(): void
    {
        $this->completedOrderWith();
        // A read model cannot be written: the aggregation breaks half way through.
        DB::statement('alter table analytics_daily_items rename to analytics_daily_items_gone');
        $broken = app(AggregationService::class)->run('SCHEDULED');
        $this->assertSame(['FAILED', 1, 0], [$broken['status'], DB::table('analytics_runs')->where('status', 'FAILED')->count(), DB::table('analytics_runs')->where('status', 'OK')->count()]);
        // Nothing half-written — a run is all or nothing — and the failure says what it was without a stack of data.
        $this->assertSame(0, DB::table('analytics_order_facts')->count() + DB::table('analytics_daily_market')->count() + DB::table('analytics_daily_restaurant')->count());
        $this->assertNotNull(DB::table('analytics_runs')->where('status', 'FAILED')->value('error'));
        // The business goes on: an order is placed, paid, prepared and completed as on any other day.
        $order = $this->completedOrderWith();
        $this->assertSame('COMPLETED', DB::table('orders')->where('id', $order->getKey())->value('status'));
        $this->assertLedgerBalanced();
        $this->artisan('analytics:aggregate')->assertFailed();

        // The read model is back: the next run sees everything since the last SUCCESSFUL one — both orders.
        DB::statement('alter table analytics_daily_items_gone rename to analytics_daily_items');
        $this->artisan('analytics:aggregate')->assertSuccessful();
        $this->assertSame([2, 2, 4], [DB::table('analytics_order_facts')->count(), (int) DB::table('analytics_daily_market')->value('orders_completed'), (int) DB::table('analytics_daily_items')->sum('units_sold')]);
        // A second run at the same time is not started: one aggregation at a time.
        $lock = Cache::lock('analytics:aggregation', 60);
        $this->assertTrue($lock->get());
        $this->assertSame('SKIPPED', app(AggregationService::class)->run('SCHEDULED')['status']);
        $lock->release();
    }

    public function test_retention_and_forgetting_a_customer_touch_only_behavioural_traces(): void
    {
        [$order, $session] = $this->sessionOrder(true);
        $key = app(Pseudonymizer::class)->customer((int) $order->customer_id);
        // An event far older than the retention period, written before today.
        DB::table('analytics_events')->insert(['event_id' => (string) Str::uuid(), 'event_name' => 'journey_search_started', 'schema_version' => 1, 'source' => 'CLIENT', 'platform' => 'WEB', 'occurred_at' => now()->subDays(400), 'received_at' => now()->subDays(400),
            'session_id' => (string) Str::uuid(), 'market_id' => $this->market->getKey(), 'properties' => '{}']);
        $this->aggregate();
        $daily = DB::table('analytics_daily_funnel')->count();
        $this->assertGreaterThan(0, DB::table('analytics_events')->where('customer_key', $key)->count());

        // The customer's behavioural trace is made anonymous: the events stay as events of nobody.
        $this->artisan('analytics:prune', ['--forget-customer' => $order->customer->public_id])->assertSuccessful();
        $this->assertSame([0, 0], [DB::table('analytics_events')->where('customer_key', $key)->count(), DB::table('analytics_session_facts')->where('customer_key', $key)->count()]);
        $this->assertSame(1, DB::table('analytics_session_facts')->where('session_id', $session)->count());
        // The raw event past its retention is gone; the aggregates built from events stay.
        $this->assertSame(0, DB::table('analytics_events')->where('occurred_at', '<', now()->subDays(180))->count());
        $this->assertSame($daily, DB::table('analytics_daily_funnel')->count());
        // The order, its payment and the ledger are other domains' records: untouched.
        $this->assertSame([1, 1], [DB::table('orders')->where('id', $order->getKey())->count(), DB::table('payment_attempts')->where('status', 'VERIFIED')->count()]);
        $this->assertLedgerBalanced();
        $this->artisan('analytics:prune', ['--forget-customer' => 'nobody'])->assertFailed();

        // The system page: what the layer did, its switches — and no claim it cannot keep.
        $admin = $this->adminWith([Permission::AdminSystemView]);
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($admin);
        $system = $this->getJson('/api/v1/admin/system/analytics')->assertOk()->json();
        $this->sample('admin-system-analytics', $system);
        $this->assertSame([false, 'NOT REQUIRED / NOT IMPLEMENTED', 'NOT USED', 'UNAVAILABLE BEFORE MODULE 38', 'NO', false], [$system['aggregation']['stale'], $system['flags']['warehouse'], $system['flags']['materialized_views'],
            $system['flags']['historical_behavioural_data'], $system['flags']['production'], $system['flags']['advertising_trackers']]);
        $this->assertSame(1, $system['read_models']['order_facts']);
        $this->getJson('/api/v1/admin/analytics/overview')->assertStatus(403);
    }
}
