<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * The platform overview and the live operational cards (Module 38).
 *
 * THE OVERVIEW is a handful of defined figures of a period plus one block per area — not forty cards. Every block is
 * the same service a detail page uses, so a number on the overview is the number on its page. What the caller may
 * not see (finance, support) comes back as "restricted", never as a zero.
 *
 * LIVE is a different thing and is labelled so: counts of what is happening NOW, read from the operational records
 * at the moment of the request. Nothing aggregated is ever called live.
 */
final class PlatformAnalyticsService
{
    public function __construct(
        private readonly OrderAnalyticsService $orders,
        private readonly CustomerAnalyticsService $customers,
        private readonly RestaurantAnalyticsService $restaurants,
        private readonly FunnelAnalyticsService $funnel,
        private readonly RouteAnalyticsService $routes,
        private readonly FinanceAnalyticsService $finance,
        private readonly SupportAnalyticsService $support,
        private readonly ReadModel $read,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $orders = $this->orders->headline($scope, $period);
        $customers = $this->customers->figures($scope, $period);
        $customersBefore = $this->customers->figures($scope, $period->previous());
        $presence = $this->restaurants->presence($scope, $period);
        $finance = $scope->finance ? $this->finance->headline($scope, $period) : null;
        $support = $scope->support ? $this->support->headline($scope, $period) : null;

        return [
            'metrics' => [
                'orders_completed' => $orders['metrics']['orders_completed'],
                'ordering_customers' => Metric::count('ordering_customers', $customers['ordering'], $customersBefore['ordering']),
                'active_restaurants' => $presence['active_restaurants'],
                'gmv' => $orders['metrics']['gmv'],
                'platform_commission' => $finance === null ? Metric::restricted('platform_commission', 'admin.finance.view') : $finance['platform_commission'],
                'order_completion_rate' => $orders['metrics']['order_completion_rate'],
                'refund_rate_orders' => Metric::ratio('refund_rate_orders', $orders['metrics']['orders_refunded']['value'], $orders['metrics']['orders_placed']['value']),
                'support_open_cases' => $support === null ? Metric::restricted('support_open_cases', 'admin.support.view') : $support['support_open_cases'],
            ],
            'order_trend' => $orders['series'],
            'funnel' => $this->funnel->headline($scope, $period),
            'restaurant_operations' => array_intersect_key($orders['metrics'], array_flip(['acceptance_time', 'preparation_time', 'pickup_wait_time', 'order_rejection_rate'])) + ['orderable_restaurants' => $presence['orderable_restaurants']],
            'route_coverage' => $this->routes->headline($scope, $period),
            'finance_summary' => $finance === null ? ['restricted' => true, 'requires' => 'admin.finance.view'] : ['restricted' => false] + array_diff_key($finance, ['platform_commission' => true]),
            'support_health' => $support === null ? ['restricted' => true, 'requires' => 'admin.support.view'] : ['restricted' => false] + $support,
        ];
    }

    /**
     * What is happening now — read from the orders, payments, refunds and cases themselves.
     *
     * @return array<string, mixed>
     */
    public function live(AnalyticsScope $scope): array
    {
        $markets = $scope->marketIds === [] ? [0] : $scope->marketIds;
        $orders = DB::table('orders')->whereIn('market_id', $markets)->whereIn('status', ['WAITING_FOR_RESTAURANT', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP'])
            ->selectRaw("count(*) as active, count(*) filter (where status = 'WAITING_FOR_RESTAURANT') as waiting, count(*) filter (where status in ('ACCEPTED', 'PREPARING')) as preparing, count(*) filter (where status = 'READY_FOR_PICKUP') as ready")->first();
        $recovery = (int) DB::table('payment_attempts')->whereIn('market_id', $markets)->where('fulfillment_status', 'RECOVERY_REQUIRED')->count();
        $review = (int) DB::table('refunds')->whereIn('market_id', $markets)->where('status', 'REVIEW_REQUIRED')->count();

        return [
            'metrics' => [
                'live_active_orders' => Metric::count('live_active_orders', (int) ($orders->active ?? 0)),
                'live_waiting_for_restaurant' => Metric::count('live_waiting_for_restaurant', (int) ($orders->waiting ?? 0)),
                'live_preparing' => Metric::count('live_preparing', (int) ($orders->preparing ?? 0)),
                'live_ready' => Metric::count('live_ready', (int) ($orders->ready ?? 0)),
                'live_payment_recovery' => Metric::count('live_payment_recovery', $recovery),
                'live_refund_review' => Metric::count('live_refund_review', $review),
            ] + ($scope->support ? array_intersect_key($this->support->liveCases($scope), array_flip(['support_urgent_cases', 'support_open_cases'])) : ['support_urgent_cases' => Metric::restricted('support_urgent_cases', 'admin.support.view')])
              + ['orderable_restaurants' => $this->restaurants->presence($scope, AnalyticsPeriod::fromInput(['preset' => 'today'], $scope->timezone))['orderable_restaurants']],
            'note' => 'Counts of this moment, read from the operational records. They are not figures of a period.',
        ];
    }
}
