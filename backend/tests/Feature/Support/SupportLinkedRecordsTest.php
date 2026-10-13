<?php

namespace Tests\Feature\Support;

use App\Enums\Permission;
use App\Models\SupportCaseLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsReviews;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — the records only the service desk can attach to a case: a review, a restaurant, an organization, a
 * customer. Each is a reference; its state is read from its own domain, for a viewer who may read that domain.
 */
class SupportLinkedRecordsTest extends TestCase
{
    use BuildsReviews, BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_desk_attaches_a_review_a_restaurant_and_a_customer_and_each_is_read_only_with_its_own_permission(): void
    {
        [$customer, $headers, $order, $review] = $this->reviewed(2, '+919876543210', ['comment' => 'The food was cold when I arrived.']);
        $case = $this->customerCase($headers, ['category' => 'REVIEW', 'subject' => 'About my review', 'order' => $order->order_number]);
        $path = '/cases/'.$case['id'].'/links';
        $panel = fn (string $type): ?array => collect($this->desk('GET', $path)->assertOk()->json('links'))->firstWhere('type', $type);

        // A requester cannot attach any of these: the request has no room for them.
        $this->customerSupport($headers, 'POST', '/cases', ['category' => 'REVIEW', 'subject' => 'x', 'message' => 'y', 'review' => (string) $review->public_id])->assertStatus(422);

        // An agent who may not read reviews, restaurants or customers cannot attach them.
        $this->agent();
        $this->desk('POST', $path, ['type' => 'REVIEW', 'reference' => (string) $review->public_id])->assertForbidden()->assertJsonPath('error.details.required_permission', 'admin.reviews.view');
        $this->desk('POST', $path, ['type' => 'LOCATION', 'reference' => (string) $this->burgerHub->public_id])->assertForbidden()->assertJsonPath('error.details.required_permission', 'admin.restaurants.view');
        $this->desk('POST', $path, ['type' => 'CUSTOMER', 'reference' => (string) $customer->public_id])->assertForbidden()->assertJsonPath('error.details.required_permission', 'admin.customers.view');
        $this->assertSame(['ORDER'], SupportCaseLink::query()->pluck('link_type')->map(fn ($t) => $t->value)->all());

        // With the domains' permissions each is attached once and read from its own record.
        $this->agent([...self::DESK, Permission::AdminReviewsView, Permission::AdminRestaurantsView, Permission::AdminCustomersView]);
        $this->desk('POST', $path, ['type' => 'REVIEW', 'reference' => (string) $review->public_id])->assertCreated();
        // The same record attached again is the same link: nothing is written twice.
        $this->desk('POST', $path, ['type' => 'REVIEW', 'reference' => (string) $review->public_id])->assertCreated();
        $this->assertSame(1, SupportCaseLink::query()->where('link_type', 'REVIEW')->count());
        $this->desk('POST', $path, ['type' => 'LOCATION', 'reference' => (string) $this->burgerHub->public_id])->assertCreated();
        $this->desk('POST', $path, ['type' => 'ORGANIZATION', 'reference' => (string) $this->organization->public_id])->assertCreated();
        $this->desk('POST', $path, ['type' => 'CUSTOMER', 'reference' => (string) $customer->public_id])->assertCreated();
        $this->desk('POST', $path, ['type' => 'REVIEW', 'reference' => 'not-a-review'])->assertNotFound()->assertJsonPath('error.code', 'linked_record_not_found');

        $this->assertSame([true, $review->status->value, 2], [$panel('REVIEW')['visible'], $panel('REVIEW')['record']['status'], $panel('REVIEW')['record']['overall_rating']]);
        $this->assertSame([true, 'Burger Hub'], [$panel('LOCATION')['visible'], $panel('LOCATION')['record']['name']]);
        $this->assertTrue($panel('ORGANIZATION')['visible']);
        // The customer's phone number is masked in the panel, and a panel of these kinds offers no action at all.
        $this->assertStringNotContainsString('9876543210', json_encode($panel('CUSTOMER')));
        $this->assertSame([[], [], [], []], [$panel('REVIEW')['shortcuts'], $panel('LOCATION')['shortcuts'], $panel('ORGANIZATION')['shortcuts'], $panel('CUSTOMER')['shortcuts']]);
        // The order panel stays closed to this agent: reading reviews is not reading orders.
        $this->assertSame([false, 'admin.orders.view'], [$panel('ORDER')['visible'], $panel('ORDER')['required_permission']]);

        // The customer reads their case with the order only: what the desk attached is the desk's.
        $this->assertSame(['ORDER'], array_column($this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case.links'), 'type'));
        // The review itself did not change.
        $this->assertSame($review->status->value, $review->fresh()->status->value);
    }
}
