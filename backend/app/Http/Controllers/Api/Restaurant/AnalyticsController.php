<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Support\StrictInput;
use App\Models\ReportExport;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Analytics\Aggregation\AggregationService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\FinanceAnalyticsService;
use App\Services\Analytics\Metric;
use App\Services\Analytics\MetricCatalog;
use App\Services\Analytics\OrderAnalyticsService;
use App\Services\Analytics\PaymentAnalyticsService;
use App\Services\Analytics\Reports\ReportExportService;
use App\Services\Analytics\RestaurantAnalyticsService;
use App\Services\Analytics\RouteAnalyticsService;
use App\Services\Analytics\SupportAnalyticsService;
use App\Services\Restaurant\RestaurantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A restaurant's own analytics (Module 38). Read-only.
 *
 * WHOSE DATA: only locations the signed-in person may open (their active memberships) AND holds
 * restaurant.analytics.view for. With ?location= one location is read — one that is not theirs does not exist for
 * them (404, like an id that was never issued), whatever the query string says; without it, every location they may
 * read, so an owner sees the organization and a manager of one location only that one. The cache key carries the
 * locations: one restaurant's cached answer is never another's.
 *
 * WHAT IS NOT HERE: another restaurant's figures or any comparison with one, a customer's name or a list of
 * customers, a traveller's route, a provider reference. Earnings are the finance module's own figures and need
 * restaurant.finance.view as well.
 */
