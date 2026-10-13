<?php

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Models\RestaurantLocation;
use App\Services\Analytics\AnalyticsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — a restaurant's own analytics: its own locations and nothing else. Another restaurant's figures, a
 * location outside the membership and a cached answer of somebody else are all unreachable, whatever the query string
 * says. Earnings are the finance module's figures. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsRestaurantApiTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const PERIOD = ['from' => '2026-10-01', 'to' => '2026-10-07'];

    private const REPORTS = ['overview', 'orders', 'menu', 'operations', 'reviews', 'routes', 'earnings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
    }

    /** A second restaurant, of ANOTHER organization. */
    private function competitor(): RestaurantLocation
    {
        return $this->location($this->organization('Second Kitchen'), 'Pizza Point', [], 28.56, 77.36);
    }

    /** Its day in the read model: 77 orders nobody else may ever see. Written after the aggregation, which owns the table. */
    private function competitorFigures(RestaurantLocation $location): void
    {
        DB::table('analytics_daily_restaurant')->updateOrInsert(['location_id' => $location->getKey(), 'local_date' => '2026-10-07'], [
            'organization_id' => $location->organization_id, 'market_id' => $location->market_id, 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'orders_placed' => 77, 'orders_completed' => 77, 'cohort_completed' => 77,
            'completed_total_minor' => 7700000, 'computed_at' => now(),
        ]);
    }

    public function test_a_restaurant_reads_its_own_figures_and_they_are_the_domains_figures(): void
    {
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        [$order] = $this->sessionOrder(true, $journey['id']);
        $this->completedOrderWith();
        $this->refundedCompletedOrder(10000);
        $other = $this->competitor();
        $this->aggregate();
        $this->competitorFigures($other);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');

        foreach (self::REPORTS as $report) {
            $answer = $this->restaurantAnalytics($owner, '/'.$report, self::PERIOD)->assertOk()->json();
            $this->assertSame([$this->burgerHub->public_id], array_column($answer['meta']['scope']['locations'], 'id'), $report);
            $this->assertSame('Asia/Kolkata', $answer['meta']['timezone'], $report);
            $this->sample('restaurant-'.$report, $answer);
        }
        $catalog = $this->restaurantAnalytics($owner, '/catalog')->assertOk()->json();
        $this->sample('restaurant-catalog', $catalog);
        // Every figure a restaurant's report returns has its definition in the restaurant's own catalog — and that
        // catalog speaks of "sales", never of GMV or of the platform's figures.
        $returned = [];
        $walk = function (mixed $node) use (&$walk, &$returned): void {
            if (is_array($node)) {
                if (isset($node['key'], $node['unit']) && is_string($node['key'])) {
                    $returned[$node['key']] = true;
                }
                array_map($walk, $node);
            }
        };
        foreach (self::REPORTS as $report) {
            $walk($this->restaurantAnalytics($owner, '/'.$report, self::PERIOD)->json());
        }
        $defined = array_column($catalog['metrics'], 'key');
        $this->assertSame([], array_values(array_diff(array_keys($returned), $defined)), 'A restaurant report returned a figure its catalog does not define.');
        $this->assertSame([], array_values(array_intersect($defined, ['gmv', 'platform_commission', 'platform_revenue', 'active_restaurants', 'journey_searches', 'support_open_cases'])));
        $this->assertArrayNotHasKey('gmv', $returned);

        $overview = $this->restaurantAnalytics($owner, '/overview', self::PERIOD)->assertOk()->json();
        $m = $overview['metrics'];
        // Three orders of its own — none of the 77 of the restaurant next door.
        $this->assertSame([3, 3, 50000 * 3, 50000], [$m['orders_placed']['value'], $m['orders_completed']['value'], $m['completed_order_total']['value']['amount'], $m['average_order_value']['value']['amount']]);
        $this->assertEquals([100.0, 33.3], [$m['restaurant_acceptance_rate']['value'], $m['restaurant_refund_rate']['value']]);
        // Net earnings: 3 × (₹500 − 10 %) from the finance module's allocations.
        $this->assertSame(['amount' => 135000, 'currency' => 'INR'], $m['restaurant_net_earnings']['value']);
        $this->assertSame([3, 3, 0, false], [$overview['customers']['ordering_customers'], $overview['customers']['first_time_here'], $overview['customers']['returning'], $overview['customers']['suppressed']]);
        // No customer is named anywhere in a restaurant's analytics.
        $everything = json_encode(array_map(fn (string $r): array => $this->restaurantAnalytics($owner, '/'.$r, self::PERIOD)->json(), self::REPORTS));
        foreach (['+9198', 'phone', 'email', 'customer_key', 'customer_id', (string) $order->customer->name] as $private) {
            $this->assertStringNotContainsString($private, $everything);
        }

        // The menu: what was SOLD comes from order items; what was looked at from the events of the one session.
        $menu = $this->restaurantAnalytics($owner, '/menu', self::PERIOD)->assertOk()->json();
        $this->assertSame([6, 3, 100000 * 3 / 2], [$menu['items'][0]['units_sold'], $menu['items'][0]['orders'], $menu['items'][0]['sales']['amount']]);
        $this->assertSame([1, 1], [$menu['metrics']['menu_views']['value'], $menu['metrics']['cart_adds']['value']]);

        // Routes: the restaurant was returned for one journey and one order came from a journey. No route is shown.
        $routes = $this->restaurantAnalytics($owner, '/routes', self::PERIOD)->assertOk()->json();
        $this->assertSame([1, 1], [$routes['metrics']['restaurant_impressions']['value'], $routes['metrics']['journey_orders']['value']]);
        foreach (['polyline', 'origin', 'destination', 'latitude', 'lng', 'lat'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', json_encode($routes));
        }

        // Earnings are the finance module's own summary — the same numbers its page shows.
        $earnings = $this->restaurantAnalytics($owner, '/earnings', self::PERIOD)->assertOk()->json();
        $this->asActor($owner);
        $finance = $this->restaurantFinance('/summary', $this->burgerHub)->assertOk()->json('currencies');
        $this->assertSame($finance, $earnings['summary']);
        $this->assertSame('LEDGER', $earnings['meta']['freshness']['mode']);
    }

    public function test_another_restaurant_another_location_and_a_missing_permission_are_all_refused(): void
    {
        $this->completedOrderWith();
        $other = $this->competitor();
        $second = $this->location($this->burgerHub->organization, 'Brew & Bites', [], 28.57, 77.37);
        $this->aggregate();
        $this->competitorFigures($other);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');
        $manager = $this->member($this->burgerHub->organization, 'MANAGER', [$this->burgerHub]);
        $staff = $this->member($this->burgerHub->organization, 'ORDER_STAFF');
        $rival = $this->member($other->organization, 'OWNER');

        foreach (self::REPORTS as $report) {
            // Another organization's location does not exist for this owner — whatever the query string says.
            $this->restaurantAnalytics($owner, '/'.$report, ['location' => $other->public_id] + self::PERIOD)->assertStatus(404)->assertJsonPath('error.code', 'restaurant_not_found');
            // A manager of ONE location cannot name another location of the same organization.
            $this->restaurantAnalytics($manager, '/'.$report, ['location' => $second->public_id] + self::PERIOD)->assertStatus(404);
            // Order staff hold no analytics permission.
            $this->restaurantAnalytics($staff, '/'.$report, self::PERIOD)->assertStatus(403);
        }
        $this->restaurantAnalytics($owner, '/overview', ['location' => 'not-an-id'])->assertStatus(422);

        // Without a location: the owner reads both locations of the organization, the manager only their own.
        $this->assertEqualsCanonicalizing([$this->burgerHub->public_id, $second->public_id], array_column($this->restaurantAnalytics($owner, '/overview', self::PERIOD)->assertOk()->json('meta.scope.locations'), 'id'));
        $mine = $this->restaurantAnalytics($manager, '/overview', self::PERIOD)->assertOk()->json();
        $this->assertSame([[$this->burgerHub->public_id], 1], [array_column($mine['meta']['scope']['locations'], 'id'), $mine['metrics']['orders_placed']['value']]);
        // The rival sees its own 77 and never the one order of Burger Hub.
        $theirs = $this->restaurantAnalytics($rival, '/overview', self::PERIOD)->assertOk()->json();
        $this->assertSame([[$other->public_id], 77], [array_column($theirs['meta']['scope']['locations'], 'id'), $theirs['metrics']['orders_placed']['value']]);

        // A viewer may read analytics but not earnings: the finance figure is "restricted", the earnings report refused.
        $viewer = $this->member($this->burgerHub->organization, 'VIEWER');
        $overview = $this->restaurantAnalytics($viewer, '/overview', self::PERIOD)->assertOk()->json();
        $this->assertSame([true, Permission::RestaurantFinanceView->value, false], [$overview['metrics']['restaurant_net_earnings']['restricted'], $overview['metrics']['restaurant_net_earnings']['requires'], $overview['meta']['permissions']['finance']]);
        $this->restaurantAnalytics($viewer, '/earnings', self::PERIOD)->assertStatus(403);
        // Administrators' reports are not a restaurant member's, and a customer's token opens nothing here.
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($owner);
        $this->getJson('/api/v1/admin/analytics/overview')->assertStatus(401);
    }

    public function test_a_cached_answer_of_one_restaurant_is_never_returned_to_another(): void
    {
        config(['analytics.cache.enabled' => true, 'analytics.cache.seconds' => 300]);
        $this->completedOrderWith();
        $other = $this->competitor();
        $this->aggregate();
        $this->competitorFigures($other);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');
        $rival = $this->member($other->organization, 'OWNER');

        $first = $this->restaurantAnalytics($owner, '/overview', self::PERIOD)->assertOk()->json();
        $again = $this->restaurantAnalytics($owner, '/overview', self::PERIOD)->assertOk()->json();
        // The second answer is the cached one — and says so, with the time it was computed.
        $this->assertSame([false, true, 1], [$first['meta']['freshness']['cached'], $again['meta']['freshness']['cached'], $again['metrics']['orders_placed']['value']]);
        $this->assertSame($first['meta']['freshness']['generated_at'], $again['meta']['freshness']['generated_at']);
        $this->assertSame(300, $again['meta']['freshness']['cache_seconds']);

        // The same report, the same period, another restaurant: its own figures, computed for it.
        $theirs = $this->restaurantAnalytics($rival, '/overview', self::PERIOD)->assertOk()->json();
        $this->assertSame([false, 77], [$theirs['meta']['freshness']['cached'], $theirs['metrics']['orders_placed']['value']]);
        $cache = app(AnalyticsCache::class);
        $this->assertNotSame($cache->key('restaurant:overview', ['locations' => [1]], ['from' => 'a']), $cache->key('restaurant:overview', ['locations' => [2]], ['from' => 'a']));

        // Writing the read models again starts a new generation: nothing stale survives an aggregation.
        $this->completedOrderWith();
        $this->aggregate('SCHEDULED');
        $fresh = $this->restaurantAnalytics($owner, '/overview', self::PERIOD)->assertOk()->json();
        $this->assertSame([false, 2], [$fresh['meta']['freshness']['cached'], $fresh['metrics']['orders_placed']['value']]);
        $this->assertNotNull($fresh['meta']['freshness']['aggregated_at']);
    }
}
