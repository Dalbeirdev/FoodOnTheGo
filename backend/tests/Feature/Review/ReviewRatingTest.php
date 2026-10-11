<?php

namespace Tests\Feature\Review;

use App\Models\RestaurantReview;
use App\Services\Review\RestaurantRatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsReviews;
use Tests\TestCase;

/**
 * Ratings (Module 34): whole stars from 1 to 5, the average of the PUBLISHED reviews of one location, the star
 * distribution — and "no reviews yet" as an explicit state, never an invented number.
 */
class ReviewRatingTest extends TestCase
{
    use BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.review_write' => 200]);
    }

    public function test_the_rating_scale_is_whole_stars_from_one_to_five_and_the_overall_rating_is_required(): void
    {
        [, $headers, $order] = $this->completedOrder();

        foreach ([['overall_rating' => 0], ['overall_rating' => 6], ['overall_rating' => 4.5], ['overall_rating' => '4.5'], ['overall_rating' => -1], ['overall_rating' => 'five'], ['overall_rating' => null], []] as $body) {
            $this->submitReview($headers, $order->order_number, $body)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => ['overall_rating']]]]);
        }
        foreach ([['category_ratings' => ['food_quality' => 6]], ['category_ratings' => ['food_quality' => 0]], ['category_ratings' => ['food_quality' => 3.5]], ['category_ratings' => ['ambience' => 4]], ['tags' => ['not_a_tag']], ['item_feedback' => [['item_id' => '00000000-0000-4000-8000-000000000042', 'sentiment' => 'liked']]]] as $extra) {
            $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, ...$extra])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->assertSame(0, RestaurantReview::query()->count());

        // One star is as valid as five; the category ratings are optional.
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 1])->assertCreated()->assertJsonPath('review.overall_rating', 1)->assertJsonPath('review.status', 'PUBLISHED');
        $this->assertSame('{}', DB::table('restaurant_reviews')->value(DB::raw('category_ratings::text')));
        [, $other, $second] = $this->completedOrder('+919811100011');
        $this->submitReview($other, $second->order_number, ['overall_rating' => 5, 'category_ratings' => ['food_quality' => 5, 'order_accuracy' => null]])->assertCreated()
            ->assertJsonPath('review.overall_rating', 5)->assertJsonPath('review.category_ratings', ['food_quality' => 5]);
    }

    public function test_no_reviews_is_an_explicit_state_never_a_number(): void
    {
        $summary = $this->publicSummary()->assertOk();
        $summary->assertJsonPath('rating.average', null)->assertJsonPath('rating.count', 0)->assertJsonPath('rating.distribution', ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0]);
        $this->publicReviews()->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.count', 0)->assertJsonPath('summary.average', null);
        $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk()->assertJsonPath('rating', ['average' => null, 'count' => 0]);
        $this->assertNull(RestaurantRatingService::average(0, 0), 'no division by zero: no reviews has no average');
        $this->assertSame([0, 0, null], $this->storedRating());
    }

    public function test_the_average_and_the_distribution_count_published_reviews_of_this_location_only(): void
    {
        [$pizzaPoint] = $this->otherRestaurant();
        foreach ([[5, '+919811100021'], [4, '+919811100022'], [4, '+919811100023'], [2, '+919811100024']] as [$stars, $phone]) {
            $this->reviewed($stars, $phone, ['category_ratings' => ['food_quality' => $stars, 'pickup_experience' => 5]]);
        }

        $this->assertSame([4, 15, '3.75'], $this->storedRating());
        $summary = $this->publicSummary()->assertOk();
        $summary->assertJsonPath('rating.count', 4)->assertJsonPath('rating.average', 3.8)->assertJsonPath('rating.distribution', ['5' => 1, '4' => 2, '3' => 0, '2' => 1, '1' => 0])
            ->assertJsonPath('rating.categories.food_quality', ['average' => 3.8, 'count' => 4])->assertJsonPath('rating.categories.pickup_experience', ['average' => 5, 'count' => 4])
            ->assertJsonPath('rating.categories.order_accuracy', ['average' => null, 'count' => 0]);
        $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk()->assertJsonPath('rating', ['average' => 3.8, 'count' => 4]);

        // Another branch of the SAME organization shares nothing: a rating belongs to a location.
        $this->getJson('/api/v1/restaurants/'.$pizzaPoint->slug)->assertOk()->assertJsonPath('rating', ['average' => null, 'count' => 0]);
        $this->publicSummary($pizzaPoint->slug)->assertOk()->assertJsonPath('rating.count', 0);
        $this->publicReviews('', $pizzaPoint->slug)->assertOk()->assertJsonCount(0, 'data');

        // The list: every row carries its rating; "highest rated" puts the unrated branch last; the filter uses the real average.
        $list = $this->getJson('/api/v1/restaurants?sort=-rating')->assertOk();
        $this->assertSame([$this->burgerHub->slug, $pizzaPoint->slug], array_column($list->json('data'), 'slug'));
        $this->assertSame([['average' => 3.8, 'count' => 4], ['average' => null, 'count' => 0]], array_column($list->json('data'), 'rating'));
        $this->assertSame([$this->burgerHub->slug], array_column($this->getJson('/api/v1/restaurants?filter[min_rating]=3.5')->assertOk()->json('data'), 'slug'));
        $this->assertSame([], array_column($this->getJson('/api/v1/restaurants?filter[min_rating]=4')->assertOk()->json('data'), 'slug'));
        $this->getJson('/api/v1/restaurants?filter[min_rating]=9')->assertStatus(422);

        // The public list: newest first by default, sortable and filterable by stars, paged.
        $newest = $this->publicReviews()->assertOk();
        $this->assertSame([2, 4, 4, 5], array_column($newest->json('data'), 'overall_rating'));
        $this->assertSame([5, 4, 4, 2], array_column($this->publicReviews('sort=highest')->json('data'), 'overall_rating'));
        $this->assertSame([2, 4, 4, 5], array_column($this->publicReviews('sort=lowest')->json('data'), 'overall_rating'));
        $this->assertSame([4, 4], array_column($this->publicReviews('rating=4')->json('data'), 'overall_rating'));
        $paged = $this->publicReviews('per_page=3&page=2')->assertOk();
        $this->assertSame([1, 4, 3], [count($paged->json('data')), $paged->json('meta.total'), $paged->json('meta.per_page')]);
        $this->publicReviews('per_page=500')->assertStatus(422);
        $this->publicReviews('sort=helpful')->assertStatus(422);
    }

    public function test_an_edited_rating_a_hidden_a_restored_and_a_removed_review_move_the_aggregate(): void
    {
        [, $headersA, , $a] = $this->reviewed(5, '+919811100031');
        [, , , $b] = $this->reviewed(2, '+919811100032');
        [, $headersC, , $c] = $this->reviewed(3, '+919811100033');
        $this->assertSame([3, 10, '3.33'], $this->storedRating());
        $this->publicSummary()->assertJsonPath('rating.average', 3.3);

        // Edit: 5 → 1 recalculates.
        $this->editReview($headersA, $a->public_id, ['version' => 1, 'overall_rating' => 1])->assertOk()->assertJsonPath('review.overall_rating', 1);
        $this->assertSame([3, 6, '2.00'], $this->storedRating());

        // Hidden: excluded. Restored: included again.
        $this->moderator();
        $this->modPost('reviews/'.$b->public_id.'/hide', ['reason' => 'Contains a personal accusation against a named member of staff.'])->assertOk()->assertJsonPath('review.status', 'HIDDEN');
        $this->assertSame([2, 4, '2.00'], $this->storedRating());
        $this->assertSame([3, 1], array_column($this->publicReviews()->json('data'), 'overall_rating'));
        $this->modPost('reviews/'.$b->public_id.'/restore', ['reason' => 'Reviewed again: the text describes the service, not a person.'])->assertOk()->assertJsonPath('review.status', 'PUBLISHED');
        $this->assertSame([3, 6, '2.00'], $this->storedRating());
        $this->publicSummary()->assertJsonPath('rating.count', 3)->assertJsonPath('rating.distribution.2', 1);

        // Removed by its author: excluded, and gone from the public list.
        $this->deleteJson('/api/v1/customer/reviews/'.$c->public_id, [], $this->using($headersC))->assertOk()->assertJsonPath('review.status', 'REMOVED');
        $this->assertSame([2, 3, '1.50'], $this->storedRating());
        $this->publicSummary()->assertJsonPath('rating.average', 1.5)->assertJsonPath('rating.count', 2);
        $this->assertSame(2, count($this->publicReviews()->json('data')));
    }

    public function test_the_summary_can_always_be_rebuilt_from_the_reviews(): void
    {
        $this->reviewed(5, '+919811100041');
        $this->reviewed(4, '+919811100042');
        DB::table('restaurant_location_rating_summaries')->update(['published_count' => 99, 'rating_sum' => 495, 'average_rating' => 5, 'star_5' => 99]);
        $this->publicSummary()->assertJsonPath('rating.count', 99);

        $this->artisan('reviews:recalculate')->assertSuccessful();

        $this->assertSame([2, 9, '4.50'], $this->storedRating());
        $this->publicSummary()->assertJsonPath('rating.count', 2)->assertJsonPath('rating.average', 4.5)->assertJsonPath('rating.distribution.5', 1);
    }

    public function test_route_discovery_sorts_and_filters_by_the_real_rating_read_at_request_time(): void
    {
        [$pizzaPoint] = $this->otherRestaurant();
        $journey = $this->planGuest();

        // Before any review: both are unrated; "highest rated" is still a valid, deterministic order and the filter finds nothing.
        $first = $this->restaurantsOf($journey['id'], $journey['headers'], ['sort' => 'highest_rated'])->assertOk();
        $this->assertEqualsCanonicalizing(['Burger Hub', 'Pizza Point'], $this->namesOf($first));
        $this->assertSame([['average' => null, 'count' => 0], ['average' => null, 'count' => 0]], $first->json('data.*.restaurant.rating'));
        $this->assertSame([], $this->namesOf($this->restaurantsOf($journey['id'], $journey['headers'], ['min_rating' => 3])->assertOk()));

        // A review is published AFTER the candidate set was calculated and cached: it counts on the next request.
        $this->reviewed(4, '+919811100045');
        $rated = $this->restaurantsOf($journey['id'], $journey['headers'], ['sort' => 'highest_rated'])->assertOk();
        $this->assertSame(['Burger Hub', 'Pizza Point'], $this->namesOf($rated), 'the rated restaurant first, the unrated one last');
        $rated->assertJsonPath('data.0.restaurant.rating', ['average' => 4, 'count' => 1])->assertJsonPath('meta.search.sort', 'highest_rated')->assertJsonPath('meta.search.min_rating', null);
        $filtered = $this->restaurantsOf($journey['id'], $journey['headers'], ['min_rating' => 3.5])->assertOk();
        $this->assertSame(['Burger Hub'], $this->namesOf($filtered));
        $filtered->assertJsonPath('meta.search.min_rating', 3.5);
        $this->assertSame([], $this->namesOf($this->restaurantsOf($journey['id'], $journey['headers'], ['min_rating' => 4.5])->assertOk()));
        $this->restaurantsOf($journey['id'], $journey['headers'], ['min_rating' => 9])->assertStatus(422);
        $this->restaurantsOf($journey['id'], $journey['headers'], ['sort' => 'best'])->assertStatus(422);
        $this->assertNotNull($pizzaPoint->getKey());
    }

    public function test_averages_are_rounded_half_up_to_one_decimal_without_floating_point_surprises(): void
    {
        $this->assertSame(4.5, RestaurantRatingService::average(9, 2));
        $this->assertSame(3.8, RestaurantRatingService::average(15, 4));     // 3.75 → 3.8
        $this->assertSame(4.3, RestaurantRatingService::average(13, 3));     // 4.333…
        $this->assertSame(4.7, RestaurantRatingService::average(14, 3));     // 4.666…
        $this->assertSame(1.0, RestaurantRatingService::average(1, 1));
        $this->assertSame(5.0, RestaurantRatingService::average(5000, 1000));
        $this->assertSame(4.4, RestaurantRatingService::average(4350, 1000)); // 4.35 → 4.4 (binary floats would say 4.3)
    }
}