class AnalyticsController extends Controller
{
    private const EXPORT_BASE = '/api/v1/restaurant/analytics/exports';

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly AnalyticsCache $cache,
        private readonly AggregationService $aggregation,
        private readonly AnalyticsMetrics $metrics,
        private readonly ReportExportService $exports,
    ) {}

    public function overview(Request $request, OrderAnalyticsService $orders, RestaurantAnalyticsService $restaurants, SupportAnalyticsService $reviews): JsonResponse
    {
        return $this->report($request, 'overview', function (AnalyticsScope $scope, AnalyticsPeriod $period) use ($orders, $restaurants, $reviews): array {
            $report = $orders->report($scope, $period);
            $totals = $orders->totals($scope, $period);
            $rows = $restaurants->rows($scope, $period, $scope->finance);
            $flat = OrderAnalyticsService::flat($totals) + ['cohort_accepted' => 0, 'cohort_rejected' => 0, 'cohort_refunded' => 0, 'orders_placed' => 0];
            $review = $reviews->reviews($scope, $period)['metrics'];
            $net = [];
            foreach ($rows as $row) {
                if ($row['net_earnings'] !== null) {
                    $net[$row['currency']] = ($net[$row['currency']] ?? 0) + (int) $row['net_earnings']['amount'];
                }
            }

            return [
                'metrics' => [
                    'orders_placed' => $report['metrics']['orders_placed'],
                    'orders_completed' => $report['metrics']['orders_completed'],
                    'completed_order_total' => Metric::money('completed_order_total', array_map(fn (array $t): int => $t['gmv_minor'], $totals), array_map(fn (array $t): int => $t['gmv_minor'], $orders->totals($scope, $period->previous())), $scope->currency),
                    'average_order_value' => $report['metrics']['average_order_value'],
                    'restaurant_acceptance_rate' => Metric::ratio('restaurant_acceptance_rate', $flat['cohort_accepted'], $flat['cohort_accepted'] + $flat['cohort_rejected']),
                    'restaurant_refund_rate' => Metric::ratio('restaurant_refund_rate', $flat['cohort_refunded'], $flat['orders_placed']),
                    'average_rating' => $review['average_rating'],
                    'reviews_published' => $review['reviews_published'],
                    'restaurant_net_earnings' => $scope->finance ? Metric::money('restaurant_net_earnings', $net, null, $scope->currency) : Metric::restricted('restaurant_net_earnings', Permission::RestaurantFinanceView->value),
                ],
                'series' => $report['series'],
                'status_distribution' => $report['status_distribution'],
                'customers' => $restaurants->customerMix($scope, $period),
                'locations' => count($rows) > 1 ? array_map(fn (array $r): array => array_intersect_key($r, array_flip(['restaurant', 'currency', 'orders_placed', 'orders_completed', 'completed_order_total', 'average_rating'])), $rows) : [],
                'sales_note' => 'Sales are what customers paid for completed orders. Your earnings — after commission — are on the Earnings tab.',
            ];
        });
    }

    public function orders(Request $request, OrderAnalyticsService $orders): JsonResponse
    {
        return $this->report($request, 'orders', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $orders->report($scope, $period));
    }

    public function menu(Request $request, RestaurantAnalyticsService $restaurants): JsonResponse
    {
        return $this->report($request, 'menu', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $restaurants->menu($scope, $period));
    }

    public function operations(Request $request, OrderAnalyticsService $orders, PaymentAnalyticsService $pickup): JsonResponse
    {
        return $this->report($request, 'operations', function (AnalyticsScope $scope, AnalyticsPeriod $period) use ($orders, $pickup): array {
            $report = $orders->report($scope, $period);

            return [
                'metrics' => array_intersect_key($report['metrics'], array_flip(['acceptance_time', 'preparation_time', 'pickup_wait_time', 'completion_time', 'orders_rejected', 'order_rejection_rate', 'orders_cancelled'])),
                'rejection_reasons' => $report['rejection_reasons'],
                'peak_times' => $report['peak_times'],
                'estimated_preparation' => $report['estimated_preparation'],
                'pickup' => $pickup->pickup($scope, $period),
                'operational_note' => 'These are times between steps of an order. They describe the operation, not the food.',
            ];
        });
    }

    public function reviews(Request $request, SupportAnalyticsService $reviews): JsonResponse
    {
        return $this->report($request, 'reviews', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $reviews->reviews($scope, $period));
    }

    public function routes(Request $request, RouteAnalyticsService $routes): JsonResponse
    {
        return $this->report($request, 'routes', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $routes->forRestaurants($scope, $period));
    }

    /**
     * Earnings: the finance module's own figures, for a person who may see them.
     */
    public function earnings(Request $request, FinanceAnalyticsService $finance): JsonResponse
    {
        [$scope, $locations] = $this->scope($request);
        foreach ($locations as $location) {
            $this->access->authorize($this->user($request), $location, Permission::RestaurantFinanceView);
        }
        $period = $this->period($request, $scope);

        // Money is read from the finance module at the moment of the request: it is never cached here.
        return response()->json(['meta' => $this->meta($scope, $period, 'ledger', false), ...$finance->restaurant($scope->locationIds ?? [], $scope->timezone, $period)]);
    }

    /* ------------------------------------------------------------------------------------------ exports */

    public function exportIndex(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $exports = ReportExport::query()->where('requested_by_restaurant_user_id', $user->getKey())->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $exports->map(fn (ReportExport $e): array => ReportExportService::present($e, self::EXPORT_BASE))->all(), 'reports' => $this->reportTypes(), 'expiry_hours' => (int) config('analytics.exports.expiry_hours')]);
    }

    public function exportStore(Request $request): JsonResponse
    {
        StrictInput::only($request, ['report', 'location', 'preset', 'from', 'to']);
        $input = $request->validate(['report' => ['required', 'string', Rule::in(array_column($this->reportTypes(), 'key'))]]);
        [$scope, $locations] = $this->scope($request, Permission::RestaurantAnalyticsExport);
        $period = AnalyticsPeriod::fromInput($request->validate(['preset' => ['sometimes', 'string', 'max:20'], 'from' => ['sometimes', 'string', 'max:10'], 'to' => ['sometimes', 'string', 'max:10']]), $scope->timezone, (int) config('analytics.exports.max_range_days'));
        $organizations = $locations->pluck('organization_id')->unique();
        $export = $this->exports->ask(ReportExport::RESTAURANT, $this->user($request), (string) $input['report'], $period, $scope, $scope->marketIds[0] ?? null, $organizations->count() === 1 ? (int) $organizations->first() : null);

        return response()->json(['export' => ReportExportService::present($export, self::EXPORT_BASE)], 202);
    }

    public function exportShow(Request $request, string $export): JsonResponse
    {
        return response()->json(['export' => ReportExportService::present($this->ownExport($request, $export), self::EXPORT_BASE)]);
    }

    public function exportDownload(Request $request, string $export): StreamedResponse
    {
        $record = $this->ownExport($request, $export);
        $user = $this->user($request);
        // Still theirs to read: every location of the export must be one they reach and may export, today.
        $ids = array_map('intval', (array) ($record->filters['location_ids'] ?? []));
        $reachable = $this->access->locations($user)->whereIn('restaurant_locations.id', $ids === [] ? [0] : $ids)->get();
        if ($reachable->count() !== count($ids) || $reachable->contains(fn (RestaurantLocation $l): bool => ! in_array(Permission::RestaurantAnalyticsExport->value, $this->access->permissions($user, $l), true))) {
            $this->metrics->record('export_refused');
            throw new AuthorizationException;
        }

        return $this->exports->download($record, $user);
    }

    /* ------------------------------------------------------------------------------------------ plumbing */

    /**
     * @param  \Closure(AnalyticsScope, AnalyticsPeriod): array<string, mixed>  $compute
     */
    private function report(Request $request, string $name, \Closure $compute): JsonResponse
    {
        [$scope] = $this->scope($request);
        $period = $this->period($request, $scope);
        $answer = $this->cache->remember('restaurant:'.$name, $scope->cacheKey(), ['from' => $period->fromDate(), 'to' => $period->toDate(), 'preset' => $period->preset], fn (): array => $compute($scope, $period) + ['generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString()]);
        $cached = (bool) $answer['cached'];
        $generated = (string) $answer['generated_at'];
        unset($answer['cached'], $answer['generated_at']);

        return response()->json(['meta' => $this->meta($scope, $period, 'aggregated', $cached, $generated), ...$answer]);
    }

    private function period(Request $request, AnalyticsScope $scope): AnalyticsPeriod
    {
        return AnalyticsPeriod::fromInput($request->validate(['preset' => ['sometimes', 'string', 'max:20'], 'from' => ['sometimes', 'string', 'max:10'], 'to' => ['sometimes', 'string', 'max:10']]), $scope->timezone);
    }

    /**
     * The locations the report covers: the named one, or every one the caller may read with the permission.
     *
     * @return array{0: AnalyticsScope, 1: Collection<int, RestaurantLocation>}
     */
    private function scope(Request $request, Permission $permission = Permission::RestaurantAnalyticsView): array
    {
        $user = $this->user($request);
        $named = $request->input('location', $request->query('location'));
        if (is_string($named) && $named !== '') {
            if (! Str::isUuid($named)) {
                throw new ApiException(422, 'validation_failed', 'The location must be an id.', ['fields' => ['location' => ['Must be a valid id.']]]);
            }
            $location = $this->access->locations($user)->with('organization')->where('restaurant_locations.public_id', $named)->first();
            if ($location === null) {
                $this->metrics->record('scope_refused');
                throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
            }
            $this->access->authorize($user, $location, $permission);
            $locations = new Collection([$location]);
        } else {
            $locations = $this->access->locations($user)->with('organization')->orderBy('restaurant_locations.name')->get()
                ->filter(fn (RestaurantLocation $l): bool => in_array($permission->value, $this->access->permissions($user, $l), true))->values();
            if ($locations->isEmpty()) {
                throw new AuthorizationException;
            }
        }
        $first = $locations->first();
        $finance = $locations->every(fn (RestaurantLocation $l): bool => in_array(Permission::RestaurantFinanceView->value, $this->access->permissions($user, $l), true));
        $label = ['locations' => $locations->map(fn (RestaurantLocation $l): array => ['id' => $l->public_id, 'name' => $l->name, 'currency' => $l->currency, 'timezone' => $l->timezone])->values()->all(), 'all_readable' => ! (is_string($named) && $named !== '')];

        return [new AnalyticsScope('RESTAURANT', $locations->pluck('market_id')->unique()->map(fn ($id): int => (int) $id)->values()->all(), $locations->modelKeys(), (string) ($first->timezone ?: 'UTC'), (string) $first->currency, $label, finance: $finance), $locations];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(AnalyticsScope $scope, AnalyticsPeriod $period, string $freshness, bool $cached, ?string $generatedAt = null): array
    {
        $last = $this->aggregation->lastSuccess();

        return [
            'period' => $period->toArray(),
            'scope' => $scope->label,
            'timezone' => $scope->timezone,
            'currency' => $scope->currency,
            'freshness' => (array) config('analytics.freshness.'.$freshness) + [
                'aggregated_at' => $freshness === 'aggregated' ? $last?->toIso8601ZuluString() : null,
                'stale' => $freshness === 'aggregated' && ($last === null || $last->lt(CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.freshness.stale_after_minutes')))),
                'generated_at' => $generatedAt ?? CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'cached' => $cached, 'cache_seconds' => $cached ? (int) config('analytics.cache.seconds') : 0,
            ],
            'permissions' => ['finance' => $scope->finance],
            'definitions' => '/api/v1/restaurant/analytics/catalog',
        ];
    }

    /**
     * The definitions of the figures a restaurant sees — plain wording, the same catalog.
     */
    public function catalog(Request $request): JsonResponse
    {
        $this->scope($request);
        // Every figure a restaurant's report can return — and none of the platform's own.
        $groups = ['orders', 'restaurants', 'menu', 'reviews', 'pickup'];
        $more = ['orders_refund_required', 'restaurant_impressions', 'journey_restaurant_views', 'journey_orders', 'average_detour'];
        $never = ['active_restaurants', 'orderable_restaurants', 'restaurant_gross_sales'];

        return response()->json([
            'metrics' => array_values(array_filter(MetricCatalog::all(), fn (array $m): bool => (in_array($m['group'], $groups, true) || in_array($m['key'], $more, true)) && ! in_array($m['key'], $never, true))),
            'periods' => ['presets' => AnalyticsPeriod::PRESETS, 'default' => (string) config('analytics.periods.default'), 'max_range_days' => (int) config('analytics.periods.max_range_days')],
            'privacy' => ['minimum_cohort' => (int) config('analytics.privacy.min_cohort'), 'statement' => 'Your own restaurant\'s figures only. No customer is named; no traveller\'s route is stored.'],
        ]);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function reportTypes(): array
    {
        $types = [];
        foreach ((array) config('analytics.exports.reports') as $key => $report) {
            if (in_array(ReportExport::RESTAURANT, (array) $report['audiences'], true)) {
                $types[] = ['key' => (string) $key, 'label' => (string) $report['label']];
            }
        }

        return $types;
    }

    private function ownExport(Request $request, string $publicId): ReportExport
    {
        $export = ReportExport::query()->where('public_id', Str::isUuid($publicId) ? $publicId : null)->first();
        // Somebody else's export — also a colleague's — does not exist for this caller.
        if ($export === null || ! ReportExportService::belongsTo($export, $this->user($request))) {
            $this->metrics->record('export_refused');
            throw ApiException::notFound('export_not_found', 'This export does not exist.');
        }

        return $export;
    }

    private function user(Request $request): RestaurantUser
    {
        return $request->user();
    }
}
