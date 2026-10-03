<?php

namespace Tests\Feature\Restaurant;

use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Restaurant\RestaurantCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Restaurants as customers see them: GET /restaurants, GET /restaurants/{slug}, GET /cuisines.
 * The clock is Monday 2026-10-05 12:00 in India.
 */
class PublicRestaurantApiTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        $this->organization = $this->organization();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $cuisines
     */
    private function restaurant(string $name, array $attributes = [], array $cuisines = [], float $lat = 28.55, float $lng = 77.35, ?RestaurantOrganization $organization = null): RestaurantLocation
    {
        $location = $this->hours($this->location($organization ?? $this->organization, $name, $attributes, $lat, $lng), [[null, '09:00', '22:00']]);
        $this->cuisines($location, $cuisines);

        return $location;
    }

    /**
     * @return list<string>
     */
    private function names(string $query = ''): array
    {
        return $this->getJson('/api/v1/restaurants'.$query)->assertOk()->json('data.*.name');
    }

    public function test_the_list_contains_only_restaurants_a_customer_may_see(): void
    {
        $this->restaurant('Open Kitchen');
        $this->hours($this->location($this->organization, 'Closed Kitchen'), [[null, '18:00', '22:00']]);
        $this->restaurant('Paused Kitchen', ['accepting_orders' => false, 'pause_reason' => 'Short of staff', 'paused_at' => now()]);
        $this->restaurant('Suspended Kitchen', ['status' => 'SUSPENDED']);
        $this->restaurant('Reviewed Kitchen', ['status' => 'UNDER_REVIEW']);
        $this->restaurant('Draft Kitchen', ['status' => 'DRAFT']);
        $this->restaurant('Faraway Kitchen', lat: 28.65);
        $this->restaurant('Banned Brand Kitchen', organization: $this->organization('Banned Brand', 'SUSPENDED'));

        $response = $this->getJson('/api/v1/restaurants')->assertOk();

        $this->assertSame(['Closed Kitchen', 'Open Kitchen', 'Paused Kitchen'], $response->json('data.*.name'));
        $this->assertSame(3, $response->json('meta.total'));

        $byName = collect($response->json('data'))->keyBy('name');
        $this->assertSame(['open_now' => true, 'orderable' => true, 'reason' => null], array_intersect_key($byName['Open Kitchen']['availability'], ['open_now' => 1, 'orderable' => 1, 'reason' => 1]));
        $this->assertSame(['open_now' => false, 'open_state' => 'CLOSED', 'accepting_orders' => true, 'orderable' => false, 'reason' => 'CLOSED_NOW'], array_slice($byName['Closed Kitchen']['availability'], 0, 5));
        $this->assertSame('2026-10-05T12:30:00+00:00', $byName['Closed Kitchen']['availability']['opens_next_at'], '18:00 in India');
        $this->assertSame(['open_now' => true, 'open_state' => 'OPEN', 'accepting_orders' => false, 'orderable' => false, 'reason' => 'NOT_ACCEPTING_ORDERS'], array_slice($byName['Paused Kitchen']['availability'], 0, 5));
    }

    public function test_the_detail_is_found_by_slug_and_has_one_customer_safe_shape(): void
    {
        $location = $this->restaurant('Burger Hub', [
            'slug' => 'burger-hub', 'branch_label' => 'Sector 62', 'short_description' => 'Burgers & fries', 'description' => "Tom & Jerry's \"Diner\"\nSecond line",
            'price_level' => 2, 'phone_e164' => '+911204567890', 'public_email' => 'hello@burgerhub.example', 'website' => 'https://burgerhub.example', 'postal_code' => '201309',
            'pickup_instructions' => 'Show your code at the counter.', 'status_note' => 'INTERNAL: never shown', 'pause_reason' => null,
        ], ['burgers', 'fast_food']);
        $location->features()->sync(DB::table('restaurant_features')->whereIn('code', ['parking', 'pure_veg'])->pluck('id')->all());
        $this->image($location, 'COVER', '/images/food-burger.jpg');
        $this->image($location, 'GALLERY', '/images/food-pizza.jpg');
        $this->special($location, '2026-10-08', note: 'Closed for Dussehra');
        DB::table('restaurant_special_hours')->update(['internal_note' => 'INTERNAL: staff party']);
        $this->special($location, '2026-10-01', note: 'Already over');
        $this->special($location, '2026-12-25', note: 'Too far ahead to show');

        $response = $this->getJson('/api/v1/restaurants/burger-hub')->assertOk();

        $response->assertExactJson([
            'id' => $location->public_id,
            'slug' => 'burger-hub',
            'name' => 'Burger Hub',
            'branch_label' => 'Sector 62',
            'short_description' => 'Burgers & fries',
            'description' => "Tom & Jerry's \"Diner\"\nSecond line",
            'cuisines' => [['code' => 'burgers', 'name' => 'Burgers'], ['code' => 'fast_food', 'name' => 'Fast Food']],
            'features' => [['code' => 'parking', 'name' => 'Parking', 'category' => 'FACILITY'], ['code' => 'pure_veg', 'name' => 'Pure Veg', 'category' => 'DIETARY']],
            'price_level' => 2,
            'phone' => '+911204567890',
            'email' => 'hello@burgerhub.example',
            'website' => 'https://burgerhub.example',
            'address' => [
                'formatted' => 'Sector 62, Noida, Uttar Pradesh 201309, India', 'line1' => null, 'postal_code' => '201309',
                'city' => 'Noida', 'city_slug' => 'noida', 'region' => 'Uttar Pradesh', 'region_code' => 'IN-UP', 'country_code' => 'IN',
            ],
            'location' => ['latitude' => 28.55, 'longitude' => 77.35],
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'images' => [
                'logo' => null,
                'cover' => ['url' => '/images/food-burger.jpg', 'alt_text' => 'Burger Hub'],
                'gallery' => [['url' => '/images/food-pizza.jpg', 'alt_text' => 'Burger Hub']],
            ],
            'hours' => [
                'timezone' => 'Asia/Kolkata',
                'weekly' => array_map(fn (int $day): array => ['day_of_week' => $day, 'periods' => [['opens_at' => '09:00', 'closes_at' => '22:00']]], range(0, 6)),
                'special' => [['date' => '2026-10-08', 'is_closed' => true, 'periods' => [], 'note' => 'Closed for Dussehra']],
            ],
            'pickup' => [
                'methods' => [['method' => 'COUNTER', 'instructions' => null, 'requires_vehicle_info' => false]],
                'asap' => true, 'scheduled' => false, 'default_prep_minutes' => 15, 'minimum_lead_minutes' => 15,
                'instructions' => 'Show your code at the counter.',
            ],
            'availability' => [
                'open_now' => true, 'open_state' => 'OPEN', 'accepting_orders' => true, 'orderable' => true, 'reason' => null,
                'closes_at' => '2026-10-05T16:30:00+00:00', 'opens_next_at' => null, 'checked_at' => '2026-10-05T06:30:00+00:00',
            ],
        ]);

        $body = $response->getContent();
        foreach (['INTERNAL', 'status_note', 'legal_name', 'Pvt. Ltd.', 'pause_reason', 'version', 'organization', 'internal_note', '"status"'] as $private) {
            $this->assertStringNotContainsString($private, $body, "customers must not receive [{$private}]");
        }
    }

    public function test_a_restaurant_a_customer_may_not_see_is_a_404_like_one_that_never_existed(): void
    {
        $this->restaurant('Suspended', ['slug' => 'suspended', 'status' => 'SUSPENDED']);
        $this->restaurant('Draft', ['slug' => 'draft', 'status' => 'DRAFT']);
        $this->restaurant('Rejected', ['slug' => 'rejected', 'status' => 'REJECTED']);
        $this->restaurant('Faraway', ['slug' => 'faraway'], lat: 28.65);
        $this->restaurant('Banned', ['slug' => 'banned'], organization: $this->organization('Banned Brand', 'SUSPENDED'));

        foreach (['suspended', 'draft', 'rejected', 'faraway', 'banned', 'never-existed'] as $slug) {
            $response = $this->getJson('/api/v1/restaurants/'.$slug)->assertNotFound();

            // The same answer for all of them: nothing tells a hidden restaurant from a slug that was never used.
            $this->assertSame(['code' => 'restaurant_not_found', 'message' => 'This restaurant is not available on FoodOnTheGo.'], array_diff_key($response->json('error'), ['request_id' => 1]), $slug);
        }

        $this->getJson('/api/v1/restaurants/Not_A_Slug')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_closed_paused_and_area_paused_restaurants_keep_their_page(): void
    {
        $this->restaurant('Paused', ['slug' => 'paused', 'accepting_orders' => false, 'paused_at' => now(), 'pause_reason' => 'Private: stock problem']);
        $this->restaurant('Switched Off', ['slug' => 'switched-off', 'operational_status' => 'TEMPORARILY_CLOSED']);

        $this->getJson('/api/v1/restaurants/paused')->assertOk()->assertJsonPath('availability.reason', 'NOT_ACCEPTING_ORDERS')->assertJsonMissingPath('pause_reason')
            ->assertDontSee('stock problem');
        $this->getJson('/api/v1/restaurants/switched-off')->assertOk()->assertJsonPath('availability.open_state', 'TEMPORARILY_CLOSED')->assertJsonPath('availability.reason', 'TEMPORARILY_CLOSED');

        DB::table('service_areas')->update(['status' => 'PAUSED']);
        $this->getJson('/api/v1/restaurants/paused')->assertOk()->assertJsonPath('availability.reason', 'AREA_UNAVAILABLE')->assertJsonPath('availability.orderable', false);

        DB::table('service_areas')->update(['status' => 'DISABLED']);
        $this->getJson('/api/v1/restaurants/paused')->assertNotFound();
    }

    public function test_filters_narrow_the_list(): void
    {
        $gujarat = $this->region($this->market, 'IN-GJ', name: 'Gujarat');
        $surat = $this->city($gujarat, 'Surat', 21.1702, 72.8311);
        $this->area($surat, [72.78, 21.12, 72.88, 21.22], name: 'Surat NH48');

        $burger = $this->restaurant('Burger Hub', ['price_level' => 2], ['burgers', 'fast_food']);
        $pizza = $this->restaurant('Pizza Point', ['price_level' => 2], ['pizza', 'italian']);
        $thali = $this->location($this->organization, 'Surat Thali', ['price_level' => 1], 21.17, 72.83, $surat);
        $this->hours($thali, [[null, '18:00', '23:00']]);
        $this->cuisines($thali, ['gujarati', 'thali']);

        $features = DB::table('restaurant_features')->pluck('id', 'code');
        $burger->features()->sync([$features['parking'], $features['quick_pickup']]);
        $pizza->features()->sync([$features['parking']]);

        $this->assertSame(['Burger Hub', 'Pizza Point', 'Surat Thali'], $this->names());
        $this->assertSame(['Burger Hub', 'Pizza Point'], $this->names('?filter[city]=noida'));
        $this->assertSame(['Surat Thali'], $this->names('?filter[city]=surat'));
        $this->assertSame(['Surat Thali'], $this->names('?filter[region]=IN-GJ'));
        $this->assertSame([], $this->names('?filter[city]=atlantis'));
        $this->assertSame(['Burger Hub', 'Pizza Point'], $this->names('?filter[service_area]='.$this->serviceArea->slug));

        $this->assertSame(['Pizza Point'], $this->names('?filter[cuisine]=pizza'));
        $this->assertSame(['Pizza Point', 'Surat Thali'], $this->names('?filter[cuisine]=pizza,gujarati'), 'any of the cuisines');
        $this->assertSame(['Burger Hub', 'Pizza Point'], $this->names('?filter[feature]=parking'));
        $this->assertSame(['Burger Hub'], $this->names('?filter[feature]=parking,quick_pickup'), 'all of the features');
        $this->assertSame(['Surat Thali'], $this->names('?filter[price_level]=1'));

        $this->assertSame(['Burger Hub', 'Pizza Point'], $this->names('?filter[open_now]=true'), 'Surat Thali opens at 18:00');
        $this->assertSame(['Burger Hub', 'Pizza Point', 'Surat Thali'], $this->names('?filter[open_now]=false'));
        $this->assertSame(['Burger Hub'], $this->names('?filter[open_now]=true&filter[cuisine]=burgers&filter[city]=noida'));

        $this->assertSame(['Pizza Point'], $this->names('?q=pizza'));
        $this->assertSame(['Surat Thali'], $this->names('?q=gujar'), 'a cuisine name matches too');
        $this->assertSame(['Burger Hub'], $this->names('?q=BURGER'));
        $this->assertSame([], $this->names('?q=%25'), 'a wildcard is only a character');

        $this->assertSame(['Surat Thali', 'Pizza Point', 'Burger Hub'], $this->names('?sort=-name'));
    }

    public function test_unknown_filters_and_sorts_are_refused_not_ignored(): void
    {
        $this->getJson('/api/v1/restaurants?filter[status]=SUSPENDED')->assertUnprocessable()->assertJsonPath('error.details.fields', ['filter.status' => ['This filter is not supported.']]);
        $this->getJson('/api/v1/restaurants?filter[organization_id]=1')->assertUnprocessable();
        $this->getJson('/api/v1/restaurants?filter[open_now]=maybe')->assertUnprocessable();
        $this->getJson('/api/v1/restaurants?sort=created_at')->assertUnprocessable();
        $this->getJson('/api/v1/restaurants?sort=distance')->assertUnprocessable()->assertJsonPath('error.details.fields.sort.0', 'Sorting by distance needs lat and lng.');
        $this->getJson('/api/v1/restaurants?lat=28.55')->assertUnprocessable();
        $this->getJson('/api/v1/restaurants?lat=128.55&lng=77.35')->assertUnprocessable();
        $this->getJson('/api/v1/restaurants?radius_meters=5000')->assertUnprocessable();
        $this->getJson('/api/v1/restaurants?country=india')->assertUnprocessable();
    }

    public function test_coordinates_add_a_straight_line_distance_and_sort_by_it(): void
    {
        $this->restaurant('Near', lat: 28.551, lng: 77.351);
        $this->restaurant('Middle', lat: 28.57, lng: 77.37);
        $this->restaurant('Far', lat: 28.59, lng: 77.39);

        $response = $this->getJson('/api/v1/restaurants?lat=28.55&lng=77.35')->assertOk();
        $this->assertSame(['Near', 'Middle', 'Far'], $response->json('data.*.name'));
        $distances = $response->json('data.*.distance_meters');
        $this->assertEqualsWithDelta(148, $distances[0], 5);
        $this->assertEqualsWithDelta(2954, $distances[1], 30);
        $this->assertEqualsWithDelta(5907, $distances[2], 60);

        $this->assertSame(['Far', 'Middle', 'Near'], $this->names('?lat=28.55&lng=77.35&sort=name'));
        $this->assertSame(['Near', 'Middle'], $this->names('?lat=28.55&lng=77.35&radius_meters=3500'));
        $this->assertSame(['Far', 'Middle', 'Near'], $this->names('?lat=28.55&lng=77.35&sort=-distance'));

        $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonMissingPath('data.0.distance_meters');
        $this->getJson('/api/v1/restaurants/'.RestaurantLocation::query()->where('name', 'Far')->value('slug').'?lat=28.55&lng=77.35')->assertOk()->assertJsonPath('distance_meters', $distances[2]);
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 7) as $n) {
            $this->restaurant('Kitchen '.$n);
        }

        $page = $this->getJson('/api/v1/restaurants?page[size]=3&page[number]=2')->assertOk();
        $this->assertSame(['Kitchen 4', 'Kitchen 5', 'Kitchen 6'], $page->json('data.*.name'));
        $this->assertEquals(['current_page' => 2, 'per_page' => 3, 'total' => 7, 'last_page' => 3], array_intersect_key($page->json('meta'), ['current_page' => 1, 'per_page' => 1, 'total' => 1, 'last_page' => 1]));
        $this->assertSame([], $this->names('?page[size]=3&page[number]=9'));
        $this->assertCount(7, $this->getJson('/api/v1/restaurants?page[size]=5000')->json('data'), 'the page size is capped, not an error');
    }

    public function test_following_the_pagination_links_walks_through_the_pages_with_the_same_size_filters_and_sort(): void
    {
        foreach (range(1, 7) as $n) {
            $this->restaurant('Kitchen '.$n, cuisines: ['pizza']);
        }
        $this->restaurant('Burger Bar', cuisines: ['burgers']);

        $first = $this->getJson('/api/v1/restaurants?filter[cuisine]=pizza&sort=-name&page[size]=3')->assertOk();
        $this->assertSame(['Kitchen 7', 'Kitchen 6', 'Kitchen 5'], $first->json('data.*.name'));
        $this->assertNull($first->json('links.prev'));

        // The link is used exactly as the API printed it.
        $follow = fn (string $url) => $this->getJson(substr($url, strpos($url, '/api/v1')))->assertOk();
        $second = $follow($first->json('links.next'));
        $this->assertSame(['Kitchen 4', 'Kitchen 3', 'Kitchen 2'], $second->json('data.*.name'));
        $this->assertSame(2, $second->json('meta.current_page'));
        $this->assertSame(3, $second->json('meta.per_page'));

        $last = $follow($second->json('links.next'));
        $this->assertSame(['Kitchen 1'], $last->json('data.*.name'), 'the filter still excludes the burger bar');
        $this->assertNull($last->json('links.next'));
        $this->assertSame($first->json('data.*.name'), $follow($last->json('links.first'))->json('data.*.name'));
        $this->assertSame($second->json('data.*.name'), $follow($last->json('links.prev'))->json('data.*.name'));

        parse_str((string) parse_url($first->json('links.next'), PHP_URL_QUERY), $query);
        $this->assertEquals(['filter' => ['cuisine' => 'pizza'], 'sort' => '-name', 'page' => ['number' => '2', 'size' => '3']], $query);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_restaurants(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/restaurants?lat=28.55&lng=77.35')->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 2) as $n) {
            $location = $this->restaurant('Kitchen '.$n, cuisines: ['pizza']);
            $this->special($location, '2026-10-0'.(5 + $n), [['10:00', '12:00']]);
            $this->image($location);
        }
        $two = $count();

        foreach (range(3, 14) as $n) {
            $location = $this->restaurant('Kitchen '.$n, cuisines: ['pizza', 'burgers']);
            $this->special($location, '2026-10-07');
            $this->image($location);
        }
        $fourteen = $count();

        $this->assertSame($two, $fourteen, 'no query per restaurant');
        $this->assertLessThanOrEqual(16, $fourteen);
    }

    public function test_cuisines_list_the_active_taxonomy_with_the_restaurants_customers_can_see(): void
    {
        $this->restaurant('Pizza One', cuisines: ['pizza']);
        $this->restaurant('Pizza Two', cuisines: ['pizza', 'italian']);
        $this->restaurant('Hidden Pizza', ['status' => 'SUSPENDED'], ['pizza', 'biryani']);
        DB::table('cuisines')->where('code', 'bengali')->update(['status' => 'INACTIVE']);

        $cuisines = collect($this->getJson('/api/v1/cuisines')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['code' => 'pizza', 'name' => 'Pizza', 'slug' => 'pizza', 'restaurants' => 2], $cuisines['pizza']);
        $this->assertSame(1, $cuisines['italian']['restaurants']);
        $this->assertSame(0, $cuisines['biryani']['restaurants'], 'a suspended restaurant is not counted');
        $this->assertFalse($cuisines->has('bengali'), 'an inactive cuisine is not offered');
        $this->assertSame('north_indian', $cuisines->keys()->first(), 'display order');
        $this->assertSame('Andhra / Telangana', $cuisines['andhra_telangana']['name']);
    }

    public function test_the_cuisine_list_is_cached_and_every_restaurant_change_invalidates_it(): void
    {
        config(['cache.default' => 'array']);
        $location = $this->restaurant('Pizza One', cuisines: ['pizza']);
        $pizzas = fn (): int => collect($this->getJson('/api/v1/cuisines')->json('data'))->firstWhere('code', 'pizza')['restaurants'];

        $this->assertSame(1, $pizzas());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(1, $pizzas());
        $this->assertCount(1, DB::getQueryLog(), 'only the market lookup: the taxonomy came from the cache');

        // A direct database change is not seen until something invalidates the cache …
        DB::table('restaurant_locations')->where('id', $location->id)->update(['status' => 'SUSPENDED']);
        $this->assertSame(1, $pizzas());

        // … and every write through a model or a service does.
        app(RestaurantCatalog::class)->flush();
        $this->assertSame(0, $pizzas());

        RestaurantLocation::query()->findOrFail($location->id)->forceFill(['status' => 'APPROVED'])->save();
        $this->assertSame(1, $pizzas(), 'saving a location invalidates');

        DB::table('service_areas')->update(['status' => 'DISABLED']);
        $this->assertSame(1, $pizzas());
        $this->serviceArea->refresh()->touch();
        $this->assertSame(0, $pizzas(), 'a change to market geography invalidates as well');
    }

    public function test_only_pickup_methods_the_market_allows_are_offered(): void
    {
        $location = $this->restaurant('Drive In', ['slug' => 'drive-in']);
        DB::table('restaurant_location_pickup_methods')->insert(['location_id' => $location->id, 'method' => 'CURBSIDE', 'enabled' => true, 'instructions' => 'Bay 3', 'requires_vehicle_info' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('restaurant_pickup_settings')->where('location_id', $location->id)->update(['scheduled_enabled' => true]);

        $this->getJson('/api/v1/restaurants/drive-in')->assertOk()->assertJsonPath('pickup.methods.*.method', ['COUNTER'])->assertJsonPath('pickup.scheduled', true);

        $this->market->forceFill(['features' => ['asap_pickup' => true, 'scheduled_pickup' => false, 'curbside_pickup' => true]])->save();

        $this->getJson('/api/v1/restaurants/drive-in')->assertOk()
            ->assertJsonPath('pickup.methods', [['method' => 'COUNTER', 'instructions' => null, 'requires_vehicle_info' => false], ['method' => 'CURBSIDE', 'instructions' => 'Bay 3', 'requires_vehicle_info' => true]])
            ->assertJsonPath('pickup.scheduled', false);
    }

    public function test_a_market_that_does_not_serve_customers_has_no_restaurants(): void
    {
        $this->restaurant('Open Kitchen', ['slug' => 'open-kitchen']);

        $this->getJson('/api/v1/restaurants?country=US')->assertNotFound()->assertJsonPath('error.code', 'market_unavailable');

        $this->market->forceFill(['status' => 'PAUSED'])->save();
        $this->getJson('/api/v1/restaurants')->assertNotFound()->assertJsonPath('error.code', 'market_unavailable');
        $this->getJson('/api/v1/restaurants/open-kitchen')->assertNotFound();
        $this->getJson('/api/v1/cuisines')->assertNotFound();
    }

    public function test_the_public_endpoints_need_no_sign_in_and_reject_writes(): void
    {
        $this->restaurant('Open Kitchen', ['slug' => 'open-kitchen']);

        $this->getJson('/api/v1/restaurants')->assertOk();
        $this->postJson('/api/v1/restaurants', ['name' => 'Mine'])->assertStatus(405);
        $this->patchJson('/api/v1/restaurants/open-kitchen', ['status' => 'APPROVED'])->assertStatus(405);
        $this->deleteJson('/api/v1/restaurants/open-kitchen')->assertStatus(405);
    }
}
