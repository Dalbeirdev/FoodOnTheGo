<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\FavoriteRestaurantResource;
use App\Http\Support\ListQuery;
use App\Models\Customer;
use App\Models\CustomerFavoriteLocation;
use App\Models\RestaurantLocation;
use App\Services\Customer\CustomerFavoriteService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Favorite restaurants of the authenticated customer (Module 25). `{restaurant}` is the restaurant's public id or
 * its slug. Adding needs a restaurant customers may currently see (404 otherwise); removing works for any
 * restaurant, visible or not, so a favorite of a suspended restaurant can still be dropped.
 */
class FavoriteController extends Controller
{
    public function __construct(private readonly CustomerFavoriteService $favorites) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $list = new ListQuery($request, sortable: ['created_at'], defaultSort: '-created_at');
        $page = $list->paginate($this->favorites->query($customer));
        $this->favorites->attachRestaurants($page->getCollection());

        return FavoriteRestaurantResource::collection($page);
    }

    public function store(Request $request, string $restaurant): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $location = $this->favorites->visibleRestaurant($restaurant);
        $created = $this->favorites->add($customer, $location);
        $favorite = CustomerFavoriteLocation::query()->where('customer_id', $customer->getKey())->where('restaurant_location_id', $location->getKey())->firstOrFail();
        $this->favorites->attachRestaurants(new Collection([$favorite]));

        return (new FavoriteRestaurantResource($favorite))->additional(['added' => $created])->response()->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(Request $request, string $restaurant): Response
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $query = RestaurantLocation::query()->withoutGlobalScope('coordinates');
        if (preg_match('/^[0-9a-f-]{36}$/i', $restaurant) === 1) {
            $query->where('public_id', strtolower($restaurant));
        } else {
            $query->where('slug', $restaurant);
        }
        $location = $query->first(['id', 'public_id', 'slug']) ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');
        $this->favorites->remove($customer, $location);

        return response()->noContent();
    }
}
