<?php

namespace Tests\Feature\Review;

use App\Models\ReviewModerationCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsReviews;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every review answer matches the schema documented for it, and every review route is documented (Module 34).
 */
class ReviewOpenApiContractTest extends TestCase
{
    use BuildsReviews, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.review_write' => 200]);
    }

    public function test_the_answers_of_every_side_match_their_schemas(): void
    {
        [, $headers, $order] = $this->completedOrder('+919811100101');
        $item = $order->items()->firstOrFail();
        $this->assertMatchesSchema('OrderReviewState', $this->reviewOfOrder($headers, $order->order_number)->assertOk()->json());
        $created = $this->submitReview($headers, $order->order_number, [
            'overall_rating' => 4, 'category_ratings' => ['food_quality' => 5], 'tags' => ['great_food'], 'item_feedback' => [['item_id' => $item->public_id, 'sentiment' => 'liked']], 'title' => 'Good stop', 'comment' => 'Fresh and quick.',
        ])->assertCreated();
        $this->assertMatchesSchema('CustomerReviewCreated', $created->json());
        $id = $created->json('review.id');
        $this->assertMatchesSchema('OrderReviewState', $this->reviewOfOrder($headers, $order->order_number)->assertOk()->json());
        $this->assertMatchesSchema('CustomerReviewPage', $this->getJson('/api/v1/customer/reviews', $this->using($headers))->assertOk()->json());
        $this->assertMatchesSchema('CustomerReviewEnvelope', $this->getJson('/api/v1/customer/reviews/'.$id, $this->using($headers))->assertOk()->json());
        $this->assertMatchesSchema('CustomerReviewEnvelope', $this->editReview($headers, $id, ['version' => 1, 'overall_rating' => 5])->assertOk()->json());
        $this->assertMatchesSchema('OrderEnvelope', $this->showOrder($headers, $order->order_number)->assertOk()->json());
        $this->assertMatchesSchema('OrderSummaryPage', $this->listOrders($headers)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->submitReview($headers, $order->order_number, ['overall_rating' => 1, 'comment' => 'Different.'])->assertStatus(409)->json());
        $this->assertMatchesSchema('Error', $this->submitReview($headers, $order->order_number, ['overall_rating' => 9])->assertStatus(422)->json());

        // The restaurant.
        $this->reviewStaff('MANAGER');
        $this->assertMatchesSchema('RestaurantReviewEnvelope', $this->staffSend('PUT', 'reviews/'.$id.'/response', ['response_text' => 'Thank you — see you on your next trip.'])->assertCreated()->json());
        $this->assertMatchesSchema('RestaurantReviewPage', $this->staffGet('reviews?location='.$this->burgerHub->public_id)->assertOk()->json());
        $this->assertMatchesSchema('RestaurantReviewSummaryEnvelope', $this->staffGet('reviews/summary?location='.$this->burgerHub->public_id)->assertOk()->json());
        $this->assertMatchesSchema('RestaurantReviewEnvelope', $this->staffGet('reviews/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('ReviewReportReceipt', $this->staffSend('POST', 'reviews/'.$id.'/report', ['reason' => 'OTHER', 'explanation' => 'Please check.'])->assertCreated()->json());

        // The public.
        $this->assertMatchesSchema('PublicReviewPage', $this->publicReviews()->assertOk()->json());
        $this->assertMatchesSchema('PublicReviewSummary', $this->publicSummary()->assertOk()->json());
        $this->assertMatchesSchema('PublicRestaurant', $this->getJson('/api/v1/restaurants/'.$this->burgerHub->slug)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->publicReviews('', 'no-such-restaurant')->assertNotFound()->json());
        [, $reporter] = $this->otherCustomer();
        $this->assertMatchesSchema('ReviewReportReceipt', $this->postJson('/api/v1/reviews/'.$id.'/report', ['reason' => 'SPAM'], $this->using($reporter))->assertCreated()->json());

        // The administrator.
        $this->moderator();
        $this->assertMatchesSchema('AdminReviewPage', $this->modGet('reviews')->assertOk()->json());
        $this->assertMatchesSchema('AdminReviewEnvelope', $this->modGet('reviews/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('ReviewCasePage', $this->modGet('review-cases')->assertOk()->json());
        $case = ReviewModerationCase::query()->sole();
        $this->assertMatchesSchema('ReviewCaseEnvelope', $this->modPost('review-cases/'.$case->public_id.'/assign')->assertOk()->json());
        $this->assertMatchesSchema('ReviewCaseEnvelope', $this->modPost('review-cases/'.$case->public_id.'/escalate', ['reason' => 'Second opinion.'])->assertOk()->json());
        $this->assertMatchesSchema('AdminReviewEnvelope', $this->modPost('reviews/'.$id.'/hide', ['reason' => 'Under investigation after three reports.'])->assertOk()->json());
        $this->assertMatchesSchema('AdminReviewEnvelope', $this->modPost('reviews/'.$id.'/restore', ['reason' => 'Nothing found.'])->assertOk()->json());
        $this->assertMatchesSchema('AdminReviewEnvelope', $this->modPost('reviews/'.$id.'/response/hide', ['reason' => 'Contains a phone number.'])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->modPost('reviews/'.$id.'/publish', ['reason' => 'Already public.'])->assertStatus(409)->json());

        // Module 33's inbox with the review notices (the new target and subject are documented).
        $this->assertMatchesSchema('CustomerNotificationPage', $this->inbox($headers)->assertOk()->json());
        $this->assertMatchesSchema('CustomerReviewEnvelope', $this->deleteJson('/api/v1/customer/reviews/'.$id, [], $this->using($headers))->assertOk()->json());
    }

    public function test_every_review_route_is_documented_with_the_right_security(): void
    {
        $document = $this->openApi();
        $mine = fn (string $uri): bool => (bool) preg_match('#(^|/)(reviews?|review-cases)(/|$)#', $uri); // not "pickup-preview"
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => [preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])])->filter(fn (array $r): bool => $mine($r[0]))
            ->flatMap(fn (array $r): array => array_map(fn (string $m): string => strtolower($m).' /'.$r[0], $r[1]))->sort()->values()->all();
        $documented = collect($document['paths'])->filter(fn ($ops, string $path): bool => $mine(ltrim($path, '/')))
            ->flatMap(fn (array $ops, string $path): array => array_map(fn (string $m): string => $m.' '.$path, array_keys($ops)))->sort()->values()->all();
        $this->assertSame($routes, $documented);
        $this->assertCount(26, $routes);

        foreach ($document['paths'] as $path => $operations) {
            if (! $mine($path)) {
                continue;
            }
            foreach ($operations as $operation) {
                $expected = match (true) {
                    str_starts_with($path, '/admin/') => [['admin' => []]],
                    str_starts_with($path, '/restaurant/') => [['restaurant' => []]],
                    str_starts_with($path, '/restaurants/') => [],
                    default => [['customer' => []]],
                };
                $this->assertSame($expected, $operation['security'], $path);
            }
        }

        // No request has room for an owner, a restaurant, a status or a "verified" flag.
        foreach (['SubmitReviewRequest', 'UpdateReviewRequest', 'ReviewResponseRequest', 'ReportReviewRequest', 'ModerateReviewRequest'] as $name) {
            $schema = $document['components']['schemas'][$name];
            $this->assertFalse($schema['additionalProperties'], $name);
            $this->assertSame([], array_intersect(array_keys($schema['properties']), ['customer_id', 'customer', 'order_id', 'restaurant_id', 'location_id', 'restaurant_location_id', 'restaurant_organization_id', 'organization_id', 'market_id', 'status', 'verified', 'verified_order', 'published_at', 'author_display_name']), $name);
        }
        $public = array_keys($document['components']['schemas']['PublicReview']['properties']);
        $this->assertSame([], array_intersect($public, ['customer', 'customer_id', 'order', 'order_number', 'phone', 'email', 'item_feedback', 'flags', 'version']));
    }
}
