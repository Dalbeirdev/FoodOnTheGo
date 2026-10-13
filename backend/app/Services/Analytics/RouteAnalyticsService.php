<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Route and corridor analytics (Module 38) — AGGREGATES of journeys, never a journey.
 *
 * What exists about a journey here: that one was planned, the BAND of its length and duration, the static corridors
 * (Module 22) it ran along, the city near each end, whether discovery found a restaurant, which restaurants were
 * returned, and whether an order came of it. No origin, no destination, no coordinate and no route is stored, so
 * none can be reported.
 *
 * A row built on fewer journeys than the configured minimum (a corridor, a city, a band) is not shown: its numbers
 * are folded into "other" so that a handful of travellers on a quiet road cannot be picked out.
 */
final class RouteAnalyticsService
{
    public function __construct(private readonly ReadModel $read) {}

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $min = max(1, (int) config('analytics.privacy.min_cohort'));
        $now = $this->bucket($scope, $period, 'ALL');
        $before = $this->bucket($scope, $period->previous(), 'ALL');
        $found = max(0, $now['searches'] - $now['no_result_searches']);

        return [
            'metrics' => [
                'journey_searches' => Metric::count('journey_searches', $now['searches'], $before['searches']),
                'no_result_searches' => Metric::count('no_result_searches', $now['no_result_searches'], $before['no_result_searches']),
                'no_result_rate' => Metric::ratio('no_result_rate', $now['no_result_searches'], $now['searches'], [$before['no_result_searches'], $before['searches']]),
                'restaurant_impressions' => Metric::count('restaurant_impressions', $now['impressions'], $before['impressions']),
                'journey_restaurant_views' => Metric::count('journey_restaurant_views', $now['restaurant_views'], $before['restaurant_views']),
                'journey_orders' => Metric::count('journey_orders', $now['orders'], $before['orders']),
                'journey_order_conversion' => Metric::ratio('journey_order_conversion', $now['orders'], $now['searches'], [$before['orders'], $before['searches']]),
                'average_detour' => Metric::duration('average_detour', $now['detour_seconds_sum'], $now['detour_count']),
                'average_restaurants_returned' => Metric::quotient('average_restaurants_returned', $now['restaurants_returned'], $found),
            ],
            'corridors' => $this->corridors($scope, $period, $min),
            'distance_bands' => $this->bands($scope, $period, 'distance_band', Bands::labels('journey_km'), $min, 'km'),
            'duration_bands' => $this->bands($scope, $period, 'duration_band', Bands::labels('journey_minutes'), $min, 'minutes'),
            'detour_bands' => $this->detourBands($scope, $period, $min),
            'coverage_gaps' => [
                'definition' => 'Journeys planned in the period for which discovery found no restaurant — demand the service does not cover yet.',
                'by_reason' => ReadModel::rows($this->read->breakdown('routes', 'no_result_reason', $scope, $period), ['NO_SERVICE_COVERAGE' => 'No service area along the route', 'NO_RESTAURANTS_IN_CORRIDOR' => 'No restaurant near the route', 'NO_REACHABLE_RESTAURANTS' => 'No restaurant within the detour limit']),
                'by_origin_city' => $this->cities($scope, $period, 'no_result_origin_city', $min),
                'by_destination_city' => $this->cities($scope, $period, 'no_result_destination_city', $min),
                'insight_only' => 'Demand without coverage is information. Nothing here activates a market, a city or a service area.',
            ],
            'demand_by_origin_city' => $this->cities($scope, $period, 'origin_city', $min),
            'demand_by_destination_city' => $this->cities($scope, $period, 'destination_city', $min),
            'privacy' => [
                'minimum_cohort' => $min, 'precise_routes_stored' => false, 'origin_destination_stored' => false,
                'statement' => 'Aggregated by corridor, city and band. No traveller\'s origin, destination or route is stored in analytics.',
            ],
            'historical_note' => 'Journey analytics are recorded from Module 38 on. Journeys themselves are deleted about an hour after they are planned: HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38.',
        ];
    }

    /**
     * The four route figures the overview shows.
     *
     * @return array<string, array<string, mixed>>
     */
    public function headline(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->bucket($scope, $period, 'ALL');
        $before = $this->bucket($scope, $period->previous(), 'ALL');

        return [
            'journey_searches' => Metric::count('journey_searches', $now['searches'], $before['searches']),
            'no_result_searches' => Metric::count('no_result_searches', $now['no_result_searches'], $before['no_result_searches']),
            'no_result_rate' => Metric::ratio('no_result_rate', $now['no_result_searches'], $now['searches'], [$before['no_result_searches'], $before['searches']]),
            'journey_orders' => Metric::count('journey_orders', $now['orders'], $before['orders']),
        ];
    }

    /**
     * A restaurant's own route discovery: how often it was returned for a journey, opened from one, and ordered from.
     * Bands of its own orders only — never a traveller's route.
     *
     * @return array<string, mixed>
     */
    public function forRestaurants(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $min = max(1, (int) config('analytics.privacy.min_cohort'));
        $sums = $this->read->sums('analytics_daily_restaurant', $scope, $period, ['impressions', 'restaurant_views', 'journey_orders', 'orders_placed']);
        $bands = $this->read->facts($scope, $period)->whereNotNull('f.detour_band')->selectRaw("f.detour_band as band, count(*) as orders, count(*) filter (where f.status = 'COMPLETED') as completed")->groupBy('f.detour_band')->get()->keyBy('band');
        $detour = $this->read->facts($scope, $period)->whereNotNull('f.detour_seconds')->selectRaw('coalesce(sum(f.detour_seconds), 0) as s, count(*) as n')->first();

        return [
            'metrics' => [
                'restaurant_impressions' => Metric::count('restaurant_impressions', $sums['impressions']),
                'journey_restaurant_views' => Metric::count('journey_restaurant_views', $sums['restaurant_views']),
                'journey_orders' => Metric::count('journey_orders', $sums['journey_orders']),
                'average_detour' => Metric::duration('average_detour', (int) ($detour->s ?? 0), (int) ($detour->n ?? 0)),
            ],
            'detour_bands' => array_map(function (string $label) use ($bands, $min): array {
                $orders = (int) ($bands[$label]->orders ?? 0);
                $hidden = $orders > 0 && $orders < $min;

                return ['band' => $label, 'unit' => 'minutes', 'orders' => $hidden ? null : $orders, 'completed' => $hidden ? null : (int) ($bands[$label]->completed ?? 0), 'suppressed' => $hidden];
            }, Bands::labels('detour_minutes')),
            'privacy' => ['minimum_cohort' => $min, 'statement' => 'Counts and bands of your own orders. No traveller\'s route, origin or destination is shown — none is stored.'],
            'views_note' => 'Restaurant views here are all views of your page; impressions are the times you were returned for a journey.',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function bucket(AnalyticsScope $scope, AnalyticsPeriod $period, string $bucket): array
    {
        $columns = ['searches', 'no_result_searches', 'impressions', 'restaurant_views', 'orders', 'orders_completed', 'detour_seconds_sum', 'detour_count', 'restaurants_returned'];
        $row = (array) $this->read->daily('analytics_daily_routes', $this->markets($scope), $period)->where('t.bucket', $bucket)
            ->selectRaw(implode(', ', array_map(fn (string $c): string => "coalesce(sum(t.{$c}), 0) as {$c}", $columns)))->first();

        return array_map('intval', $row + array_fill_keys($columns, 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function corridors(AnalyticsScope $scope, AnalyticsPeriod $period, int $min): array
    {
        $rows = $this->read->daily('analytics_daily_routes', $this->markets($scope), $period)->where('t.bucket', 'CORRIDOR')->join('route_corridors as c', 'c.id', '=', 't.corridor_id')
            ->selectRaw('c.public_id, c.name, c.highway, sum(t.searches) as searches, sum(t.no_result_searches) as no_result, sum(t.impressions) as impressions, sum(t.restaurant_views) as views, sum(t.orders) as orders, sum(t.orders_completed) as completed,
                sum(t.detour_seconds_sum) as detour_s, sum(t.detour_count) as detour_n')->groupBy('c.public_id', 'c.name', 'c.highway')->orderByRaw('sum(t.searches) desc, c.name')->limit(200)->get();
        $shown = [];
        $other = ['corridors' => 0, 'searches' => 0, 'no_result_searches' => 0, 'orders' => 0];
        foreach ($rows as $r) {
            if ((int) $r->searches < $min) {
                $other['corridors']++;
                $other['searches'] += (int) $r->searches;
                $other['no_result_searches'] += (int) $r->no_result;
                $other['orders'] += (int) $r->orders;

                continue;
            }
            $shown[] = [
                'corridor' => ['id' => (string) $r->public_id, 'name' => (string) $r->name, 'highway' => $r->highway],
                'searches' => (int) $r->searches, 'no_result_searches' => (int) $r->no_result, 'impressions' => (int) $r->impressions, 'restaurant_views' => (int) $r->views, 'orders' => (int) $r->orders, 'orders_completed' => (int) $r->completed,
                'conversion' => Metric::ratio('journey_order_conversion', (int) $r->orders, (int) $r->searches),
                'no_result_rate' => Metric::ratio('no_result_rate', (int) $r->no_result, (int) $r->searches),
                'average_detour' => Metric::duration('average_detour', (int) $r->detour_s, (int) $r->detour_n),
            ];
        }
        $none = $this->bucket($scope, $period, 'NO_CORRIDOR');

        return [
            'rows' => $shown,
            // Corridors with too few journeys to show on their own, together — only if together they are enough.
            'other' => $other['corridors'] === 0 ? null : ($other['searches'] >= $min ? $other + ['suppressed' => false] : ['corridors' => $other['corridors'], 'searches' => null, 'no_result_searches' => null, 'orders' => null, 'suppressed' => true]),
            'without_static_corridor' => ['searches' => $none['searches'], 'no_result_searches' => $none['no_result_searches'], 'orders' => $none['orders'], 'note' => 'Journeys along no static corridor are counted for the market as a whole.'],
            'multi_corridor_note' => 'A journey along two corridors counts in both; the totals above count it once.',
        ];
    }

    /**
     * @param  list<string>  $labels
     * @return list<array<string, mixed>>
     */
    private function bands(AnalyticsScope $scope, AnalyticsPeriod $period, string $dimension, array $labels, int $min, string $unit): array
    {
        $rows = $this->read->breakdown('routes', $dimension, $this->markets($scope), $period);

        return array_map(function (string $label) use ($rows, $min, $unit): array {
            $count = $rows[$label]['count'] ?? 0;
            $hidden = $count > 0 && $count < $min;

            return ['band' => $label, 'unit' => $unit, 'searches' => $hidden ? null : $count, 'suppressed' => $hidden];
        }, $labels);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detourBands(AnalyticsScope $scope, AnalyticsPeriod $period, int $min): array
    {
        $impressions = $this->read->breakdown('routes', 'detour_band_impressions', $this->markets($scope), $period);
        $orders = $this->read->breakdown('routes', 'detour_band_orders', $this->markets($scope), $period);

        return array_map(function (string $label) use ($impressions, $orders, $min): array {
            $shown = $impressions[$label]['count'] ?? 0;
            $placed = $orders[$label]['count'] ?? 0;
            $hidden = ($shown > 0 && $shown < $min) || ($placed > 0 && $placed < $min && $shown < $min);

            return ['band' => $label, 'unit' => 'minutes', 'impressions' => $hidden ? null : $shown, 'orders' => $hidden ? null : $placed, 'orders_completed' => $hidden ? null : ($orders[$label]['seconds_count'] ?? 0),
                'orders_per_100_impressions' => $hidden || $shown < $min ? null : round($placed * 100 / $shown, 1), 'suppressed' => $hidden];
        }, Bands::labels('detour_minutes'));
    }

    /**
     * Counts by city — the city NEAR an end of the journey, never the end itself. Cities under the minimum are folded.
     *
     * @return array<string, mixed>
     */
    private function cities(AnalyticsScope $scope, AnalyticsPeriod $period, string $dimension, int $min): array
    {
        $rows = $this->read->breakdown('routes', $dimension, $this->markets($scope), $period);
        $names = DB::table('cities')->whereIn('id', array_map('intval', array_keys($rows)) ?: [0])->pluck('name', 'id');
        $shown = [];
        $folded = ['cities' => 0, 'searches' => 0];
        foreach ($rows as $cityId => $figures) {
            if ($figures['count'] < $min || ! isset($names[(int) $cityId])) {
                $folded['cities']++;
                $folded['searches'] += $figures['count'];

                continue;
            }
            $shown[] = ['city' => (string) $names[(int) $cityId], 'searches' => $figures['count']];
        }
        usort($shown, fn (array $a, array $b): int => [$b['searches'], $a['city']] <=> [$a['searches'], $b['city']]);

        return ['rows' => $shown, 'other' => $folded['cities'] === 0 ? null : ['cities' => $folded['cities'], 'searches' => $folded['searches'] >= $min ? $folded['searches'] : null, 'suppressed' => $folded['searches'] < $min]];
    }

    /** Route figures are a market's: they have no restaurant grain. */
    private function markets(AnalyticsScope $scope): AnalyticsScope
    {
        return new AnalyticsScope($scope->audience, $scope->marketIds, null, $scope->timezone, $scope->currency, $scope->label);
    }
}
