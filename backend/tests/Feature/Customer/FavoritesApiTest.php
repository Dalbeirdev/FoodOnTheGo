<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Customer\CustomerFavoriteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Favorite restaurants (Module 25): add (idempotent, unique in the database), list with the restaurant as
 * customers see it, remove (idempotent), hidden restaurants, ownership.
 */
class FavoritesApiTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase;

    private Customer $rahul;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    private RestaurantLocation $brew;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->organization = $this->organization('Riverside');
        $this->burger = $this->hours($this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '00:00', '00:00']]);
        $this->brew = $this->location($this->organization, 'Brew & Bites', ['slug' => 'brew-bites']); // no hours → closed, still visible
        $this->rahul = $this->customer($this->market, ['name' => 'Rahul Sharma']);
        $this->actingAsPrincipal($this->rahul);
    }

    public function test_adding_by_slug_or_id_is_idempotent_and_the_list_carries_the_restaurant_as_customers_see_it(): void
    {
        $this->postJson('/api/v1/customer/favorites/burger-hub')->assertCreated()->assertJsonPath('added', true)->assertJsonPath('data.available', true)->assertJsonPath('data.restaurant.slug', 'burger-hub')->assertJsonPath('data.restaurant.availability.orderable', true);
        $this->postJson('/api/v1/customer/favorites/burger-hub')->assertOk()->assertJsonPath('added', false);
        $this->postJson('/api/v1/customer/favorites/'.$this->brew->public_id)->assertCreated()->assertJsonPath('data.restaurant.availability.orderable', false);
        $this->assertDatabaseCount('customer_favorite_locations', 2);

        $list = $this->getJson('/api/v1/customer/favorites')->assertOk()->json();
        $this->assertSame(['brew-bites', 'burger-hub'], array_column($list['data'], 'slug'), 'newest first');
        $this->assertSame([true, true], array_column($list['data'], 'available'));
        $this->assertSame('Brew & Bites', $list['data'][0]['restaurant']['name']);
        $this->assertArrayHasKey('hours', $list['data'][0]['restaurant']);
        $this->assertArrayNotHasKey('status_note', $list['data'][0]['restaurant']);
        $this->assertSame(2, $list['meta']['total']);
        $this->assertStringContainsString('page%5Bnumber%5D=1', $list['links']['first']);
    }

    public function test_two_simultaneous_adds_cannot_create_a_duplicate(): void
    {
        $service = app(CustomerFavoriteService::class);
        $this->assertTrue($service->add($this->rahul, $this->burger));
        $this->assertFalse($service->add($this->rahul, $this->burger));
        $this->assertSame(1, DB::table('customer_favorite_locations')->count());
        // the database refuses a second row even when the service is bypassed
        $this->expectException(QueryException::class);
        DB::table('customer_favorite_locations')->insert(['customer_id' => $this->rahul->getKey(), 'restaurant_location_id' => $this->burger->getKey(), 'created_at' => now()]);
    }

    public function test_removing_is_idempotent_and_works_for_a_restaurant_customers_can_no_longer_see(): void
    {
        $this->postJson('/api/v1/customer/favorites/burger-hub')->assertCreated();
        $this->deleteJson('/api/v1/customer/favorites/burger-hub')->assertNoContent();
        $this->deleteJson('/api/v1/customer/favorites/burger-hub')->assertNoContent();
        $this->deleteJson('/api/v1/customer/favorites/no-such-restaurant')->assertNotFound();
        $this->assertDatabaseCount('customer_favorite_locations', 0);

        $this->postJson('/api/v1/customer/favorites/brew-bites')->assertCreated();
        $this->brew->forceFill(['status' => 'SUSPENDED'])->save();
        $this->deleteJson('/api/v1/customer/favorites/brew-bites')->assertNoContent();
        $this->assertDatabaseCount('customer_favorite_locations', 0);
    }

    public function test_a_restaurant_customers_may_not_see_cannot_be_favorited_but_an_existing_favorite_of_one_is_kept_and_marked_unavailable(): void
    {
        $hidden = $this->location($this->organization, 'Hidden Kitchen', ['slug' => 'hidden-kitchen', 'status' => 'UNDER_REVIEW']);
        $this->postJson('/api/v1/customer/favorites/hidden-kitchen')->assertNotFound()->assertJsonPath('error.code', 'restaurant_not_found');
        $this->postJson('/api/v1/customer/favorites/'.$hidden->public_id)->assertNotFound();
        $this->postJson('/api/v1/customer/favorites/does-not-exist')->assertNotFound();
        $this->assertDatabaseCount('customer_favorite_locations', 0);

        // favorited while visible, then the restaurant is suspended
        $this->postJson('/api/v1/customer/favorites/burger-hub')->assertCreated();
        $this->burger->forceFill(['status' => 'SUSPENDED'])->save();
        $list = $this->getJson('/api/v1/customer/favorites')->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $this->assertSame(['burger-hub', 'Burger Hub', false, null], [$list['data'][0]['slug'], $list['data'][0]['name'], $list['data'][0]['available'], $list['data'][0]['restaurant']]);
        // reactivated: it is back in full
        $this->burger->forceFill(['status' => 'APPROVED'])->save();
        $this->assertTrue($this->getJson('/api/v1/customer/favorites')->json('data.0.available'));
    }

    public function test_favorites_are_private_to_their_owner_and_customer_id_in_a_body_is_meaningless(): void
    {
        $this->postJson('/api/v1/customer/favorites/burger-hub')->assertCreated();
        $asha = $this->customer($this->market, ['name' => 'Asha Verma']);
        $this->actingAsPrincipal($asha);
        $this->assertSame([], $this->getJson('/api/v1/customer/favorites')->assertOk()->json('data'));
        $this->postJson('/api/v1/customer/favorites/brew-bites', ['customer_id' => $this->rahul->getKey()])->assertCreated();
        $this->assertSame(1, DB::table('customer_favorite_locations')->where('customer_id', $asha->getKey())->count());
        $this->assertSame(1, DB::table('customer_favorite_locations')->where('customer_id', $this->rahul->getKey())->count());
        $this->deleteJson('/api/v1/customer/favorites/burger-hub')->assertNoContent();
        $this->assertSame(1, DB::table('customer_favorite_locations')->where('customer_id', $this->rahul->getKey())->count(), 'Asha cannot remove Rahul\'s favorite');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/favorites')->assertUnauthorized();
    }

    public function test_the_list_is_paginated_and_costs_a_fixed_number_of_queries(): void
    {
        $service = app(CustomerFavoriteService::class);
        for ($i = 1; $i <= 12; $i++) {
            $service->add($this->rahul, $this->hours($this->location($this->organization, "Place $i", ['slug' => "place-$i"]), [[null, '09:00', '22:00']]));
        }
        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->assertSame($count('/api/v1/customer/favorites?page[size]=2'), $count('/api/v1/customer/favorites?page[size]=12'), 'no query per favorite');
        $page = $this->getJson('/api/v1/customer/favorites?page[size]=5&page[number]=2')->assertOk()->json();
        $this->assertCount(5, $page['data']);
        $this->assertSame([12, 3, 2], [$page['meta']['total'], $page['meta']['last_page'], $page['meta']['current_page']]);
    }
}
