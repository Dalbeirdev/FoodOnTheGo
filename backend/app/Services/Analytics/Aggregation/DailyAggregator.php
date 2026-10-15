<?php

namespace App\Services\Analytics\Aggregation;

use App\Services\Finance\FinanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily read models of analytics (Module 38). Every method does the same thing for one market and a list of
 * local days: DELETE those days, then write them again from the sources. Running a day twice gives the same rows;
 * a late event, a refund or a correction of last week changes last week when its day is run again.
 *
 * "Local day" is a day in the MARKET's reporting time zone (markets.default_timezone) — and, for the tables that
 * describe one restaurant, a day in that RESTAURANT's own time zone. PostgreSQL converts (`at time zone`), so a
 * market with daylight saving time gets its 23- and 25-hour days right.
 *
 * Nothing here is calculated from a client's event that a domain already knows: order counts come from the orders,
 * money from the orders' own amounts and the financial ledger, refunds from the refund domain, ratings from published
 * reviews, deliveries from the notification domain.
 */
final class DailyAggregator
{
    private const ACTIVE = "('WAITING_FOR_RESTAURANT', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP')";

    /**
     * @param  object{id: int, timezone: string, currency: string, is_default: bool}  $market
     * @param  list<string>  $dates  local days, Y-m-d
     */
    public function run(string $group, object $market, array $dates, CarbonImmutable $now): int
    {
        return match ($group) {
            'orders' => $this->orders($market, $dates, $now),
            'customers' => $this->customers($market, $dates, $now),
            'restaurants' => $this->restaurants($market, $dates, $now),
            'items' => $this->items($market, $dates, $now),
            'funnel' => $this->funnel($market, $dates, $now),
            'routes' => $this->routes($market, $dates, $now),
            'payments' => $this->payments($market, $dates, $now),
            'refunds' => $this->refunds($market, $dates, $now),
            'finance' => $this->finance($market, $dates, $now),
            'support' => $this->support($market, $dates, $now),
            'notifications' => $this->notifications($market, $dates, $now),
            'pickup' => $this->pickup($market, $dates, $now),
            default => 0,
        };
    }

    private function orders(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        DB::delete('delete from analytics_daily_market where market_id = ? and local_date = any(?::date[])', [$m->id, $days]);
        $rows = DB::affectingStatement(
            "insert into analytics_daily_market (market_id, local_date, currency, timezone, orders_placed, placed_total_minor, cohort_completed, cohort_rejected, cohort_cancelled, cohort_active, cohort_refunded, cohort_accepted,
                orders_completed, gmv_minor, accept_seconds_sum, accept_count, prep_seconds_sum, prep_count, pickup_wait_seconds_sum, pickup_wait_count, total_seconds_sum, total_count, computed_at)
            select ?, d.local_date, d.currency, ?, sum(d.placed), sum(d.placed_total), sum(d.completed_c), sum(d.rejected_c), sum(d.cancelled_c), sum(d.active_c), sum(d.refunded_c), sum(d.accepted_c), sum(d.completed), sum(d.gmv),
                coalesce(sum(d.accept_s), 0), count(d.accept_s), coalesce(sum(d.prep_s), 0), count(d.prep_s), coalesce(sum(d.wait_s), 0), count(d.wait_s), coalesce(sum(d.total_s), 0), count(d.total_s), ?
            from (
                select (f.placed_at at time zone {$z})::date as local_date, f.currency, 1 as placed, f.total_minor as placed_total, (f.status = 'COMPLETED')::int as completed_c, (f.status = 'REJECTED')::int as rejected_c,
                    (f.status = 'CANCELLED')::int as cancelled_c, (f.status in ".self::ACTIVE.")::int as active_c, (f.refunds_completed > 0)::int as refunded_c, (f.accepted_at is not null)::int as accepted_c, 0 as completed, 0::bigint as gmv,
                    f.accept_seconds as accept_s, f.prep_seconds as prep_s, f.pickup_wait_seconds as wait_s, f.total_seconds as total_s
                from analytics_order_facts f where f.market_id = ? and f.placed_at >= ? and f.placed_at < ?
                union all
                select (f.completed_at at time zone {$z})::date, f.currency, 0, 0, 0, 0, 0, 0, 0, 0, 1, f.total_minor, null, null, null, null
                from analytics_order_facts f where f.market_id = ? and f.status = 'COMPLETED' and f.completed_at >= ? and f.completed_at < ?
            ) d where d.local_date = any(?::date[]) group by d.local_date, d.currency",
            [$m->id, $m->timezone, $now, $m->id, $from, $to, $m->id, $from, $to, $days],
        );

