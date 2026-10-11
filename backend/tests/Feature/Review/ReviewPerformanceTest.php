<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\BuildsReviews;
use Tests\TestCase;

/**
 * Query counts of the review reads (Module 34): a page of reviews costs the same number of queries whatever its
 * size, and a restaurant list does not pay per row for its ratings. The numbers measured are written to
 * storage/logs/m34-performance.json.
 */
class ReviewPerformanceTest extends TestCase
{
    use BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.review_write' => 200]);
    }

    public function test_review_reads_use_a_bounded_number_of_queries_and_the_indexes_exist(): void
    {
        $this->otherRestaurant();
        $reviews = [];
        foreach ([5, 4, 3, 5] as $i => $stars) {
            [, $headers, , $review] = $this->reviewed($stars, '+91981110011'.$i, ['comment' => 'Review number '.$i.' with enough words to be a sentence.']);
            $reviews[] = [$headers, $review];
        }
        $this->reviewStaff('MANAGER');
        $this->staffSend('PUT', 'reviews/'.$reviews[0][1]->public_id.'/response', ['response_text' => 'Thanks for stopping by.'])->assertCreated();

        $measure = function (callable $call): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $call();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $publicOne = $measure(fn () => $this->publicReviews('per_page=1')->assertOk());
        $publicFour = $measure(fn () => $this->publicReviews('per_page=4')->assertOk()->assertJsonCount(4, 'data'));
        $summary = $measure(fn () => $this->publicSummary()->assertOk());
        $mine = $measure(fn () => $this->getJson('/api/v1/customer/reviews', $this->using($reviews[0][0]))->assertOk());
        $dashboard = $measure(fn () => $this->staffGet('reviews?location='.$this->burgerHub->public_id)->assertOk()->assertJsonCount(4, 'data'));
        $this->moderator();
        $admin = $measure(fn () => $this->modGet('reviews')->assertOk()->assertJsonCount(4, 'data'));
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $listRated = $measure(fn () => $this->getJson('/api/v1/restaurants?sort=-rating')->assertOk()->assertJsonCount(2, 'data'));

        $this->assertSame($publicOne, $publicFour, 'the public page costs the same for one review as for four (no query per row)');
        $this->assertLessThanOrEqual(8, $publicFour);
        $this->assertLessThanOrEqual(5, $summary);
        $this->assertLessThanOrEqual(8, $mine);
        $this->assertLessThanOrEqual(16, $dashboard);
        $this->assertLessThanOrEqual(16, $admin);

        $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'restaurant_reviews'"))->pluck('indexdef', 'indexname');
        $this->assertTrue($indexes->contains(fn (string $def): bool => str_contains($def, 'UNIQUE') && str_contains($def, '(order_id)')), 'one review per order is a unique index');
        foreach (['(customer_id, submitted_at)', '(location_id, status, published_at)', '(location_id, status, overall_rating)', '(organization_id, status)', '(market_id, status, submitted_at)', '(status, submitted_at)'] as $columns) {
            $this->assertTrue($indexes->contains(fn (string $def): bool => str_contains($def, $columns)), 'index on '.$columns);
        }
        $plan = collect(DB::select("explain select * from restaurant_reviews where location_id = ? and status = 'PUBLISHED' order by published_at desc limit 10", [$this->burgerHub->getKey()]))->pluck('QUERY PLAN')->implode(' ');

        File::put(storage_path('logs/m34-performance.json'), json_encode([
            'public_reviews_page_queries' => $publicFour, 'public_summary_queries' => $summary, 'my_reviews_queries' => $mine,
            'dashboard_reviews_queries' => $dashboard, 'admin_reviews_queries' => $admin, 'restaurant_list_sorted_by_rating_queries_two_rows' => $listRated,
            'public_reviews_plan' => mb_substr($plan, 0, 300),
        ], JSON_PRETTY_PRINT));
    }
}
