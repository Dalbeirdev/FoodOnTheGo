<?php

namespace Tests\Feature\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — the customer funnel and the customer figures. Sessions against sessions, every conversion with its
 * numerator and denominator, stages 9–11 from the payment and order domains, and nothing invented for a time before
 * behavioural events were recorded. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsFunnelTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const DAY = ['from' => '2026-10-07', 'to' => '2026-10-07'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_a_hundred_sessions_become_eighteen_completed_orders_and_every_conversion_is_exact(): void
    {
        // Twenty verified payments with their orders (payment and order domains); eighteen of them completed.
        $checkouts = [];
        $customers = [];
        $numbers = [];
        for ($i = 0; $i < 20; $i++) {
            // Twenty customers from one address in one second: the request limits are not what this test is about.
            Cache::flush();
            [$customer, , $checkout, , $order] = $this->paidOrder($this->nextPhone());
            $checkouts[] = $checkout['id'];
            $customers[] = (int) $customer->getKey();
            $numbers[] = $order['order_number'];
        }
        $this->orderStaff();
        foreach (array_slice($numbers, 0, 18) as $number) {
            Cache::flush();
            foreach (['accept', 'start-preparing', 'ready'] as $step) {
                $this->step($number, $step)->assertOk();
            }
            DB::table('orders')->where('order_number', $number)->update(['status' => 'COMPLETED', 'picked_up_at' => now(), 'completed_at' => now()]);
        }
        // The sessions behind them, one per order and customer, and the eighty that stopped earlier.
        foreach ($checkouts as $i => $checkout) {
            $this->sessionsReaching(1, 8, '2026-10-07 10:00', $i < 12 ? 'WEB' : 'ANDROID', [0 => $checkout], $customers[$i]);
        }
        $this->sessionsReaching(5, 8);     // pressed Pay — no verified payment
        $this->sessionsReaching(5, 7);     // opened a checkout
        $this->sessionsReaching(5, 6);     // chose a pickup time
        $this->sessionsReaching(5, 5);     // added to a cart
        $this->sessionsReaching(20, 4);    // saw a menu
        $this->sessionsReaching(20, 3);    // opened a restaurant
        $this->sessionsReaching(10, 2);    // saw the results
        $this->sessionsReaching(10, 1);    // searched
        $this->aggregate();

        $funnel = $this->adminAnalytics($this->analyst(), '/funnel', self::DAY)->assertOk()->json();
        $this->sample('admin-funnel-100', $funnel);
        $sessions = array_column($funnel['stages'], 'sessions', 'stage');
        $this->assertSame([1 => 100, 2 => 90, 3 => 80, 4 => 60, 5 => 40, 6 => 35, 7 => 30, 8 => 25, 9 => 20, 10 => 20, 11 => 18], $sessions);
        $this->assertSame('SESSION', $funnel['unit']);
        // Each conversion: the next stage's sessions over this stage's sessions — and it says both.
        $by = array_column($funnel['stages'], null, 'stage');
        $this->assertNull($by[1]['conversion_from_previous']);
        foreach ([2 => [90, 100, 90.0], 3 => [80, 90, 88.9], 4 => [60, 80, 75.0], 5 => [40, 60, 66.7], 8 => [25, 30, 83.3], 9 => [20, 25, 80.0], 10 => [20, 20, 100.0], 11 => [18, 20, 90.0]] as $stage => [$numerator, $denominator, $percent]) {
            $this->assertEquals([$numerator, $denominator, $percent], [$by[$stage]['conversion_from_previous']['numerator'], $by[$stage]['conversion_from_previous']['denominator'], $by[$stage]['conversion_from_previous']['value']], 'stage '.$stage);
        }
        $this->assertEquals([18, 100, 18.0], [$funnel['metrics']['funnel_overall_conversion']['numerator'], $funnel['metrics']['funnel_overall_conversion']['denominator'], $funnel['metrics']['funnel_overall_conversion']['value']]);
        $this->assertEquals(18.0, $by[11]['conversion_overall']['value']);
        $this->assertSame([10, 10, 20, 20, 5, 5, 5, 5, 0, 2, null], array_column($funnel['stages'], 'did_not_continue'));
        // Abandonment between the named points of the journey.
        $this->assertSame(['journey_to_restaurant' => 20, 'restaurant_to_cart' => 40, 'cart_to_checkout' => 10, 'checkout_to_payment' => 5, 'payment_to_order' => 5], array_column($funnel['abandonment'], 'sessions', 'key'));
        // Signed-in customers are another number in another column: twenty of the hundred sessions had one.
        $this->assertSame([20, 20, 18], [$by[1]['customers'], $by[9]['customers'], $by[11]['customers']]);
        // Events are a third number: here one per stage and session, and none for the stages that are not events.
        $this->assertSame([100, 25, null, null], [$by[1]['events'], $by[8]['events'], $by[9]['events'], $by[11]['events']]);
        // Per platform — and the filter gives the same split.
        $this->assertSame(['ANDROID' => [8, 6], 'WEB' => [92, 12]], array_map(fn (array $p): array => [$p['sessions'], $p['completed']], array_column($funnel['platforms'], null, 'platform')));
        $android = array_column($this->adminAnalytics($this->analyst(), '/funnel', self::DAY + ['platform' => 'ANDROID'])->assertOk()->json('stages'), 'sessions', 'stage');
        $this->assertSame([8, 8, 6], [$android[1], $android[9], $android[11]]);

        // A day without sessions: zeros and "no data" — never a division by zero.
        $empty = $this->adminAnalytics($this->analyst(), '/funnel', ['from' => '2026-09-01', 'to' => '2026-09-01'])->assertOk()->json();
        $this->assertSame([0, null, true], [$empty['stages'][0]['sessions'], $empty['metrics']['funnel_overall_conversion']['value'], $empty['metrics']['funnel_overall_conversion']['no_data']]);
        $this->assertStringContainsString('HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38', $empty['historical_note']);
        $this->assertStringNotContainsString('NaN', json_encode($empty));
    }

    public function test_a_session_counts_only_in_order_inside_its_own_life_and_inside_the_conversion_window(): void
    {
        [$customer, , $checkout] = $this->paidOrder($this->nextPhone());
        $at = CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata')->utc();
        $row = fn (string $session, string $name, CarbonImmutable $when, ?string $ref = null): array => ['event_id' => (string) Str::uuid(), 'event_name' => $name, 'schema_version' => 1, 'source' => 'CLIENT', 'platform' => 'WEB', 'occurred_at' => $when,
            'received_at' => $when, 'session_id' => $session, 'market_id' => $this->market->getKey(), 'checkout_ref' => $ref, 'properties' => '{}'];
        // A: jumped straight to a checkout without the stages before it.
        $a = (string) Str::uuid();
        // B: searched in the morning; the same identifier "views a restaurant" thirteen hours later.
        $b = (string) Str::uuid();
        // C: a complete session whose payment was verified two days after its checkout was opened.
        [$c] = $this->sessionsReaching(1, 8, '2026-10-05 10:00', 'WEB', [0 => $checkout['id']], (int) $customer->getKey());
        DB::table('analytics_events')->insert([
            $row($a, 'journey_search_started', $at), $row($a, 'checkout_started', $at->addMinute(), $checkout['id']), $row($a, 'payment_started', $at->addMinutes(2), $checkout['id']),
            $row($b, 'journey_search_started', $at), $row($b, 'journey_results_viewed', $at->addMinute()), $row($b, 'restaurant_viewed', $at->addHours(13)),
        ]);
        $this->aggregate();

        $facts = DB::table('analytics_session_facts')->get()->keyBy('session_id');
        // A: stopped at the search — and is marked as out of order for the data-quality check.
        $this->assertSame([1, true, null], [$facts[$a]->stage, (bool) $facts[$a]->out_of_order, $facts[$a]->order_id]);
        // B: the late event is not part of the session.
        $this->assertSame([2, 2, false], [$facts[$b]->stage, $facts[$b]->events_count, (bool) $facts[$b]->out_of_order]);
        // C: a payment verified outside the conversion window is not this session's conversion.
        $this->assertSame([8, null], [$facts[$c]->stage, $facts[$c]->payment_verified_at]);
        $this->assertSame('2026-10-05', substr((string) $facts[$c]->local_date, 0, 10));

        // The same session with the payment verified an hour after the checkout: the conversion counts.
        DB::table('payment_attempts')->where('checkout_public_id', $checkout['id'])->update(['verified_at' => CarbonImmutable::parse('2026-10-05 11:00', 'Asia/Kolkata')->utc()]);
        DB::table('analytics_events')->where('session_id', $c)->update(['received_at' => now()->addMinute()]);
        $this->aggregate('SCHEDULED');
        $this->assertSame(10, DB::table('analytics_session_facts')->where('session_id', $c)->value('stage'));
    }

    public function test_customer_figures_are_distinct_people_named_precisely(): void
    {
        // One customer orders on the 5th and on the 7th; two others order once on the 7th; one account never orders.
        $first = $this->completedOrderWith();
        DB::table('orders')->where('id', $first->getKey())->update(['placed_at' => CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata')->utc(), 'completed_at' => CarbonImmutable::parse('2026-10-05 13:00', 'Asia/Kolkata')->utc()]);
        $this->orderCopy($first->refresh(), ['placed_at' => CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Kolkata')->utc(), 'completed_at' => CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata')->utc()]);
        $this->completedOrderWith();
        $this->completedOrderWith();
        $idle = $this->customer($this->market, ['phone_e164' => '+919876549999']);
        DB::table('customers')->where('id', $idle->getKey())->update(['created_at' => CarbonImmutable::parse('2026-10-06 12:00', 'Asia/Kolkata')->utc()]);
        $this->aggregate();
        $admin = $this->analyst();

        $day = $this->adminAnalytics($admin, '/customers', self::DAY)->assertOk()->json();
        $m = $day['metrics'];
        // On the 7th: three people ordered; two for the first time; one is a repeat customer (two completed orders by then).
        $this->assertSame([3, 2, 1, 3], [$m['ordering_customers']['value'], $m['first_time_ordering_customers']['value'], $m['repeat_customers']['value'], $m['active_customers']['value']]);
        $this->assertEquals([33.3, 1, 3], [$m['repeat_order_rate']['value'], $m['repeat_order_rate']['numerator'], $m['repeat_order_rate']['denominator']]);
        $this->assertEquals([1.0, 3, 3], [$m['orders_per_customer']['value'], $m['orders_per_customer']['numerator'], $m['orders_per_customer']['denominator']]);
        $this->assertSame(['FIRST_TIME' => 2, 'RETURNING' => 1], array_column($day['segments'], 'customers', 'key'));
        $this->assertArrayHasKey('lifetime_value', $day['not_reported']);

        // Over the three days: still three people (a person of two days counts once), all three first-time, and the accounts.
        $week = $this->adminAnalytics($admin, '/customers', ['from' => '2026-10-05', 'to' => '2026-10-07'])->assertOk()->json('metrics');
        $this->assertSame([3, 3, 1], [$week['ordering_customers']['value'], $week['first_time_ordering_customers']['value'], $week['repeat_customers']['value']]);
        $this->assertEquals(1.33, $week['orders_per_customer']['value']);
        // New ACCOUNTS are a different thing from first-time ordering customers.
        $this->assertSame(1, $this->adminAnalytics($admin, '/customers', ['from' => '2026-10-06', 'to' => '2026-10-06'])->assertOk()->json('metrics.new_accounts.value'));
        // The cohort table: three customers whose first order was in October.
        $cohort = collect($day['retention']['cohorts'])->firstWhere('cohort', '2026-10');
        $this->assertSame(3, $cohort['customers']);
        // No answer names a customer.
        $this->assertStringNotContainsString('+9198', json_encode([$day, $week]));
    }
}
