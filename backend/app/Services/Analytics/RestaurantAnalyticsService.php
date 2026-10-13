<?php

namespace App\Services\Analytics;

use App\Services\Finance\FinanceReportService;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant performance (Module 38), per physical LOCATION — the operational unit; an organization is the sum of
 * the locations the caller may read.
 *
 * A restaurant's own report covers its own locations only. The comparison of restaurants is an administrator's
 * report: the rows carry no ranking score and nothing here feeds a public "top restaurant" list, a suspension or any
 * other decision — those belong to the restaurant domain and to people.
 *
 * Money: "sales" of the performance table are the totals customers paid for completed orders (order domain); a
 * restaurant's gross sales, commission and net earnings are read from the financial allocations of Module 36 and
 * only for a caller who may see finance.
 */
final class RestaurantAnalyticsService
{
    public function __construct(private readonly ReadModel $read, private readonly FinanceReportService $finance) {}

    /**
     * One row per location in scope.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(AnalyticsScope $scope, AnalyticsPeriod $period, bool $withFinance): array
    {
        $rows = $this->read->daily('analytics_daily_restaurant', $scope, $period)->join('restaurant_locations as l', 'l.id', '=', 't.location_id')
            ->selectRaw('l.id, l.public_id, l.name, l.currency, sum(t.orders_placed) as placed, sum(t.cohort_completed) as completed_c, sum(t.cohort_rejected) as rejected, sum(t.cohort_cancelled) as cancelled, sum(t.cohort_active) as active,
                sum(t.cohort_refunded) as refunded, sum(t.cohort_accepted) as accepted, sum(t.orders_completed) as completed, sum(t.completed_total_minor) as sales, sum(t.refunded_minor) as refunded_minor,
                sum(t.accept_seconds_sum) as accept_s, sum(t.accept_count) as accept_n, sum(t.prep_seconds_sum) as prep_s, sum(t.prep_count) as prep_n, sum(t.pickup_wait_seconds_sum) as wait_s, sum(t.pickup_wait_count) as wait_n,
                sum(t.reviews_published) as reviews, sum(t.rating_sum) as rating_sum, sum(t.impressions) as impressions, sum(t.restaurant_views) as views, sum(t.journey_orders) as journey_orders')
            ->groupBy('l.id', 'l.public_id', 'l.name', 'l.currency')->orderBy('l.name')->limit(500)->get();
        $money = $withFinance ? $this->earnings($scope, $period) : [];
        $out = [];
        foreach ($rows as $r) {
            $answered = (int) $r->accepted + (int) $r->rejected;
            $currency = (string) $r->currency;
            $earned = $money[(int) $r->id] ?? null;
            $out[] = [
                'restaurant' => ['id' => (string) $r->public_id, 'name' => (string) $r->name],
                'currency' => $currency,
                'orders_placed' => (int) $r->placed, 'orders_completed' => (int) $r->completed, 'orders_rejected' => (int) $r->rejected, 'orders_cancelled' => (int) $r->cancelled, 'orders_in_progress' => (int) $r->active,
                'completed_order_total' => ['amount' => (int) $r->sales, 'currency' => $currency],
                'average_order_value' => (int) $r->completed > 0 ? ['amount' => (int) round((int) $r->sales / (int) $r->completed), 'currency' => $currency] : null,
                'acceptance_rate' => Metric::ratio('restaurant_acceptance_rate', (int) $r->accepted, $answered),
                'rejection_rate' => Metric::ratio('order_rejection_rate', (int) $r->rejected, (int) $r->placed),
                'refund_rate' => Metric::ratio('restaurant_refund_rate', (int) $r->refunded, (int) $r->placed),
                'refund_amount' => ['amount' => (int) $r->refunded_minor, 'currency' => $currency],
                'acceptance_time' => Metric::duration('acceptance_time', (int) $r->accept_s, (int) $r->accept_n),
                'preparation_time' => Metric::duration('preparation_time', (int) $r->prep_s, (int) $r->prep_n),
                'pickup_wait_time' => Metric::duration('pickup_wait_time', (int) $r->wait_s, (int) $r->wait_n),
                'average_rating' => Metric::rating('average_rating', (int) $r->rating_sum, (int) $r->reviews),
                'reviews_published' => (int) $r->reviews,
                'impressions' => (int) $r->impressions, 'restaurant_views' => (int) $r->views, 'journey_orders' => (int) $r->journey_orders,
                // From the financial allocations (Module 36) — or nothing at all for a caller without finance access.
                'gross_sales' => $withFinance ? ['amount' => $earned['gross'] ?? 0, 'currency' => $currency] : null,
                'net_earnings' => $withFinance ? ['amount' => $earned['net'] ?? 0, 'currency' => $currency] : null,
                'finance_restricted' => ! $withFinance,
            ];
        }

        return $out;
    }

    /**
     * Gross sales and net earnings per location — the finance module's own commission report, not a second sum.
     *
     * @return array<int, array{gross: int, net: int}>
     */
    private function earnings(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $ids = DB::table('restaurant_locations')->whereIn('market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)->pluck('id', 'public_id');
        $out = [];
        foreach ($this->finance->commission($scope->marketIds, $period->startUtc(), $period->endUtc(), 'location', $period->timezone) as $row) {
            $id = (int) ($ids[$row['key']] ?? 0);
            if ($id > 0 && (! $scope->forLocations() || in_array($id, (array) $scope->locationIds, true))) {
                $out[$id] = ['gross' => ($out[$id]['gross'] ?? 0) + (int) $row['gross_marketplace_sales_minor'], 'net' => ($out[$id]['net'] ?? 0) + (int) $row['restaurant_earnings_minor']];
            }
        }

        return $out;
    }

    /**
     * How many locations completed an order in the period, and how many are taking orders at this moment — two
     * different figures with two different names.
     *
     * @return array<string, mixed>
     */
    public function presence(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $active = fn (AnalyticsPeriod $p): int => (int) $this->read->daily('analytics_daily_restaurant', $scope, $p)->where('t.orders_completed', '>', 0)->distinct()->count('t.location_id');
        $orderable = (int) DB::table('restaurant_locations')->whereIn('market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)
            ->when($scope->forLocations(), fn ($q) => $q->whereIn('id', $scope->locationIds === [] ? [0] : $scope->locationIds))->where('status', 'APPROVED')->where('accepting_orders', true)->count();

        return ['active_restaurants' => Metric::count('active_restaurants', $active($period), $active($period->previous())), 'orderable_restaurants' => Metric::count('orderable_restaurants', $orderable)];
    }

    /**
     * Menu performance of the locations in scope. What sessions LOOKED AT (behavioural) and what was SOLD (order
     * items of completed orders) are two columns, never one figure.
     *
     * @return array<string, mixed>
     */
    public function menu(AnalyticsScope $scope, AnalyticsPeriod $period, int $limit = 50): array
    {
        $rows = $this->read->daily('analytics_daily_items', $this->locationScoped($scope), $period, 't', false)
            ->selectRaw('t.item_key, max(t.item_public_id::text) as item_id, max(t.name) as name, max(t.currency) as currency, sum(t.units_sold) as units, sum(t.revenue_minor) as revenue, sum(t.orders) as orders, sum(t.views) as views, sum(t.cart_adds) as adds')
            ->groupBy('t.item_key')->orderByRaw('sum(t.revenue_minor) desc, max(t.name)')->limit($limit)->get();
        // An item's share is its share of the sales in ITS currency.
        $totals = $rows->groupBy('currency')->map(fn ($group): int => (int) $group->sum('revenue'));
        $events = $this->read->sums('analytics_daily_restaurant', $scope, $period, ['menu_views', 'cart_adds']);
        $sessions = $this->sessionCounts($scope, $period);
        $sold = $this->read->daily('analytics_daily_items', $this->locationScoped($scope), $period, 't', false)->selectRaw('t.currency, coalesce(sum(t.revenue_minor), 0) as revenue, coalesce(sum(t.units_sold), 0) as units, coalesce(sum(t.views), 0) as views')->groupBy('t.currency')->get();

        return [
            'metrics' => [
                'menu_views' => Metric::count('menu_views', $events['menu_views']),
                'item_views' => Metric::count('item_views', (int) $sold->sum('views')),
                'cart_adds' => Metric::count('cart_adds', $events['cart_adds']),
                'units_sold' => Metric::count('units_sold', (int) $sold->sum('units')),
                'item_sales' => Metric::money('item_sales', $sold->mapWithKeys(fn ($r): array => [(string) $r->currency => (int) $r->revenue])->all(), null, $scope->currency),
                'add_to_cart_rate' => Metric::ratio('add_to_cart_rate', $sessions['adds'], $sessions['menus']),
            ],
            'items' => $rows->map(fn ($r): array => [
                'item' => ['id' => $r->item_id, 'name' => (string) $r->name],
                'views' => (int) $r->views, 'cart_adds' => (int) $r->adds, 'units_sold' => (int) $r->units, 'orders' => (int) $r->orders,
                'sales' => ['amount' => (int) $r->revenue, 'currency' => (string) $r->currency],
                'share_of_sales_percent' => ($totals[(string) $r->currency] ?? 0) > 0 ? round((int) $r->revenue * 100 / $totals[(string) $r->currency], 1) : null,
                // Events against events of the same item: how often a view of the item was followed by an add.
                'cart_adds_per_view' => (int) $r->views >= max(1, (int) config('analytics.small_sample.min_denominator')) ? round((int) $r->adds / (int) $r->views, 2) : null,
            ])->all(),
            'sold_source' => 'Order items of completed orders (what the order recorded)',
            'viewed_source' => 'Behavioural events, recorded from Module 38 on',
            'advice_only' => 'These figures are information for the restaurant. Nothing is changed on a menu because of them.',
        ];
    }

    /**
     * New and returning customers of the locations in scope — COUNTS only. A restaurant never gets a list of
     * customers out of analytics, and under the minimum cohort it gets no split at all.
     *
     * @return array<string, mixed>
     */
    public function customerMix(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $locations = implode(',', array_map('intval', $scope->locationIds ?: [0]));
        $row = DB::selectOne(
            "with done as (select customer_key, completed_at from analytics_order_facts where location_id in ({$locations}) and status = 'COMPLETED'),
                per as (select customer_key, count(*) filter (where completed_at < ?) as earlier, count(*) filter (where completed_at >= ? and completed_at < ?) as inside from done group by customer_key)
            select count(*) filter (where inside > 0) as ordering, count(*) filter (where inside > 0 and earlier = 0) as first_time, count(*) filter (where inside > 0 and earlier > 0) as returning, coalesce(sum(inside), 0) as orders from per",
            [$period->startUtc(), $period->startUtc(), $period->endUtc()],
        );
        $ordering = (int) ($row->ordering ?? 0);
        $min = max(1, (int) config('analytics.privacy.min_cohort'));
        $hidden = $ordering > 0 && $ordering < $min;

        return [
            'ordering_customers' => $hidden ? null : $ordering,
            'first_time_here' => $hidden ? null : (int) ($row->first_time ?? 0),
            'returning' => $hidden ? null : (int) ($row->returning ?? 0),
            'orders_per_customer' => $hidden || $ordering === 0 ? null : round((int) $row->orders / $ordering, 2),
            'suppressed' => $hidden, 'minimum_cohort' => $min,
            'statement' => 'Counts of customers who completed an order here. No customer is named or listed.',
        ];
    }

    /**
     * Sessions that saw a menu and sessions that added to a cart at the locations in scope — sessions against sessions.
     *
     * @return array{menus: int, adds: int}
     */
    private function sessionCounts(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $row = DB::table('analytics_events as e')->whereIn('e.market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)->whereNotNull('e.session_id')->whereNotNull('e.location_id')
            ->when($scope->forLocations(), fn ($q) => $q->whereIn('e.location_id', $scope->locationIds === [] ? [0] : $scope->locationIds))
            ->where('e.occurred_at', '>=', $period->startUtc())->where('e.occurred_at', '<', $period->endUtc())->whereIn('e.event_name', ['menu_viewed', 'item_added_to_cart'])
            ->selectRaw("count(distinct (e.session_id, e.location_id)) filter (where e.event_name = 'menu_viewed') as menus,
                count(distinct (e.session_id, e.location_id)) filter (where e.event_name = 'item_added_to_cart' and exists (select 1 from analytics_events m where m.session_id = e.session_id and m.location_id = e.location_id and m.event_name = 'menu_viewed')) as adds")->first();

        return ['menus' => (int) ($row->menus ?? 0), 'adds' => (int) ($row->adds ?? 0)];
    }

    /** The items table has no market column: an administrator's scope is turned into its locations. */
    private function locationScoped(AnalyticsScope $scope): AnalyticsScope
    {
        if ($scope->forLocations()) {
            return $scope;
        }
        $ids = DB::table('restaurant_locations')->whereIn('market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return new AnalyticsScope($scope->audience, $scope->marketIds, $ids, $scope->timezone, $scope->currency, $scope->label);
    }
}
