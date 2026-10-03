<?php

namespace App\Services\Customer;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\CustomerFavoriteLocation;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantAvailabilityService;
use App\Services\Restaurant\RestaurantVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Favorite restaurant locations of a customer (Module 25). The target is the physical location — menus,
 * hours and orders are per location. A customer may favorite any restaurant customers can currently see
 * (open or closed); a favorite of a restaurant that was hidden later stays and is returned as unavailable.
 * Adding is idempotent and race-safe (database unique constraint); removing is idempotent.
 */
final class CustomerFavoriteService
{
    public function __construct(private readonly RestaurantVisibility $visibility, private readonly RestaurantAvailabilityService $availability) {}

    /**
     * The restaurant a customer may favorite: by public id or by slug, visible to customers — anything else
     * is a 404 (no hint that it exists).
     */
    public function visibleRestaurant(string $idOrSlug): RestaurantLocation
    {
        $query = RestaurantLocation::query()->with(['organization', 'market']);
        if (preg_match('/^[0-9a-f-]{36}$/i', $idOrSlug) === 1) {
            $query->where('restaurant_locations.public_id', strtolower($idOrSlug));
        } else {
            $query->where('restaurant_locations.slug', $idOrSlug);
        }
        $this->visibility->scopeVisible($query);

        return $query->first() ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');
    }

    /** @return bool true when the favorite was created, false when it already existed */
    public function add(Customer $customer, RestaurantLocation $location): bool
    {
        return DB::table('customer_favorite_locations')->insertOrIgnore([
            'customer_id' => $customer->getKey(), 'restaurant_location_id' => $location->getKey(), 'created_at' => now(),
        ]) === 1;
    }

    /** @return bool true when a favorite was removed */
    public function remove(Customer $customer, RestaurantLocation $location): bool
    {
        return CustomerFavoriteLocation::query()->where('customer_id', $customer->getKey())->where('restaurant_location_id', $location->getKey())->delete() === 1;
    }

    public function isFavorite(Customer $customer, RestaurantLocation $location): bool
    {
        return CustomerFavoriteLocation::query()->where('customer_id', $customer->getKey())->where('restaurant_location_id', $location->getKey())->exists();
    }

    /**
     * @return Builder<CustomerFavoriteLocation>
     */
    public function query(Customer $customer): Builder
    {
        return CustomerFavoriteLocation::query()->where('customer_id', $customer->getKey())->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Attaches to each favorite of the page the restaurant as customers see it — or marks it unavailable when
     * customers may not see it right now (suspended, unapproved, out of coverage). Everything the resource and the
     * availability answer read is loaded once per page.
     *
     * @param  Collection<int, CustomerFavoriteLocation>  $favorites
     */
    public function attachRestaurants(Collection $favorites): void
    {
        $ids = $favorites->pluck('restaurant_location_id')->unique()->values()->all();
        if ($ids === []) {
            return;
        }
        $names = RestaurantLocation::query()->withoutGlobalScope('coordinates')->whereIn('restaurant_locations.id', $ids)->get(['restaurant_locations.id', 'restaurant_locations.public_id', 'restaurant_locations.slug', 'restaurant_locations.name'])->keyBy('id');

        $visible = RestaurantLocation::query()->whereIn('restaurant_locations.id', $ids)->with([
            'organization', 'market', 'city', 'region', 'cuisines', 'features', 'images', 'hours', 'pickupSettings', 'pickupMethods',
            'specialHours' => fn ($query) => $query->where('date', '>=', now()->subDays(2)->toDateString()),
            'specialHours.periods',
        ]);
        $this->visibility->scopeVisible($visible);
        $this->visibility->selectServiceable($visible, now());
        $visibleById = $visible->get()->keyBy('id');
        $this->availability->preloadCoverage($visibleById->values());

        foreach ($favorites as $favorite) {
            /** @var CustomerFavoriteLocation $favorite */
            $favorite->setRelation('location', $visibleById->get($favorite->restaurant_location_id));
            $favorite->setAttribute('summary', $names->get($favorite->restaurant_location_id));
        }
    }
}
