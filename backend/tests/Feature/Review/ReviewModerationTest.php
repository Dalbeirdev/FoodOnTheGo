<?php

namespace Tests\Feature\Review;

use App\Enums\Permission;
use App\Enums\ReviewFlag;
use App\Models\AuditEvent;
use App\Models\CustomerNotification;
use App\Models\RestaurantReview;
use App\Models\ReviewModerationCase;
use App\Services\Review\ReviewContentScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsReviews;
use Tests\TestCase;

/**
 * Moderation (Module 34): the screening holds what its plain rules flag, an administrator decides with a reason, a
 * report asks for a look and proves nothing, and an unfavourable review that breaks no rule stays public.
 */
class ReviewModerationTest extends TestCase
{
    use BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.review_write' => 200, 'rate_limits.review_report' => 200]);
    }

    public function test_a_harsh_but_clean_review_is_published_and_a_flagged_one_waits_for_a_person(): void
    {
        // Negative is not a violation: one star with blunt words goes straight out.
        [, , , $harsh] = $this->reviewed(1, '+919811100061', ['comment' => 'Terrible. Cold burger, soggy fries, forty minutes late. Never again.']);
        $this->assertSame(['PUBLISHED', []], [$harsh->status->value, $harsh->flags]);
        $this->assertSame(0, ReviewModerationCase::query()->count());

        // A link, a phone number, an e-mail address, a spam phrase: held, not public, not counted, one automated case.
        $held = [];
        foreach ([
            ['+919811100062', 'Best deals at www.cheap-food.example.com today', 'UNSAFE_LINK'],
            ['+919811100063', 'Call the manager on 98765 43210 he is rude', 'PERSONAL_INFORMATION'],
            ['+919811100064', 'Write to owner.personal@example.org about this', 'PERSONAL_INFORMATION'],
            ['+919811100065', 'Join my telegram group for free food', 'SPAM_PATTERN'],
        ] as [$phone, $text, $flag]) {
            [, $headers, $order] = $this->completedOrder($phone);
            $response = $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => $text])->assertCreated()->assertJsonPath('review.status', 'PENDING_MODERATION');
            $review = RestaurantReview::query()->where('public_id', $response->json('review.id'))->firstOrFail();
            $this->assertContains($flag, $review->flags, $text);
            $this->assertNull($review->published_at);
            $held[] = $review;
        }
        $this->assertSame(4, ReviewModerationCase::query()->where('source', 'AUTOMATED')->where('status', 'OPEN')->count());
        $this->assertSame([1, 1, '1.00'], $this->storedRating(), 'only the published review counts');
        $this->publicReviews()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $harsh->public_id);

        // The same text posted again by the same customer is flagged as repeated content.
        $text = 'A perfectly ordinary sentence about a perfectly ordinary lunch.';
        [$customer, $h1, $o1] = $this->completedOrder('+919811100066');
        $id = $this->submitReview($h1, $o1->order_number, ['overall_rating' => 4, 'comment' => $text])->assertCreated()->assertJsonPath('review.status', 'PUBLISHED')->json('review.id');
        $own = RestaurantReview::query()->where('public_id', $id)->firstOrFail();
        $screen = app(ReviewContentScreen::class);
        $this->assertSame([ReviewFlag::RepeatedContent], $screen->flags(null, $text, (int) $customer->getKey()), 'the same customer, the same words, another order');
        $this->assertSame([], $screen->flags(null, $text, (int) $customer->getKey(), (int) $own->getKey()), 'editing a review is not repeating it');
        $this->assertSame([], $screen->flags(null, $text, (int) $held[0]->customer_id), 'a different customer may say the same thing');
        $this->assertSame([], $screen->flags(null, 'Worst meal of my life. One star is generous.', (int) $customer->getKey()), 'harsh is not a flag');
    }

    public function test_publish_hide_reject_and_restore_with_reasons_audit_notices_and_the_aggregate(): void
    {
        [$customer, $headers, $order] = $this->completedOrder('+919811100071');
        $id = $this->submitReview($headers, $order->order_number, ['overall_rating' => 4, 'comment' => 'See photos at www.my-blog.example.com'])->assertCreated()->json('review.id');
        $this->assertSame([0, 0, null], $this->storedRating());

        $this->moderator();
        $this->modGet('reviews?status=PENDING_MODERATION')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.flags', ['UNSAFE_LINK'])->assertJsonPath('data.0.open_case.source', 'AUTOMATED')
            ->assertJsonPath('counts.pending_moderation', 1)->assertJsonPath('counts.reported', 1);
        $this->modGet('review-cases')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.review.id', $id);

        // Publish: public, counted, the case closed, the customer told.
        $this->modPost('reviews/'.$id.'/publish', ['reason' => 'A link to the customer\'s own photos; no harm.'])->assertOk()->assertJsonPath('review.status', 'PUBLISHED')->assertJsonPath('review.open_case', null)
            ->assertJsonPath('review.cases.0.resolution', 'PUBLISHED')->assertJsonPath('review.version', 2);
        $this->assertSame([1, 4, '4.00'], $this->storedRating());
        $this->publicReviews()->assertOk()->assertJsonCount(1, 'data');
        $notice = $this->notificationOf($customer, 'review.published');
        $this->assertSame(['ORDER_UPDATES', 'REVIEW', $order->order_number], [$notice->category->value, $notice->action_target->value, $notice->action_reference]);

        // Hide needs a reason; then it is gone from the public and from the average.
        $this->modPost('reviews/'.$id.'/hide', [])->assertStatus(422);
        $this->modPost('reviews/'.$id.'/hide', ['reason' => 'x'])->assertStatus(422);
        $this->modPost('reviews/'.$id.'/hide', ['reason' => 'The linked page turned out to be an advertisement.'])->assertOk()->assertJsonPath('review.status', 'HIDDEN');
        $this->assertSame([0, 0, null], $this->storedRating());
        $this->publicReviews()->assertOk()->assertJsonCount(0, 'data');
        $moderated = $this->notificationOf($customer, 'review.moderated');
        $this->assertStringContainsString('review guidelines', $moderated->body);
        $this->assertStringNotContainsString('advertisement', $moderated->body, 'the customer is told the outcome, never the internal reason or signal');
        $this->getJson('/api/v1/customer/reviews/'.$id, $this->using($headers))->assertOk()->assertJsonPath('review.status', 'HIDDEN')->assertJsonPath('review.can_edit', false);

        // Reject from hidden, restore from rejected (its own permission), each with a reason.
        $this->moderator();
        $this->modPost('reviews/'.$id.'/reject', ['reason' => 'Advertising: the review exists to promote a site.'])->assertOk()->assertJsonPath('review.status', 'REJECTED');
        $this->modPost('reviews/'.$id.'/restore', ['reason' => 'Appeal accepted: the customer removed nothing but the site is harmless.'])->assertOk()->assertJsonPath('review.status', 'PUBLISHED');
        $this->assertSame([1, 4, '4.00'], $this->storedRating());

        // The trail: every decision with its reason and its author, in order; nothing of it is public.
        $detail = $this->modGet('reviews/'.$id)->assertOk();
        $this->assertSame(['review.created', 'review.published', 'review.hidden', 'review.rejected', 'review.restored'], array_column($detail->json('review.history'), 'action'));
        $this->assertSame('Advertising: the review exists to promote a site.', $detail->json('review.history.3.reason'));
        $this->assertSame('Customer', $detail->json('review.history.0.by'));
        $this->assertCount(4, $detail->json('review.revisions'));
        $this->assertSame(4, AuditEvent::query()->whereIn('action', ['review.published', 'review.hidden', 'review.rejected', 'review.restored'])->whereNotNull('reason')->where('market_id', $this->market->getKey())->count());
        $this->assertStringNotContainsString('history', $this->publicReviews()->getContent());
    }

    public function test_two_administrators_deciding_at_once_get_one_decision_and_one_conflict(): void
    {
        [, , , $review] = $this->reviewed(2, '+919811100072', ['comment' => 'The order was wrong twice.']);
        $this->moderator();

        // Both loaded version 1. The first hides; the second's decision no longer applies.
        $this->modPost('reviews/'.$review->public_id.'/hide', ['reason' => 'Harassment of a named employee.', 'expected_version' => 1])->assertOk()->assertJsonPath('review.version', 2);
        $this->modPost('reviews/'.$review->public_id.'/hide', ['reason' => 'Harassment of a named employee.', 'expected_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')->assertJsonPath('error.details.current_version', 2);
        $this->modPost('reviews/'.$review->public_id.'/hide', ['reason' => 'Harassment of a named employee.'])->assertStatus(409)->assertJsonPath('error.code', 'review_state_conflict')->assertJsonPath('error.details.status', 'HIDDEN');
        $this->modPost('reviews/'.$review->public_id.'/publish', ['reason' => 'It was fine.'])->assertStatus(409)->assertJsonPath('error.code', 'review_state_conflict');

        $this->assertSame(1, AuditEvent::query()->where('action', 'review.hidden')->count());
        $this->assertSame(['HIDDEN', 2], [$review->fresh()->status->value, $review->fresh()->version]);
        // The customer's edit from before the decision is refused too: the review they saw is not the review that is.
        $this->modPost('reviews/00000000-0000-4000-8000-000000000001/hide', ['reason' => 'No such review.'])->assertNotFound();
    }

    public function test_reports_open_one_case_that_an_administrator_can_assign_escalate_or_close_without_touching_the_review(): void
    {
        [, , , $review] = $this->reviewed(1, '+919811100073', ['comment' => 'Rude at the counter and the food was cold.']);
        [, $reporterOne] = $this->signedIn('+919811100074');
        [, $reporterTwo] = $this->signedIn('+919811100075');
        $this->postJson('/api/v1/reviews/'.$review->public_id.'/report', ['reason' => 'OFFENSIVE_CONTENT'], $this->using($reporterOne))->assertCreated();
        $this->postJson('/api/v1/reviews/'.$review->public_id.'/report', ['reason' => 'FAKE_REVIEW_CONCERN', 'explanation' => 'I was there, it was fine.'], $this->using($reporterTwo))->assertCreated();
        $this->reviewStaff('MANAGER');
        $this->staffSend('POST', 'reviews/'.$review->public_id.'/report', ['reason' => 'HARASSMENT', 'explanation' => 'It is unfair to my team.'])->assertCreated();
        $this->staffSend('POST', 'reviews/'.$review->public_id.'/report', ['reason' => 'HARASSMENT'])->assertStatus(409)->assertJsonPath('error.code', 'review_already_reported');
        $this->staffGet('reviews/'.$review->public_id)->assertOk()->assertJsonPath('review.reported', true)->assertJsonPath('review.can_report', false);

        $case = ReviewModerationCase::query()->sole();
        $this->assertSame(['CUSTOMER_REPORT', 'OFFENSIVE_CONTENT', 'OPEN', 3], [$case->source->value, $case->reason, $case->status->value, $case->report_count]);
        $this->assertSame(3, AuditEvent::query()->where('action', 'review.reported')->count());

        $admin = $this->moderator();
        $queue = $this->modGet('review-cases')->assertOk();
        $queue->assertJsonCount(1, 'data')->assertJsonPath('data.0.reports', 3)->assertJsonCount(3, 'data.0.report_list')->assertJsonPath('data.0.review.status', 'PUBLISHED');
        $this->assertSame(['customer', 'customer', 'restaurant_user'], array_column($queue->json('data.0.report_list'), 'reporter_type'));
        $this->modGet('reviews?reported=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reports', 3);

        $this->modPost('review-cases/'.$case->public_id.'/assign')->assertOk()->assertJsonPath('case.assigned_to.id', $admin->public_id);
        $this->modPost('review-cases/'.$case->public_id.'/escalate', ['reason' => 'Needs a second opinion from policy.'])->assertOk()->assertJsonPath('case.status', 'ESCALATED');
        $this->modPost('review-cases/'.$case->public_id.'/resolve', ['reason' => 'An unfavourable account of a visit; no rule is broken.'])->assertOk()
            ->assertJsonPath('case.status', 'RESOLVED')->assertJsonPath('case.resolution', 'NO_VIOLATION');
        $this->modPost('review-cases/'.$case->public_id.'/resolve', ['reason' => 'Again.'])->assertStatus(409)->assertJsonPath('error.code', 'case_already_resolved');

        // Three reports and a closed case later the review is exactly where it was: public and counted.
        $this->assertSame('PUBLISHED', $review->fresh()->status->value);
        $this->publicReviews()->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([1, 1, '1.00'], $this->storedRating());
        $this->modGet('review-cases')->assertOk()->assertJsonCount(0, 'data');
        $this->modGet('review-cases?status=RESOLVED')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(0, CustomerNotification::query()->where('event_type', 'review.moderated')->count(), 'nobody is told a review was "moderated" when nothing happened to it');

        // A moderator without the reports permission does not see the queue.
        $this->moderator([Permission::AdminReviewsView, Permission::AdminReviewsModerate]);
        $this->modGet('review-cases')->assertForbidden();
    }

    public function test_the_admin_list_filters_searches_and_counts(): void
    {
        [, , $orderA, $a] = $this->reviewed(5, '+919811100076', ['comment' => 'Lovely masala fries.']);
        [, , , $b] = $this->reviewed(2, '+919811100077', ['comment' => 'Too salty.']);
        $this->moderator();
        $this->modPost('reviews/'.$b->public_id.'/hide', ['reason' => 'Duplicate of another account\'s review.'])->assertOk();

        $all = $this->modGet('reviews')->assertOk();
        $all->assertJsonCount(2, 'data')->assertJsonPath('counts.all', 2)->assertJsonPath('counts.published', 1)->assertJsonPath('counts.hidden', 1)->assertJsonPath('counts.reported', 0);
        $this->assertSame([$b->public_id, $a->public_id], array_column($all->json('data'), 'id'));
        $all->assertJsonPath('data.1.customer.public_name', $a->author_display_name)->assertJsonPath('data.1.order.order_number', $orderA->order_number)->assertJsonPath('data.1.market.country_code', 'IN');
        $this->modGet('reviews?status=HIDDEN')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->public_id);
        $this->modGet('reviews?rating=5')->assertOk()->assertJsonCount(1, 'data');
        $this->modGet('reviews?q=masala')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->public_id);
        $this->modGet('reviews?q='.$orderA->order_number)->assertOk()->assertJsonCount(1, 'data');
        $this->modGet('reviews?q=100%25')->assertOk()->assertJsonCount(0, 'data');
        $this->modGet('reviews?restaurant='.$this->burgerHub->public_id)->assertOk()->assertJsonCount(2, 'data');
        $this->modGet('reviews?status=NOPE')->assertStatus(422);
        $this->modGet('reviews?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }
}
