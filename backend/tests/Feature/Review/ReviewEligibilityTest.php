<?php

namespace Tests\Feature\Review;

use App\Models\AuditEvent;
use App\Models\RestaurantReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsReviews;
use Tests\TestCase;

/**
 * Who may review what (Module 34): only the customer of a COMPLETED order, once, inside the review window — and the
 * order, never the request, decides the restaurant.
 */
class ReviewEligibilityTest extends TestCase
{
    use BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_completed_order_can_be_reviewed_once_and_the_review_takes_everything_from_the_order(): void
    {
        [$customer, $headers, $order] = $this->completedOrder();

        $before = $this->reviewOfOrder($headers, $order->order_number)->assertOk();
        $before->assertJsonPath('eligibility.eligible', true)->assertJsonPath('eligibility.reason', 'ELIGIBLE')->assertJsonPath('review', null)
            ->assertJsonPath('order.order_number', $order->order_number)->assertJsonPath('config.scale.max', 5)->assertJsonPath('config.submission_window_days', 30)
            ->assertJsonPath('config.policy_status', 'DEVELOPMENT_DEFAULT_PENDING_BUSINESS_APPROVAL');
        $this->assertNotNull($before->json('eligibility.window_ends_at'));
        $this->assertCount($order->items()->count(), $before->json('order.items'));
        $this->showOrder($headers, $order->order_number)->assertOk()->assertJsonPath('order.review.state', 'ELIGIBLE')->assertJsonPath('order.review.review', null);

        $item = $order->items()->firstOrFail();
        $created = $this->submitReview($headers, $order->order_number, [
            'overall_rating' => 4, 'category_ratings' => ['food_quality' => 5, 'pickup_experience' => 3], 'tags' => ['great_food', 'fast_pickup'],
            'item_feedback' => [['item_id' => $item->public_id, 'sentiment' => 'liked']], 'comment' => "  Hot and fresh.\r\nThe counter was quick.  ",
        ])->assertCreated();
        $created->assertJsonPath('created', true)->assertJsonPath('review.status', 'PUBLISHED')->assertJsonPath('review.verified_order', true)
            ->assertJsonPath('review.overall_rating', 4)->assertJsonPath('review.category_ratings.food_quality', 5)->assertJsonPath('review.tags', ['fast_pickup', 'great_food'])
            ->assertJsonPath('review.comment', "Hot and fresh.\nThe counter was quick.")->assertJsonPath('review.item_feedback.0.name', $item->name)
            ->assertJsonPath('review.order.order_number', $order->order_number)->assertJsonPath('review.restaurant.slug', $this->burgerHub->slug)
            ->assertJsonPath('review.version', 1)->assertJsonPath('review.can_edit', true)->assertJsonPath('review.edits_left', 3);

        $review = RestaurantReview::query()->sole();
        $this->assertSame(
            [$order->getKey(), $customer->getKey(), $order->organization_id, $order->location_id, $order->market_id],
            [$review->order_id, $review->customer_id, $review->organization_id, $review->location_id, $review->market_id],
            'customer, organization, location and market are copied from the order',
        );
        $this->assertNotNull($review->published_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'review.created')->where('target_public_id', $review->public_id)->count());