        // The breakdowns include one in each restaurant's own days, so the neighbouring days are written again too.
        $wide = $this->around($dates);
        $this->clear('orders', $m, $wide);
        $rows += $this->breakdown('orders', $now, $this->days($wide),
            "select (f.placed_at at time zone {$z})::date as local_date, f.market_id, null::bigint as location_id, f.currency, x.dimension, x.value, count(*) as n, coalesce(sum(f.total_minor), 0) as amount, 0 as seconds_sum, 0 as seconds_count
            from analytics_order_facts f
            cross join lateral (values ('status', f.status), ('rejection_reason', case when f.status = 'REJECTED' then coalesce(f.rejection_reason, 'UNKNOWN') end), ('cancelled_by', case when f.status = 'CANCELLED' then coalesce(f.cancelled_by_type, 'UNKNOWN') end)) as x(dimension, value)
            where f.market_id = ? and f.placed_at >= ? and f.placed_at < ? and x.value is not null group by 1, 2, 4, 5, 6",
            [$m->id, $from, $to]);
        // Peak times, in each restaurant's own time zone: ISO day of week (1 = Monday) and hour.
        $rows += $this->breakdown('orders', $now, $this->days($wide),
            "select (f.placed_at at time zone f.restaurant_timezone)::date as local_date, f.market_id, f.location_id, f.currency, 'dow_hour' as dimension,
                extract(isodow from (f.placed_at at time zone f.restaurant_timezone))::int || ':' || lpad(extract(hour from (f.placed_at at time zone f.restaurant_timezone))::int::text, 2, '0') as value,
                count(*) as n, coalesce(sum(f.total_minor), 0) as amount, 0 as seconds_sum, 0 as seconds_count
            from analytics_order_facts f where f.market_id = ? and f.placed_at >= ? and f.placed_at < ? group by 1, 2, 3, 4, 6",
            [$m->id, $from, $to]);

