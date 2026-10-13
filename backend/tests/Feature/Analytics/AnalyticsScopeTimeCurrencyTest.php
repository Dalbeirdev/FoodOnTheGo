<?php

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Models\Order;
use App\Services\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — where a figure belongs: which local day (also across midnight and across a daylight-saving change),
 * which currency (never added to another), which market (the server's decision, not the query string's), and what a
 * comparison says when there is nothing to compare with. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsScopeTimeCurrencyTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
        $this->order = $this->completedOrderWith();
    }

    /** A completed copy of the fixture order placed and completed at a local time of a zone. */
    private function completedAt(string $local, string $zone = 'Asia/Kolkata', array $more = []): int
    {
        $at = CarbonImmutable::parse($local, $zone)->utc();

        return $this->orderCopy($this->order, ['placed_at' => $at, 'accepted_at' => $at->addMinutes(2), 'ready_at' => $at->addMinutes(12), 'picked_up_at' => $at->addMinutes(15), 'completed_at' => $at->addMinutes(15)] + $more);
    }

    public function test_an_order_near_midnight_falls_on_its_own_local_day_and_a_period_includes_both_its_ends(): void
    {
        $this->completedAt('2026-10-03 23:40');             // 3 October, completed 23:55
        $this->completedAt('2026-10-03 23:50');             // placed on the 3rd, COMPLETED on the 4th at 00:05
        $this->completedAt('2026-10-04 00:00');             // the very first instant of the 4th
        $this->completedAt('2026-10-04 23:44:59');          // completed at 23:59:59 on the 4th
        $this->completedAt('2026-10-05 00:00');             // the first instant of the 5th — not the 4th
        $this->aggregate();
        $admin = $this->analyst();
        $day = fn (string $date): array => $this->adminAnalytics($admin, '/orders', ['from' => $date, 'to' => $date])->assertOk()->json('metrics');

        // Placed and completed are counted on their own days: the 23:50 order is placed on the 3rd and completed on the 4th.
        $third = $day('2026-10-03');
        $fourth = $day('2026-10-04');
        $fifth = $day('2026-10-05');
        $this->assertSame([2, 1], [$third['orders_placed']['value'], $third['orders_completed']['value']]);
        $this->assertSame([2, 3], [$fourth['orders_placed']['value'], $fourth['orders_completed']['value']]);
        $this->assertSame([1, 1], [$fifth['orders_placed']['value'], $fifth['orders_completed']['value']]);
        // In UTC those five orders are on other days (18:10 … 18:30 of the day before): the report's zone is explicit.
        $this->assertSame(['2026-10-04', '2026-10-04', 'Asia/Kolkata', '2026-10-03T18:30:00Z', '2026-10-04T18:30:00Z'], array_values(array_intersect_key($this->adminAnalytics($admin, '/orders', ['from' => '2026-10-04', 'to' => '2026-10-04'])->json('meta.period'), array_flip(['from', 'to', 'timezone', 'starts_at', 'ends_before']))));
        // A range includes both of its ends and nothing outside them.
        $range = $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-03', 'to' => '2026-10-04'])->assertOk()->json();
        $this->assertSame([4, 4], [$range['metrics']['orders_placed']['value'], $range['metrics']['orders_completed']['value']]);
        // The chart has a point for every day, also the empty ones — an explicit zero, not a gap.
        $week = $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-01', 'to' => '2026-10-05'])->assertOk()->json('series');
        $this->assertSame(['day', ['2026-10-01' => 0, '2026-10-02' => 0, '2026-10-03' => 2, '2026-10-04' => 2, '2026-10-05' => 1]], [$week['bucket'], array_column($week['points'], 'orders_placed', 'bucket')]);
        // One day is cut by the hour.
        $hours = $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-04', 'to' => '2026-10-04'])->assertOk()->json('series');
        $byHour = array_column($hours['points'], 'orders_placed', 'bucket');
        $this->assertSame(['hour', 24, 1, 1], [$hours['bucket'], count($hours['points']), $byHour['2026-10-04 00:00'], $byHour['2026-10-04 23:00']]);

        // The presets resolve against the clock of the test (7 October 2026, 12:00 in India).
        $presets = [];
        foreach (AnalyticsPeriod::PRESETS as $preset) {
            if ($preset !== 'custom') {
                $period = $this->adminAnalytics($admin, '/orders', ['preset' => $preset])->assertOk()->json('meta.period');
                $presets[$preset] = [$period['from'], $period['to']];
            }
        }
        $this->assertSame(['today' => ['2026-10-07', '2026-10-07'], 'yesterday' => ['2026-10-06', '2026-10-06'], 'last_7_days' => ['2026-10-01', '2026-10-07'], 'last_30_days' => ['2026-09-08', '2026-10-07'],
            'this_month' => ['2026-10-01', '2026-10-07'], 'previous_month' => ['2026-09-01', '2026-09-30']], $presets);
        // A period that is upside down, not a day, too long or unknown is refused.
        $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-05', 'to' => '2026-10-01'])->assertStatus(422);
        $this->adminAnalytics($admin, '/orders', ['from' => '2026-02-30', 'to' => '2026-03-01'])->assertStatus(422);
        $this->adminAnalytics($admin, '/orders', ['from' => '2025-01-01', 'to' => '2026-10-01'])->assertStatus(422)->assertJsonPath('error.code', 'range_too_long');
        $this->adminAnalytics($admin, '/orders', ['preset' => 'last_decade'])->assertStatus(422);
        $this->adminAnalytics($admin, '/orders', ['preset' => 'custom'])->assertStatus(422);
    }

    public function test_a_market_with_daylight_saving_time_gets_its_long_and_short_days_right(): void
    {
        [$us, $diner] = $this->secondMarket();
        $there = ['market_id' => $us->getKey(), 'organization_id' => $diner->organization_id, 'location_id' => $diner->getKey(), 'currency' => 'USD', 'restaurant_timezone' => 'America/New_York'];
        // 1 November 2026: the clocks go back at 02:00 — the day has 25 hours in New York.
        $this->completedAt('2026-10-31 23:30', 'America/New_York', $there);
        $this->completedAt('2026-11-01 00:30', 'America/New_York', $there);
        $this->orderCopy($this->order, $there + ['placed_at' => '2026-11-01 05:30:00+00', 'completed_at' => '2026-11-01 05:45:00+00']);   // 01:30 EDT, before the change
        $this->orderCopy($this->order, $there + ['placed_at' => '2026-11-01 06:30:00+00', 'completed_at' => '2026-11-01 06:45:00+00']);   // 01:30 EST, the same wall time again
        $this->completedAt('2026-11-01 23:30', 'America/New_York', $there);
        $this->completedAt('2026-11-02 00:30', 'America/New_York', $there);
        // 8 March 2026: the clocks go forward — the day has 23 hours.
        $this->completedAt('2026-03-08 23:30', 'America/New_York', $there);
        $this->aggregate();
        // The backfill ends today (the test's clock stands on 7 October): the November days are asked for by name.
        $this->aggregate('REBUILD', '2026-10-30', '2026-11-03');
        $admin = $this->analyst();

        $november = AnalyticsPeriod::of('2026-11-01', '2026-11-01', 'America/New_York');
        $march = AnalyticsPeriod::of('2026-03-08', '2026-03-08', 'America/New_York');
        $this->assertSame([25, 23, 1, 1], [(int) ($november->startUtc()->diffInHours($november->endUtc())), (int) ($march->startUtc()->diffInHours($march->endUtc())), $november->days(), $march->days()]);

        $day = $this->adminAnalytics($admin, '/orders', ['market' => $us->slug, 'from' => '2026-11-01', 'to' => '2026-11-01'])->assertOk()->json();
        // Four orders on the 25-hour day — also the two at "01:30", an hour apart; the neighbours stay on their own days.
        $this->assertSame(['America/New_York', 'USD', 4, '2026-11-01T04:00:00Z', '2026-11-02T05:00:00Z'], [$day['meta']['timezone'], $day['meta']['currency'], $day['metrics']['orders_placed']['value'], $day['meta']['period']['starts_at'], $day['meta']['period']['ends_before']]);
        $this->assertSame(25, count($day['series']['points']));
        $this->assertSame([1, 1], [
            $this->adminAnalytics($admin, '/orders', ['market' => $us->slug, 'from' => '2026-10-31', 'to' => '2026-10-31'])->json('metrics.orders_placed.value'),
            $this->adminAnalytics($admin, '/orders', ['market' => $us->slug, 'from' => '2026-11-02', 'to' => '2026-11-02'])->json('metrics.orders_placed.value'),
        ]);
        $this->assertSame(['2026-03-08T05:00:00Z', '2026-03-09T04:00:00Z', 1], array_values(array_intersect_key(($spring = $this->adminAnalytics($admin, '/orders', ['market' => $us->slug, 'from' => '2026-03-08', 'to' => '2026-03-08'])->json())['meta']['period'], array_flip(['starts_at', 'ends_before']))) + [2 => $spring['metrics']['orders_placed']['value']]);
        // The restaurant's own days are the restaurant's zone's days.
        $this->assertSame(4, (int) DB::table('analytics_daily_restaurant')->where('location_id', $diner->getKey())->where('local_date', '2026-11-01')->value('orders_placed'));
        $this->assertSame('America/New_York', DB::table('analytics_daily_market')->where('market_id', $us->getKey())->value('timezone'));
    }

    public function test_two_currencies_are_shown_side_by_side_and_never_added(): void
    {
        [$us, $diner] = $this->secondMarket();
        $this->completedAt('2026-10-07 10:00', 'America/New_York', ['market_id' => $us->getKey(), 'organization_id' => $diner->organization_id, 'location_id' => $diner->getKey(), 'currency' => 'USD', 'restaurant_timezone' => 'America/New_York', 'subtotal_minor' => 2000, 'total_minor' => 2000, 'discount_minor' => 0, 'tax_minor' => 0, 'fee_minor' => 0]);
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminFinanceView]);
        $period = ['from' => '2026-10-01', 'to' => '2026-10-08'];

        $india = $this->adminAnalytics($admin, '/orders', $period)->assertOk()->json();
        $this->assertSame([['amount' => 50000, 'currency' => 'INR'], false, 1], [$india['metrics']['gmv']['value'], $india['metrics']['gmv']['mixed_currencies'], $india['metrics']['orders_completed']['value']]);
        $there = $this->adminAnalytics($admin, '/orders', $period + ['market' => $us->slug])->assertOk()->json();
        $this->assertSame([['amount' => 2000, 'currency' => 'USD'], 'USD'], [$there['metrics']['gmv']['value'], $there['meta']['currency']]);

        // Every market at once: two orders, two amounts — and no single money figure.
        $all = $this->adminAnalytics($admin, '/orders', $period + ['market' => 'all'])->assertOk()->json();
        $gmv = $all['metrics']['gmv'];
        $this->assertSame([2, null, true, [['INR', 50000], ['USD', 2000]]], [$all['metrics']['orders_completed']['value'], $gmv['value'], $gmv['mixed_currencies'], array_map(fn (array $v): array => [$v['currency'], $v['amount']], $gmv['values'])]);
        $this->assertSame([true, null, false], [$all['meta']['scope']['all_markets'], $all['metrics']['average_order_value']['value'], $all['series']['money_series_available']]);
        $this->assertEqualsCanonicalizing(['india', $us->slug], array_column($all['meta']['scope']['markets'], 'slug'));
        // 52,000 — the sum of rupees and dollars — is nowhere.
        $everything = json_encode([$all, $this->adminAnalytics($admin, '/overview', $period + ['market' => 'all'])->assertOk()->json(), $this->adminAnalytics($admin, '/finance', $period + ['market' => 'all'])->assertOk()->json()]);
        $this->assertStringNotContainsString('52000', $everything);
        // The read model keeps a row per currency.
        $this->assertEqualsCanonicalizing(['INR', 'USD'], DB::table('analytics_daily_market')->distinct()->pluck('currency')->all());
    }

    public function test_the_market_of_a_report_is_the_servers_decision(): void
    {
        [$us, $diner] = $this->secondMarket();
        $this->completedAt('2026-10-07 10:00', 'America/New_York', ['market_id' => $us->getKey(), 'organization_id' => $diner->organization_id, 'location_id' => $diner->getKey(), 'currency' => 'USD', 'restaurant_timezone' => 'America/New_York']);
        $this->aggregate();
        $period = ['from' => '2026-10-01', 'to' => '2026-10-08'];
        $indiaOnly = $this->analyst([Permission::AdminFinanceView, Permission::AdminSupportView], $this->market);
        $usOnly = $this->analyst([], $us);

        // India only: the default is India; another market is refused whatever the query string says; "all" is India.
        $this->assertSame(['india'], array_column($this->adminAnalytics($indiaOnly, '/orders', $period)->assertOk()->json('meta.scope.markets'), 'slug'));
        foreach (['overview', 'orders', 'restaurants', 'routes', 'funnel', 'payments', 'finance', 'support'] as $report) {
            $this->adminAnalytics($indiaOnly, '/'.$report, $period + ['market' => $us->slug])->assertStatus(403);
        }
        $all = $this->adminAnalytics($indiaOnly, '/orders', $period + ['market' => 'all'])->assertOk()->json();
        $this->assertSame([['india'], 1, false], [array_column($all['meta']['scope']['markets'], 'slug'), $all['metrics']['orders_completed']['value'], $all['metrics']['gmv']['mixed_currencies']]);
        $this->assertStringNotContainsString('USD', json_encode($all));
        // A restaurant of the other market does not exist for this administrator; nor does a market nobody has.
        $this->adminAnalytics($indiaOnly, '/orders', $period + ['restaurant' => $diner->public_id])->assertStatus(404)->assertJsonPath('error.code', 'restaurant_not_found');
        $this->adminAnalytics($indiaOnly, '/orders', $period + ['market' => 'atlantis'])->assertStatus(404)->assertJsonPath('error.code', 'market_not_found');
        $this->adminAnalytics($indiaOnly, '/restaurants', $period + ['market' => 'all'])->assertOk()->assertJsonCount(1, 'restaurants');
        // The checks that look across every market need the permission for every market.
        $this->adminAnalytics($indiaOnly, '/quality')->assertStatus(403);
        $this->adminAnalytics($this->analyst(), '/quality')->assertOk();

        // The other way round: an administrator of the other market gets that market by default, and never India.
        $theirs = $this->adminAnalytics($usOnly, '/orders', $period)->assertOk()->json();
        $this->assertSame([[$us->slug], 'USD', 1], [array_column($theirs['meta']['scope']['markets'], 'slug'), $theirs['meta']['currency'], $theirs['metrics']['orders_completed']['value']]);
        $this->adminAnalytics($usOnly, '/orders', $period + ['market' => 'india'])->assertStatus(403);
        // One restaurant of one's own market can be singled out.
        $one = $this->adminAnalytics($this->analyst(), '/orders', $period + ['restaurant' => $this->burgerHub->public_id])->assertOk()->json();
        $this->assertSame([$this->burgerHub->public_id, 1], [$one['meta']['scope']['restaurant']['id'], $one['metrics']['orders_placed']['value']]);
    }

    public function test_a_comparison_never_divides_by_nothing_and_a_tiny_sample_is_not_a_percentage(): void
    {
        // Previous week: two orders. This week (1–7 October): the fixture's one and two more.
        $this->completedAt('2026-09-25 12:00');
        $this->completedAt('2026-09-26 12:00');
        $this->completedAt('2026-10-02 12:00');
        $this->completedAt('2026-10-03 12:00');
        $this->aggregate();
        $admin = $this->analyst();

        $now = $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-01', 'to' => '2026-10-07'])->assertOk()->json();
        // Three against two: +50 %, the same range of days immediately before.
        $this->assertEquals([3, ['previous' => 2, 'change_pct' => 50.0, 'state' => 'UP'], ['from' => '2026-09-24', 'to' => '2026-09-30']], [$now['metrics']['orders_completed']['value'], $now['metrics']['orders_completed']['comparison'], $now['meta']['period']['previous']]);
        $this->assertEquals(['UP', 50.0], [$now['metrics']['gmv']['comparison']['state'], $now['metrics']['gmv']['comparison']['change_pct']]);
        // Two against nothing: "new" — not infinity. Nothing against nothing: "no data".
        $before = $this->adminAnalytics($admin, '/orders', ['from' => '2026-09-24', 'to' => '2026-09-30'])->assertOk()->json();
        $this->assertSame(['previous' => 0, 'change_pct' => null, 'state' => 'NEW'], $before['metrics']['orders_completed']['comparison']);
        $nothing = $this->adminAnalytics($admin, '/orders', ['from' => '2026-06-01', 'to' => '2026-06-07'])->assertOk()->json();
        $this->assertSame(['previous' => 0, 'change_pct' => null, 'state' => 'NO_DATA'], $nothing['metrics']['orders_completed']['comparison']);
        // Nothing against two: down by 100 %.
        $after = $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-08', 'to' => '2026-10-14'])->assertOk()->json();
        $this->assertEquals(['previous' => 3, 'change_pct' => -100.0, 'state' => 'DOWN'], $after['metrics']['orders_completed']['comparison']);
        foreach ([$now, $before, $nothing, $after] as $answer) {
            $json = json_encode($answer);
            $this->assertStringNotContainsString('INF', $json);
            $this->assertStringNotContainsString('NaN', $json);
        }

        // With the real minimum sample a rate over three orders is "insufficient data", with its numbers still there.
        config(['analytics.small_sample.min_denominator' => 5]);
        $rate = $this->adminAnalytics($admin, '/orders', ['from' => '2026-10-01', 'to' => '2026-10-07'])->assertOk()->json('metrics.order_completion_rate');
        $this->assertSame([null, true, false, 3, 3, 5], [$rate['value'], $rate['insufficient'], $rate['no_data'], $rate['numerator'], $rate['denominator'], $rate['minimum_denominator']]);
    }
}
