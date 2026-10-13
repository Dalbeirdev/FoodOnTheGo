<?php

namespace App\Services\Analytics\Reports;

use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Analytics\RestaurantAnalyticsService;
use App\Services\Analytics\RouteAnalyticsService;
use App\Services\Finance\FinanceReportService;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The curated reports (Module 38): a fixed list, each with fixed columns. There is no report builder and no free
 * query — a report is one of these, for a period and a scope the server decided.
 *
 * WHAT IS NEVER IN A FILE: a customer's name, phone number, e-mail address or address, a journey's origin,
 * destination or route, a payment's card or UPI detail, a provider reference, a case's subject or messages. Orders
 * and cases are named by their own numbers. Figures come from the same services and domains the screens read.
 *
 * Text that people typed (a restaurant's name, an item's name) goes through the CSV writer, which neutralises a
 * leading = + - @ so a spreadsheet does not run it as a formula.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly RestaurantAnalyticsService $restaurants,
        private readonly FunnelAnalyticsService $funnel,
        private readonly RouteAnalyticsService $routes,
        private readonly FinanceReportService $finance,
    ) {}

    /**
     * @return list<string>
     */
    public function header(string $type): array
    {
        return match ($type) {
            'orders' => ['Order number', 'Placed at', 'Status', 'Restaurant', 'Currency', 'Order total', 'Items', 'Pickup', 'Pickup method', 'Accepted at', 'Ready at', 'Completed at', 'Refund status', 'From a journey'],
            'payments' => ['Created at', 'Status', 'Method', 'Currency', 'Amount', 'Restaurant', 'Failure category', 'Verified at', 'Order created'],
            'refunds' => ['Order number', 'Asked at', 'Status', 'Kind', 'Reason', 'Currency', 'Amount', 'Completed at', 'Restaurant'],
            'restaurant_performance' => ['Restaurant', 'Currency', 'Orders placed', 'Orders completed', 'Orders rejected', 'Orders cancelled', 'Sales of completed orders', 'Average order value', 'Acceptance rate %', 'Refund rate %',
                'Time to accept (s)', 'Preparation time (s)', 'Ready-to-pickup time (s)', 'Reviews published', 'Average rating', 'Restaurant impressions', 'Orders from a journey', 'Gross sales (finance)', 'Net earnings (finance)'],
            'customer_funnel' => ['Stage', 'Name', 'Source', 'Sessions', 'Signed-in customers', 'Events', 'From previous stage %', 'From journey search %', 'Did not continue'],
            'route_performance' => ['Kind', 'Name', 'Journey searches', 'Searches without a restaurant', 'Restaurant impressions', 'Orders', 'Orders completed', 'Journey to order %'],
            'support_cases' => ['Case number', 'Opened at', 'Category', 'Priority', 'Status', 'Requester', 'Team', 'First response at', 'Resolved at', 'Closed at', 'Times reopened', 'Target status'],
            'financial_summary' => ['Day', 'Currency', 'Orders recognized', 'Gross marketplace sales', 'Commission base', 'Platform commission', 'Restaurant earnings', 'Platform-funded discounts', 'Restaurant-funded discounts'],
            'menu_items' => ['Item', 'Currency', 'Views', 'Add-to-cart', 'Units sold', 'Orders', 'Item sales', 'Share of sales %'],
            default => throw new \InvalidArgumentException('Unknown report.'),
        };
    }

    /**
     * The first line of a file: what it is, for whom, for when — and what it is not.
     */
    public function title(string $type, AnalyticsPeriod $period, AnalyticsScope $scope): string
    {
        $label = (string) (config('analytics.exports.reports.'.$type.'.label') ?? $type);
        $extra = match ($type) {
            'financial_summary' => ' — figures of the finance module; this is not a tax document',
            'customer_funnel' => ' — sessions, not people; behavioural data exists from Module 38 on',
            'route_performance' => ' — aggregates only; rows under the minimum number of journeys are not shown',
            default => '',
        };

        return "FoodOnTheGo {$label} report — {$period->fromDate()} to {$period->toDate()} ({$period->timezone}){$extra}";
    }

    /**
     * @return iterable<array<int, mixed>>
     */
    public function rows(string $type, AnalyticsPeriod $period, AnalyticsScope $scope): iterable
    {
        $local = fn (mixed $at): string => $at === null ? '' : CarbonImmutable::parse((string) $at)->setTimezone($period->timezone)->format('Y-m-d H:i');
        $scoped = function (Builder $query, string $alias) use ($scope): Builder {
            $query->whereIn($alias.'.market_id', $scope->marketIds === [] ? [0] : $scope->marketIds);

            return $scope->forLocations() ? $query->whereIn($alias.'.location_id', $scope->locationIds === [] ? [0] : $scope->locationIds) : $query;
        };

        switch ($type) {
            case 'orders':
                $query = $scoped(DB::table('orders as o')->join('restaurant_locations as l', 'l.id', '=', 'o.location_id'), 'o')->where('o.placed_at', '>=', $period->startUtc())->where('o.placed_at', '<', $period->endUtc())
                    ->orderBy('o.placed_at')->orderBy('o.id')->select(['o.order_number', 'o.placed_at', 'o.status', 'l.name', 'o.currency', 'o.total_minor', 'o.item_count', 'o.pickup_mode', 'o.pickup_method', 'o.accepted_at', 'o.ready_at', 'o.completed_at', 'o.refund_status'])
                    ->selectRaw("(nullif(o.snapshot->'journey'->>'id', '') is not null) as from_journey");
                foreach ($query->cursor() as $r) {
                    yield [$r->order_number, $local($r->placed_at), $r->status, $r->name, $r->currency, Csv::amount((int) $r->total_minor, (string) $r->currency), (int) $r->item_count, $r->pickup_mode, $r->pickup_method, $local($r->accepted_at), $local($r->ready_at), $local($r->completed_at), $r->refund_status, $r->from_journey ? 'yes' : 'no'];
                }
                break;
            case 'payments':
                $query = $scoped(DB::table('payment_attempts as p'), 'p')->where('p.created_at', '>=', $period->startUtc())->where('p.created_at', '<', $period->endUtc())->orderBy('p.created_at')->orderBy('p.id')
                    ->select(['p.created_at', 'p.status', 'p.method_type', 'p.currency', 'p.amount_minor', 'p.restaurant_name', 'p.failure_category', 'p.verified_at', 'p.fulfillment_status']);
                foreach ($query->cursor() as $r) {
                    yield [$local($r->created_at), $r->status, $r->method_type, $r->currency, Csv::amount((int) $r->amount_minor, (string) $r->currency), $r->restaurant_name, $r->failure_category, $local($r->verified_at), $r->fulfillment_status === 'ORDER_CREATED' ? 'yes' : 'no'];
                }
                break;
            case 'refunds':
                $query = $scoped(DB::table('refunds as r'), 'r')->whereRaw('coalesce(r.requested_at, r.created_at) >= ?', [$period->startUtc()])->whereRaw('coalesce(r.requested_at, r.created_at) < ?', [$period->endUtc()])->orderBy('r.created_at')->orderBy('r.id')
                    ->select(['r.order_number', 'r.requested_at', 'r.created_at', 'r.status', 'r.type', 'r.reason_code', 'r.currency', 'r.approved_amount_minor', 'r.requested_amount_minor', 'r.completed_at', 'r.restaurant_name']);
                foreach ($query->cursor() as $r) {
                    yield [$r->order_number, $local($r->requested_at ?? $r->created_at), $r->status, $r->type, $r->reason_code, $r->currency, Csv::amount((int) ($r->approved_amount_minor ?? $r->requested_amount_minor), (string) $r->currency), $local($r->completed_at), $r->restaurant_name];
                }
                break;
            case 'restaurant_performance':
                foreach ($this->restaurants->rows($scope, $period, $scope->finance) as $r) {
                    $money = fn (?array $m): string => $m === null ? '' : Csv::amount((int) $m['amount'], (string) $m['currency']);
                    yield [$r['restaurant']['name'], $r['currency'], $r['orders_placed'], $r['orders_completed'], $r['orders_rejected'], $r['orders_cancelled'], $money($r['completed_order_total']), $money($r['average_order_value']),
                        $r['acceptance_rate']['value'], $r['refund_rate']['value'], $r['acceptance_time']['average'], $r['preparation_time']['average'], $r['pickup_wait_time']['average'], $r['reviews_published'], $r['average_rating']['value'],
                        $r['impressions'], $r['journey_orders'], $money($r['gross_sales']), $money($r['net_earnings'])];
                }
                break;
            case 'customer_funnel':
                foreach ($this->funnel->report($scope, $period)['stages'] as $s) {
                    yield [$s['stage'], $s['label'], $s['source'], $s['sessions'], $s['customers'], $s['events'], $s['conversion_from_previous']['value'] ?? null, $s['conversion_overall']['value'], $s['did_not_continue']];
                }
                break;
            case 'route_performance':
                $report = $this->routes->report($scope, $period);
                $m = $report['metrics'];
                yield ['Market total', 'All journeys', $m['journey_searches']['value'], $m['no_result_searches']['value'], $m['restaurant_impressions']['value'], $m['journey_orders']['value'], '', $m['journey_order_conversion']['value']];
                foreach ($report['corridors']['rows'] as $c) {
                    yield ['Corridor', $c['corridor']['name'], $c['searches'], $c['no_result_searches'], $c['impressions'], $c['orders'], $c['orders_completed'], $c['conversion']['value']];
                }
                if (($other = $report['corridors']['other']) !== null) {
                    yield ['Corridor', 'Other corridors ('.$other['corridors'].', too few journeys to show each)', $other['searches'], $other['no_result_searches'], '', $other['orders'], '', ''];
                }
                foreach ($report['distance_bands'] as $b) {
                    yield ['Journey length band (km)', $b['band'], $b['searches'], '', '', '', '', ''];
                }
                foreach ($report['detour_bands'] as $b) {
                    yield ['Detour band (minutes)', $b['band'], '', '', $b['impressions'], $b['orders'], $b['orders_completed'], ''];
                }
                break;
            case 'support_cases':
                $query = DB::table('support_cases as c')->where(function (Builder $q) use ($scope): void {
                    $q->whereIn('c.market_id', $scope->marketIds === [] ? [0] : $scope->marketIds);
                    if ($scope->includesUnassigned) {
                        $q->orWhereNull('c.market_id');
                    }
                })
                    // A restricted case (finance, security) is for the holders of its class: it is in no export.
                    ->where('c.classification', 'STANDARD')->where('c.created_at', '>=', $period->startUtc())->where('c.created_at', '<', $period->endUtc())->orderBy('c.created_at')->orderBy('c.id')
                    ->select(['c.case_number', 'c.created_at', 'c.category', 'c.priority', 'c.status', 'c.requester_type', 'c.assigned_team', 'c.first_response_at', 'c.resolved_at', 'c.closed_at', 'c.reopen_count', 'c.sla_status']);
                foreach ($query->cursor() as $r) {
                    yield [$r->case_number, $local($r->created_at), $r->category, $r->priority, $r->status, $r->requester_type, $r->assigned_team, $local($r->first_response_at), $local($r->resolved_at), $local($r->closed_at), (int) $r->reopen_count, $r->sla_status];
                }
                break;
            case 'financial_summary':
                foreach ($this->finance->commission($scope->includesUnassigned ? null : $scope->marketIds, $period->startUtc(), $period->endUtc(), 'day', $period->timezone) as $r) {
                    $money = fn (string $key): string => Csv::amount((int) $r[$key], (string) $r['currency']);
                    yield [$r['key'], $r['currency'], $r['orders'], $money('gross_marketplace_sales_minor'), $money('commission_base_minor'), $money('commission_minor'), $money('restaurant_earnings_minor'), $money('platform_funded_discount_minor'), $money('restaurant_funded_discount_minor')];
                }
                break;
            case 'menu_items':
                foreach ($this->restaurants->menu($scope, $period, 1000)['items'] as $i) {
                    yield [$i['item']['name'], $i['sales']['currency'], $i['views'], $i['cart_adds'], $i['units_sold'], $i['orders'], Csv::amount((int) $i['sales']['amount'], (string) $i['sales']['currency']), $i['share_of_sales_percent']];
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown report.');
        }
    }
}
