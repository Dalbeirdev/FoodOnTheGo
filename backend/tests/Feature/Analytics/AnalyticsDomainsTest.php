<?php

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Events\Pickup\PickupSelectionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — each report reads its own domain: order times from the orders' timestamps, payments from payment
 * attempts (pending is not verified), reviews that are published (a hidden one is in no figure), notifications where
 * "sent" is never "read", support cases with their own times, pickup and capacity only where configured, and route
 * demand as aggregates that hide a handful of travellers. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsDomainsTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const DAY = ['from' => '2026-10-07', 'to' => '2026-10-07'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_order_times_are_the_intervals_between_the_orders_own_timestamps(): void
    {
        $placed = CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Kolkata')->utc();
        $timed = function (int $accept, int $prepare, int $wait) use ($placed): void {
            $order = $this->completedOrderWith();
            DB::table('orders')->where('id', $order->getKey())->update(['placed_at' => $placed, 'accepted_at' => $placed->addSeconds($accept), 'preparing_at' => $placed->addSeconds($accept + 30), 'ready_at' => $placed->addSeconds($accept + $prepare),
                'picked_up_at' => $placed->addSeconds($accept + $prepare + $wait), 'completed_at' => $placed->addSeconds($accept + $prepare + $wait), 'estimated_prep_minutes' => 15]);
        };
        $timed(60, 600, 120);      // accepted after 1 min, ready 10 min later, collected 2 min after that
        $timed(120, 900, 240);
        $timed(300, 1800, 600);    // a slow one: ready after 30 min against an estimate of 15
        // One order nobody has answered yet, and one the restaurant rejected.
        $this->paidOrder($this->nextPhone(), '15:00');
        [, , , , $refused] = $this->paidOrder($this->nextPhone(), '15:15');
        $this->orderStaff();
        $this->step($refused['order_number'], 'reject', ['reason' => 'KITCHEN_CAPACITY'])->assertOk();
        $this->aggregate();

        $report = $this->adminAnalytics($this->analyst(), '/orders', self::DAY)->assertOk()->json();
        $m = $report['metrics'];
        // Accept: 60, 120, 300 → average 160, median 120. Preparation (accepted → ready): 600, 900, 1800 → 1100 / 900.
        $this->assertSame([160, 120, 3], [$m['acceptance_time']['average'], $m['acceptance_time']['median'], $m['acceptance_time']['count']]);
        $this->assertSame([1100, 900, 3], [$m['preparation_time']['average'], $m['preparation_time']['median'], $m['preparation_time']['count']]);
        // Ready → picked up: 120, 240, 600 → 320 / 240. Placed → completed: 780, 1260, 2700 → 1580 / 1260.
        $this->assertSame([320, 240], [$m['pickup_wait_time']['average'], $m['pickup_wait_time']['median']]);
        $this->assertSame([1580, 1260, 3], [$m['completion_time']['average'], $m['completion_time']['median'], $m['completion_time']['count']]);
        $this->assertGreaterThan($m['preparation_time']['median'], $m['preparation_time']['p90']);
        // Five placed: three completed, one rejected, one still waiting — which is in no rate's numerator.
        $this->assertSame([5, 3, 1, 0, 1], [$m['orders_placed']['value'], $m['orders_completed']['value'], $m['orders_rejected']['value'], $m['orders_cancelled']['value'], $m['orders_in_progress']['value']]);
        $this->assertEquals([75.0, 3, 4, 20.0, 1, 5], [$m['order_completion_rate']['value'], $m['order_completion_rate']['numerator'], $m['order_completion_rate']['denominator'], $m['order_rejection_rate']['value'], $m['order_rejection_rate']['numerator'], $m['order_rejection_rate']['denominator']]);
        $this->assertSame(['COMPLETED' => 3, 'REJECTED' => 1, 'WAITING_FOR_RESTAURANT' => 1], array_column($report['status_distribution'], 'count', 'key'));
        $this->assertSame(['KITCHEN_CAPACITY' => 1], array_column($report['rejection_reasons'], 'count', 'key'));
        // Estimated against actual: an operational comparison (two of three ready within the 15 minutes they were told).
        $this->assertSame([3, 900, 1100, 2], [$report['estimated_preparation']['orders'], $report['estimated_preparation']['estimated_seconds_average'], $report['estimated_preparation']['actual_seconds_average'], $report['estimated_preparation']['ready_within_estimate']]);
        $this->assertStringContainsString('nothing about the food', $report['estimated_preparation']['note']);
        // Peak times: Wednesday (3), noon.
        $this->assertSame(['day_of_week' => 3, 'hour' => 12, 'orders' => 5], $report['peak_times']['cells'][0]);

        // The restaurant's row: four answered, three accepted.
        $row = $this->adminAnalytics($this->analyst(), '/restaurants', self::DAY)->assertOk()->json('restaurants.0');
        $this->assertEquals([75.0, 3, 4, 160, 1100, 320], [$row['acceptance_rate']['value'], $row['acceptance_rate']['numerator'], $row['acceptance_rate']['denominator'], $row['acceptance_time']['average'], $row['preparation_time']['average'], $row['pickup_wait_time']['average']]);
        // The owner sees the same operation.
        $operations = $this->restaurantAnalytics($this->member($this->burgerHub->organization, 'OWNER'), '/operations', self::DAY)->assertOk()->json();
        $this->assertSame([160, 1100, 320, 1], [$operations['metrics']['acceptance_time']['average'], $operations['metrics']['preparation_time']['average'], $operations['metrics']['pickup_wait_time']['average'], $operations['metrics']['orders_rejected']['value']]);
    }

    public function test_a_pending_payment_is_not_a_verified_one_and_methods_are_what_the_provider_reported(): void
    {
        $this->completedOrderWith();
        $this->completedOrderWith();
        // A payment that was started and never finished, and one the customer gave up.
        [, $headers, $checkout] = $this->readyCheckout(2, '15:00', $this->nextPhone());
        $this->startPayment($headers, $checkout)->assertCreated();
        [, $other, $second] = $this->readyCheckout(2, '15:15', $this->nextPhone());
        $abandoned = $this->startPayment($other, $second)->assertCreated()->json('payment.id');
        DB::table('payment_attempts')->where('public_id', $abandoned)->update(['status' => 'CANCELLED', 'failure_category' => 'CUSTOMER_CANCELLED', 'closed_at' => now(), 'updated_at' => now()]);
        $this->aggregate();

        $report = $this->adminAnalytics($this->analyst(), '/payments', self::DAY)->assertOk()->json();
        $m = $report['metrics'];
        $this->assertSame([4, 2, 0, 1, 1, 0], [$m['payment_attempts']['value'], $m['payments_verified']['value'], $m['payments_failed']['value'], $m['payments_cancelled']['value'], $m['payments_pending']['value'], $m['payments_unknown']['value']]);
        // Two verified of three FINISHED attempts: the pending one is in neither number.
        $this->assertEquals([66.7, 2, 3], [$m['payment_success_rate']['value'], $m['payment_success_rate']['numerator'], $m['payment_success_rate']['denominator']]);
        $this->assertSame(100000, $m['payments_verified_amount']['value']['amount']);
        $this->assertSame(['CUSTOMER_CANCELLED' => 1], array_column($report['failure_categories'], 'count', 'key'));
        // UPI: two verified of the three attempts that finished (the one the customer gave up had asked for UPI too).
        $upi = collect($report['methods'])->firstWhere('key', 'UPI');
        $this->assertEquals([2, 3, 66.7], [$upi['verified'], $upi['count'], $upi['success_rate']['value']]);
        // Nothing of the provider's own records is in the answer.
        foreach (['provider_payment_reference', 'provider_order_reference', 'receipt', 'vpa', 'card'] as $private) {
            $this->assertStringNotContainsString($private, strtolower(json_encode($report)));
        }
    }

    public function test_only_published_reviews_count_and_a_sent_push_is_never_a_read_one(): void
    {
        $review = function (int $rating): int {
            [, $headers, $order] = $this->completedPaidOrder($this->nextPhone());
            Cache::flush();
            $this->postJson('/api/v1/customer/orders/'.$order->order_number.'/review', ['overall_rating' => $rating], $this->using($headers))->assertCreated();

            return (int) DB::table('restaurant_reviews')->where('order_id', $order->getKey())->value('id');
        };
        $five = $review(5);
        $review(4);
        $one = $review(1);
        DB::table('restaurant_reviews')->update(['status' => 'PUBLISHED', 'published_at' => now(), 'updated_at' => now()]);
        // The one-star review is hidden by moderation; the five-star one gets an answer and a report.
        DB::table('restaurant_reviews')->where('id', $one)->update(['status' => 'HIDDEN', 'moderated_at' => now()]);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');
        DB::table('restaurant_review_responses')->insert(['public_id' => (string) Str::uuid(), 'review_id' => $five, 'location_id' => $this->burgerHub->getKey(), 'restaurant_user_id' => $owner->getKey(), 'responder_name' => 'Burger Hub',
            'response_text' => 'Thank you.', 'status' => 'PUBLISHED', 'version' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $moderation = DB::table('review_moderation_cases')->insertGetId(['public_id' => (string) Str::uuid(), 'review_id' => $five, 'market_id' => $this->market->getKey(), 'source' => 'RESTAURANT_REPORT', 'reason' => 'OTHER', 'status' => 'OPEN',
            'report_count' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('review_reports')->insert(['public_id' => (string) Str::uuid(), 'review_id' => $five, 'case_id' => $moderation, 'reporter_type' => 'RESTAURANT_USER', 'reporter_id' => $owner->getKey(), 'reporter_public_id' => $owner->public_id, 'reason' => 'OTHER', 'created_at' => now()]);
        // One notification is read in the application.
        DB::table('notifications')->orderBy('id')->limit(1)->update(['read_at' => now()]);
        $this->aggregate();
        $admin = $this->analyst();

        $reviews = $this->adminAnalytics($admin, '/reviews', self::DAY)->assertOk()->json();
        // Two published (5 and 4): average 4.5. The hidden one-star review is in no figure.
        $this->assertEquals([2, 4.5, 9, 2, 50.0, 1], [$reviews['metrics']['reviews_published']['value'], $reviews['metrics']['average_rating']['value'], $reviews['metrics']['average_rating']['numerator'], $reviews['metrics']['average_rating']['denominator'],
            $reviews['metrics']['review_response_rate']['value'], $reviews['metrics']['reviews_reported']['value']]);
        $this->assertSame([5 => 1, 4 => 1, 3 => 0, 2 => 0, 1 => 0], array_column($reviews['distribution'], 'reviews', 'stars'));
        $mine = $this->restaurantAnalytics($owner, '/reviews', self::DAY)->assertOk()->json('metrics');
        $this->assertEquals([2, 4.5], [$mine['reviews_published']['value'], $mine['average_rating']['value']]);

        $notifications = $this->adminAnalytics($admin, '/notifications', self::DAY)->assertOk()->json();
        $n = $notifications['metrics'];
        $created = (int) DB::table('notifications')->count();
        $this->assertSame([$created, 1], [$n['notifications_created']['value'], $n['in_app_read']['value']]);
        $this->assertSame([1, $created], [$n['in_app_read_rate']['numerator'], $n['in_app_read_rate']['denominator']]);
        // Push: what the provider accepted, what was not attempted — and no "read" of a push anywhere.
        $push = DB::table('notification_deliveries')->where('channel', 'PUSH')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->assertSame([(int) ($push['SENT'] ?? 0) + (int) ($push['DELIVERED'] ?? 0), (int) ($push['SKIPPED'] ?? 0), (int) ($push['FAILED'] ?? 0)], [$n['push_sent']['value'], $n['push_preference_skips']['value'], $n['push_failed']['value']]);
        $this->assertSame([false, true], [$notifications['push_read']['tracked'], str_contains($notifications['push_read']['statement'], 'NOT TRACKED')]);
        $this->assertSame([], array_values(array_filter(array_keys($n), fn (string $key): bool => str_contains($key, 'push_read') || str_contains($key, 'open'))));
        $this->assertNotSame($n['push_sent']['value'], $n['in_app_read']['value'] + 1000);
    }

    public function test_support_figures_are_the_cases_own_times_and_need_support_access(): void
    {
        [, $headers] = $this->signedIn($this->nextPhone());
        $opened = CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Kolkata')->utc();
        $case = function (array $body, array $times) use ($headers, $opened): int {
            Cache::flush();
            $id = $this->customerCase($headers, $body)['id'];
            DB::table('support_cases')->where('public_id', $id)->update(['created_at' => $opened, 'updated_at' => now()] + $times);

            return (int) DB::table('support_cases')->where('public_id', $id)->value('id');
        };
        // Answered after 10 and 30 minutes; one resolved after two hours, one resolved and reopened, one untouched and urgent.
        $case(['category' => 'ORDER'], ['first_response_at' => $opened->addMinutes(10), 'status' => 'RESOLVED', 'resolution_code' => 'ANSWERED', 'resolved_at' => $opened->addHours(2)]);
        $case(['category' => 'PAYMENT', 'subcategory' => 'PAYMENT_FAILED'], ['first_response_at' => $opened->addMinutes(30), 'status' => 'REOPENED', 'resolved_at' => $opened->addHours(4), 'reopened_at' => $opened->addHours(5), 'reopen_count' => 1, 'escalated_at' => $opened->addHours(1), 'escalation_level' => 'L2']);
        $case(['category' => 'ORDER'], ['priority' => 'URGENT', 'sla_status' => 'BREACHED', 'sla_breached_at' => $opened->addHours(3)]);
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminSupportView]);

        $report = $this->adminAnalytics($admin, '/support', self::DAY)->assertOk()->json();
        $m = $report['metrics'];
        $this->assertSame([3, 2, 0, 1, 1, 1], [$m['support_cases_created']['value'], $m['support_cases_resolved']['value'], $m['support_cases_closed']['value'], $m['support_cases_reopened']['value'], $m['support_cases_escalated']['value'], $m['support_sla_breached']['value']]);
        // First response: (10 + 30) / 2 minutes over the two cases that have one. Resolution: (2 h + 4 h) / 2.
        $this->assertSame([1200, 2, 10800, 2], [$m['support_first_response_time']['average'], $m['support_first_response_time']['count'], $m['support_resolution_time']['average'], $m['support_resolution_time']['count']]);
        $this->assertEquals([50.0, 1, 2], [$m['support_reopen_rate']['value'], $m['support_reopen_rate']['numerator'], $m['support_reopen_rate']['denominator']]);
        // Now: two open (the reopened one and the untouched one), both unassigned, one urgent and past its target.
        $this->assertSame([2, 2, 1, 1], [$m['support_open_cases']['value'], $m['support_unassigned_cases']['value'], $m['support_urgent_cases']['value'], $m['support_sla_breached_open']['value']]);
        $this->assertSame(['ORDER' => 2, 'PAYMENT' => 1], array_column($report['categories'], 'created', 'key'));
        // No figure per agent, and nothing a customer wrote.
        $json = json_encode($report);
        foreach (['agent_id', 'assigned_admin', 'My order is late', 'It has been 40 minutes'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
        $this->assertArrayHasKey('no_agent_ranking', $report);
        // Without support access the report is refused.
        $this->adminAnalytics($this->analyst(), '/support', self::DAY)->assertStatus(403);
    }

    public function test_pickup_figures_come_from_orders_and_capacity_is_shown_only_where_one_is_configured(): void
    {
        $order = $this->completedOrderWith();
        $this->completedOrderWith();
        DB::table('orders')->where('id', $order->getKey())->update(['pickup_mode' => 'ASAP', 'pickup_at' => DB::raw("placed_at + interval '20 minutes'")]);
        // The backend refuses a pickup time twice: once because the slot is full.
        $cart = $this->cartModel($this->guestWithBurger())->load('location');
        event(new PickupSelectionEvent(PickupSelectionEvent::REFUSED, $cart, ['reason' => 'SLOT_FULL']));
        event(new PickupSelectionEvent(PickupSelectionEvent::REFUSED, $cart, ['reason' => 'ASAP_UNAVAILABLE']));
        $this->aggregate();
        $admin = $this->analyst();

        $pickup = $this->adminAnalytics($admin, '/pickup', self::DAY)->assertOk()->json();
        $this->assertSame([1, 1, 2, 1], [$pickup['metrics']['pickup_asap_orders']['value'], $pickup['metrics']['pickup_scheduled_orders']['value'], $pickup['metrics']['pickup_refusals']['value'], $pickup['metrics']['pickup_slot_full']['value']]);
        $this->assertSame(['COUNTER' => 2], array_column($pickup['methods'], 'count', 'key'));
        $this->assertSame(1200, collect($pickup['modes'])->firstWhere('key', 'ASAP')['lead_time_average']);
        // No capacity per slot is configured: nothing is made up — no utilization, and it says why.
        $this->assertSame([false, null, null, true], [$pickup['capacity']['configured'], $pickup['capacity']['units_capacity'], $pickup['metrics']['pickup_capacity_utilization']['value'], $pickup['metrics']['pickup_capacity_utilization']['no_data']]);
        $this->assertStringContainsString('Not offered', $pickup['delivery']);

        // With a capacity of 4 per slot: the units orders reserved, of the capacity of the slots that had an order.
        $this->settings(['capacity_per_slot' => 4]);
        $this->aggregate('REBUILD', '2026-10-06', '2026-10-08');
        $used = DB::table('pickup_slot_reservations')->where('source', 'ORDER')->where('status', 'ACTIVE')->selectRaw('count(distinct slot_starts_at) as slots, coalesce(sum(units), 0) as units')->first();
        $capacity = $this->adminAnalytics($admin, '/pickup', self::DAY)->assertOk()->json();
        if ((int) $used->slots > 0) {
            $this->assertSame([true, (int) $used->slots, (int) $used->units, (int) $used->slots * 4], [$capacity['capacity']['configured'], $capacity['capacity']['slots_with_orders'], $capacity['capacity']['units_used'], $capacity['capacity']['units_capacity']]);
            $this->assertSame([(int) $used->units, (int) $used->slots * 4], [$capacity['metrics']['pickup_capacity_utilization']['numerator'], $capacity['metrics']['pickup_capacity_utilization']['denominator']]);
        } else {
            $this->assertFalse($capacity['capacity']['configured']);
        }
    }

    public function test_route_demand_is_aggregated_a_search_without_a_restaurant_is_a_gap_and_small_groups_are_hidden(): void
    {
        config(['analytics.privacy.min_cohort' => 3]);
        $busy = $this->corridor($this->market, [[77.29, 28.55], [77.41, 28.55]], 'ACTIVE', 5000, 'Noida east–west');
        $quiet = $this->corridor($this->market, [[77.29, 28.75], [77.41, 28.75]], 'ACTIVE', 3000, 'Northern bypass');
        // Four journeys along the busy corridor (a restaurant is on it), two along the quiet one (none is).
        for ($i = 0; $i < 4; $i++) {
            Cache::flush();
            $journey = $this->planGuest();
            $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk()->assertJsonPath('meta.counts.matching', 1);
        }
        for ($i = 0; $i < 2; $i++) {
            Cache::flush();
            $journey = $this->planGuest(['origin' => ['lat' => 28.75, 'lng' => 77.30], 'destination' => ['lat' => 28.75, 'lng' => 77.40]]);
            $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk()->assertJsonPath('meta.counts.matching', 0);
        }
        // One order came from a journey along the busy corridor.
        $planned = $this->planGuest();
        $this->restaurantsOf($planned['id'], $planned['headers'])->assertOk();
        $this->sessionOrder(true, $planned['id']);
        $this->aggregate();
        $admin = $this->analyst();

        $routes = $this->adminAnalytics($admin, '/routes', self::DAY)->assertOk()->json();
        $this->sample('admin-routes-corridors', $routes);
        $m = $routes['metrics'];
        // Seven journeys planned; two found no restaurant; five restaurants returned over the five journeys that found one.
        $this->assertSame([7, 2, 5, 1, 1], [$m['journey_searches']['value'], $m['no_result_searches']['value'], $m['restaurant_impressions']['value'], $m['journey_orders']['value'], $m['journey_restaurant_views']['value']]);
        $this->assertEquals([28.6, 2, 7, 14.3, 1, 7, 1.0], [$m['no_result_rate']['value'], $m['no_result_rate']['numerator'], $m['no_result_rate']['denominator'], $m['journey_order_conversion']['value'], $m['journey_order_conversion']['numerator'], $m['journey_order_conversion']['denominator'], $m['average_restaurants_returned']['value']]);
        $this->assertSame([240, 1], [$m['average_detour']['average'], $m['average_detour']['count']]);
        // The busy corridor is a row; the quiet one — two journeys, under the minimum of three — is folded away, numbers and all.
        $this->assertSame([[$busy->public_id], 5, 0, 1], [array_column(array_column($routes['corridors']['rows'], 'corridor'), 'id'), $routes['corridors']['rows'][0]['searches'], $routes['corridors']['rows'][0]['no_result_searches'], $routes['corridors']['rows'][0]['orders']]);
        $this->assertSame(['corridors' => 1, 'searches' => null, 'no_result_searches' => null, 'orders' => null, 'suppressed' => true], $routes['corridors']['other']);
        $this->assertStringNotContainsString($quiet->public_id, json_encode($routes));
        $this->assertStringNotContainsString('Northern bypass', json_encode($routes));
        // Why the two found nothing — the coverage gap, by reason.
        $this->assertSame(2, array_sum(array_column($routes['coverage_gaps']['by_reason'], 'count')));
        // Bands instead of lengths; and nothing of anybody's journey.
        $this->assertSame(7, array_sum(array_map(fn (array $b): int => (int) $b['searches'], $routes['distance_bands'])));
        $this->assertSame([false, false, 3], [$routes['privacy']['precise_routes_stored'], $routes['privacy']['origin_destination_stored'], $routes['privacy']['minimum_cohort']]);
        $json = json_encode($routes);
        foreach (['West gate', 'East gate', '28.55', '28.75', '77.3', 'polyline', '"lat"', '"lng"', 'journey_key', $planned['id']] as $private) {
            $this->assertStringNotContainsString($private, $json, $private);
        }
        // The same rule for a restaurant: one order from a journey is under the minimum — its band is not shown.
        $mine = $this->restaurantAnalytics($this->member($this->burgerHub->organization, 'OWNER'), '/routes', self::DAY)->assertOk()->json();
        $this->assertSame([5, 1], [$mine['metrics']['restaurant_impressions']['value'], $mine['metrics']['journey_orders']['value']]);
        $band = collect($mine['detour_bands'])->firstWhere('band', '0-5');
        $this->assertSame([null, true], [$band['orders'], $band['suppressed']]);
        // No analytics table has a column that could hold a route or an end of one.
        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'like', 'analytics_%')->pluck('column_name')->unique()->all();
        foreach (['encoded_polyline', 'route_geometry', 'origin', 'destination', 'origin_point', 'destination_point', 'latitude', 'longitude', 'lat', 'lng', 'geometry'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
        $this->assertSame(0, (int) DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'like', 'analytics_%')->where('udt_name', 'in', ['geometry', 'geography'])->count());
    }
}
