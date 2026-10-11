<?php

namespace Tests\Support;

use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantReview;
use App\Models\RestaurantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Module 34 test world: orders that really reached COMPLETED (paid → accepted → preparing → ready → pickup verified →
 * completed — no test writes an order status), and the review endpoints of every side.
 */
trait BuildsReviews
{
    use BuildsNotifications;

    /** Slots handed to successive completed orders of one test (the Burger Hub fixture has room in each). */
    private int $reviewSlot = 0;

    /**
     * @return array{0: Customer, 1: array<string, string>, 2: Order, 3: RestaurantUser}
     */
    protected function completedOrder(string $phone = '+919876543210', ?string $slot = null): array
    {
        $slots = ['13:00', '13:15', '13:30', '13:45', '14:00', '14:15', '14:30', '14:45', '15:00', '15:15', '15:30', '15:45'];
        [$customer, $headers, $order, $staff] = $this->readyOrder($phone, $slot ?? $slots[$this->reviewSlot++ % count($slots)]);
        $payload = $this->credentialOf($headers, $order['order_number'])['qr_payload'];
        $this->verifyQr($payload)->assertOk()->assertJsonPath('order.status', 'COMPLETED');

        return [$customer, $headers, $this->orderModel($order['order_number']), $staff];
    }

    /**
     * A completed order with a published review of the given rating.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: Customer, 1: array<string, string>, 2: Order, 3: RestaurantReview}
     */
    protected function reviewed(int $rating, string $phone, array $body = []): array
    {
        [$customer, $headers, $order] = $this->completedOrder($phone);
        $id = $this->submitReview($headers, $order->order_number, ['overall_rating' => $rating, ...$body])->assertCreated()->json('review.id');

        return [$customer, $headers, $order, RestaurantReview::query()->where('public_id', $id)->firstOrFail()];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $extraHeaders
     */
    protected function submitReview(array $headers, string $order, array $body = ['overall_rating' => 5], array $extraHeaders = []): TestResponse
    {
        return $this->postJson('/api/v1/customer/orders/'.$order.'/review', $body, [...$this->using($headers), ...$extraHeaders]);
    }

    protected function reviewOfOrder(array $headers, string $order): TestResponse
    {
        return $this->getJson('/api/v1/customer/orders/'.$order.'/review', $this->using($headers));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function editReview(array $headers, string $review, array $body): TestResponse
    {
        return $this->patchJson('/api/v1/customer/reviews/'.$review, $body, $this->using($headers));
    }

    protected function publicReviews(string $query = '', ?string $slug = null): TestResponse
    {
        $slug ??= (string) $this->burgerHub->slug;
        $this->flushHeaders();
        app('auth')->forgetGuards();

        return $this->getJson('/api/v1/restaurants/'.$slug.'/reviews'.($query === '' ? '' : '?'.$query));
    }

    protected function publicSummary(?string $slug = null): TestResponse
    {
        $slug ??= (string) $this->burgerHub->slug;
        $this->flushHeaders();
        app('auth')->forgetGuards();

        return $this->getJson('/api/v1/restaurants/'.$slug.'/reviews/summary');
    }

    /** A staff member of Burger Hub's organization becomes the actor. */
    protected function reviewStaff(string $role = 'MANAGER', ?array $locations = null): RestaurantUser
    {
        return $this->asActor($this->member($this->organization, $role, $locations));
    }

    protected function staffGet(string $path): TestResponse
    {
        $this->flushHeaders();
        $this->actingAsPrincipal($this->actor);

        return $this->getJson('/api/v1/restaurant/'.ltrim($path, '/'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function staffSend(string $method, string $path, array $body = []): TestResponse
    {
        $this->flushHeaders();
        $this->actingAsPrincipal($this->actor);

        return $this->json($method, '/api/v1/restaurant/'.ltrim($path, '/'), $body);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    protected function moderator(array $permissions = [Permission::AdminReviewsView, Permission::AdminReviewsModerate, Permission::AdminReviewsRestore, Permission::AdminReviewsReportsView], mixed $scope = null): AdminUser
    {
        return $this->asActor($this->adminWith($permissions, $scope));
    }

    protected function modGet(string $path): TestResponse
    {
        $this->flushHeaders();
        $this->actingAsPrincipal($this->actor);

        return $this->getJson('/api/v1/admin/'.ltrim($path, '/'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function modPost(string $path, array $body = []): TestResponse
    {
        $this->flushHeaders();
        $this->actingAsPrincipal($this->actor);

        return $this->postJson('/api/v1/admin/'.ltrim($path, '/'), $body);
    }

    /**
     * The stored aggregate of Burger Hub as [count, sum, average].
     *
     * @return array{0: int, 1: int, 2: string|null}
     */
    protected function storedRating(): array
    {
        $row = DB::table('restaurant_location_rating_summaries')->where('location_id', $this->burgerHub->getKey())->first();

        return $row === null ? [0, 0, null] : [(int) $row->published_count, (int) $row->rating_sum, $row->average_rating === null ? null : (string) $row->average_rating];
    }
}
