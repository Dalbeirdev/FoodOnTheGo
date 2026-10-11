<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Review\ReviewPresenter;
use App\Models\RestaurantLocation;
use App\Models\RestaurantReview;
use App\Services\Market\MarketContext;
use App\Services\Restaurant\RestaurantVisibility;
use App\Services\Review\RestaurantRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * The reviews of a restaurant as anybody may read them: PUBLISHED reviews of a visible location only, paged, with the
 * rating summary. Waiting, hidden, rejected and removed reviews do not exist here, and a restaurant that customers
 * cannot see answers 404 like one that never existed.
 */
class RestaurantReviewController extends Controller
{
    public function __construct(
        private readonly RestaurantVisibility $visibility,
        private readonly MarketContext $markets,
        private readonly RestaurantRatingService $ratings,
        private readonly ReviewPresenter $presenter,
    ) {}

    public function index(Request $request, string $restaurantSlug): JsonResponse
    {
        $input = $request->validate([
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest', 'highest', 'lowest'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('reviews.lists.max_page_size')],
        ]);
        $location = $this->location($restaurantSlug, $input['country'] ?? null);
        $query = RestaurantReview::query()->published()->where('location_id', $location->getKey())->with(['response', 'location']);
        if (isset($input['rating'])) {
            $query->where('overall_rating', (int) $input['rating']);
        }
        match ($input['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'highest' => $query->orderByDesc('overall_rating')->orderByDesc('published_at')->orderByDesc('id'),
            'lowest' => $query->orderBy('overall_rating')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
        $page = $query->paginate((int) ($input['per_page'] ?? config('reviews.lists.page_size')))->through(fn (RestaurantReview $r): array => $this->presenter->forPublic($r));
        $response = JsonResource::collection($page)->response()->getData(true);
        $response['summary'] = $this->ratings->present($location->ratingSummary);

        return response()->json($response);
    }

    public function summary(Request $request, string $restaurantSlug): JsonResponse
    {
        $input = $request->validate(['country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/']]);
        $location = $this->location($restaurantSlug, $input['country'] ?? null);

        return response()->json([
            'restaurant' => ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name],
            'rating' => $this->ratings->present($location->ratingSummary),
        ]);
    }

    private function location(string $slug, ?string $country): RestaurantLocation
    {
        $market = $this->markets->current($country);
        $query = RestaurantLocation::query()->where('restaurant_locations.market_id', $market->getKey())->where('restaurant_locations.slug', $slug)->with('ratingSummary');
        $this->visibility->scopeVisible($query);

        return $query->first() ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');
    }
}
