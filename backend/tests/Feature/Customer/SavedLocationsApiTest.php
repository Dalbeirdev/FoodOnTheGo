<?php

namespace Tests\Feature\Customer;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerRecentLocation;
use App\Models\Market;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Saved journey locations (Module 25): create / list / update / delete, the PostGIS point, market resolution
 * (India inside and outside coverage, abroad, no coordinates), the single default, versions, the limit, Unicode
 * labels, validation, ownership (IDOR) and that nothing here speaks of delivery.
 */
class SavedLocationsApiTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase;

    private Customer $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld(); // India market, Noida, ACTIVE service area [77.30, 28.50] – [77.40, 28.60]
        $this->rahul = $this->customer($this->market, ['name' => 'Rahul Sharma']);
        $this->actingAsPrincipal($this->rahul);
    }

    /**
     * @return array<string, mixed>
     */
    private function home(array $patch = []): array
    {
        return ['kind' => 'HOME', 'label' => 'Home', 'line1' => 'A-203, Green Valley Apartments', 'locality' => 'Sector 62', 'city' => 'Noida', 'region' => 'Uttar Pradesh', 'postal_code' => '201309', 'country_code' => 'IN', 'formatted_address' => 'A-203, Green Valley Apartments, Sector 62, Noida, Uttar Pradesh 201309, India', 'lat' => 28.55, 'lng' => 77.35, ...$patch];
    }

    public function test_a_location_with_coordinates_is_stored_as_a_postgis_point_resolved_to_the_market_and_reported_supported(): void
    {
        $l = $this->postJson('/api/v1/customer/saved-locations', $this->home())->assertCreated()->json();
        $this->assertSame(['HOME', 'Home', true, 1], [$l['kind'], $l['label'], $l['is_default'], $l['version']]);
        $this->assertSame(['latitude' => 28.55, 'longitude' => 77.35], $l['location']);
        $this->assertSame('Sector 62', $l['address']['locality']);
        $this->assertSame('Noida', $l['address']['city']);
        $this->assertSame('IN', $l['address']['country_code']);
        $this->assertSame('Asia/Kolkata', $l['timezone']);
        $this->assertSame(['status' => 'supported', 'reason' => null, 'market' => 'IN', 'city' => 'Noida', 'service_area' => 'Noida Central'], $l['coverage']);
        $row = DB::selectOne('select ST_SRID(location::geometry) as srid, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng, market_id, city_id, service_area_id from customer_saved_locations where public_id = ?', [$l['id']]);
        $this->assertSame([4326, 28.55, 77.35], [(int) $row->srid, round((float) $row->lat, 4), round((float) $row->lng, 4)]);
        $this->assertSame((int) $this->market->getKey(), (int) $row->market_id);
        $this->assertSame((int) $this->noida->getKey(), (int) $row->city_id);
        $this->assertSame((int) $this->serviceArea->getKey(), (int) $row->service_area_id);
        $this->assertStringNotContainsString('deliver', strtolower(json_encode($l)));
        $this->assertSame('customer.saved_location_added', AuditEvent::query()->latest('id')->value('action'));
    }

    public function test_places_outside_coverage_abroad_or_without_coordinates_are_kept_and_told_apart(): void
    {
        $outside = $this->postJson('/api/v1/customer/saved-locations', $this->home(['kind' => 'OTHER', 'label' => 'Mumbai hotel', 'lat' => 18.9432, 'lng' => 72.8236, 'locality' => 'Churchgate', 'region' => 'Maharashtra', 'postal_code' => '400020', 'formatted_address' => 'Marine Drive, Mumbai']))->assertCreated()->json();
        $this->assertSame('unsupported', $outside['coverage']['status']);
        $this->assertSame('IN', $outside['coverage']['market']);
        $this->assertContains($outside['coverage']['reason'], ['OUTSIDE_SERVICE_AREA', 'CITY_UNAVAILABLE', 'REGION_UNAVAILABLE']);

        $abroad = $this->postJson('/api/v1/customer/saved-locations', ['kind' => 'OTHER', 'label' => 'Dubai airport', 'country_code' => 'AE', 'formatted_address' => 'Dubai International Airport', 'lat' => 25.2532, 'lng' => 55.3657, 'timezone' => 'Asia/Dubai'])->assertCreated()->json();
        $this->assertSame(['unsupported', 'MARKET_UNSUPPORTED', null], [$abroad['coverage']['status'], $abroad['coverage']['reason'], $abroad['coverage']['market']]);
        $this->assertSame(['AE', 'Asia/Dubai'], [$abroad['address']['country_code'], $abroad['timezone']]);
        $this->assertSame('DRAFT', Market::query()->where('country_code', 'AE')->value('status') ?? 'DRAFT', 'saving a place abroad never activates a market');

        $textOnly = $this->postJson('/api/v1/customer/saved-locations', ['kind' => 'OTHER', 'label' => "Grandma's place", 'line1' => '14, Model Town', 'locality' => 'Model Town', 'region' => 'Punjab', 'formatted_address' => '14, Model Town, Ludhiana, Punjab'])->assertCreated()->json();
        $this->assertNull($textOnly['location']);
        $this->assertSame(['status' => 'unknown', 'reason' => 'NO_COORDINATES', 'market' => null, 'city' => null, 'service_area' => null], $textOnly['coverage']);

        $list = $this->getJson('/api/v1/customer/saved-locations')->assertOk()->json();
        $this->assertSame(['Mumbai hotel', 'Dubai airport', "Grandma's place"], array_column($list, 'label'));
        $this->assertSame([true, false, false], array_column($list, 'is_default'), 'the first saved location is the default');
    }

    public function test_unicode_labels_flexible_addresses_and_validation(): void
    {
        $this->postJson('/api/v1/customer/saved-locations', ['label' => 'घर', 'kind' => 'HOME', 'formatted_address' => 'सेक्टर 62, नोएडा', 'lat' => 28.55, 'lng' => 77.35])->assertCreated()->assertJsonPath('label', 'घर')->assertJsonPath('address.formatted', 'सेक्टर 62, नोएडा')->assertJsonPath('address.postal_code', null);
        $fields = fn (array $body): array => $this->postJson('/api/v1/customer/saved-locations', $body)->assertUnprocessable()->json('error.details.fields');
        $this->assertArrayHasKey('label', $fields(['formatted_address' => 'x', 'lat' => 28.55, 'lng' => 77.35]));
        $this->assertArrayHasKey('lat', $fields(['label' => 'Bad', 'lat' => 95, 'lng' => 77.35]));
        $this->assertArrayHasKey('lng', $fields(['label' => 'Bad', 'lat' => 28.55, 'lng' => 181]));
        $this->assertArrayHasKey('lat', $fields(['label' => 'Half', 'lng' => 77.35]), 'a coordinate alone is refused');
        $this->assertArrayHasKey('kind', $fields(['label' => 'Bad', 'kind' => 'DELIVERY', 'formatted_address' => 'x']));
        $this->assertArrayHasKey('country_code', $fields(['label' => 'Bad', 'country_code' => 'India', 'formatted_address' => 'x']));
        $this->assertArrayHasKey('label', $fields(['label' => '<script>alert(1)</script>', 'formatted_address' => 'x']));
        $this->assertArrayHasKey('formatted_address', $fields(['label' => 'Text only', 'line1' => 'No address and no point']));
        $this->assertArrayHasKey('market_id', $fields($this->home(['market_id' => 1])));
        $this->assertArrayHasKey('customer_id', $fields($this->home(['customer_id' => 1])));
        $this->assertSame(1, DB::table('customer_saved_locations')->count());
    }

    public function test_updates_carry_a_version_can_move_or_clear_the_point_and_the_default_is_unique(): void
    {
        $home = $this->postJson('/api/v1/customer/saved-locations', $this->home())->assertCreated()->json();
        $work = $this->postJson('/api/v1/customer/saved-locations', $this->home(['kind' => 'WORK', 'label' => 'Work', 'lat' => 28.4987, 'lng' => 77.4115, 'locality' => 'Sector 142']))->assertCreated()->json();
        $this->assertSame([true, false], [$home['is_default'], $work['is_default']]);

        $moved = $this->patchJson('/api/v1/customer/saved-locations/'.$work['id'], ['version' => 1, 'label' => 'Office', 'lat' => 28.56, 'lng' => 77.36])->assertOk()->json();
        $this->assertSame(['Office', 2, 'supported'], [$moved['label'], $moved['version'], $moved['coverage']['status']]);
        $this->assertSame(['latitude' => 28.56, 'longitude' => 77.36], $moved['location']);
        $this->patchJson('/api/v1/customer/saved-locations/'.$work['id'], ['version' => 1, 'label' => 'Stale'])->assertStatus(409)->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 2);
        $cleared = $this->patchJson('/api/v1/customer/saved-locations/'.$work['id'], ['version' => 2, 'location' => null])->assertOk()->json();
        $this->assertNull($cleared['location']);
        $this->assertSame('unknown', $cleared['coverage']['status']);
        $this->patchJson('/api/v1/customer/saved-locations/'.$work['id'], ['version' => 3, 'location' => ['lat' => 1]])->assertUnprocessable();

        $this->postJson('/api/v1/customer/saved-locations/'.$home['id'].'/default')->assertOk()->assertJsonPath('is_default', true)->assertJsonPath('coverage.status', 'supported')->assertJsonPath('location.latitude', 28.55);
        $this->postJson('/api/v1/customer/saved-locations/'.$work['id'].'/default')->assertOk()->assertJsonPath('is_default', true);
        $this->assertSame([$work['id']], DB::table('customer_saved_locations')->where('is_default', true)->pluck('public_id')->all());
        $this->assertSame([$work['id'], $home['id']], array_column($this->getJson('/api/v1/customer/saved-locations')->json(), 'id'), 'default first');
        // the database itself refuses a second default
        $this->expectException(QueryException::class);
        DB::table('customer_saved_locations')->where('public_id', $home['id'])->update(['is_default' => true]);
    }

    public function test_deleting_a_default_promotes_the_oldest_remaining_one_and_the_limit_is_configurable(): void
    {
        $a = $this->postJson('/api/v1/customer/saved-locations', $this->home(['label' => 'A']))->json();
        $b = $this->postJson('/api/v1/customer/saved-locations', $this->home(['label' => 'B', 'kind' => 'OTHER']))->json();
        $this->postJson('/api/v1/customer/saved-locations', $this->home(['label' => 'C', 'kind' => 'OTHER']))->assertCreated();
        $this->deleteJson('/api/v1/customer/saved-locations/'.$a['id'])->assertNoContent();
        $this->deleteJson('/api/v1/customer/saved-locations/'.$a['id'])->assertNotFound();
        $this->assertSame([$b['id']], DB::table('customer_saved_locations')->where('is_default', true)->pluck('public_id')->all());

        config(['customer.limits.saved_locations' => 3]);
        $this->postJson('/api/v1/customer/saved-locations', $this->home(['label' => 'D', 'kind' => 'OTHER']))->assertCreated();
        $this->postJson('/api/v1/customer/saved-locations', $this->home(['label' => 'E', 'kind' => 'OTHER']))->assertUnprocessable()->assertJsonPath('error.details.fields.label.0', 'You have reached the maximum number of saved locations (3).');
    }

    public function test_another_customer_cannot_read_change_default_or_delete_my_locations_even_with_the_id(): void
    {
        $home = $this->postJson('/api/v1/customer/saved-locations', $this->home())->assertCreated()->json();
        $asha = $this->customer($this->market, ['name' => 'Asha Verma']);
        $this->actingAsPrincipal($asha);
        $this->assertSame([], $this->getJson('/api/v1/customer/saved-locations')->assertOk()->json());
        $this->patchJson('/api/v1/customer/saved-locations/'.$home['id'], ['version' => 1, 'label' => 'Mine now'])->assertNotFound()->assertJsonPath('error.code', 'saved_location_not_found');
        $this->postJson('/api/v1/customer/saved-locations/'.$home['id'].'/default')->assertNotFound();
        $this->deleteJson('/api/v1/customer/saved-locations/'.$home['id'])->assertNotFound();
        $this->assertSame('Home', DB::table('customer_saved_locations')->where('public_id', $home['id'])->value('label'));
        $this->assertSame(1, DB::table('customer_saved_locations')->count());
        // coordinates are private: nothing public ever returns them
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/saved-locations')->assertUnauthorized();
    }

    public function test_recent_locations_are_bounded_deduplicated_clearable_and_pruned(): void
    {
        config(['customer.limits.recent_locations' => 3]);
        $place = fn (string $label, float $lat, float $lng, ?string $placeId = null): array => ['label' => $label, 'lat' => $lat, 'lng' => $lng, 'formatted_address' => "$label, Noida", ...($placeId ? ['place_provider' => 'development', 'place_id' => $placeId] : [])];
        $this->postJson('/api/v1/customer/recent-locations', $place('Sector 18', 28.5700, 77.3200, 'dev:s18'))->assertCreated()->assertJsonPath('times_used', 1);
        $this->postJson('/api/v1/customer/recent-locations', $place('Sector 62', 28.6271, 77.3717))->assertCreated();
        $this->postJson('/api/v1/customer/recent-locations', $place('Sector 18 (again)', 28.5700, 77.3200, 'dev:s18'))->assertCreated()->assertJsonPath('times_used', 2)->assertJsonPath('label', 'Sector 18 (again)')->assertJsonPath('location.latitude', 28.57);
        $this->postJson('/api/v1/customer/recent-locations', $place('Sector 62 by coordinates', 28.62712, 77.37168))->assertCreated()->assertJsonPath('times_used', 2);
        $this->postJson('/api/v1/customer/recent-locations', $place('Airport', 28.5562, 77.1000))->assertCreated();
        $this->postJson('/api/v1/customer/recent-locations', $place('Station', 28.6420, 77.2200))->assertCreated();
        $list = $this->getJson('/api/v1/customer/recent-locations')->assertOk()->json();
        $this->assertSame(['Station', 'Airport', 'Sector 62 by coordinates'], array_column($list, 'label'), 'newest three only, deduplicated');
        $this->assertSame(['latitude' => 28.642, 'longitude' => 77.22], $list[0]['location']);
        $this->postJson('/api/v1/customer/recent-locations', ['label' => 'Bad', 'lat' => 91, 'lng' => 0])->assertUnprocessable();

        CustomerRecentLocation::query()->where('label', 'Airport')->update(['last_used_at' => now()->subDays(100)]);
        $this->artisan('customer:prune-recent-locations')->assertSuccessful();
        $this->assertSame(['Station', 'Sector 62 by coordinates'], array_column($this->getJson('/api/v1/customer/recent-locations')->json(), 'label'));

        $asha = $this->customer($this->market);
        $this->actingAsPrincipal($asha);
        $this->assertSame([], $this->getJson('/api/v1/customer/recent-locations')->json());
        $this->actingAsPrincipal($this->rahul);
        $this->deleteJson('/api/v1/customer/recent-locations')->assertNoContent();
        $this->assertSame([], $this->getJson('/api/v1/customer/recent-locations')->json());
    }
}