        $after = $this->reviewOfOrder($headers, $order->order_number)->assertOk();
        $after->assertJsonPath('eligibility.eligible', false)->assertJsonPath('eligibility.reason', 'REVIEW_ALREADY_EXISTS')->assertJsonPath('review.id', $review->public_id);
        $this->showOrder($headers, $order->order_number)->assertOk()->assertJsonPath('order.review.state', 'REVIEWED')->assertJsonPath('order.review.review.overall_rating', 4);
        $this->listOrders($headers)->assertOk()->assertJsonPath('data.0.review.state', 'REVIEWED');
    }

    public function test_orders_that_are_not_completed_cannot_be_reviewed(): void
    {
        // Paid, waiting for the restaurant.
        [, $headers, , , $paid] = $this->paidOrder('+919811100001', '13:00');
        $this->reviewOfOrder($headers, $paid['order_number'])->assertOk()->assertJsonPath('eligibility.eligible', false)->assertJsonPath('eligibility.reason', 'ORDER_NOT_COMPLETED');
        $this->submitReview($headers, $paid['order_number'])->assertStatus(409)->assertJsonPath('error.code', 'order_not_completed');

        // Preparing, then ready.
        $this->orderStaff();
        $this->step($paid['order_number'], 'accept')->assertOk();
        $this->step($paid['order_number'], 'start-preparing')->assertOk();
        $this->submitReview($headers, $paid['order_number'])->assertStatus(409)->assertJsonPath('error.code', 'order_not_completed');
        $this->step($paid['order_number'], 'ready')->assertOk();
        $this->submitReview($headers, $paid['order_number'])->assertStatus(409)->assertJsonPath('error.code', 'order_not_completed');
        $this->showOrder($headers, $paid['order_number'])->assertOk()->assertJsonPath('order.review.state', 'NOT_ELIGIBLE')->assertJsonPath('order.review.reason', 'ORDER_NOT_COMPLETED');

        // Rejected by the restaurant.
        [, $rejectedHeaders, , , $rejected] = $this->paidOrder('+919811100002', '13:15');
        $this->orderStaff();
        $this->step($rejected['order_number'], 'reject', ['reason' => 'ITEM_UNAVAILABLE'])->assertOk();
        $this->submitReview($rejectedHeaders, $rejected['order_number'])->assertStatus(409)->assertJsonPath('error.code', 'order_not_completed');

        $this->assertSame(0, RestaurantReview::query()->count());
    }

    public function test_picked_up_but_not_completed_is_not_enough(): void
    {
        [, $headers, $order] = $this->completedOrder();
        // The one state between pickup and completion, set on the row for this check only: completion is automatic in
        // the product, so no API call can hold an order in PICKED_UP.
        DB::table('orders')->where('id', $order->getKey())->update(['status' => 'PICKED_UP', 'completed_at' => null]);

        $this->reviewOfOrder($headers, $order->order_number)->assertOk()->assertJsonPath('eligibility.reason', 'ORDER_NOT_COMPLETED');
        $this->submitReview($headers, $order->order_number)->assertStatus(409)->assertJsonPath('error.code', 'order_not_completed');
        $this->assertSame(0, RestaurantReview::query()->count());
    }

    public function test_another_customer_cannot_review_or_even_see_the_order_and_a_missing_order_is_not_found(): void
    {
        [, , $order] = $this->completedOrder();
        [, $other] = $this->otherCustomer();

        $this->reviewOfOrder($other, $order->order_number)->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
        $this->submitReview($other, $order->order_number)->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
        $this->submitReview($other, 'FOTG-20261007-ZZZZZZ')->assertNotFound();
        $this->submitReview($other, '00000000-0000-4000-8000-000000000001')->assertNotFound();
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/customer/orders/'.$order->order_number.'/review', ['overall_rating' => 5])->assertUnauthorized();
        $this->assertSame(0, RestaurantReview::query()->count());
    }

    public function test_a_second_review_of_the_same_order_is_refused_and_a_retry_of_the_same_one_creates_nothing(): void
    {
        [, $headers, $order] = $this->completedOrder();
        $body = ['overall_rating' => 5, 'comment' => 'Great burgers.', 'client_submission_id' => 'sub_0123456789abcdef'];
        $first = $this->submitReview($headers, $order->order_number, $body)->assertCreated()->json('review.id');

        // The same submission again (a network retry): the same review, nothing new.
        $this->submitReview($headers, $order->order_number, $body)->assertOk()->assertJsonPath('created', false)->assertJsonPath('review.id', $first);
        // The same content without the token is the same submission too.
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => 'Great burgers.'])->assertOk()->assertJsonPath('review.id', $first);
        // A different review of the same order is a conflict.
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 1, 'comment' => 'Changed my mind.'])->assertStatus(409)
            ->assertJsonPath('error.code', 'review_already_exists')->assertJsonPath('error.details.review_id', $first);

        $this->assertSame(1, RestaurantReview::query()->count());
        $this->assertSame(5, (int) RestaurantReview::query()->sole()->overall_rating);
    }

    public function test_the_review_window_closes_and_is_configuration(): void
    {
        [$customer, , $order] = $this->completedOrder();
        $this->travel(31)->days();
        $headers = $this->sessionOf($customer); // the session of a month ago has expired; the customer signs in again
        $this->reviewOfOrder($headers, $order->order_number)->assertOk()->assertJsonPath('eligibility.eligible', false)->assertJsonPath('eligibility.reason', 'REVIEW_WINDOW_EXPIRED');
        $this->submitReview($headers, $order->order_number)->assertStatus(409)->assertJsonPath('error.code', 'review_window_expired');
        $this->showOrder($headers, $order->order_number)->assertOk()->assertJsonPath('order.review.state', 'NOT_ELIGIBLE')->assertJsonPath('order.review.reason', 'REVIEW_WINDOW_EXPIRED');

        config(['reviews.submission_window_days' => 60]);
        $this->reviewOfOrder($headers, $order->order_number)->assertOk()->assertJsonPath('eligibility.eligible', true)->assertJsonPath('config.submission_window_days', 60);
        $this->submitReview($headers, $order->order_number)->assertCreated();
    }

    public function test_the_request_cannot_choose_the_restaurant_the_customer_the_status_or_verification(): void
    {
        config(['rate_limits.review_write' => 100]); // this test sends more refused submissions than a customer may in a minute
        [$customer, $headers, $order] = $this->completedOrder();
        [$stranger] = $this->otherCustomer();

        foreach ([
            ['restaurant_location_id' => '00000000-0000-4000-8000-000000000009'], ['location_id' => 999], ['restaurant_organization_id' => 5], ['customer_id' => $stranger->public_id],
            ['order_id' => 12345], ['status' => 'PUBLISHED'], ['verified' => true], ['verified_order' => true], ['published_at' => '2026-01-01T00:00:00Z'], ['author_display_name' => 'Someone Else'],
        ] as $extra) {
            $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, ...$extra])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['details' => ['fields' => [array_key_first($extra)]]]]);
        }
        $this->assertSame(0, RestaurantReview::query()->count());

        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5])->assertCreated();
        $review = RestaurantReview::query()->sole();
        $this->assertSame([$customer->getKey(), $this->burgerHub->getKey()], [$review->customer_id, $review->location_id]);
    }
}
