<?php

namespace Tests\Feature\Geo;

use App\Enums\Permission as P;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\MarketConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGeography;
use Tests\TestCase;

class AdminGeographyApiTest extends TestCase
{
    use BuildsGeography, RefreshDatabase;

    private const BOX = [77.30, 28.50, 77.40, 28.60];

    private Market $india;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->india = $this->india();
        $this->admin = $this->adminWith([
            P::AdminMarketsView, P::AdminMarketsManage, P::AdminCitiesView, P::AdminCitiesManage, P::AdminServiceAreasView, P::AdminServiceAreasManage,
            P::AdminMarketConfigurationView, P::AdminMarketConfigurationManage, P::AdminMarketFeaturesManage, P::AdminAuditView,
        ]);
        $this->actingAsPrincipal($this->admin);
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/admin/markets/'.$this->india->public_id.$path;
    }

    public function test_a_full_launch_flow_region_city_area_activation_makes_a_location_serviceable(): void
    {
        $point = ['lat' => 28.55, 'lng' => 77.35];

        $region = $this->postJson($this->url('/regions'), ['code' => 'IN-UP', 'name' => 'Uttar Pradesh', 'type' => 'STATE'])
            ->assertCreated()->assertJson(['code' => 'IN-UP', 'status' => 'PLANNED', 'version' => 1])->json();

        $city = $this->postJson($this->url('/cities'), ['region_id' => $region['id'], 'name' => 'Noida', 'latitude' => 28.5355, 'longitude' => 77.391, 'aliases' => ['NOIDA']])
            ->assertCreated()->assertJson(['slug' => 'noida', 'status' => 'PLANNED', 'timezone' => 'Asia/Kolkata', 'latitude' => 28.5355, 'longitude' => 77.391, 'serving_customers' => false])->json();

        $area = $this->postJson($this->url('/service-areas'), ['city_id' => $city['id'], 'name' => 'Noida Central', 'geometry' => $this->square(self::BOX)])
            ->assertCreated()->assertJson(['status' => 'PLANNED', 'vertices' => 5, 'geometry' => ['type' => 'MultiPolygon']])->json();
        $this->assertEqualsWithDelta(108_500_000, $area['area_square_meters'], 1_500_000, '≈ 9.78 km × 11.1 km');

        // Nothing is created live: the location is not serviceable until every level is opened.
        $this->postJson('/api/v1/availability/location', $point)->assertJson(['supported' => false]);

        $this->patchJson('/api/v1/admin/service-areas/'.$area['id'], ['version' => 1, 'status' => 'ACTIVE'])->assertOk()->assertJson(['status' => 'ACTIVE', 'version' => 2]);
        $this->postJson('/api/v1/availability/location', $point)->assertJson(['supported' => false, 'reason' => 'REGION_UNAVAILABLE']);

        $this->patchJson('/api/v1/admin/regions/'.$region['id'], ['version' => 1, 'status' => 'ACTIVE'])->assertOk();
        $this->postJson('/api/v1/availability/location', $point)->assertJson(['supported' => false, 'reason' => 'CITY_UNAVAILABLE']);

        $this->patchJson('/api/v1/admin/cities/'.$city['id'], ['version' => 1, 'status' => 'PILOT'])->assertOk()->assertJson(['status' => 'PILOT', 'serving_customers' => true])->assertJsonPath('launched_at', fn ($v) => $v !== null);
        $this->postJson('/api/v1/availability/location', $point)->assertJson(['supported' => true, 'city' => ['name' => 'Noida', 'status' => 'PILOT']]);

        $this->assertSame(['region.created', 'city.created', 'service_area.created', 'service_area.updated', 'region.updated', 'city.updated'], AuditEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_a_stale_version_writes_nothing(): void
    {
        $city = $this->city($this->region($this->india));

        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'name' => 'Noida City'])->assertOk()->assertJson(['name' => 'Noida City', 'version' => 2]);

        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'name' => 'Overwritten'])
            ->assertConflict()->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 2);

        $this->assertSame('Noida City', DB::table('cities')->value('name'));
        $this->assertSame(1, AuditEvent::query()->count());
        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['name' => 'No version'])->assertUnprocessable();
    }

    public function test_an_update_that_changes_nothing_does_not_bump_the_version_or_write_an_audit_event(): void
    {
        $city = $this->city($this->region($this->india));

        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'name' => 'Noida', 'status' => 'ACTIVE'])->assertOk()->assertJson(['version' => 1]);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_status_changes_follow_controlled_transitions(): void
    {
        $city = $this->city($this->region($this->india), status: 'UNAVAILABLE');

        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'status' => 'ACTIVE'])
            ->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition')->assertJsonPath('error.details.allowed', ['PLANNED']);
        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'status' => 'LIVE'])->assertUnprocessable();

        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'status' => 'PLANNED'])->assertOk();
        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 2, 'status' => 'ACTIVE'])->assertOk()->assertJson(['status' => 'ACTIVE']);
    }

    public function test_taking_coverage_away_from_customers_needs_a_reason_which_is_audited(): void
    {
        $area = $this->area($this->city($this->region($this->india)), self::BOX);
        $url = '/api/v1/admin/service-areas/'.$area->public_id;

        $this->patchJson($url, ['version' => 1, 'status' => 'PAUSED'])->assertUnprocessable()->assertJsonPath('error.details.fields.reason.0', 'A reason is required for this change.');
        $this->patchJson($url, ['version' => 1, 'status' => 'PAUSED', 'reason' => '  '])->assertUnprocessable();
        $this->assertSame('ACTIVE', DB::table('service_areas')->value('status'));

        $this->patchJson($url, ['version' => 1, 'status' => 'PAUSED', 'reason' => 'Flooding on NH24'])->assertOk()->assertJson(['status' => 'PAUSED']);

        $event = AuditEvent::query()->sole();
        $this->assertSame('service_area.updated', $event->action);
        $this->assertSame('Flooding on NH24', $event->reason);
        $this->assertSame($this->admin->public_id, $event->actor_public_id);
        $this->assertSame('admin_user', $event->actor_type);
        $this->assertSame($area->public_id, $event->target_public_id);
        $this->assertEquals(['from' => 'ACTIVE', 'to' => 'PAUSED'], $event->changes['status']);
        $this->assertSame(['status'], array_keys($event->changes));
        $this->assertNotNull($event->request_id);

        // Bringing it back needs no reason.
        $this->patchJson($url, ['version' => 2, 'status' => 'ACTIVE'])->assertOk();
    }

    public function test_invalid_geometry_is_rejected_and_never_stored(): void
    {
        $city = $this->city($this->region($this->india));
        $post = fn (mixed $geometry) => $this->postJson($this->url('/service-areas'), ['city_id' => $city->public_id, 'name' => 'Bad '.uniqid(), 'geometry' => $geometry]);

        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[77.30, 28.50], [77.40, 28.60], [77.40, 28.50], [77.30, 28.60], [77.30, 28.50]]]];
        $post($bowtie)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry')->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'Self-intersection'));

        $open = ['type' => 'Polygon', 'coordinates' => [[[77.30, 28.50], [77.40, 28.50], [77.40, 28.60], [77.30, 28.60]]]];
        $post($open)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry');

        $post(['type' => 'Point', 'coordinates' => [77.3, 28.5]])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry');
        $post(['type' => 'Polygon', 'coordinates' => [[[77.30, 128.50], [77.40, 28.50], [77.40, 28.60], [77.30, 128.50]]]])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry');
        $post([...$this->square(self::BOX), 'crs' => ['type' => 'name', 'properties' => ['name' => 'EPSG:3857']]])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry');
        $post(['type' => 'Feature', 'geometry' => $this->square(self::BOX)])->assertUnprocessable();
        $post('POLYGON((77.3 28.5, 77.4 28.5, 77.4 28.6, 77.3 28.5))')->assertUnprocessable();

        // Latitude and longitude swapped: a valid polygon, but not in India.
        $post($this->square([28.50, 77.30, 28.60, 77.40]))->assertUnprocessable()->assertJsonPath('error.code', 'geometry_outside_market');

        config(['geo.max_positions' => 4]);
        $post($this->square(self::BOX))->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry');

        $this->assertSame(0, DB::table('service_areas')->count());
    }

    public function test_service_area_geometry_can_be_replaced_and_is_stored_as_a_wgs84_multipolygon(): void
    {
        $area = $this->area($this->city($this->region($this->india)), self::BOX);
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [$this->square([77.30, 28.50, 77.35, 28.55])['coordinates'], $this->square([77.36, 28.56, 77.40, 28.60])['coordinates']]];

        $this->patchJson('/api/v1/admin/service-areas/'.$area->public_id, ['version' => 1, 'geometry' => $multi])
            ->assertOk()->assertJson(['version' => 2, 'vertices' => 10, 'geometry' => ['type' => 'MultiPolygon']]);

        $row = DB::selectOne('select ST_SRID(geometry) as srid, GeometryType(geometry) as type, ST_NumGeometries(geometry) as parts from service_areas');
        $this->assertSame([4326, 'MULTIPOLYGON', 2], [(int) $row->srid, $row->type, (int) $row->parts]);

        $this->postJson('/api/v1/availability/location', ['lat' => 28.555, 'lng' => 77.355])->assertJson(['supported' => false]);   // the gap between the two parts
        $this->postJson('/api/v1/availability/location', ['lat' => 28.52, 'lng' => 77.32])->assertJson(['supported' => true]);

        $this->getJson('/api/v1/admin/service-areas/'.$area->public_id)->assertOk()->assertJsonPath('geometry.type', 'MultiPolygon');
        $this->getJson($this->url('/service-areas'))->assertOk()->assertJsonMissingPath('data.0.geometry')->assertJsonPath('data.0.bbox.type', 'Polygon');
    }

    public function test_route_corridors_are_created_from_a_linestring_and_validated(): void
    {
        $region = $this->region($this->india);
        $delhi = $this->city($region, 'Delhi', 28.6139, 77.209);
        $karnal = $this->city($region, 'Karnal', 29.6857, 76.9905);
        $line = ['type' => 'LineString', 'coordinates' => [[77.209, 28.6139], [76.9905, 29.6857]]];
        $body = ['name' => 'Delhi → Karnal', 'highway' => 'NH44', 'origin_city_id' => $delhi->public_id, 'destination_city_id' => $karnal->public_id, 'geometry' => $line, 'corridor_width_meters' => 5000];

        $corridor = $this->postJson($this->url('/route-corridors'), $body)
            ->assertCreated()->assertJson(['status' => 'PLANNED', 'slug' => 'delhi-karnal', 'origin_city_id' => $delhi->public_id, 'corridor_width_meters' => 5000, 'geometry' => ['type' => 'LineString']])->json();
        $this->assertEqualsWithDelta(120_700, $corridor['length_meters'], 1_000);

        $this->postJson($this->url('/route-corridors'), $body)->assertUnprocessable();                                                   // duplicate name
        $this->postJson($this->url('/route-corridors'), [...$body, 'name' => 'Narrow', 'corridor_width_meters' => 50])->assertUnprocessable();
        $this->postJson($this->url('/route-corridors'), [...$body, 'name' => 'Polygon', 'geometry' => $this->square(self::BOX)])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_geometry');
        $this->postJson($this->url('/route-corridors'), [...$body, 'name' => 'One point', 'geometry' => ['type' => 'LineString', 'coordinates' => [[77.2, 28.6]]]])->assertUnprocessable();
        $this->postJson($this->url('/route-corridors'), [...$body, 'name' => 'Foreign city', 'origin_city_id' => '0a000000-0000-4000-8000-00000000000a'])->assertUnprocessable();

        $this->patchJson('/api/v1/admin/route-corridors/'.$corridor['id'], ['version' => 1, 'status' => 'ACTIVE', 'corridor_width_meters' => 8000])->assertOk()->assertJson(['status' => 'ACTIVE', 'corridor_width_meters' => 8000, 'version' => 2]);
        $this->getJson($this->url('/route-corridors'))->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.geometry');
    }

    public function test_market_status_changes_need_a_reason_and_pausing_stops_customer_service(): void
    {
        $this->area($this->city($this->region($this->india)), self::BOX);
        $point = ['lat' => 28.55, 'lng' => 77.35];

        $this->patchJson($this->url(), ['version' => 1, 'status' => 'PAUSED'])->assertUnprocessable();
        $this->patchJson($this->url(), ['version' => 1, 'status' => 'DRAFT', 'reason' => 'Trying to go back'])->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');

        $this->getJson('/api/v1/markets/current')->assertOk();
        $this->patchJson($this->url(), ['version' => 1, 'status' => 'PAUSED', 'reason' => 'Regulatory review'])->assertOk()->assertJson(['status' => 'PAUSED', 'serving_customers' => false, 'version' => 2]);

        $this->getJson('/api/v1/markets/current')->assertNotFound();
        $this->getJson('/api/v1/markets')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/availability/location', $point)->assertJson(['supported' => false, 'reason' => 'MARKET_PAUSED']);

        $this->patchJson($this->url(), ['version' => 2, 'status' => 'ACTIVE', 'reason' => 'Review passed'])->assertOk();
        $this->postJson('/api/v1/availability/location', $point)->assertJson(['supported' => true]);
    }

    public function test_feature_flags_change_with_a_reason_and_locked_features_cannot_be_enabled(): void
    {
        (new MarketConfiguration)->forceFill(['market_id' => $this->india->id, 'locked_features' => ['cash_at_pickup']])->save();

        $this->getJson('/api/v1/markets/current')->assertJsonPath('features.reviews', true);

        $this->patchJson($this->url('/features'), ['version' => 1, 'features' => ['reviews' => false], 'reason' => 'Moderation backlog'])
            ->assertOk()->assertJsonPath('features.reviews', false)->assertJsonPath('features.scheduled_pickup', true)->assertJsonPath('version', 2);
        $this->getJson('/api/v1/markets/current')->assertJsonPath('features.reviews', false);

        $this->patchJson($this->url('/features'), ['version' => 2, 'features' => ['cash_at_pickup' => true], 'reason' => 'Try it'])->assertConflict()->assertJsonPath('error.code', 'feature_locked');
        $this->patchJson($this->url('/features'), ['version' => 2, 'features' => ['teleport' => true], 'reason' => 'Unknown'])->assertUnprocessable();
        $this->patchJson($this->url('/features'), ['version' => 2, 'features' => ['reviews' => true]])->assertUnprocessable();
        $this->patchJson($this->url('/features'), ['version' => 2, 'features' => ['reviews' => 'yes'], 'reason' => 'Not a boolean'])->assertUnprocessable();

        $this->assertFalse($this->india->refresh()->featureEnabled('cash_at_pickup'));
        $this->assertSame(['market.features_updated'], AuditEvent::query()->pluck('action')->all());
    }

    public function test_market_configuration_is_read_and_updated_with_version_and_reason(): void
    {
        $this->getJson($this->url('/configuration'))->assertOk()->assertJson(['version' => 1, 'locked_features' => []]);

        $this->patchJson($this->url('/configuration'), ['version' => 1, 'reason' => 'GST registration received', 'tax' => ['regime' => 'GST', 'status' => 'CONFIGURED']])
            ->assertOk()->assertJson(['version' => 2, 'tax' => ['regime' => 'GST', 'status' => 'CONFIGURED']]);

        $this->patchJson($this->url('/configuration'), ['version' => 1, 'reason' => 'Stale', 'tax' => ['status' => 'X']])->assertConflict();
        $this->patchJson($this->url('/configuration'), ['version' => 2, 'tax' => ['status' => 'X']])->assertUnprocessable();

        $event = AuditEvent::query()->sole();
        $this->assertSame(['market.configuration_updated', 'markets', $this->india->public_id], [$event->action, $event->target_type, $event->target_public_id]);
    }

    public function test_market_configuration_refuses_anything_that_looks_like_a_secret(): void
    {
        $this->patchJson($this->url('/configuration'), ['version' => 1, 'reason' => 'Provider setup', 'payment' => ['provider' => 'razorpay', 'credentials' => ['api_secret' => 'rzp_live_should_never_be_here']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'secrets_not_allowed');

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertStringNotContainsString('rzp_live', (string) json_encode(DB::table('market_configurations')->get()));
    }

    public function test_the_map_endpoint_returns_every_status_with_geometry_and_the_diagnostic_explains_a_point(): void
    {
        $region = $this->region($this->india);
        $city = $this->city($region);
        $this->area($city, self::BOX, 'TESTING', 'Testing area');
        $live = $this->area($city, [77.50, 28.50, 77.60, 28.60], name: 'Live area');
        $this->corridor($this->india, [[77.0, 28.55], [78.0, 28.55]], 'PLANNED');

        $this->getJson($this->url('/map'))->assertOk()
            ->assertJsonCount(1, 'regions')->assertJsonCount(1, 'cities')->assertJsonCount(2, 'service_areas')->assertJsonCount(1, 'route_corridors')
            ->assertJsonPath('service_areas.1.geometry.type', 'MultiPolygon')->assertJsonPath('route_corridors.0.geometry.type', 'LineString')->assertJsonPath('route_corridors.0.status', 'PLANNED');

        $check = $this->postJson($this->url('/availability-check'), ['lat' => 28.55, 'lng' => 77.35])->assertOk()
            ->assertJsonPath('availability.supported', false)->assertJsonPath('availability.reason', 'SERVICE_AREA_PAUSED')
            ->assertJsonPath('covering_service_areas.0.status', 'TESTING')
            ->assertJsonPath('nearest_active_service_area.id', $live->public_id)->json();
        $this->assertEqualsWithDelta(14_670, $check['nearest_active_service_area']['distance_meters'], 100, '0.15° of longitude at 28.55° N');
    }

    public function test_lists_are_paginated_filterable_and_never_expose_internal_ids(): void
    {
        $region = $this->region($this->india);
        $this->city($region, 'Noida');
        $this->city($region, 'Agra', 27.1767, 78.0081, 'PLANNED');

        $list = $this->getJson($this->url('/cities?filter[status]=PLANNED'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Agra')->assertJsonPath('meta.total', 1);
        $this->assertArrayNotHasKey('center', $list->json('data.0'));
        $this->assertArrayNotHasKey('market_id', $list->json('data.0'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $list->json('data.0.id'));

        $this->getJson($this->url('/cities?sort=-name'))->assertJsonPath('data.0.name', 'Noida');
        $this->getJson($this->url('/cities?filter[region_id]=1'))->assertUnprocessable();
        $this->getJson('/api/v1/admin/cities/'.$region->id)->assertNotFound();
    }

    public function test_lists_can_be_searched_by_name_and_narrowed_to_a_parent(): void
    {
        $up = $this->region($this->india);
        $hr = $this->region($this->india, 'IN-HR', 'ACTIVE', 'Haryana');
        $noida = $this->city($up, 'Noida');
        $this->city($up, 'Greater Noida', 28.4744, 77.504);
        $karnal = $this->city($hr, 'Karnal', 29.6857, 76.9905);
        $this->area($noida, self::BOX, name: 'Noida 100% Central');
        $this->area($karnal, [76.95, 29.65, 77.05, 29.75], name: 'Karnal NH44');
        $this->corridor($this->india, [[77.0, 28.55], [78.0, 28.55]], name: 'Delhi → Karnal');

        $names = fn (string $path) => array_column($this->getJson($this->url($path))->assertOk()->json('data'), 'name');

        $this->assertSame(['Greater Noida', 'Noida'], $names('/cities?q=noida'));
        $this->assertSame(['Karnal'], $names('/cities?region='.$hr->public_id));
        $this->assertSame(['Noida'], $names('/cities?q=NOIDA&filter[status]=ACTIVE&region='.$up->public_id.'&sort=-name&page[size]=1'));
        $this->assertSame(['Karnal NH44'], $names('/service-areas?city='.$karnal->public_id));
        $this->assertSame(['Noida 100% Central'], $names('/service-areas?q='.urlencode('100%')));
        $this->assertSame(['Noida 100% Central'], $names('/service-areas?q='.urlencode('%')), 'a wildcard is matched literally: only the name that contains a percent sign');
        $this->assertSame([], $names('/service-areas?q=_'));
        $this->assertSame([], $names('/cities?q='.urlencode("' or 1=1 --")));
        $this->assertSame(['Delhi → Karnal'], $names('/route-corridors?q=karnal'));
        $this->getJson($this->url('/cities?region=1'))->assertUnprocessable();
        $this->assertSame(3, DB::table('cities')->count());
    }

    public function test_the_audit_list_is_newest_first_and_filterable(): void
    {
        $city = $this->city($this->region($this->india));
        $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'name' => 'Noida City'])->assertOk();
        $this->travel(1)->minutes();
        $this->patchJson($this->url('/features'), ['version' => 1, 'features' => ['reviews' => false], 'reason' => 'Backlog'])->assertOk();

        $this->getJson('/api/v1/admin/audit-events')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'market.features_updated')->assertJsonPath('data.0.actor_id', $this->admin->public_id)
            ->assertJsonPath('data.1.changes.name.to', 'Noida City')->assertJsonMissingPath('data.0.ip');
        $this->getJson('/api/v1/admin/audit-events?filter[action]=city.updated')->assertJsonCount(1, 'data')->assertJsonPath('data.0.target_id', $city->public_id);
    }
}
