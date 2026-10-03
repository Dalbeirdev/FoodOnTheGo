<?php

namespace Tests\Feature\Restaurant;

use App\Enums\Permission as P;
use App\Enums\RestaurantStatus;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * The approval lifecycle of restaurant organizations and locations, driven through the administrator API.
 * The clock is Monday 2026-10-05 12:00 in India.
 */
class AdminRestaurantLifecycleTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private AdminUser $onboarding;

    private AdminUser $operations;

    private AdminUser $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $this->onboarding = $this->adminWith([P::AdminRestaurantsView, P::AdminRestaurantsApprove, P::AdminRestaurantsManage]);
        $this->operations = $this->adminWith([P::AdminRestaurantsView, P::AdminRestaurantsSuspend]);
        $this->support = $this->adminWith([P::AdminRestaurantsView]);
        $this->actingAsPrincipal($this->onboarding);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function move(RestaurantLocation|RestaurantOrganization $record, string $status, array $body = []): TestResponse
    {
        $path = $record instanceof RestaurantLocation ? 'restaurants' : 'restaurant-organizations';
        $version = (int) DB::table($record->getTable())->where('id', $record->id)->value('version');

        return $this->postJson("/api/v1/admin/{$path}/{$record->public_id}/status", ['status' => $status, 'version' => $version, ...$body]);
    }

    private function statusOf(RestaurantLocation|RestaurantOrganization $record): string
    {
        return (string) DB::table($record->getTable())->where('id', $record->id)->value('status');
    }

    public function test_a_restaurant_reaches_customers_only_after_organization_and_location_are_both_approved(): void
    {
        $organization = $this->organization('Riverside', 'DRAFT');
        $location = $this->hours($this->location($organization, 'Burger Hub', ['slug' => 'burger-hub', 'status' => 'DRAFT']), [[null, '09:00', '22:00']]);
        $public = fn () => $this->getJson('/api/v1/restaurants/burger-hub');

        $this->move($location, 'SUBMITTED')->assertOk()->assertJsonPath('status', 'SUBMITTED')->assertJsonPath('submitted_at', '2026-10-05T06:30:00+00:00')->assertJsonPath('version', 2);
        $this->move($location, 'UNDER_REVIEW')->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
        $this->move($location, 'APPROVED')->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('approved_at', '2026-10-05T06:30:00+00:00')
            ->assertJsonPath('availability.visible_to_customers', false)->assertJsonPath('availability.reason', 'RESTAURANT_NOT_APPROVED');
        $public()->assertNotFound();

        $this->move($organization, 'SUBMITTED')->assertOk()->assertJsonPath('status', 'SUBMITTED');
        $this->move($organization, 'UNDER_REVIEW')->assertOk();
        $public()->assertNotFound();
        $this->move($organization, 'APPROVED')->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('locations.0.status', 'APPROVED');

        $public()->assertOk()->assertJsonPath('availability.orderable', true);

        $this->assertSame([
            'restaurant_location.submitted', 'restaurant_location.review_started', 'restaurant_location.approved',
            'restaurant.submitted', 'restaurant.review_started', 'restaurant.approved',
        ], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $event = AuditEvent::query()->orderBy('id')->first();
        $this->assertSame($this->onboarding->public_id, $event->actor_public_id);
        $this->assertSame($location->public_id, $event->target_public_id);
        $this->assertEquals(['status' => ['from' => 'DRAFT', 'to' => 'SUBMITTED']], $event->changes);
        $this->assertSame($this->market->id, $event->market_id);
    }

    public function test_a_status_only_moves_along_the_allowed_transitions(): void
    {
        $location = $this->location($this->organization(), 'Burger Hub', ['status' => 'DRAFT']);

        $this->move($location, 'APPROVED')->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition')
            ->assertJsonPath('error.details', ['from' => 'DRAFT', 'allowed' => ['SUBMITTED', 'INACTIVE']]);
        $this->move($location, 'UNDER_REVIEW')->assertConflict();
        $this->move($location, 'REJECTED', ['reason' => 'x', 'public_reason' => 'y', 'category' => 'OTHER'])->assertConflict();
        $this->move($location, 'LIVE')->assertUnprocessable();
        $this->postJson('/api/v1/admin/restaurants/'.$location->public_id.'/status', ['status' => 'SUBMITTED'])->assertUnprocessable();

        $this->assertSame('DRAFT', $this->statusOf($location));
        $this->assertSame(0, AuditEvent::query()->count());

        // Every move the table allows is accepted, and nothing else is.
        foreach (RestaurantStatus::transitions() as $from => $targets) {
            foreach (RestaurantStatus::cases() as $target) {
                $this->assertSame(in_array($target->value, $targets, true), RestaurantStatus::from($from)->canBecome($target), "{$from} → {$target->value}");
            }
            $this->assertNotContains($from, $targets);
        }

        // A stale version writes nothing; repeating the current status is not a change.
        $this->move($location, 'SUBMITTED')->assertOk();
        $this->postJson('/api/v1/admin/restaurants/'.$location->public_id.'/status', ['status' => 'UNDER_REVIEW', 'version' => 1])->assertConflict()->assertJsonPath('error.code', 'stale_update');
        $this->move($location, 'SUBMITTED')->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_a_rejection_needs_a_category_an_explanation_for_the_restaurant_and_an_internal_reason(): void
    {
        $organization = $this->organization('Curry Leaf');
        $location = $this->location($organization, 'Curry Leaf Kitchen', ['status' => 'UNDER_REVIEW']);
        $owner = $this->member($organization, 'OWNER');
        $complete = ['category' => 'INCOMPLETE_DOCUMENTS', 'public_reason' => 'The food-licence copy was missing. Please send it and apply again.', 'reason' => 'Licence scan unreadable; applicant called twice.'];

        foreach (['category', 'public_reason', 'reason'] as $missing) {
            $this->move($location, 'REJECTED', array_diff_key($complete, [$missing => 1]))->assertUnprocessable();
        }
        $this->move($location, 'REJECTED', [...$complete, 'category' => 'BAD_VIBES'])->assertUnprocessable();
        $this->move($location, 'REJECTED', [...$complete, 'public_reason' => '<b>No</b>'])->assertUnprocessable();
        $this->assertSame('UNDER_REVIEW', $this->statusOf($location));

        $this->move($location, 'REJECTED', $complete)->assertOk()
            ->assertJsonPath('status', 'REJECTED')->assertJsonPath('rejection_category', 'INCOMPLETE_DOCUMENTS')->assertJsonPath('status_note', $complete['public_reason'])
            ->assertJsonPath('history.0.action', 'restaurant_location.rejected')->assertJsonPath('history.0.reason', $complete['reason']);

        $event = AuditEvent::query()->sole();
        $this->assertSame($complete['reason'], $event->reason);
        $this->assertEquals(['from' => null, 'to' => 'INCOMPLETE_DOCUMENTS'], $event->changes['rejection_category']);

        // The restaurant reads the explanation meant for it — never the internal reason.
        $this->actingAsPrincipal($owner);
        $staff = $this->getJson('/api/v1/restaurant/locations/'.$location->public_id)->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('status_note', $complete['public_reason'])
            ->assertJsonPath('rejection_category', 'INCOMPLETE_DOCUMENTS')->assertJsonPath('availability.reason', 'LOCATION_NOT_APPROVED');
        $this->assertStringNotContainsString('unreadable', $staff->getContent());

        // Applying again clears the rejection.
        $this->actingAsPrincipal($this->onboarding);
        $this->move($location, 'SUBMITTED')->assertOk()->assertJsonPath('status_note', null)->assertJsonPath('rejection_category', null);
    }

    public function test_suspending_and_reactivating_need_a_reason_and_their_own_permission(): void
    {
        $organization = $this->organization('Riverside');
        $location = $this->hours($this->location($organization, 'Burger Hub', ['slug' => 'burger-hub', 'approved_at' => '2026-09-01 10:00:00+00']), [[null, '09:00', '22:00']]);
        $sibling = $this->hours($this->location($organization, 'Brew & Bites', ['slug' => 'brew-bites']), [[null, '09:00', '22:00']]);

        // Onboarding may approve but not suspend; support may only look.
        $this->move($location, 'SUSPENDED', ['reason' => 'Hygiene complaint'])->assertForbidden();
        $this->actingAsPrincipal($this->support);
        $this->move($location, 'SUSPENDED', ['reason' => 'Hygiene complaint'])->assertForbidden();

        $this->actingAsPrincipal($this->operations);
        $this->move($location, 'SUSPENDED')->assertUnprocessable()->assertJsonPath('error.details.fields.reason.0', 'A reason is required for this change.');
        $this->move($location, 'SUSPENDED', ['reason' => '   '])->assertUnprocessable();
        $this->assertSame('APPROVED', $this->statusOf($location));

        $this->move($location, 'SUSPENDED', ['reason' => 'Hygiene complaint under review', 'public_reason' => 'Suspended while a complaint is reviewed.'])->assertOk()
            ->assertJsonPath('status', 'SUSPENDED')->assertJsonPath('status_note', 'Suspended while a complaint is reviewed.')
            ->assertJsonPath('suspended_at', '2026-10-05T06:30:00+00:00')->assertJsonPath('availability.reason', 'LOCATION_SUSPENDED');

        // One location suspended: it disappears, its sibling keeps trading, the organization is untouched.
        $this->getJson('/api/v1/restaurants/burger-hub')->assertNotFound();
        $this->getJson('/api/v1/restaurants/brew-bites')->assertOk()->assertJsonPath('availability.orderable', true);
        $this->assertSame('APPROVED', $this->statusOf($organization));

        // Approving a suspended restaurant is "reactivating": the suspend permission, not the approve permission.
        $this->actingAsPrincipal($this->onboarding);
        $this->move($location, 'APPROVED')->assertForbidden();
        $this->actingAsPrincipal($this->operations);
        $this->move($location, 'APPROVED')->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('status_note', null)->assertJsonPath('suspended_at', null)
            ->assertJsonPath('approved_at', '2026-09-01T10:00:00+00:00');
        $this->getJson('/api/v1/restaurants/burger-hub')->assertOk();

        $this->assertSame(['restaurant_location.suspended', 'restaurant_location.reactivated'], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame('Hygiene complaint under review', AuditEvent::query()->orderBy('id')->first()->reason);
        $this->assertNotNull($sibling);
    }

    public function test_suspending_the_organization_takes_every_location_away_and_reactivating_brings_them_back(): void
    {
        $organization = $this->organization('Riverside');
        $this->hours($this->location($organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '09:00', '22:00']]);
        $this->hours($this->location($organization, 'Brew & Bites', ['slug' => 'brew-bites']), [[null, '09:00', '22:00']]);
        $draft = $this->location($organization, 'New Outlet', ['slug' => 'new-outlet', 'status' => 'DRAFT']);
        $owner = $this->member($organization, 'OWNER');

        $this->actingAsPrincipal($this->operations);
        $this->move($organization, 'SUSPENDED', ['reason' => 'Payment dispute', 'public_reason' => 'Your account is suspended. Please contact support.'])->assertOk()->assertJsonPath('status', 'SUSPENDED');

        $this->assertSame([], $this->getJson('/api/v1/restaurants')->json('data'));
        $this->getJson('/api/v1/restaurants/burger-hub')->assertNotFound();
        $this->assertSame(['APPROVED', 'APPROVED', 'DRAFT'], DB::table('restaurant_locations')->orderBy('id')->pluck('status')->all(), 'locations keep their own status');

        // Staff still get in, see why, and cannot lift it themselves.
        $this->actingAsPrincipal($owner);
        $this->getJson('/api/v1/restaurant/context')->assertOk()->assertJsonPath('organizations.0.status', 'SUSPENDED')
            ->assertJsonPath('organizations.0.status_note', 'Your account is suspended. Please contact support.')->assertJsonPath('locations.0.availability.reason', 'RESTAURANT_SUSPENDED');
        $this->postJson('/api/v1/admin/restaurant-organizations/'.$organization->public_id.'/status', ['status' => 'APPROVED', 'version' => 2])->assertUnauthorized();

        $this->actingAsPrincipal($this->operations);
        $this->move($organization, 'APPROVED')->assertOk();
        $this->assertSame(['Brew & Bites', 'Burger Hub'], $this->getJson('/api/v1/restaurants')->json('data.*.name'), 'exactly the approved locations return');
        $this->assertSame('DRAFT', $this->statusOf($draft));
    }

    public function test_each_move_needs_the_permission_that_belongs_to_it(): void
    {
        $expected = [
            // from, to, onboarding (view + approve + manage), operations (view + suspend), support (view)
            ['DRAFT', 'SUBMITTED', true, false, false],
            ['DRAFT', 'INACTIVE', true, false, false],
            ['SUBMITTED', 'UNDER_REVIEW', true, false, false],
            ['SUBMITTED', 'APPROVED', true, false, false],
            ['SUBMITTED', 'DRAFT', true, false, false],
            ['UNDER_REVIEW', 'REJECTED', true, false, false],
            ['APPROVED', 'SUSPENDED', false, true, false],
            ['APPROVED', 'INACTIVE', true, false, false],
            ['SUSPENDED', 'APPROVED', false, true, false],
            ['SUSPENDED', 'INACTIVE', true, false, false],
            ['REJECTED', 'SUBMITTED', true, false, false],
            ['INACTIVE', 'DRAFT', true, false, false],
        ];
        $body = ['reason' => 'Matrix test', 'public_reason' => 'Matrix test', 'category' => 'OTHER'];
        $organization = $this->organization();

        foreach ($expected as $index => [$from, $to, $onboarding, $operations, $support]) {
            foreach (['onboarding' => $onboarding, 'operations' => $operations, 'support' => $support] as $who => $allowed) {
                $location = $this->location($organization, "Case {$index} {$who}", ['status' => $from]);
                $this->actingAsPrincipal($this->{$who});

                $response = $this->move($location, $to, $body);

                $this->assertSame($allowed ? 200 : 403, $response->status(), "{$who}: {$from} → {$to}");
                $this->assertSame($allowed ? $to : $from, $this->statusOf($location), "{$who}: {$from} → {$to}");
            }
        }
    }

    public function test_allowed_transitions_tell_each_administrator_what_they_may_do_next(): void
    {
        $location = $this->location($this->organization(), 'Burger Hub');
        $url = '/api/v1/admin/restaurants/'.$location->public_id;

        $this->getJson($url)->assertOk()->assertJsonPath('allowed_transitions', ['INACTIVE'])->assertJsonPath('can_manage', true);
        $this->actingAsPrincipal($this->operations);
        $this->getJson($url)->assertOk()->assertJsonPath('allowed_transitions', ['SUSPENDED'])->assertJsonPath('can_manage', false);
        $this->actingAsPrincipal($this->support);
        $this->getJson($url)->assertOk()->assertJsonPath('allowed_transitions', [])->assertJsonPath('organization.allowed_transitions', []);

        DB::table('restaurant_locations')->update(['status' => 'SUBMITTED']);
        $this->actingAsPrincipal($this->onboarding);
        $this->getJson($url)->assertJsonPath('allowed_transitions', ['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'DRAFT']);
        $this->assertSame(['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'DRAFT'], $this->getJson('/api/v1/admin/restaurants')->json('data.0.allowed_transitions'));
    }

    public function test_a_restaurant_cannot_approve_itself(): void
    {
        $organization = $this->organization('Riverside', 'DRAFT');
        $location = $this->location($organization, 'Burger Hub', ['status' => 'DRAFT']);
        $this->actingAsPrincipal($this->member($organization, 'OWNER'));

        $this->postJson('/api/v1/admin/restaurants/'.$location->public_id.'/status', ['status' => 'SUBMITTED', 'version' => 1])->assertUnauthorized();
        $this->patchJson('/api/v1/restaurant/locations/'.$location->public_id.'/profile', ['version' => 1, 'status' => 'APPROVED'])->assertUnprocessable();
        $this->patchJson('/api/v1/restaurant/locations/'.$location->public_id.'/availability', ['accepting_orders' => true, 'status' => 'APPROVED'])->assertOk();
        $this->putJson('/api/v1/restaurant/locations/'.$location->public_id, ['status' => 'APPROVED'])->assertStatus(405);

        $this->assertSame('DRAFT', $this->statusOf($location));
        $this->assertSame('DRAFT', $this->statusOf($organization));
    }
}
