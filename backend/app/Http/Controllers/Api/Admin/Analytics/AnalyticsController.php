<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Enums\Permission;
use App\Http\Controllers\Api\Admin\Analytics\Concerns\AnalyticsScoping;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Services\Analytics\Aggregation\AggregationService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Services\Analytics\DataQualityService;
use App\Services\Analytics\EventRegistry;
use App\Services\Analytics\FinanceAnalyticsService;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Analytics\MetricCatalog;
use App\Services\Analytics\OrderAnalyticsService;
use App\Services\Analytics\PaymentAnalyticsService;
use App\Services\Analytics\PlatformAnalyticsService;
use App\Services\Analytics\RefundAnalyticsService;
use App\Services\Analytics\RestaurantAnalyticsService;
use App\Services\Analytics\RouteAnalyticsService;
use App\Services\Analytics\SupportAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Analytics for administrators (Module 38) — read-only reports over the analytics read models and, for money, over
 * the finance module's own reports.
 *
 * SCOPE is the server's: `admin.analytics.view` held for a market reaches that market only. The report covers the
 * market named in `?market=` (a slug), the default market (India) when none is named, or — with `market=all` — every
 * market the caller may see; a market that is not the caller's is refused, whatever the query string says. Money of
 * several currencies is never added together.
 *
 * Finance figures additionally need `admin.finance.view`, support figures `admin.support.view`, for every market of
 * the report: without it a finance or support report is refused and a block on the overview comes back "restricted".
 */
class AnalyticsController extends Controller
{
    use AnalyticsScoping;

    public function __construct(private readonly AnalyticsCache $cache, private readonly AggregationService $aggregation, private readonly AnalyticsMetrics $metrics) {}