        return $rows;
    }

    private function customers(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        $days = $this->days($dates);
        DB::delete('delete from analytics_daily_customers where market_id = ? and local_date = any(?::date[])', [$m->id, $days]);

        // A customer without a market belongs to the default market — there is one account space, not one per market.
        // Module 40: every figure is computed ONCE, grouped by day, and the scans of customers, order facts and events
        // are bounded by the UTC window of the requested days (their market + time indexes). The first version ran
        // five correlated sub-queries per requested day, each over the market's whole history — on the large perf
        // dataset (187,515 orders, 2,000,068 events, 365 days) one statement ran for minutes. Same figures: the first
        // completed order is still taken over the whole history, and the join to the requested days drops the rest.
        [$from, $to] = $this->window($dates);

        return DB::affectingStatement(
            "with days as (select unnest(?::date[]) as d),
                done as (select customer_key, (completed_at at time zone {$z})::date as d from analytics_order_facts where market_id = ? and status = 'COMPLETED'),
                firsts as (select customer_key, min(d) as first_d from done group by customer_key),
                in_days as (select customer_key, d from done where d in (select d from days)),
                new_accounts as (select (c.created_at at time zone {$z})::date as d, count(*) as n from customers c
                    where (c.market_id = ? or (c.market_id is null and ?)) and c.created_at >= ? and c.created_at < ? group by 1),
                first_times as (select first_d as d, count(*) as n from firsts group by 1),
                orderers as (select d, count(distinct customer_key) as n from in_days group by d),
                repeaters as (select w.d, count(distinct w.customer_key) as n from in_days w join firsts f on f.customer_key = w.customer_key where f.first_d < w.d group by w.d),
                actives as (select d, count(*) as n from (
                    select (placed_at at time zone {$z})::date as d, customer_key from analytics_order_facts where market_id = ? and placed_at >= ? and placed_at < ?
                    union select (occurred_at at time zone {$z})::date as d, customer_key from analytics_events where market_id = ? and customer_key is not null and occurred_at >= ? and occurred_at < ?
                ) a group by d)
            insert into analytics_daily_customers (market_id, local_date, timezone, new_accounts, first_time_ordering, ordering_customers, repeat_ordering, active_customers, computed_at)
            select y.* from (select ?::bigint as market_id, days.d, ?::text as timezone,
                coalesce(na.n, 0), coalesce(ft.n, 0), coalesce(o.n, 0), coalesce(r.n, 0), coalesce(a.n, 0),
                ?::timestamptz as computed_at
            from days
                left join new_accounts na on na.d = days.d left join first_times ft on ft.d = days.d left join orderers o on o.d = days.d
                left join repeaters r on r.d = days.d left join actives a on a.d = days.d
            ) as y(market_id, d, timezone, new_accounts, first_time_ordering, ordering_customers, repeat_ordering, active_customers, computed_at)
            where y.new_accounts + y.first_time_ordering + y.ordering_customers + y.repeat_ordering + y.active_customers > 0",
            [$days, $m->id, $m->id, $m->is_default, $from, $to, $m->id, $from, $to, $m->id, $from, $to, $m->id, $m->timezone, $now],
        );
    }

    private function restaurants(object $m, array $dates, CarbonImmutable $now): int
    {
        $dates = $this->around($dates);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        DB::delete('delete from analytics_daily_restaurant where market_id = ? and local_date = any(?::date[])', [$m->id, $days]);
        $rows = 0;

        // 1. The orders PLACED on the day, and what became of them.
        $rows += $this->restaurantRows($now, $days,
            ['orders_placed', 'placed_total_minor', 'cohort_completed', 'cohort_rejected', 'cohort_cancelled', 'cohort_active', 'cohort_refunded', 'cohort_accepted', 'journey_orders',
                'accept_seconds_sum', 'accept_count', 'prep_seconds_sum', 'prep_count', 'pickup_wait_seconds_sum', 'pickup_wait_count'],
            "select f.location_id, (f.placed_at at time zone l.timezone)::date as local_date, count(*), coalesce(sum(f.total_minor), 0), count(*) filter (where f.status = 'COMPLETED'), count(*) filter (where f.status = 'REJECTED'),
                count(*) filter (where f.status = 'CANCELLED'), count(*) filter (where f.status in ".self::ACTIVE.'), count(*) filter (where f.refunds_completed > 0), count(*) filter (where f.accepted_at is not null),
                count(*) filter (where f.journey_key is not null), coalesce(sum(f.accept_seconds), 0), count(f.accept_seconds), coalesce(sum(f.prep_seconds), 0), count(f.prep_seconds), coalesce(sum(f.pickup_wait_seconds), 0), count(f.pickup_wait_seconds)
            from analytics_order_facts f join restaurant_locations l on l.id = f.location_id where f.market_id = ? and f.placed_at >= ? and f.placed_at < ? group by 1, 2',
            [$m->id, $from, $to]);
        // 2. The orders COMPLETED on the day.
        $rows += $this->restaurantRows($now, $days, ['orders_completed', 'completed_total_minor'],
            "select f.location_id, (f.completed_at at time zone l.timezone)::date as local_date, count(*), coalesce(sum(f.total_minor), 0)
            from analytics_order_facts f join restaurant_locations l on l.id = f.location_id where f.market_id = ? and f.status = 'COMPLETED' and f.completed_at >= ? and f.completed_at < ? group by 1, 2",
            [$m->id, $from, $to]);
        // 3. What refunds actually returned on the day (refund domain).
        $rows += $this->restaurantRows($now, $days, ['refunded_minor'],
            "select r.location_id, (r.completed_at at time zone l.timezone)::date as local_date, coalesce(sum(coalesce(r.approved_amount_minor, r.requested_amount_minor)), 0)
            from refunds r join restaurant_locations l on l.id = r.location_id where l.market_id = ? and r.status = 'REFUNDED' and r.completed_at >= ? and r.completed_at < ? group by 1, 2",
            [$m->id, $from, $to]);
        // 4. Behaviour: returned in discovery (server-recorded), opened, menu shown, added to a cart (sessions' events).
        $rows += $this->restaurantRows($now, $days, ['impressions', 'restaurant_views', 'menu_views', 'cart_adds'],
            "select e.location_id, (e.occurred_at at time zone l.timezone)::date as local_date, count(*) filter (where e.event_name = 'restaurant_impression'), count(*) filter (where e.event_name = 'restaurant_viewed'),
                count(*) filter (where e.event_name = 'menu_viewed'), count(*) filter (where e.event_name = 'item_added_to_cart')
            from analytics_events e join restaurant_locations l on l.id = e.location_id
            where l.market_id = ? and e.occurred_at >= ? and e.occurred_at < ? and e.event_name in ('restaurant_impression', 'restaurant_viewed', 'menu_viewed', 'item_added_to_cart') group by 1, 2",
            [$m->id, $from, $to]);
        // 5. Reviews PUBLISHED on the day. A hidden, rejected or removed review is not published and is not counted.
        $rows += $this->restaurantRows($now, $days, ['reviews_published', 'rating_sum', 'star_1', 'star_2', 'star_3', 'star_4', 'star_5'],
            "select v.location_id, (v.published_at at time zone l.timezone)::date as local_date, count(*), coalesce(sum(v.overall_rating), 0), count(*) filter (where v.overall_rating = 1), count(*) filter (where v.overall_rating = 2),
                count(*) filter (where v.overall_rating = 3), count(*) filter (where v.overall_rating = 4), count(*) filter (where v.overall_rating = 5)
            from restaurant_reviews v join restaurant_locations l on l.id = v.location_id where l.market_id = ? and v.status = 'PUBLISHED' and v.published_at >= ? and v.published_at < ? group by 1, 2",
            [$m->id, $from, $to]);
        // 6. Responses the restaurant published to reviews that are published, by the day of the REVIEW.
        $rows += $this->restaurantRows($now, $days, ['review_responses'],
            "select v.location_id, (v.published_at at time zone l.timezone)::date as local_date, count(*)
            from restaurant_review_responses p join restaurant_reviews v on v.id = p.review_id join restaurant_locations l on l.id = v.location_id
            where l.market_id = ? and p.status = 'PUBLISHED' and v.status = 'PUBLISHED' and v.published_at >= ? and v.published_at < ? group by 1, 2",
            [$m->id, $from, $to]);
        $rows += $this->restaurantRows($now, $days, ['review_reports'],
            'select v.location_id, (p.created_at at time zone l.timezone)::date as local_date, count(*)
            from review_reports p join restaurant_reviews v on v.id = p.review_id join restaurant_locations l on l.id = v.location_id
            where l.market_id = ? and p.created_at >= ? and p.created_at < ? group by 1, 2',
            [$m->id, $from, $to]);

        return $rows;
    }

    /**
     * Writes some columns of analytics_daily_restaurant from a select of (location_id, local_date, the columns…).
     * The day's rows were deleted first and every statement SETS its own columns, so a rerun changes nothing.
     *
     * @param  list<string>  $columns
     */
    private function restaurantRows(CarbonImmutable $now, string $days, array $columns, string $select, array $bindings): int
    {
        $aliases = implode(', ', array_map(fn (int $i): string => 'c'.$i, array_keys($columns)));

        return DB::affectingStatement(
            'insert into analytics_daily_restaurant (location_id, organization_id, market_id, local_date, timezone, currency, '.implode(', ', $columns).", computed_at)
            select x.location_id, l.organization_id, l.market_id, x.local_date, l.timezone, l.currency, {$aliases}, ?
            from ({$select}) as x(location_id, local_date, {$aliases}) join restaurant_locations l on l.id = x.location_id where x.local_date = any(?::date[])
            on conflict (location_id, local_date) do update set ".implode(', ', array_map(fn (string $c): string => "{$c} = excluded.{$c}", $columns)).', computed_at = excluded.computed_at',
            [$now, ...$bindings, $days],
        );
    }

    private function items(object $m, array $dates, CarbonImmutable $now): int
    {
        $dates = $this->around($dates);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        DB::delete('delete from analytics_daily_items where local_date = any(?::date[]) and location_id in (select id from restaurant_locations where market_id = ?)', [$days, $m->id]);

        // What was SOLD: the items of completed orders, as the order recorded them. Not what was put in a cart.
        $rows = DB::affectingStatement(
            "insert into analytics_daily_items (location_id, local_date, item_key, item_public_id, name, currency, units_sold, revenue_minor, orders, computed_at)
            select x.location_id, x.local_date, x.item_key, x.item_public_id, x.name, x.currency, x.units, x.revenue, x.orders, ? from (
                select f.location_id, (f.completed_at at time zone l.timezone)::date as local_date, coalesce(i.item_public_id::text, 'name:' || md5(i.name)) as item_key, (array_agg(i.item_public_id))[1] as item_public_id,
                    max(i.name) as name, max(i.currency) as currency, sum(i.quantity) as units, coalesce(sum(i.line_subtotal_minor), 0) as revenue, count(distinct f.order_id) as orders
                from analytics_order_facts f join order_items i on i.order_id = f.order_id join restaurant_locations l on l.id = f.location_id
                where f.market_id = ? and f.status = 'COMPLETED' and f.completed_at >= ? and f.completed_at < ? group by 1, 2, 3
            ) x where x.local_date = any(?::date[])",
            [$now, $m->id, $from, $to, $days],
        );
        // What sessions LOOKED AT and put in a cart: behavioural events. Kept apart from what was sold.
        $rows += DB::affectingStatement(
            "insert into analytics_daily_items (location_id, local_date, item_key, item_public_id, name, currency, views, cart_adds, computed_at)
            select x.location_id, x.local_date, x.item_key, x.item_public_id, x.name, x.currency, x.views, x.adds, ? from (
                select e.location_id, (e.occurred_at at time zone l.timezone)::date as local_date, e.item_ref::text as item_key, e.item_ref as item_public_id, coalesce(max(mi.name), 'Item no longer on the menu') as name, l.currency,
                    count(*) filter (where e.event_name = 'item_viewed') as views, count(*) filter (where e.event_name = 'item_added_to_cart') as adds
                from analytics_events e join restaurant_locations l on l.id = e.location_id left join menu_items mi on mi.public_id = e.item_ref
                where l.market_id = ? and e.item_ref is not null and e.event_name in ('item_viewed', 'item_added_to_cart') and e.occurred_at >= ? and e.occurred_at < ? group by 1, 2, 3, 4, l.currency
            ) x where x.local_date = any(?::date[])
            on conflict (location_id, local_date, item_key) do update set views = excluded.views, cart_adds = excluded.cart_adds, computed_at = excluded.computed_at",
            [$now, $m->id, $from, $to, $days],
        );

        return $rows;
    }

    private function funnel(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        DB::delete('delete from analytics_daily_funnel where market_id = ? and local_date = any(?::date[])', [$m->id, $days]);

        // Sessions of the day's cohort that reached each stage, in order. A session is counted once per stage.
        $rows = DB::affectingStatement(
            "insert into analytics_daily_funnel (market_id, local_date, platform, stage, sessions, customers, events, computed_at)
            select ?, s.local_date, coalesce(s.platform, 'WEB'), g.stage, count(*) filter (where s.stage >= g.stage), count(distinct s.customer_key) filter (where s.stage >= g.stage and s.customer_key is not null), 0, ?
            from analytics_session_facts s cross join generate_series(1, 11) as g(stage)
            where s.market_id = ? and s.local_date = any(?::date[]) and s.stage >= 1 group by s.local_date, coalesce(s.platform, 'WEB'), g.stage",
            [$m->id, $now, $m->id, $days],
        );
        // Every event of a stage's name on the day, repeats included — a different number, never mixed with sessions.
        $cases = '';
        foreach ((array) config('analytics.funnel.stages') as $number => $stage) {
            if (($stage['event'] ?? null) !== null && preg_match('/\A[a-z_]+\z/', (string) $stage['event']) === 1) {
                $cases .= " when '".$stage['event']."' then ".(int) $number;
            }
        }
        $rows += DB::affectingStatement(
            "insert into analytics_daily_funnel (market_id, local_date, platform, stage, sessions, customers, events, computed_at)
            select ?, x.local_date, x.platform, x.stage, 0, 0, x.n, ? from (
                select (e.occurred_at at time zone {$z})::date as local_date, coalesce(e.platform, 'WEB') as platform, (case e.event_name {$cases} end) as stage, count(*) as n
                from analytics_events e where e.market_id = ? and e.source = 'CLIENT' and e.occurred_at >= ? and e.occurred_at < ? group by 1, 2, 3
            ) x where x.stage is not null and x.local_date = any(?::date[])
            on conflict (market_id, local_date, platform, stage) do update set events = excluded.events, computed_at = excluded.computed_at",
            [$m->id, $now, $m->id, $from, $to, $days],
        );

        return $rows;
    }

    private function routes(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        DB::delete('delete from analytics_daily_routes where market_id = ? and local_date = any(?::date[])', [$m->id, $days]);

        // One line per thing that happened to a journey, with the corridors its search recorded. A journey along two
        // corridors counts in both; ALL counts it once.
        $source = "with searches as (select journey_key, properties->'corridors' as corridors from analytics_events where event_name = 'journey_search_succeeded' and market_id = ?),
            lines as (
                select (e.occurred_at at time zone {$z})::date as d, coalesce(s.corridors, '[]'::jsonb) as corridors, (e.event_name = 'journey_search_succeeded')::int as searches, (e.event_name = 'journey_search_no_result')::int as no_result,
                    (e.event_name = 'restaurant_impression')::int as impressions, (e.event_name = 'restaurant_viewed')::int as views, 0 as orders, 0 as completed, 0::bigint as detour_sum, 0 as detour_n
                from analytics_events e left join searches s on s.journey_key = e.journey_key
                where e.market_id = ? and e.journey_key is not null and e.event_name in ('journey_search_succeeded', 'journey_search_no_result', 'restaurant_impression', 'restaurant_viewed') and e.occurred_at >= ? and e.occurred_at < ?
                union all
                select (f.placed_at at time zone {$z})::date, f.corridors, 0, 0, 0, 0, 1, (f.status = 'COMPLETED')::int, coalesce(f.detour_seconds, 0), (f.detour_seconds is not null)::int
                from analytics_order_facts f where f.market_id = ? and f.journey_key is not null and f.placed_at >= ? and f.placed_at < ?
            )";
        $sums = 'sum(searches), sum(no_result), sum(impressions), sum(views), sum(orders), sum(completed), sum(detour_sum), sum(detour_n), sum(impressions)';
        $rows = DB::affectingStatement(
            "{$source}
            insert into analytics_daily_routes (market_id, local_date, bucket, corridor_id, searches, no_result_searches, impressions, restaurant_views, orders, orders_completed, detour_seconds_sum, detour_count, restaurants_returned, computed_at)
            select ?, y.d, y.bucket, y.corridor_id, y.c1, y.c2, y.c3, y.c4, y.c5, y.c6, y.c7, y.c8, y.c9, ? from (
                select d, 'ALL' as bucket, null::bigint as corridor_id, {$sums} from lines group by d
                union all select d, 'NO_CORRIDOR', null::bigint, {$sums} from lines where jsonb_array_length(corridors) = 0 group by d
                union all select l.d, 'CORRIDOR', c.id, {$sums} from lines l cross join lateral jsonb_array_elements_text(l.corridors) as x(id) join route_corridors c on c.id = x.id::bigint group by l.d, c.id
            ) as y(d, bucket, corridor_id, c1, c2, c3, c4, c5, c6, c7, c8, c9) where y.d = any(?::date[])",
            [$m->id, $m->id, $from, $to, $m->id, $from, $to, $m->id, $now, $days],
        );

        $this->clear('routes', $m, $dates);
        // Bands of the journeys planned on the day, and where they began and ended — as a city, never as a point.
        $rows += $this->breakdown('routes', $now, $days,
            "select (e.occurred_at at time zone {$z})::date as local_date, e.market_id, null::bigint as location_id, '' as currency, x.dimension, x.value, count(*) as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from analytics_events e cross join lateral (values ('distance_band', e.properties->>'distance_band'), ('duration_band', e.properties->>'duration_band'), ('origin_city', e.properties->>'origin_city'), ('destination_city', e.properties->>'destination_city')) as x(dimension, value)
            where e.market_id = ? and e.event_name = 'journey_search_succeeded' and e.occurred_at >= ? and e.occurred_at < ? and x.value is not null group by 1, 2, 5, 6",
            [$m->id, $from, $to]);
        // Searches that found no restaurant: why, and near which cities the journey began and ended.
        $rows += $this->breakdown('routes', $now, $days,
            "select (e.occurred_at at time zone {$z})::date as local_date, e.market_id, null::bigint as location_id, '' as currency, x.dimension, x.value, count(*) as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from analytics_events e left join analytics_events s on s.journey_key = e.journey_key and s.event_name = 'journey_search_succeeded'
            cross join lateral (values ('no_result_reason', e.properties->>'reason'), ('no_result_origin_city', s.properties->>'origin_city'), ('no_result_destination_city', s.properties->>'destination_city')) as x(dimension, value)
            where e.market_id = ? and e.event_name = 'journey_search_no_result' and e.occurred_at >= ? and e.occurred_at < ? and x.value is not null group by 1, 2, 5, 6",
            [$m->id, $from, $to]);
        $rows += $this->breakdown('routes', $now, $days,
            "select (e.occurred_at at time zone {$z})::date as local_date, e.market_id, null::bigint as location_id, '' as currency, 'detour_band_impressions' as dimension, e.properties->>'detour_band' as value, count(*) as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from analytics_events e where e.market_id = ? and e.event_name = 'restaurant_impression' and e.occurred_at >= ? and e.occurred_at < ? and e.properties->>'detour_band' is not null group by 1, 2, 6",
            [$m->id, $from, $to]);
        // Orders that came from a journey, by the band of their detour. seconds_count carries how many were completed.
        $rows += $this->breakdown('routes', $now, $days,
            "select (f.placed_at at time zone {$z})::date as local_date, f.market_id, null::bigint as location_id, '' as currency, 'detour_band_orders' as dimension, f.detour_band as value, count(*) as n, 0 as amount, 0 as seconds_sum,
                count(*) filter (where f.status = 'COMPLETED') as seconds_count
            from analytics_order_facts f where f.market_id = ? and f.detour_band is not null and f.placed_at >= ? and f.placed_at < ? group by 1, 2, 6",
            [$m->id, $from, $to]);

        return $rows;
    }

    private function payments(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        $this->clear('payments', $m, $dates);
        $latency = fn (string $to, string $from): string => "case when p.{$to} is not null then greatest(0, extract(epoch from (p.{$to} - p.{$from}))) end";

        // Payment attempts by the day they were created (payment domain). seconds = created → verified.
        return $this->breakdown('payments', $now, $days,
            "select (p.created_at at time zone {$z})::date as local_date, p.market_id, null::bigint as location_id, p.currency, x.dimension, x.value, count(*) as n, coalesce(sum(p.amount_minor), 0) as amount,
                coalesce(sum(x.seconds), 0)::bigint as seconds_sum, count(x.seconds) as seconds_count
            from payment_attempts p cross join lateral (values
                ('status', p.status, {$latency('verified_at', 'created_at')}),
                ('method', case when p.status = 'VERIFIED' then coalesce(p.method_type, 'UNKNOWN') end, {$latency('verified_at', 'created_at')}),
                ('method_finalized', case when p.status in ('VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED') then coalesce(p.method_type, p.requested_method_type, 'UNKNOWN') end, null),
                ('failure_category', case when p.status in ('FAILED', 'CANCELLED', 'EXPIRED') then coalesce(p.failure_category, 'UNKNOWN') end, null),
                ('provider', p.provider, {$latency('provider_ready_at', 'created_at')})
            ) as x(dimension, value, seconds)
            where p.market_id = ? and p.created_at >= ? and p.created_at < ? and x.value is not null group by 1, 2, 4, 5, 6",
            [$m->id, $from, $to]);
    }

    private function refunds(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        $this->clear('refunds', $m, $dates);
        $amount = 'coalesce(r.approved_amount_minor, r.requested_amount_minor)';
        $asked = 'coalesce(r.requested_at, r.created_at)';

        // Refunds by the day they were asked for: where they stand, and why they were asked.
        $rows = $this->breakdown('refunds', $now, $days,
            "select ({$asked} at time zone {$z})::date as local_date, r.market_id, null::bigint as location_id, r.currency, x.dimension, x.value, count(*) as n, coalesce(sum({$amount}), 0) as amount, 0 as seconds_sum, 0 as seconds_count
            from refunds r cross join lateral (values ('status', r.status), ('reason', coalesce(r.reason_code, 'UNKNOWN'))) as x(dimension, value)
            where r.market_id = ? and {$asked} >= ? and {$asked} < ? group by 1, 2, 4, 5, 6",
            [$m->id, $from, $to]);
        // Refunds by the day they COMPLETED: full or partial, the money that went back, and how long it took.
        $rows += $this->breakdown('refunds', $now, $days,
            "select (r.completed_at at time zone {$z})::date as local_date, r.market_id, null::bigint as location_id, r.currency, 'completed' as dimension, coalesce(r.type, 'UNKNOWN') as value, count(*) as n, coalesce(sum({$amount}), 0) as amount,
                coalesce(sum(greatest(0, extract(epoch from (r.completed_at - {$asked})))), 0)::bigint as seconds_sum, count(*) as seconds_count
            from refunds r where r.market_id = ? and r.status = 'REFUNDED' and r.completed_at >= ? and r.completed_at < ? group by 1, 2, 4, 6",
            [$m->id, $from, $to]);

        return $rows;
    }

    private function finance(object $m, array $dates, CarbonImmutable $now): int
    {
        $rows = $this->financeFor($m->id, $m->timezone, $dates, $now);
        // Ledger lines without a market (a provider's fee, a provider's settlement): once, in the default market's days.
        if ($m->is_default) {
            $rows += $this->financeFor(null, $m->timezone, $dates, $now);
        }

        return $rows;
    }

    /**
     * The financial ledger per day — the SAME figures FinanceReportService::overview reads for a period, kept per day.
     */
    private function financeFor(?int $marketId, string $timezone, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        $where = $marketId === null ? 'is null' : '= '.(int) $marketId;
        DB::delete("delete from analytics_daily_finance where market_id {$where} and local_date = any(?::date[])", [$days]);

        return DB::affectingStatement(
            "insert into analytics_daily_finance (market_id, local_date, currency, timezone, measure, amount_minor, count, computed_at)
            select ?, x.local_date, x.currency, ?, x.measure, x.amount, x.n, ? from (
                select (e.occurred_at at time zone {$z})::date as local_date, e.currency, a.code as measure, sum(case when e.direction = a.normal_side then e.amount_minor else -e.amount_minor end) as amount, count(*) as n
                from ledger_entries e join ledger_accounts a on a.id = e.account_id where e.market_id {$where} and e.occurred_at >= ? and e.occurred_at < ? group by 1, 2, 3
                union all
                select (t.occurred_at at time zone {$z})::date, t.currency, case t.type when 'PAYMENT_VERIFIED' then 'CUSTOMER_PAYMENTS' else 'REFUNDS' end, coalesce(sum(t.amount_minor), 0), count(*)
                from ledger_transactions t where t.market_id {$where} and t.type in ('PAYMENT_VERIFIED', 'REFUND_COMPLETED') and t.occurred_at >= ? and t.occurred_at < ? group by 1, 2, 3
                union all
                select (o.recognized_at at time zone {$z})::date, o.currency, v.measure, coalesce(sum(v.amount), 0), count(*)
                from order_financial_allocations o cross join lateral (values ('GROSS_MARKETPLACE_SALES', o.merchandise_gross_minor), ('RESTAURANT_EARNINGS', o.restaurant_net_minor)) as v(measure, amount)
                where o.market_id {$where} and o.status = 'RECOGNIZED' and o.recognized_at >= ? and o.recognized_at < ? group by 1, 2, 3
            ) x where x.local_date = any(?::date[])",
            [$marketId, $timezone, $now, $from, $to, $from, $to, $from, $to, $days],
        );
    }

    private function support(object $m, array $dates, CarbonImmutable $now): int
    {
        $rows = $this->supportFor($m->id, $m->timezone, $dates, $now);
        if ($m->is_default) {
            $rows += $this->supportFor(null, $m->timezone, $dates, $now);
        }

        return $rows;
    }

    /**
     * Support cases per day (support domain): each count on the day its own timestamp says it happened.
     */
    private function supportFor(?int $marketId, string $timezone, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        $where = $marketId === null ? 'is null' : '= '.(int) $marketId;
        DB::delete("delete from analytics_daily_support where market_id {$where} and local_date = any(?::date[])", [$days]);
        $part = fn (string $column, string $fields): string => "select (c.{$column} at time zone {$z})::date as d, c.category, c.priority, {$fields} from support_cases c where c.market_id {$where} and c.{$column} >= ? and c.{$column} < ?";
        $response = 'case when c.first_response_at is not null then greatest(0, extract(epoch from (c.first_response_at - c.created_at))) end';
        $resolution = 'greatest(0, extract(epoch from (c.resolved_at - c.created_at)))';

        return DB::affectingStatement(
            "insert into analytics_daily_support (market_id, local_date, category, priority, created, resolved, closed, reopened, escalated, sla_breached, first_response_seconds_sum, first_response_count, resolution_seconds_sum, resolution_count, computed_at)
            select ?, x.d, x.category, x.priority, sum(x.created), sum(x.resolved), sum(x.closed), sum(x.reopened), sum(x.escalated), sum(x.breached), coalesce(sum(x.response_s), 0)::bigint, count(x.response_s), coalesce(sum(x.resolution_s), 0)::bigint, count(x.resolution_s), ?
            from (
                {$part('created_at', "1 as created, 0 as resolved, 0 as closed, 0 as reopened, 0 as escalated, 0 as breached, {$response} as response_s, null::numeric as resolution_s")}
                union all {$part('resolved_at', "0, 1, 0, 0, 0, 0, null::numeric, {$resolution}")}
                union all {$part('closed_at', '0, 0, 1, 0, 0, 0, null::numeric, null::numeric')}
                union all {$part('reopened_at', '0, 0, 0, 1, 0, 0, null::numeric, null::numeric')}
                union all {$part('escalated_at', '0, 0, 0, 0, 1, 0, null::numeric, null::numeric')}
                union all {$part('sla_breached_at', '0, 0, 0, 0, 0, 1, null::numeric, null::numeric')}
            ) x where x.d = any(?::date[]) group by x.d, x.category, x.priority",
            [$marketId, $now, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $days],
        );
    }

    private function notifications(object $m, array $dates, CarbonImmutable $now): int
    {
        $z = FinanceReportService::zone($m->timezone);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        $this->clear('notifications', $m, $dates);
        // A notification has no market of its own: it is its customer's.
        $mine = '(c.market_id = ? or (c.market_id is null and ?))';

        // Notifications by the day they were created. "read" is the IN-APP read mark — nothing says a push was read.
        $rows = $this->breakdown('notifications', $now, $days,
            "select (n.created_at at time zone {$z})::date as local_date, ?::bigint as market_id, null::bigint as location_id, '' as currency, x.dimension, x.value, count(*) as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from notifications n join customers c on c.id = n.customer_id cross join lateral (values ('created', n.category), ('in_app_read', case when n.read_at is not null then n.category end)) as x(dimension, value)
            where {$mine} and n.created_at >= ? and n.created_at < ? and x.value is not null group by 1, 5, 6",
            [$m->id, $m->id, $m->is_default, $from, $to]);
        // Deliveries per channel and status; why some were not attempted; why some failed.
        $rows += $this->breakdown('notifications', $now, $days,
            "select (n.created_at at time zone {$z})::date as local_date, ?::bigint as market_id, null::bigint as location_id, '' as currency, x.dimension, x.value, count(*) as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from notification_deliveries d join notifications n on n.id = d.notification_id join customers c on c.id = n.customer_id
            cross join lateral (values ('delivery', d.channel || ':' || d.status), ('policy', case when d.status = 'SKIPPED' then d.channel || ':' || coalesce(d.policy_result, 'UNKNOWN') end),
                ('error', case when d.status in ('FAILED', 'INVALID_DESTINATION') then d.channel || ':' || coalesce(d.last_error_category, 'UNKNOWN') end)) as x(dimension, value)
            where {$mine} and n.created_at >= ? and n.created_at < ? and x.value is not null group by 1, 5, 6",
            [$m->id, $m->id, $m->is_default, $from, $to]);
        // The provider's answer time per attempt. NOTE: this one dimension keeps MILLISECONDS in seconds_sum.
        $rows += $this->breakdown('notifications', $now, $days,
            "select (n.created_at at time zone {$z})::date as local_date, ?::bigint as market_id, null::bigint as location_id, '' as currency, 'latency_ms' as dimension, d.channel || ':' || coalesce(d.provider, 'NONE') as value, count(*) as n, 0 as amount,
                coalesce(sum(a.latency_ms), 0)::bigint as seconds_sum, count(a.latency_ms) as seconds_count
            from notification_delivery_attempts a join notification_deliveries d on d.id = a.delivery_id join notifications n on n.id = d.notification_id join customers c on c.id = n.customer_id
            where {$mine} and n.created_at >= ? and n.created_at < ? group by 1, 6",
            [$m->id, $m->id, $m->is_default, $from, $to]);

        return $rows;
    }

    private function pickup(object $m, array $dates, CarbonImmutable $now): int
    {
        $dates = $this->around($dates);
        [$from, $to] = $this->window($dates);
        $days = $this->days($dates);
        $this->clear('pickup', $m, $dates);

        // What orders chose (order domain), in the restaurant's own days. seconds = placed → pickup time (lead time).
        $rows = $this->breakdown('pickup', $now, $days,
            "select (f.placed_at at time zone f.restaurant_timezone)::date as local_date, f.market_id, f.location_id, '' as currency, x.dimension, x.value, count(*) as n, 0 as amount,
                coalesce(sum(greatest(0, extract(epoch from (f.pickup_at - f.placed_at)))) filter (where f.pickup_at is not null), 0)::bigint as seconds_sum, count(f.pickup_at) as seconds_count
            from analytics_order_facts f cross join lateral (values ('mode', f.pickup_mode), ('method', f.pickup_method)) as x(dimension, value)
            where f.market_id = ? and f.placed_at >= ? and f.placed_at < ? and x.value is not null group by 1, 2, 3, 5, 6",
            [$m->id, $from, $to]);
        // Pickup times the backend refused (server-recorded), and what sessions selected (behavioural).
        $rows += $this->breakdown('pickup', $now, $days,
            "select (e.occurred_at at time zone l.timezone)::date as local_date, l.market_id, e.location_id, '' as currency, x.dimension, x.value, count(*) as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from analytics_events e join restaurant_locations l on l.id = e.location_id
            cross join lateral (values ('refused', case when e.event_name = 'pickup_slot_refused' then coalesce(e.properties->>'reason', 'OTHER') end),
                ('selected', case when e.event_name = 'pickup_selected' then coalesce(e.properties->>'mode', 'UNKNOWN') end),
                ('recommended', case when e.event_name = 'pickup_selected' and e.properties->>'recommended' is not null then e.properties->>'recommended' end)) as x(dimension, value)
            where l.market_id = ? and e.event_name in ('pickup_slot_refused', 'pickup_selected') and e.occurred_at >= ? and e.occurred_at < ? and x.value is not null group by 1, 2, 3, 5, 6",
            [$m->id, $from, $to]);
        // Capacity — ONLY where the restaurant configured one. count = slots (or units); nothing is made up for "unlimited".
        $rows += $this->breakdown('pickup', $now, $days,
            "select s.local_date, s.market_id, s.location_id, '' as currency, 'capacity' as dimension, x.value, sum(x.n)::int as n, 0 as amount, 0 as seconds_sum, 0 as seconds_count
            from (
                select (r.slot_starts_at at time zone l.timezone)::date as local_date, l.market_id, r.location_id, r.slot_starts_at, sum(r.units) as units, max(p.capacity_per_slot) as capacity
                from pickup_slot_reservations r join restaurant_locations l on l.id = r.location_id join restaurant_pickup_settings p on p.location_id = r.location_id
                where l.market_id = ? and r.source = 'ORDER' and r.status = 'ACTIVE' and p.capacity_per_slot is not null and r.slot_starts_at >= ? and r.slot_starts_at < ? group by 1, 2, 3, 4
            ) s cross join lateral (values ('slots_used', 1), ('units_used', s.units), ('units_capacity', s.capacity), ('slots_full', (s.units >= s.capacity)::int)) as x(value, n)
            group by 1, 2, 3, 6",
            [$m->id, $from, $to]);

        return $rows;
    }

    private function clear(string $group, object $m, array $dates): void
    {
        DB::delete('delete from analytics_daily_breakdowns where grp = ? and market_id = ? and local_date = any(?::date[])', [$group, $m->id, $this->days($dates)]);
    }

    /**
     * Writes breakdown rows from a select of (local_date, market_id, location_id, currency, dimension, value, count,
     * amount, seconds_sum, seconds_count).
     */
    private function breakdown(string $group, CarbonImmutable $now, string $days, string $select, array $bindings): int
    {
        return DB::affectingStatement(
            "insert into analytics_daily_breakdowns (grp, local_date, market_id, location_id, currency, dimension, value, count, amount_minor, seconds_sum, seconds_count, computed_at)
            select ?, x.local_date, x.market_id, x.location_id, coalesce(x.currency, ''), x.dimension, left(x.value, 64), x.n, x.amount, x.seconds_sum, x.seconds_count, ?
            from ({$select}) as x(local_date, market_id, location_id, currency, dimension, value, n, amount, seconds_sum, seconds_count) where x.local_date = any(?::date[])",
            [$group, $now, ...$bindings, $days],
        );
    }

    /**
     * @param  list<string>  $dates
     */
    private function days(array $dates): string
    {
        return '{'.implode(',', array_filter($dates, fn (string $d): bool => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $d) === 1)).'}';
    }

    /**
     * The days and their neighbours: a restaurant's day may be the market's day before or after.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    private function around(array $dates): array
    {
        $all = [];
        foreach ($dates as $date) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
            foreach ([-1, 0, 1] as $shift) {
                $all[$day->addDays($shift)->toDateString()] = true;
            }
        }
        ksort($all);

        return array_keys($all);
    }

    /**
     * A coarse instant range that surely contains the days in any time zone — it lets the indexes on the timestamps
     * work; the exact day test is made on the converted value.
     *
     * @param  list<string>  $dates
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $dates): array
    {
        return [CarbonImmutable::createFromFormat('!Y-m-d', min($dates), 'UTC')->subDays(2), CarbonImmutable::createFromFormat('!Y-m-d', max($dates), 'UTC')->addDays(3)];
    }
}
