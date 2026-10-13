<?php

namespace Tests\Feature\Analytics;

use App\Console\Commands\AnalyticsCatalog;
use App\Enums\Permission;
use App\Services\Analytics\EventRegistry;
use App\Services\Analytics\MetricCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnalytics;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Module 38 — the documented contract of analytics is the real one: every route is documented, every answer matches
 * its schema, the reports are read-only, and the one write endpoint has no room for a fact of another domain.
 */
class AnalyticsOpenApiContractTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase, ValidatesOpenApi;

    private const PERIOD = ['from' => '2026-10-01', 'to' => '2026-10-07'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
        Storage::fake('local');
    }

    public function test_the_answers_of_every_analytics_endpoint_match_their_schemas(): void
    {
        $this->feeRule();
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        $this->sessionOrder(true, $journey['id']);
        $this->refundedCompletedOrder(10000);
        $this->aggregate();
        $this->artisan('analytics:check');
        $admin = $this->analyst([Permission::AdminFinanceView, Permission::AdminSupportView, Permission::AdminSystemView]);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');

        foreach (['overview' => 'AnalyticsAdminOverview', 'funnel' => 'AnalyticsFunnel', 'customers' => 'AnalyticsCustomers', 'orders' => 'AnalyticsOrders', 'restaurants' => 'AnalyticsRestaurants', 'menu' => 'AnalyticsMenu',
            'routes' => 'AnalyticsRoutes', 'pickup' => 'AnalyticsPickup', 'payments' => 'AnalyticsPayments', 'refunds' => 'AnalyticsRefunds', 'finance' => 'AnalyticsFinance', 'support' => 'AnalyticsSupport',
            'reviews' => 'AnalyticsReviews', 'notifications' => 'AnalyticsNotifications'] as $report => $schema) {
            $this->assertMatchesSchema($schema, $this->adminAnalytics($admin, '/'.$report, self::PERIOD)->assertOk()->json());
        }
        // One day (hour buckets) and every market at once use the same schemas.
        $this->assertMatchesSchema('AnalyticsOrders', $this->adminAnalytics($admin, '/orders', ['preset' => 'today', 'market' => 'all'])->assertOk()->json());
        $this->assertMatchesSchema('AnalyticsAdminOverview', $this->adminAnalytics($this->analyst(), '/overview', self::PERIOD)->assertOk()->json());
        $this->assertMatchesSchema('AnalyticsLive', $this->adminAnalytics($admin, '/live')->assertOk()->json());
        $this->assertMatchesSchema('AnalyticsCatalog', $this->adminAnalytics($admin, '/catalog')->assertOk()->json());
        $this->assertMatchesSchema('AnalyticsQuality', $this->adminAnalytics($admin, '/quality')->assertOk()->json());
        $this->assertMatchesSchema('AnalyticsSystem', $this->getJson('/api/v1/admin/system/analytics')->assertOk()->json());

        foreach (['overview' => 'AnalyticsRestaurantOverview', 'orders' => 'AnalyticsOrders', 'menu' => 'AnalyticsMenu', 'operations' => 'AnalyticsRestaurantOperations', 'reviews' => 'AnalyticsReviews', 'routes' => 'AnalyticsRestaurantRoutes',
            'earnings' => 'AnalyticsRestaurantEarnings', 'catalog' => 'AnalyticsRestaurantCatalog'] as $report => $schema) {
            $this->assertMatchesSchema($schema, $this->restaurantAnalytics($owner, '/'.$report, self::PERIOD)->assertOk()->json());
        }

        // Exports, both audiences.
        foreach ([['admin', $admin, 'orders'], ['restaurant', $owner, 'menu_items']] as [$audience, $actor, $report]) {
            $this->app['auth']->forgetGuards();
            $this->actingAsPrincipal($actor);
            $created = $this->postJson('/api/v1/'.$audience.'/analytics/exports', ['report' => $report] + self::PERIOD)->assertStatus(202)->json();
            $this->assertMatchesSchema('ReportExportEnvelope', $created);
            $this->assertMatchesSchema('ReportExportEnvelope', $this->getJson('/api/v1/'.$audience.'/analytics/exports/'.$created['export']['id'])->assertOk()->json());
            $this->assertMatchesSchema('ReportExportList', $this->getJson('/api/v1/'.$audience.'/analytics/exports')->assertOk()->json());
            $this->assertMatchesSchema('Error', $this->getJson('/api/v1/'.$audience.'/analytics/exports/00000000-0000-4000-8000-000000000000')->assertNotFound()->json());
        }
        // Events.
        $batch = ['session' => '11111111-1111-4111-8111-111111111111', 'platform' => 'WEB', 'events' => [$this->event('journey_search_started'), $this->event('order_completed')]];
        $this->assertMatchesSchema('AnalyticsEventBatch', array_merge($batch, ['events' => [$batch['events'][0]]]));
        $this->assertMatchesSchema('AnalyticsEventAnswer', $this->sendEvents($batch['events'], [], $batch['session'])->assertStatus(202)->json());
        // Errors.
        foreach ([$this->adminAnalytics($admin, '/orders', ['market' => 'atlantis'])->assertNotFound(), $this->adminAnalytics($admin, '/orders', ['preset' => 'never'])->assertStatus(422), $this->adminAnalytics($this->analyst(), '/finance')->assertForbidden()] as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }
    }

    public function test_every_analytics_route_is_documented_read_only_and_the_event_door_has_no_room_for_another_domains_facts(): void
    {
        $doc = $this->openApi();
        $documented = [];
        foreach ($doc['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }
        $mine = fn (string $route): bool => (bool) preg_match('#/analytics(/|$)#', $route);
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn (string $m): bool => $m === 'HEAD')->map(fn (string $m): string => $m.' '.preg_replace('#^/api/v1#', '', '/'.ltrim($r->uri(), '/'))))
            ->filter($mine)->unique()->sort()->values()->all();
        $this->assertSame($registered, collect($documented)->filter($mine)->sort()->values()->all(), 'every analytics route is documented and nothing else is');
        // 35 = 21 administrator (17 reports and definitions + 4 exports) + 12 restaurant (8 + 4) + the system page + the event door.
        $count = fn (string $prefix): int => collect($registered)->filter(fn (string $r): bool => str_contains($r, $prefix))->count();
        $this->assertSame([35, 21, 12, 1, 1], [count($registered), $count(' /admin/analytics'), $count(' /restaurant/analytics'), $count(' /admin/system/analytics'), $count('POST /analytics/events')]);
        // Analytics is a read layer: the only writes are "ask for an export" and "take these events".
        $this->assertSame(['POST /admin/analytics/exports', 'POST /analytics/events', 'POST /restaurant/analytics/exports'], collect($registered)->reject(fn (string $r): bool => str_starts_with($r, 'GET '))->values()->all());
        // No customer-facing analytics exists.
        $this->assertSame([], collect($registered)->filter(fn (string $r): bool => str_contains($r, ' /customer/'))->values()->all());
        // Each surface is its own principal's; the event door is open to guests and to customers.
        foreach ($doc['paths'] as $path => $operations) {
            if (! $mine($path)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                $expected = match (true) {
                    str_starts_with($path, '/admin/') => [['admin' => []]],
                    str_starts_with($path, '/restaurant/') => [['restaurant' => []]],
                    default => [[], ['customer' => []]],
                };
                $this->assertSame($expected, $operation['security'], $method.' '.$path);
                $this->assertStringContainsString('Module 38', $operation['summary']);
            }
        }
        // The event names a client may send are exactly the registry's client events — and none is another domain's fact.
        $names = $doc['components']['schemas']['AnalyticsEventBatch']['properties']['events']['items']['properties']['name']['enum'];
        $client = array_keys(array_filter((array) config('analytics.events.registry'), fn (array $e): bool => $e['source'] === 'CLIENT'));
        $this->assertEqualsCanonicalizing($client, $names);
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/verified|order_created|order_completed|refund|paid|settle|payout/', $name);
        }
        // Closed lists: neither request has room for a customer, an amount, a status or a query.
        foreach (['AnalyticsEventBatch', 'ReportExportAdminRequest', 'ReportExportRestaurantRequest'] as $schema) {
            $this->assertFalse($doc['components']['schemas'][$schema]['additionalProperties'], $schema);
            $this->assertSame([], array_values(array_intersect(array_keys($doc['components']['schemas'][$schema]['properties']), ['customer', 'customer_id', 'phone', 'email', 'amount', 'status', 'sql', 'query', 'columns', 'origin', 'destination'])));
        }
        // The documented privacy of route analytics: both statements can only ever be "false".
        $privacy = $doc['components']['schemas']['AnalyticsRoutes']['properties']['privacy']['properties'];
        $this->assertSame([[false], [false]], [$privacy['precise_routes_stored']['enum'], $privacy['origin_destination_stored']['enum']]);
        $this->assertSame([false], $doc['components']['schemas']['AnalyticsNotifications']['properties']['push_read']['properties']['tracked']['enum']);
    }

    public function test_the_metric_catalog_and_the_event_registry_documents_are_what_the_code_prints(): void
    {
        $normalise = fn (string $text): string => trim(str_replace("\r\n", "\n", $text));
        $docs = base_path('../docs/analytics');
        $this->assertSame($normalise(AnalyticsCatalog::metrics()), $normalise((string) file_get_contents($docs.'/metric-catalog.md')), 'docs/analytics/metric-catalog.md is out of date: run php artisan analytics:catalog --write');
        $this->assertSame($normalise(AnalyticsCatalog::events(app(EventRegistry::class))), $normalise((string) file_get_contents($docs.'/event-registry.md')), 'docs/analytics/event-registry.md is out of date: run php artisan analytics:catalog --write');
        // Every metric has every field a definition needs; a rate has both sides of its division.
        foreach (MetricCatalog::all() as $metric) {
            foreach (['name', 'definition', 'source', 'filters', 'timezone', 'currency', 'exclusions', 'aggregation', 'freshness', 'owner'] as $field) {
                $this->assertNotSame('', trim((string) $metric[$field]), $metric['key'].'.'.$field);
            }
            if (in_array($metric['unit'], ['percent', 'number'], true)) {
                $this->assertNotNull($metric['numerator'], $metric['key']);
                $this->assertNotNull($metric['denominator'], $metric['key']);
            }
        }
        // Every word the specification forbids as a bare label has a definition behind it, or is not used at all.
        $names = array_map('strtolower', array_column(MetricCatalog::all(), 'name'));
        foreach (['revenue', 'conversion rate', 'active customer', 'successful order'] as $bare) {
            $this->assertNotContains($bare, $names);
        }
        foreach (['analytics', 'architecture', 'funnel-definitions', 'financial-metrics', 'restaurant-metrics', 'route-analytics', 'support-analytics', 'privacy', 'report-exports', 'README'] as $document) {
            $this->assertFileExists($docs.'/'.($document === 'analytics' ? '../backend/analytics' : $document).'.md');
        }
    }
}
