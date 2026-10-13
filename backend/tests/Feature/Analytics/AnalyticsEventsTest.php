<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Pseudonymizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — behavioural events: what the applications may send, what is stored of it, and what it can never do.
 * An event is telemetry: it is stored once, without anything personal, and it cannot make a payment verified, create
 * an order or stop one from being placed.
 */
class AnalyticsEventsTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    public function test_a_guest_and_a_customer_send_events_and_nothing_personal_is_stored(): void
    {
        $session = (string) Str::uuid();
        $restaurant = (string) $this->burgerHub->public_id;
        $journey = (string) Str::uuid();

        // A guest: a session the application made up, no customer.
        $this->sendEvents([$this->event('journey_search_started'), $this->event('journey_results_viewed', ['journey' => $journey], ['results' => 3]), $this->event('restaurant_viewed', ['restaurant' => $this->burgerHub->slug, 'journey' => $journey], ['origin' => 'JOURNEY'])], [], $session, 'ANDROID', ['app_version' => '0.1.0+42'])
            ->assertStatus(202)->assertJson(['accepted' => 3, 'duplicates' => 0, 'rejected' => [], 'stored' => true, 'session' => ['idle_minutes' => 30, 'max_hours' => 12]]);
        $rows = DB::table('analytics_events')->orderBy('id')->get();
        $this->assertSame(['CLIENT', 'ANDROID', '0.1.0+42', $session, null, 1], [$rows[0]->source, $rows[0]->platform, $rows[0]->app_version, $rows[0]->session_id, $rows[0]->customer_key, $rows[0]->schema_version]);
        // The restaurant was named by its slug; the journey is kept as a keyed hash, not as its id.
        $this->assertSame([(int) $this->burgerHub->getKey(), (int) $this->market->getKey(), app(Pseudonymizer::class)->journey($journey)], [(int) $rows[2]->location_id, (int) $rows[2]->market_id, $rows[2]->journey_key]);
        $this->assertStringNotContainsString($journey, json_encode($rows));
        $this->assertSame(['origin' => 'JOURNEY'], json_decode($rows[2]->properties, true));

        // The same session after signing in: the customer is a keyed hash — never the id, the phone or the e-mail.
        [$customer, $headers] = $this->signedIn('+919876540001');
        $this->sendEvents([$this->event('menu_viewed', ['restaurant' => $restaurant])], $headers, $session)->assertStatus(202)->assertJsonPath('accepted', 1);
        $signed = DB::table('analytics_events')->where('event_name', 'menu_viewed')->first();
        $this->assertSame([app(Pseudonymizer::class)->customer((int) $customer->getKey()), 32, 'WEB'], [$signed->customer_key, strlen($signed->customer_key), $signed->platform]);
        $this->assertNotSame((string) $customer->getKey(), $signed->customer_key);
        $stored = json_encode(DB::table('analytics_events')->get());
        foreach (['+919876540001', '9876540001', (string) $customer->public_id, (string) $customer->name] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }
        // The table itself has no place for anything personal or for a route.
        $columns = Schema::getColumnListing('analytics_events');
        foreach (['phone', 'phone_e164', 'email', 'name', 'address', 'origin', 'destination', 'latitude', 'longitude', 'lat', 'lng', 'polyline', 'route_geometry', 'customer_id', 'ip', 'user_agent', 'device_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_the_same_event_id_is_stored_once(): void
    {
        $session = (string) Str::uuid();
        $event = $this->event('journey_search_started');
        $this->sendEvents([$event], [], $session)->assertStatus(202)->assertJson(['accepted' => 1, 'duplicates' => 0]);
        // The application sends the batch again (it never got the answer), together with something new.
        $this->sendEvents([$event, $this->event('journey_search_started')], [], $session)->assertStatus(202)->assertJson(['accepted' => 1, 'duplicates' => 1]);
        // The same id twice in one request is one event too.
        $twice = $this->event('journey_search_started');
        $this->sendEvents([$twice, $twice], [], $session)->assertStatus(202)->assertJson(['accepted' => 1]);
        $this->assertSame(3, DB::table('analytics_events')->count());
        $this->assertSame(1, DB::table('analytics_events')->where('event_id', $event['id'])->count());
    }

    public function test_only_registered_events_with_registered_properties_are_stored(): void
    {
        $restaurant = (string) $this->burgerHub->public_id;
        $good = $this->event('journey_search_started');
        $answer = $this->sendEvents([
            $good,
            // A fact of the payment, order and refund domains is not a client's to report: there is no such event.
            $this->event('payment_verified', [], [], null, '11111111-1111-4111-8111-111111111111'),
            $this->event('order_completed', [], [], null, '22222222-2222-4222-8222-222222222222'),
            // A server-recorded event cannot be sent by a client either.
            $this->event('journey_search_succeeded', ['journey' => (string) Str::uuid()], [], null, '33333333-3333-4333-8333-333333333333'),
            // A property that is not on the list — however harmless or harmful it looks.
            $this->event('restaurant_viewed', ['restaurant' => $restaurant], ['phone' => '+919876543210'], null, '44444444-4444-4444-8444-444444444444'),
            $this->event('item_added_to_cart', ['restaurant' => $restaurant], ['quantity' => 2, 'amount' => 50000], null, '55555555-5555-4555-8555-555555555555'),
            $this->event('restaurant_viewed', ['restaurant' => $restaurant], ['origin' => 'DROP TABLE'], null, '66666666-6666-4666-8666-666666666666'),
            $this->event('item_added_to_cart', ['restaurant' => $restaurant], ['quantity' => 5000], null, '77777777-7777-4777-8777-777777777777'),
            // A reference the event does not take, a restaurant that does not exist, a required reference left out.
            $this->event('journey_search_started', ['checkout' => (string) Str::uuid()], [], null, '88888888-8888-4888-8888-888888888888'),
            $this->event('menu_viewed', ['restaurant' => (string) Str::uuid()], [], null, '99999999-9999-4999-8999-999999999999'),
            $this->event('menu_viewed', [], [], null, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            // Too old, and not a time at all.
            $this->event('journey_search_started', [], [], CarbonImmutable::now('UTC')->subDays(4)->format('Y-m-d\TH:i:s\Z'), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
            $this->event('journey_search_started', [], [], 'yesterday', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'),
            ['id' => 'not-an-id', 'name' => 'journey_search_started', 'occurred_at' => $good['occurred_at']],
        ])->assertStatus(202)->json();

        $this->assertSame(1, $answer['accepted']);
        $reasons = array_column($answer['rejected'], 'reason', 'id');
        $this->assertSame([
            '11111111-1111-4111-8111-111111111111' => 'unknown_event', '22222222-2222-4222-8222-222222222222' => 'unknown_event', '33333333-3333-4333-8333-333333333333' => 'not_a_client_event',
            '44444444-4444-4444-8444-444444444444' => 'unknown_property', '55555555-5555-4555-8555-555555555555' => 'unknown_property', '66666666-6666-4666-8666-666666666666' => 'invalid_property',
            '77777777-7777-4777-8777-777777777777' => 'invalid_property', '88888888-8888-4888-8888-888888888888' => 'unknown_reference_kind', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => 'missing_reference',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' => 'too_old', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' => 'invalid_time', '99999999-9999-4999-8999-999999999999' => 'unknown_reference',
        ], array_filter($reasons, fn ($id): bool => $id !== '', ARRAY_FILTER_USE_KEY));
        $this->assertContains('invalid_id', array_column($answer['rejected'], 'reason'));
        $this->assertSame(1, DB::table('analytics_events')->count());
        $this->assertStringNotContainsString('9876543210', json_encode(DB::table('analytics_events')->get()));

        // The request as a whole: its fields are a closed list, a batch has a size, a session is an id.
        $this->postJson('/api/v1/analytics/events', ['session' => (string) Str::uuid(), 'platform' => 'WEB', 'events' => [$this->event('journey_search_started')], 'customer_id' => 7])->assertStatus(422);
        $this->postJson('/api/v1/analytics/events', ['session' => 'device-1234', 'platform' => 'WEB', 'events' => [$this->event('journey_search_started')]])->assertStatus(422);
        $this->postJson('/api/v1/analytics/events', ['session' => (string) Str::uuid(), 'platform' => 'IOS', 'events' => [$this->event('journey_search_started')]])->assertStatus(422);
        $this->sendEvents(array_map(fn (): array => $this->event('journey_search_started'), range(1, 26)))->assertStatus(422);
        $this->sendEvents([])->assertStatus(422);
        $this->assertSame(1, DB::table('analytics_events')->count());

        // A clock that runs ahead does not put an event in the future.
        $this->sendEvents([$this->event('journey_search_started', [], [], CarbonImmutable::now('UTC')->addHours(6)->format('Y-m-d\TH:i:s\Z'))])->assertStatus(202)->assertJsonPath('accepted', 1);
        $this->assertSame(0, DB::table('analytics_events')->where('occurred_at', '>', CarbonImmutable::now('UTC')->addMinute())->count());
    }

    public function test_a_checkout_reference_is_kept_only_for_the_customer_whose_checkout_it_is(): void
    {
        [, $headers, $checkout] = $this->readyCheckout(2, '13:00', '+919876540002');
        [, $stranger] = $this->signedIn('+919876540003');
        $ref = ['checkout' => $checkout['id']];

        // Somebody else, and a guest, name the checkout: the event is refused — its id proves nothing.
        $this->sendEvents([$this->event('checkout_started', $ref)], $stranger)->assertStatus(202)->assertJson(['accepted' => 0, 'rejected' => [['reason' => 'unknown_reference']]]);
        $this->sendEvents([$this->event('payment_started', $ref)], [])->assertStatus(202)->assertJsonPath('accepted', 0);
        $this->assertSame(0, DB::table('analytics_events')->count());
        // The owner: stored, with the reference.
        $this->sendEvents([$this->event('checkout_started', $ref), $this->event('payment_started', $ref, ['method' => 'UPI'])], $headers)->assertStatus(202)->assertJsonPath('accepted', 2);
        $this->assertSame([$checkout['id'], $checkout['id']], DB::table('analytics_events')->orderBy('id')->pluck('checkout_ref')->all());
    }

    public function test_events_cannot_create_a_payment_an_order_or_a_figure_of_either(): void
    {
        [, $headers, $checkout] = $this->readyCheckout(2, '13:00', '+919876540004');
        $before = [DB::table('payment_attempts')->count(), DB::table('orders')->count(), DB::table('ledger_transactions')->count()];
        // Every stage of the funnel is claimed by events alone — and somebody writes "payment verified" into the table by hand.
        $session = (string) Str::uuid();
        $this->sendEvents([
            $this->event('journey_search_started'), $this->event('journey_results_viewed', ['journey' => (string) Str::uuid()]), $this->event('restaurant_viewed', ['restaurant' => $this->burgerHub->public_id]),
            $this->event('menu_viewed', ['restaurant' => $this->burgerHub->public_id]), $this->event('item_added_to_cart', ['restaurant' => $this->burgerHub->public_id]), $this->event('pickup_selected'),
            $this->event('checkout_started', ['checkout' => $checkout['id']]), $this->event('payment_started', ['checkout' => $checkout['id']]),
        ], $headers, $session)->assertStatus(202)->assertJsonPath('accepted', 8);
        foreach (['payment_verified', 'order_created', 'order_completed'] as $i => $forged) {
            DB::table('analytics_events')->insert(['event_id' => (string) Str::uuid(), 'event_name' => $forged, 'schema_version' => 1, 'source' => 'CLIENT', 'platform' => 'WEB', 'occurred_at' => now()->addMinutes($i + 1), 'received_at' => now(),
                'session_id' => $session, 'market_id' => $this->market->getKey(), 'checkout_ref' => $checkout['id'], 'properties' => '{}']);
        }
        $this->aggregate();

        // Nothing was paid, nothing was ordered, nothing was posted.
        $this->assertSame($before, [DB::table('payment_attempts')->count(), DB::table('orders')->count(), DB::table('ledger_transactions')->count()]);
        // The session stops at "payment started": stages 9–11 belong to the payment and order domains.
        $fact = DB::table('analytics_session_facts')->where('session_id', $session)->first();
        $this->assertSame([8, null, null], [$fact->stage, $fact->payment_verified_at, $fact->order_id]);
        $admin = $this->analyst();
        $stages = array_column($this->adminAnalytics($admin, '/funnel', ['preset' => 'today'])->assertOk()->json('stages'), 'sessions', 'stage');
        $this->assertSame([1, 1, 0, 0, 0], [$stages[1], $stages[8], $stages[9], $stages[10], $stages[11]]);
        $payments = $this->adminAnalytics($admin, '/payments', ['preset' => 'today'])->assertOk()->json('metrics');
        $this->assertSame([0, 0], [$payments['payments_verified']['value'], $payments['payment_attempts']['value']]);
        $this->assertSame(0, $this->adminAnalytics($admin, '/orders', ['preset' => 'today'])->assertOk()->json('metrics.orders_placed.value'));
    }

    public function test_analytics_being_down_or_switched_off_stops_no_journey_no_cart_and_no_order(): void
    {
        // The event table is gone: every write of analytics fails.
        DB::statement('alter table analytics_events rename to analytics_events_gone');
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk()->assertJsonPath('meta.counts.matching', 1);
        $this->sendEvents([$this->event('journey_search_started')])->assertStatus(202)->assertJson(['accepted' => 0, 'stored' => false]);
        [, , , $payment, $order] = $this->paidOrder('+919876540005', '13:00');
        $this->assertSame(['VERIFIED', 'WAITING_FOR_RESTAURANT'], [$payment['status'], $order['status']]);
        DB::statement('alter table analytics_events_gone rename to analytics_events');
        $this->assertSame(0, DB::table('analytics_events')->count());

        // Switched off by configuration: accepted and thrown away, and the backend records nothing of its own either.
        config(['analytics.events.enabled' => false]);
        $this->sendEvents([$this->event('journey_search_started')])->assertStatus(202)->assertJson(['accepted' => 0, 'stored' => false]);
        $again = $this->planGuest();
        $this->restaurantsOf($again['id'], $again['headers'])->assertOk();
        $this->assertSame(0, DB::table('analytics_events')->count());

        // The person did not agree: nothing is kept.
        config(['analytics.events.enabled' => true]);
        $this->sendEvents([$this->event('journey_search_started')], [], null, 'WEB', ['consent' => false])->assertStatus(202)->assertJson(['accepted' => 0, 'stored' => false]);
        $this->assertSame(0, DB::table('analytics_events')->count());

        // And the door has a limit of its own.
        Cache::flush();
        config(['rate_limits.analytics_ingest' => 2]);
        $this->sendEvents([$this->event('journey_search_started')])->assertStatus(202);
        $this->sendEvents([$this->event('journey_search_started')])->assertStatus(202);
        $this->sendEvents([$this->event('journey_search_started')])->assertStatus(429);
    }

    public function test_the_backend_records_a_planned_journey_as_bands_and_corridors_and_never_as_a_route(): void
    {
        $corridor = $this->corridor($this->market, [[77.29, 28.55], [77.41, 28.55]], 'ACTIVE', 5000, 'Noida east–west');
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        // The same answer again (a reload, a second page): the same journey and restaurant are not counted twice.
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();

        $planned = DB::table('analytics_events')->where('event_name', 'journey_search_succeeded')->get();
        $this->assertCount(1, $planned);
        $properties = json_decode($planned[0]->properties, true);
        $this->assertSame(['SERVER', null, app(Pseudonymizer::class)->journey($journey['id'])], [$planned[0]->source, $planned[0]->session_id, $planned[0]->journey_key]);
        $this->assertSame([[(int) $corridor->getKey()], '0-25', 'COVERED'], [$properties['corridors'], $properties['distance_band'], $properties['coverage']]);
        $this->assertEqualsCanonicalizing(['distance_band', 'duration_band', 'coverage', 'corridors', 'origin_city', 'destination_city'], array_keys($properties + ['origin_city' => 0, 'destination_city' => 0]));
        $impressions = DB::table('analytics_events')->where('event_name', 'restaurant_impression')->get();
        $this->assertCount(1, $impressions);
        $this->assertSame([(int) $this->burgerHub->getKey(), ['detour_band' => '0-5']], [(int) $impressions[0]->location_id, json_decode($impressions[0]->properties, true)]);

        // What the journey itself holds — its ends, its points, its line — is nowhere in analytics.
        $stored = json_encode(DB::table('analytics_events')->get());
        $row = DB::table('journeys')->where('public_id', $journey['id'])->first();
        foreach (['West gate', 'East gate', '28.55', '77.3', '77.4', $journey['id'], (string) $row->encoded_polyline, (string) $row->route_hash] as $private) {
            $this->assertStringNotContainsString($private, $stored, $private);
        }
        // The journey is deleted an hour later; what analytics kept does not need it.
        DB::table('journeys')->delete();
        $this->aggregate();
        $this->assertSame([1, 1], [(int) DB::table('analytics_daily_routes')->where('bucket', 'CORRIDOR')->value('searches'), (int) DB::table('analytics_daily_routes')->where('bucket', 'ALL')->value('impressions')]);
    }
}
