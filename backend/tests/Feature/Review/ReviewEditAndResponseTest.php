<?php

namespace Tests\Feature\Review;

use App\Models\AuditEvent;
use App\Models\CustomerNotification;
use App\Models\RestaurantReview;
use App\Models\RestaurantReviewResponse;
use App\Models\ReviewRevision;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsReviews;
use Tests\TestCase;

/**
 * Changing and withdrawing a review, and the restaurant's one answer (Module 34).
 */
class ReviewEditAndResponseTest extends TestCase
{
    use BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.review_write' => 200]);
    }

    public function test_the_owner_edits_inside_the_policy_with_a_version_and_the_previous_text_is_kept(): void
    {
        [$customer, $headers, , $review] = $this->reviewed(3, '+919811100081', ['comment' => 'It was fine.', 'tags' => ['as_expected']]);
        $id = $review->public_id;

        $this->editReview($headers, $id, ['overall_rating' => 4])->assertStatus(422); // no version: no edit
        $this->editReview($headers, $id, ['version' => 1, 'status' => 'PUBLISHED'])->assertStatus(422);
        $this->editReview($headers, $id, ['version' => 1, 'overall_rating' => 7])->assertStatus(422);
        $first = $this->editReview($headers, $id, ['version' => 1, 'overall_rating' => 4, 'comment' => 'Better than I first thought.'])->assertOk();
        $first->assertJsonPath('review.overall_rating', 4)->assertJsonPath('review.comment', 'Better than I first thought.')->assertJsonPath('review.tags', ['as_expected'])
            ->assertJsonPath('review.version', 2)->assertJsonPath('review.edits_left', 2)->assertJsonPath('review.status', 'PUBLISHED');
        $this->assertNotNull($first->json('review.edited_at'));
        $this->assertSame([1, 4, '4.00'], $this->storedRating());
        $this->publicReviews()->assertOk()->assertJsonPath('data.0.edited', true)->assertJsonPath('data.0.comment', 'Better than I first thought.');

        // A second tab still holding version 1 loses: nothing is overwritten silently.
        $this->editReview($headers, $id, ['version' => 1, 'overall_rating' => 1])->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')->assertJsonPath('error.details.current_version', 2);

        // History: what it said before, who changed it — not public.
        $revision = ReviewRevision::query()->where('review_id', $review->getKey())->sole();
        $this->assertSame(['REVIEW', 1, 3, 'It was fine.', 'customer', $customer->public_id], [$revision->subject, $revision->version, $revision->overall_rating, $revision->body, $revision->actor_type, $revision->actor_public_id]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'review.edited')->count());

        // Two more edits use the allowance up; a fourth is refused.
        $this->editReview($headers, $id, ['version' => 2, 'comment' => 'Second thoughts.'])->assertOk()->assertJsonPath('review.edits_left', 1);
        $this->editReview($headers, $id, ['version' => 3, 'comment' => 'Third thoughts.'])->assertOk()->assertJsonPath('review.edits_left', 0)->assertJsonPath('review.can_edit', false);
        $this->editReview($headers, $id, ['version' => 4, 'comment' => 'Fourth.'])->assertStatus(409)->assertJsonPath('error.code', 'review_edit_limit_reached');
        $this->assertSame(3, ReviewRevision::query()->count());
    }

    public function test_the_edit_window_closes_and_a_flagged_edit_goes_back_to_a_person(): void
    {
        [, $headers, , $review] = $this->reviewed(5, '+919811100082', ['comment' => 'Great.']);

        $flagged = $this->editReview($headers, $review->public_id, ['version' => 1, 'comment' => 'Great. Order from www.other-site.example.com instead'])->assertOk();
        $flagged->assertJsonPath('review.status', 'PENDING_MODERATION');
        $this->assertSame([0, 0, null], $this->storedRating(), 'a review that waits is not counted');
        $this->publicReviews()->assertOk()->assertJsonCount(0, 'data');
        // Editing the link out again does not publish it by itself: a person decides.
        $this->editReview($headers, $review->public_id, ['version' => 2, 'comment' => 'Great.'])->assertOk()->assertJsonPath('review.status', 'PENDING_MODERATION');

        [$customer, , , $other] = $this->reviewed(4, '+919811100083', ['comment' => 'Good.']);
        $this->travel(73)->hours();
        $fresh = $this->sessionOf($customer);
        $this->getJson('/api/v1/customer/reviews/'.$other->public_id, $fresh)->assertOk()->assertJsonPath('review.can_edit', false);
        $this->editReview($fresh, $other->public_id, ['version' => 1, 'overall_rating' => 1])->assertStatus(409)->assertJsonPath('error.code', 'review_edit_window_expired');
        $this->assertSame(4, (int) $other->fresh()->overall_rating);
    }

    public function test_a_withdrawn_review_is_not_public_not_counted_kept_and_the_order_cannot_be_reviewed_again(): void
    {
        [, $headers, $order, $review] = $this->reviewed(2, '+919811100084', ['comment' => 'Disappointing.']);

        $this->deleteJson('/api/v1/customer/reviews/'.$review->public_id, [], $this->using($headers))->assertOk()->assertJsonPath('review.status', 'REMOVED');
        $this->deleteJson('/api/v1/customer/reviews/'.$review->public_id, [], $this->using($headers))->assertOk()->assertJsonPath('review.status', 'REMOVED'); // again: the same answer

        $kept = RestaurantReview::query()->sole();
        $this->assertSame(['REMOVED', 'CUSTOMER', 'Disappointing.'], [$kept->status->value, $kept->removed_by, $kept->comment], 'the row and its text are kept for the record');
        $this->assertSame(1, AuditEvent::query()->where('action', 'review.removed')->count());
        $this->publicReviews()->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame([0, 0, null], $this->storedRating());
        $this->getJson('/api/v1/customer/reviews', $this->using($headers))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customer/reviews/'.$review->public_id, $this->using($headers))->assertNotFound();
        $this->showOrder($headers, $order->order_number)->assertOk()->assertJsonPath('order.review.state', 'REMOVED');
        $this->reviewOfOrder($headers, $order->order_number)->assertOk()->assertJsonPath('eligibility.eligible', false)->assertJsonPath('eligibility.reason', 'REVIEW_ALREADY_EXISTS');
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => 'Actually it was amazing!'])->assertStatus(409)->assertJsonPath('error.code', 'review_already_exists');
        $this->editReview($headers, $review->public_id, ['version' => 2, 'overall_rating' => 5])->assertNotFound();
    }

    public function test_the_database_refuses_a_second_review_of_an_order_whatever_the_code_does(): void
    {
        [, , $order, $review] = $this->reviewed(5, '+919811100085');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('restaurant_reviews')->insert([
            'public_id' => '00000000-0000-4000-8000-00000000abcd', 'order_id' => $order->getKey(), 'customer_id' => $review->customer_id, 'organization_id' => $review->organization_id,
            'location_id' => $review->location_id, 'market_id' => $review->market_id, 'overall_rating' => 1, 'author_display_name' => 'x', 'status' => 'PUBLISHED', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_restaurant_answers_once_edits_its_answer_and_the_customer_is_told(): void
    {
        [$customer, $headers, $order, $review] = $this->reviewed(2, '+919811100086', ['comment' => 'The order took too long.']);
        $this->registerDevice($headers, 'inst-review-0000001', 'fake-token-review-1');
        $staff = $this->reviewStaff('MANAGER');
        $path = 'reviews/'.$review->public_id.'/response';

        $this->staffSend('PUT', $path, [])->assertStatus(422);
        $this->staffSend('PUT', $path, ['response_text' => '   '])->assertStatus(422);
        $this->staffSend('PUT', $path, ['response_text' => '<a href="https://x.example">click</a>'])->assertStatus(422);
        $this->staffSend('PUT', $path, ['response_text' => str_repeat('a', 601)])->assertStatus(422);
        $created = $this->staffSend('PUT', $path, ['response_text' => ' We are sorry for the wait — the kitchen was short-staffed that day. '])->assertCreated();
        $created->assertJsonPath('review.response.text', 'We are sorry for the wait — the kitchen was short-staffed that day.')->assertJsonPath('review.response.version', 1)
            ->assertJsonPath('review.response.responder_name', $staff->name)->assertJsonPath('review.overall_rating', 2)->assertJsonPath('review.comment', 'The order took too long.');

        // The customer's review is untouched; the public sees the restaurant's name, never the staff member's.
        $this->assertSame([2, 'The order took too long.', 1], [$review->fresh()->overall_rating, $review->fresh()->comment, $review->fresh()->version]);
        $public = $this->publicReviews()->assertOk();
        $public->assertJsonPath('data.0.response.text', 'We are sorry for the wait — the kitchen was short-staffed that day.')->assertJsonPath('data.0.response.restaurant_name', 'Burger Hub')->assertJsonPath('data.0.response.edited', false);
        $this->assertStringNotContainsString((string) $staff->name, $public->getContent());
        $this->getJson('/api/v1/customer/reviews/'.$review->public_id, $this->using($headers))->assertOk()->assertJsonPath('review.response.restaurant_name', 'Burger Hub');

        // The customer is told once, through the Module 33 notification domain (in-app + push by preference).
        $notice = $this->notificationOf($customer, 'review.response');
        $this->assertSame(['ORDER_UPDATES', 'REVIEW', $order->order_number, 'Burger Hub replied to your review'], [$notice->category->value, $notice->action_target->value, $notice->action_reference, $notice->title]);
        $this->assertEquals(['IN_APP' => ['SENT' => 1], 'PUSH' => ['SENT' => 1]], $this->deliverySummary($notice));
        $this->inbox($headers)->assertOk()->assertJsonPath('data.0.type', 'review.response')->assertJsonPath('data.0.action.target', 'REVIEW');

        // One response per review: a second call edits it, keeps the previous text, and does not notify again.
        $this->reviewStaff('OWNER');
        $this->staffSend('PUT', $path, ['response_text' => 'Edited by somebody holding an old copy.', 'expected_version' => 7])->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
        // A second answer from somebody who never loaded the first one (no version) overwrites nothing; the same text again is a replay.
        $this->staffSend('PUT', $path, ['response_text' => 'A different answer written without reading the first.'])->assertStatus(409)->assertJsonPath('error.code', 'response_exists')->assertJsonPath('error.details.current_version', 1);
        $this->staffSend('PUT', $path, ['response_text' => 'We are sorry for the wait. We have added staff at lunch.', 'expected_version' => 1])->assertOk()->assertJsonPath('review.response.version', 2);
        $this->assertSame(1, RestaurantReviewResponse::query()->count());
        $revision = ReviewRevision::query()->where('subject', 'RESPONSE')->sole();
        $this->assertSame([1, 'We are sorry for the wait — the kitchen was short-staffed that day.', 'restaurant_user'], [$revision->version, $revision->body, $revision->actor_type]);
        $this->assertSame(1, CustomerNotification::query()->where('event_type', 'review.response')->count());
        $this->assertSame([1, 1], [AuditEvent::query()->where('action', 'review.response_added')->count(), AuditEvent::query()->where('action', 'review.response_edited')->count()]);
        $this->publicReviews()->assertOk()->assertJsonPath('data.0.response.edited', true);

        // An administrator can hide a response; the restaurant cannot overwrite a hidden one; restoring brings it back.
        $this->moderator();
        $this->modPost('reviews/'.$review->public_id.'/response/hide', ['reason' => 'The response names the customer\'s order details.'])->assertOk()->assertJsonPath('review.response.status', 'HIDDEN');
        $this->publicReviews()->assertOk()->assertJsonPath('data.0.response', null);
        $this->reviewStaff('MANAGER');
        $this->staffSend('PUT', $path, ['response_text' => 'Trying again.'])->assertStatus(409)->assertJsonPath('error.code', 'response_hidden');
        $this->moderator();
        $this->modPost('reviews/'.$review->public_id.'/response/restore', ['reason' => 'Checked: nothing private in it.'])->assertOk()->assertJsonPath('review.response.status', 'PUBLISHED');
        $this->assertNotNull($this->publicReviews()->json('data.0.response'));
    }

    public function test_only_a_published_review_can_be_answered_and_the_dashboard_lists_filters_and_summarises(): void
    {
        [, , , $low] = $this->reviewed(1, '+919811100087', ['comment' => 'Bad.']);
        [, , , $high] = $this->reviewed(5, '+919811100088', ['comment' => 'Excellent.']);
        [, $pending, $order] = $this->completedOrder('+919811100089');
        $this->submitReview($pending, $order->order_number, ['overall_rating' => 5, 'comment' => 'visit www.spam.example.com'])->assertCreated()->assertJsonPath('review.status', 'PENDING_MODERATION');
        $waiting = RestaurantReview::query()->where('status', 'PENDING_MODERATION')->sole();

        $this->reviewStaff('MANAGER');
        $here = $this->burgerHub->public_id;
        // What waits for moderation is not shown to the restaurant and cannot be answered.
        $list = $this->staffGet('reviews?location='.$here)->assertOk();
        $this->assertEqualsCanonicalizing([$low->public_id, $high->public_id], array_column($list->json('data'), 'id'));
        $this->staffGet('reviews/'.$waiting->public_id)->assertNotFound();
        $this->staffSend('PUT', 'reviews/'.$waiting->public_id.'/response', ['response_text' => 'Thanks'])->assertNotFound();

        $this->staffSend('PUT', 'reviews/'.$low->public_id.'/response', ['response_text' => 'Sorry to hear that.'])->assertCreated();
        $list = $this->staffGet('reviews?location='.$here)->assertOk();
        $list->assertJsonPath('summary.count', 2)->assertJsonPath('summary.average', 3)->assertJsonPath('summary.answered', 1)->assertJsonPath('summary.unanswered', 1)->assertJsonPath('summary.distribution.5', 1);
        $this->assertSame([$high->public_id], array_column($this->staffGet('reviews?location='.$here.'&responded=false')->json('data'), 'id'));
        $this->assertSame([$low->public_id], array_column($this->staffGet('reviews?location='.$here.'&responded=true')->json('data'), 'id'));
        $this->assertSame([$low->public_id], array_column($this->staffGet('reviews?location='.$here.'&max_rating=3')->json('data'), 'id'));
        $this->assertSame([$high->public_id], array_column($this->staffGet('reviews?location='.$here.'&rating=5')->json('data'), 'id'));
        $this->assertSame([$low->public_id, $high->public_id], array_column($this->staffGet('reviews?location='.$here.'&sort=lowest')->json('data'), 'id'));
        $this->staffGet('reviews/summary?location='.$here)->assertOk()->assertJsonPath('summary.count', 2);
        $this->staffGet('reviews')->assertStatus(422); // the location is always named
        // The restaurant sees the order number of ITS order and the display name — never a phone or an e-mail.
        $body = $list->getContent();
        $this->assertStringNotContainsString('98111000', $body);
        $this->assertStringNotContainsString('@', $body);
    }

    public function test_the_completion_notice_invites_the_review_once_without_a_second_notification(): void
    {
        [$customer, $headers, $order] = $this->completedOrder('+919811100090');

        $completed = $this->notificationOf($customer, 'order.completed');
        $this->assertStringContainsString('rate your experience', $completed->body);
        $this->assertSame(2, (int) $completed->template_version);
        $this->assertSame(0, CustomerNotification::query()->where('customer_id', $customer->getKey())->where('event_type', 'like', 'review.%')->count(), 'no separate invitation and no reminder');
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5])->assertCreated();
        $this->assertSame(0, CustomerNotification::query()->where('customer_id', $customer->getKey())->where('event_type', 'like', 'review.%')->count(), 'writing a review notifies nobody about it');
    }
}