    public function overview(Request $request, PlatformAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'overview', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->overview($scope, $period));
    }

    public function live(Request $request, PlatformAnalyticsService $service): JsonResponse
    {
        [$scope] = $this->scope($request);

        // Never cached: these are counts of this moment.
        return response()->json(['meta' => $this->meta($scope, null, 'operational', false), ...$service->live($scope)]);
    }

    public function funnel(Request $request, FunnelAnalyticsService $service): JsonResponse
    {
        $platform = $request->validate(['platform' => ['sometimes', Rule::in(['WEB', 'ANDROID'])]])['platform'] ?? null;

        return $this->report($request, 'funnel', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->report($scope, $period, $platform), ['platform' => $platform]);
    }

    public function customers(Request $request, CustomerAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'customers', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->report($scope, $period));
    }

    public function orders(Request $request, OrderAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'orders', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->report($scope, $period), [], restaurant: true);
    }

    public function restaurants(Request $request, RestaurantAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'restaurants', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => [
            'metrics' => $service->presence($scope, $period),
            'restaurants' => $service->rows($scope, $period, $scope->finance),
            'comparison_note' => 'A comparison for administrators. The rows carry no ranking score, and nothing here suspends, promotes or publicly ranks a restaurant.',
            'finance_restricted' => ! $scope->finance,
        ], [], restaurant: true);
    }

    public function menu(Request $request, RestaurantAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'menu', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->menu($scope, $period), [], restaurant: true);
    }

    public function routes(Request $request, RouteAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'routes', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->report($scope, $period));
    }

    public function pickup(Request $request, PaymentAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'pickup', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->pickup($scope, $period), [], restaurant: true);
    }

    public function payments(Request $request, PaymentAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'payments', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->payments($scope, $period));
    }

    public function refunds(Request $request, RefundAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'refunds', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->report($scope, $period));
    }

    public function finance(Request $request, FinanceAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'finance', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->report($scope, $period), [], needs: Permission::AdminFinanceView, freshness: 'ledger');
    }

    public function support(Request $request, SupportAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'support', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->support($scope, $period), [], needs: Permission::AdminSupportView);
    }

    public function reviews(Request $request, SupportAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'reviews', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->reviews($scope, $period), [], restaurant: true);
    }

    public function notifications(Request $request, SupportAnalyticsService $service): JsonResponse
    {
        return $this->report($request, 'notifications', fn (AnalyticsScope $scope, AnalyticsPeriod $period): array => $service->notifications($scope, $period));
    }

    /**
     * The definitions: every metric, every behavioural event, the funnel's stages. What a tooltip shows.
     */
    public function catalog(Request $request, EventRegistry $events): JsonResponse
    {
        $this->marketsWith($request, Permission::AdminAnalyticsView);

        return response()->json([
            'metrics' => MetricCatalog::all(),
            'events' => array_map(fn (string $name, array $e): array => ['name' => $name, 'source' => $e['source'], 'trigger' => $e['trigger'], 'funnel_stage' => $e['stage'], 'references' => $e['refs'], 'required_references' => $e['required'],
                'properties' => (object) $e['properties'], 'privacy_class' => $e['privacy'], 'dedupe' => $e['dedupe'], 'schema_version' => (int) config('analytics.events.schema_version')], array_keys($events->all()), array_values($events->all())),
            'funnel' => ['stages' => array_map(fn (int $n, array $s): array => ['stage' => $n] + $s, array_keys((array) config('analytics.funnel.stages')), array_values((array) config('analytics.funnel.stages'))),
                'unit' => 'SESSION', 'conversion_window_hours' => (int) config('analytics.funnel.conversion_window_hours')],
            'session' => (array) config('analytics.session'),
            'bands' => (array) config('analytics.bands'),
            'periods' => ['presets' => AnalyticsPeriod::PRESETS, 'default' => (string) config('analytics.periods.default'), 'max_range_days' => (int) config('analytics.periods.max_range_days')],
            'privacy' => ['minimum_cohort' => (int) config('analytics.privacy.min_cohort'), 'minimum_denominator' => (int) config('analytics.small_sample.min_denominator'), 'advertising_trackers' => false,
                'statement' => 'Analytics holds aggregates and pseudonyms. No phone number, e-mail address, name, address, coordinate or route is stored in an analytics table.'],
            'approval' => (string) config('analytics.approval'),
        ]);
    }

    /**
     * Data quality: what the integrity checks found the last time they ran. They report; they repair nothing.
     */
    public function quality(Request $request, DataQualityService $quality): JsonResponse
    {
        // The checks look across every market: the permission must be held for all of them.
        if ($this->marketsWith($request, Permission::AdminAnalyticsView) !== null) {
            throw new AuthorizationException;
        }

        return response()->json($quality->latest());
    }

    /**
     * The administrators' system page: what the analytics layer itself did lately, and its switches.
     */
    public function system(DataQualityService $quality): JsonResponse
    {
        $last = $this->aggregation->lastSuccess();
        $runs = DB::table('analytics_runs')->orderByDesc('id')->limit(10)->get(['public_id', 'trigger', 'dry_run', 'status', 'rows_written', 'duration_ms', 'error', 'started_at', 'finished_at']);
        $stale = $last === null || $last->lt(CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.freshness.stale_after_minutes')));

        return response()->json([
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'aggregation' => ['last_success_at' => $last?->toIso8601ZuluString(), 'stale' => $stale, 'scheduled' => (bool) config('analytics.aggregation.scheduled'), 'every_minutes' => (int) config('analytics.aggregation.every_minutes'),
                'runs' => $runs->map(fn ($r): array => ['id' => (string) $r->public_id, 'trigger' => $r->trigger, 'dry_run' => (bool) $r->dry_run, 'status' => $r->status, 'rows_written' => (int) $r->rows_written, 'duration_ms' => $r->duration_ms === null ? null : (int) $r->duration_ms,
                    'error' => $r->error === null ? null : 'FAILED', 'started_at' => CarbonImmutable::parse((string) $r->started_at)->toIso8601ZuluString()])->all()],
            'read_models' => ['events' => (int) DB::table('analytics_events')->count(), 'sessions' => (int) DB::table('analytics_session_facts')->count(), 'order_facts' => (int) DB::table('analytics_order_facts')->count(),
                'first_event_at' => ($first = DB::table('analytics_events')->min('occurred_at')) === null ? null : CarbonImmutable::parse((string) $first)->toIso8601ZuluString()],
            'data_quality' => $quality->latest()['status'],
            'exports' => (object) DB::table('report_exports')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all(),
            'window_hours' => 24,
            'events' => $this->metrics->events(24),
            'policies' => ['approval' => (string) config('analytics.approval'), 'min_cohort' => (int) config('analytics.privacy.min_cohort'), 'min_denominator' => (int) config('analytics.small_sample.min_denominator'),
                'raw_event_days' => (int) config('analytics.retention.raw_event_days'), 'export_expiry_hours' => (int) config('analytics.exports.expiry_hours'), 'session' => (array) config('analytics.session')],
            'flags' => ['events_enabled' => (bool) config('analytics.events.enabled'), 'cache_enabled' => (bool) config('analytics.cache.enabled'), 'advertising_trackers' => false, 'warehouse' => 'NOT REQUIRED / NOT IMPLEMENTED',
                'materialized_views' => 'NOT USED', 'historical_behavioural_data' => 'UNAVAILABLE BEFORE MODULE 38', 'production' => (string) config('analytics.production')],
        ]);
    }

    /**
     * One report: scope, period, permission, cache, the same envelope.
     *
     * @param  \Closure(AnalyticsScope, AnalyticsPeriod): array<string, mixed>  $compute
     * @param  array<string, mixed>  $parameters
     */
    private function report(Request $request, string $name, \Closure $compute, array $parameters = [], ?Permission $needs = null, bool $restaurant = false, string $freshness = 'aggregated'): JsonResponse
    {
        [$scope, $markets] = $this->scope($request, $restaurant);
        if ($needs !== null) {
            foreach ($markets as $market) {
                $this->authorizeFor($needs, $market);
            }
            if ($scope->includesUnassigned) {
                $this->authorizeFor($needs, null);
            }
        }
        $period = AnalyticsPeriod::fromInput($request->validate(['preset' => ['sometimes', 'string', 'max:20'], 'from' => ['sometimes', 'string', 'max:10'], 'to' => ['sometimes', 'string', 'max:10']]), $scope->timezone);
        $answer = $this->cache->remember('admin:'.$name, $scope->cacheKey(), ['from' => $period->fromDate(), 'to' => $period->toDate(), 'preset' => $period->preset] + $parameters, fn (): array => $compute($scope, $period) + ['generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString()]);
        $cached = (bool) $answer['cached'];
        $generated = (string) $answer['generated_at'];
        unset($answer['cached'], $answer['generated_at']);

        return response()->json(['meta' => $this->meta($scope, $period, $freshness, $cached, $generated), ...$answer]);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(AnalyticsScope $scope, ?AnalyticsPeriod $period, string $freshness, bool $cached, ?string $generatedAt = null): array
    {
        $last = $this->aggregation->lastSuccess();
        $mode = (array) config('analytics.freshness.'.$freshness);

        return [
            'period' => $period?->toArray(),
            'scope' => $scope->label,
            'timezone' => $scope->timezone,
            'currency' => $scope->currency,
            'freshness' => $mode + [
                'aggregated_at' => $freshness === 'aggregated' ? $last?->toIso8601ZuluString() : null,
                'stale' => $freshness === 'aggregated' && ($last === null || $last->lt(CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.freshness.stale_after_minutes')))),
                'generated_at' => $generatedAt ?? CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'cached' => $cached, 'cache_seconds' => $cached ? (int) config('analytics.cache.seconds') : 0,
            ],
            'permissions' => ['finance' => $scope->finance, 'support' => $scope->support],
            'definitions' => '/api/v1/admin/analytics/catalog',
        ];
    }
}
