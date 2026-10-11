<?php

namespace Tests\Feature\Review;

use App\Enums\Permission;
use App\Models\Market;
use App\Models\RestaurantReview;
use App\Models\RestaurantReviewResponse;
use App\Models\ReviewReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsReviews;
use Tests\TestCase;

/**
 * Review boundaries (Module 34): a customer reaches only their own review, a restaurant only the reviews of its own
 * locations, an administrator only their markets — and text is text, never markup.
 */
class ReviewSecurityTest extends TestCase
{
    use BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.review_write' => 200, 'rate_limits.review_report' => 200]);
    }

    public function test_a_customer_cannot_read_change_or_remove_another_customers_review(): void
    {
        [, $headers, , $review] = $this->reviewed(4, '+919811100051', ['comment' => 'Solid lunch.']);
        [, $other] = $this->otherCustomer();

        $this->getJson('/api/v1/customer/reviews/'.$review->public_id, $this->using($other))->assertNotFound()->assertJsonPath('error.code', 'review_not_found');
        $this->editReview($other, $review->public_id, ['version' => 1, 'overall_rating' => 1])->assertNotFound();
        $this->deleteJson('/api/v1/customer/reviews/'.$review->public_id, [], $this->using($other))->assertNotFound();
        $this->getJson('/api/v1/customer/reviews', $this->using($other))->assertOk()->assertJsonCount(0, 'data');

        $fresh = $review->fresh();
        $this->assertSame([4, 'PUBLISHED', 1], [$fresh->overall_rating, $fresh->status->value, $fresh->version]);
        $this->getJson('/api/v1/customer/reviews', $this->using($headers))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $review->public_id);
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/customer/reviews')->assertUnauthorized();
    }

    public function test_markup_is_refused_and_plain_text_with_angle_brackets_is_kept_as_text(): void
    {
        [, $headers, $order] = $this->completedOrder();

        foreach ([
            '<script>alert(1)</script>', 'Nice <b>burger</b>', '<img src=x onerror=alert(1)>', '&lt;script&gt;alert(1)&lt;/script&gt;', 'click javascript:alert(1)', '<!-- hidden -->', '&#x3C;svg onload=alert(1)&#x3E;',
        ] as $payload) {
            $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => $payload])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => ['comment']]]]);
            $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'title' => $payload])->assertStatus(422);
        }
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => str_repeat('a', 501)])->assertStatus(422);
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => str_repeat('a', 5000)])->assertStatus(422);
        $this->assertSame(0, RestaurantReview::query()->count());

        // Ordinary text that happens to hold < and > — and text in other scripts — is a review like any other.
        $text = 'I <3 the fries, and 5 > 4. बहुत स्वादिष्ट 🍔 とても美味しい';
        $created = $this->submitReview($headers, $order->order_number, ['overall_rating' => 5, 'comment' => $text])->assertCreated();
        $created->assertJsonPath('review.comment', $text)->assertJsonPath('review.status', 'PUBLISHED')->assertHeader('Content-Type', 'application/json');
        $this->assertSame($text, $this->publicReviews()->assertOk()->json('data.0.comment'));
        // 500 characters of a multi-byte script are 500 characters, not 1,500 bytes.
        [, $second, $another] = $this->completedOrder('+919811100052');
        $this->submitReview($second, $another->order_number, ['overall_rating' => 4, 'comment' => str_repeat('स', 500)])->assertCreated();
    }

    public function test_a_public_review_carries_a_display_name_and_nothing_else_of_the_customer(): void
    {
        [$customer, , $order, $review] = $this->reviewed(5, '+919811100053', ['comment' => 'Quick pickup.']);
        $customer->forceFill(['name' => 'Rahul Kumar Sharma', 'email' => 'rahul.private@example.com'])->save();
        $review->forceFill(['author_display_name' => $customer->publicDisplayName()])->save();

        $list = $this->publicReviews()->assertOk();
        $list->assertJsonPath('data.0.author', ['display_name' => 'Rahul S.'])->assertJsonPath('data.0.verified_order', true);
        $this->assertSame(['id', 'author', 'overall_rating', 'category_ratings', 'tags', 'title', 'comment', 'verified_order', 'edited', 'response', 'submitted_at'], array_keys($list->json('data.0')));
        $body = $list->getContent().$this->publicSummary()->getContent();
        foreach (['9811100053', 'rahul.private', 'Kumar', $customer->public_id, $order->order_number, $order->public_id, 'pickup_code', 'payment', 'journey'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
        $this->assertSame('FoodOnTheGo customer', tap($customer, fn ($c) => $c->forceFill(['name' => null]))->publicDisplayName(), 'never the masked phone number');
        $this->assertSame('आशा', tap($customer, fn ($c) => $c->forceFill(['name' => ' आशा ']))->publicDisplayName());
    }

    public function test_restaurant_staff_reach_only_the_reviews_of_their_own_locations_and_need_the_permission(): void
    {
        [$pizzaPoint] = $this->otherRestaurant();
        [, , , $review] = $this->reviewed(3, '+919811100054', ['comment' => 'Average.']);
        $here = $this->burgerHub->public_id;

        // A manager of the organization: sees it.
        $this->reviewStaff('MANAGER');
        $this->staffGet('reviews?location='.$here)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $review->public_id)->assertJsonPath('data.0.can_respond', true)->assertJsonPath('summary.count', 1);
        $this->staffGet('reviews/'.$review->public_id)->assertOk()->assertJsonPath('review.order_number', $review->order->order_number);

        // Another organization: the location and the review do not exist for them.
        $this->asActor($this->member($this->organization('Other Kitchens'), 'OWNER'));
        $this->staffGet('reviews?location='.$here)->assertNotFound();
        $this->staffGet('reviews/summary?location='.$here)->assertNotFound();
        $this->staffGet('reviews/'.$review->public_id)->assertNotFound()->assertJsonPath('error.code', 'review_not_found');
        $this->staffSend('PUT', 'reviews/'.$review->public_id.'/response', ['response_text' => 'Thanks!'])->assertNotFound();
        $this->staffSend('POST', 'reviews/'.$review->public_id.'/report', ['reason' => 'SPAM'])->assertNotFound();

        // The same organization, but a membership for the other branch only: also not found.
        $this->asActor($this->member($this->organization, 'MANAGER', [$pizzaPoint]));
        $this->staffGet('reviews?location='.$here)->assertNotFound();
        $this->staffGet('reviews/'.$review->public_id)->assertNotFound();
        $this->staffSend('PUT', 'reviews/'.$review->public_id.'/response', ['response_text' => 'Thanks!'])->assertNotFound();
        $this->staffGet('reviews?location='.$pizzaPoint->public_id)->assertOk()->assertJsonCount(0, 'data');

        // Roles: a viewer reads but cannot answer or report; order staff cannot read reviews at all.
        $this->reviewStaff('VIEWER');
        $this->staffGet('reviews?location='.$here)->assertOk()->assertJsonPath('data.0.can_respond', false)->assertJsonPath('data.0.can_report', false);
        $this->staffSend('PUT', 'reviews/'.$review->public_id.'/response', ['response_text' => 'Thanks!'])->assertForbidden();
        $this->staffSend('POST', 'reviews/'.$review->public_id.'/report', ['reason' => 'SPAM'])->assertForbidden();
        $this->reviewStaff('ORDER_STAFF');
        $this->staffGet('reviews?location='.$here)->assertForbidden();

        $this->assertSame(0, RestaurantReviewResponse::query()->count());
        $this->assertSame(0, ReviewReport::query()->count());
    }

    public function test_the_restaurant_cannot_change_or_remove_the_customers_review(): void
    {
        [, , , $review] = $this->reviewed(1, '+919811100055', ['comment' => 'Cold food and a long wait.']);
        $this->reviewStaff('OWNER');

        foreach ([['overall_rating' => 5], ['comment' => 'Great!'], ['status' => 'HIDDEN'], ['customer_id' => 1]] as $extra) {
            $this->staffSend('PUT', 'reviews/'.$review->public_id.'/response', ['response_text' => 'We are sorry.', ...$extra])->assertStatus(422);
        }
        foreach (['PATCH', 'DELETE', 'PUT', 'POST'] as $method) {
            $this->assertContains($this->staffSend($method, 'reviews/'.$review->public_id, ['overall_rating' => 5])->status(), [404, 405]);
        }
        foreach (['hide', 'reject', 'publish', 'restore'] as $action) {
            $this->flushHeaders();
            $this->actingAsPrincipal($this->actor);
            $this->assertContains($this->postJson('/api/v1/admin/reviews/'.$review->public_id.'/'.$action, ['reason' => 'We disagree with it.'])->status(), [401, 403]);
        }

        $fresh = $review->fresh();
        $this->assertSame([1, 'Cold food and a long wait.', 'PUBLISHED'], [$fresh->overall_rating, $fresh->comment, $fresh->status->value]);
    }

    public function test_an_administrator_is_held_to_the_permission_and_to_the_market(): void
    {
        [, $headers, , $review] = $this->reviewed(2, '+919811100056', ['comment' => 'Not what I ordered.']);

        // Read-only moderator: sees, cannot decide.
        $this->moderator([Permission::AdminReviewsView]);
        $this->modGet('reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.published', 1);
        $this->modGet('reviews/'.$review->public_id)->assertOk()->assertJsonPath('review.id', $review->public_id);
        $this->modPost('reviews/'.$review->public_id.'/hide', ['reason' => 'No reason that holds.'])->assertForbidden();
        $this->modGet('review-cases')->assertForbidden();

        // Moderate without restore: may hide, may not bring back.
        $this->moderator([Permission::AdminReviewsView, Permission::AdminReviewsModerate]);
        $this->modPost('reviews/'.$review->public_id.'/hide', ['reason' => 'Names a member of staff with an accusation.'])->assertOk();
        $this->modPost('reviews/'.$review->public_id.'/restore', ['reason' => 'Second look.'])->assertForbidden();

        // A grant for another market opens nothing of this one.
        $elsewhere = Market::factory()->create(['country_code' => 'AE', 'name' => 'United Arab Emirates']);
        $this->moderator([Permission::AdminReviewsView, Permission::AdminReviewsModerate, Permission::AdminReviewsRestore, Permission::AdminReviewsReportsView], $elsewhere);
        $this->modGet('reviews')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('counts.all', 0);
        $this->modGet('reviews/'.$review->public_id)->assertForbidden();
        $this->modPost('reviews/'.$review->public_id.'/restore', ['reason' => 'Out of my market.'])->assertForbidden();
        $this->modGet('review-cases')->assertOk()->assertJsonCount(0, 'data');

        // No review permission at all, and the other kinds of account.
        $this->moderator([Permission::AdminOrdersView]);
        $this->modGet('reviews')->assertForbidden();
        $this->assertContains($this->getJson('/api/v1/admin/reviews', $this->using($headers))->status(), [401, 403]);

        $this->assertSame('HIDDEN', $review->fresh()->status->value);
    }

    public function test_reporting_is_limited_to_one_report_per_account_and_never_hides_a_review(): void
    {
        [, $authorHeaders, , $review] = $this->reviewed(1, '+919811100057', ['comment' => 'Would not come back.']);
        [, $reporter] = $this->otherCustomer();
        $path = '/api/v1/reviews/'.$review->public_id.'/report';

        $this->postJson($path, ['reason' => 'NOT_A_REASON'], $this->using($reporter))->assertStatus(422);
        $this->postJson($path, ['reason' => 'SPAM', 'status' => 'HIDDEN'], $this->using($reporter))->assertStatus(422);
        $this->postJson($path, ['reason' => 'SPAM', 'explanation' => '<script>x</script>'], $this->using($reporter))->assertStatus(422);
        $this->postJson($path, ['reason' => 'FAKE_REVIEW_CONCERN', 'explanation' => 'Looks copied.'], $this->using($reporter))->assertCreated()->assertJsonPath('report.reason', 'FAKE_REVIEW_CONCERN');
        $this->postJson($path, ['reason' => 'SPAM'], $this->using($reporter))->assertStatus(409)->assertJsonPath('error.code', 'review_already_reported');
        $this->postJson($path, ['reason' => 'SPAM'], $this->using($authorHeaders))->assertStatus(409)->assertJsonPath('error.code', 'cannot_report_own_review');
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->postJson($path, ['reason' => 'SPAM'])->assertUnauthorized();

        // A report proves nothing: the review is still public and still counted.
        $this->assertSame(['PUBLISHED', 1], [$review->fresh()->status->value, ReviewReport::query()->count()]);
        $this->publicReviews()->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([1, 1, '1.00'], $this->storedRating());

        // A review that is not public does not exist for a reporter; the daily ceiling holds.
        $review->forceFill(['status' => 'HIDDEN'])->save();
        [, $third] = $this->signedIn('+919811100058');
        $this->postJson($path, ['reason' => 'SPAM'], $this->using($third))->assertNotFound();
        $review->forceFill(['status' => 'PUBLISHED'])->save();
        config(['reviews.reports.max_per_day' => 0]);
        $this->postJson($path, ['reason' => 'SPAM'], $this->using($third))->assertStatus(409)->assertJsonPath('error.code', 'report_limit_reached');
    }

    public function test_review_writes_are_rate_limited(): void
    {
        config(['rate_limits.review_write' => 3]);
        [, $headers, $order] = $this->completedOrder();
        foreach (range(1, 3) as $i) {
            $this->submitReview($headers, $order->order_number, ['overall_rating' => 9])->assertStatus(422);
        }
        $this->submitReview($headers, $order->order_number, ['overall_rating' => 5])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertSame(0, RestaurantReview::query()->count());
    }
}
