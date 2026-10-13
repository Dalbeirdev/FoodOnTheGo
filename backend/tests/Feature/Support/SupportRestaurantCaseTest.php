<?php

namespace Tests\Feature\Support;

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayout;
use App\Models\SupportCase;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — a restaurant's support cases: its own, scoped by organization and location, money cases only with the
 * finance permission, and never an internal note.
 */
class SupportRestaurantCaseTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    private RestaurantLocation $pizzaPoint;

    private RestaurantLocation $secondKitchen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
        // A second location of the same organization, and another organization altogether.
        [$this->pizzaPoint] = $this->otherRestaurant();
        $this->secondKitchen = $this->eligible('Second Kitchen', 28.57, 77.37, organization: $this->organization('Second Kitchen'));
    }

    public function test_a_restaurant_opens_a_case_about_its_own_order_and_talks_with_support(): void
    {
        [, , , , $order] = $this->paidOrder($this->nextPhone(), '13:00');
        $owner = $this->asActor($this->member($this->organization, 'OWNER'));
        $catalog = $this->restaurantSupport('GET', '/catalog')->assertOk()->json();
        $this->assertTrue($catalog['can']['create']);
        $this->assertEqualsCanonicalizing(['Burger Hub', 'Pizza Point'], array_column($catalog['locations'], 'name'));
        $this->assertContains('SETTLEMENT', array_column($catalog['categories'], 'code'));
        $this->assertSame([], array_values(array_intersect(['DISPUTE', 'PAYMENT', 'REFUND'], array_column($catalog['categories'], 'code'))));
        $this->assertSame(['ORDER', 'SETTLEMENT', 'PAYOUT'], $catalog['link_types']);

        $case = $this->restaurantCase($this->burgerHub, ['category' => 'ORDER', 'subcategory' => 'CUSTOMER_DISPUTE', 'subject' => 'Customer says the order was wrong', 'message' => 'We packed it as ordered.', 'order' => $order['order_number']]);
        $this->assertSame(['OPEN', 'Burger Hub', 'ORDER', $order['order_number']], [$case['status'], $case['location']['name'], $case['links'][0]['type'], $case['links'][0]['order_number']]);
        foreach (['priority', 'assigned', 'sla', 'resolution', 'summary', 'classification', 'version', 'requester'] as $internal) {
            $this->assertArrayNotHasKey($internal, $case, $internal);
        }
        $model = $this->caseModel($case['id']);
        $this->assertSame(['RESTAURANT', 'RESTAURANT_DASHBOARD', 'RESTAURANT_SUPPORT', $owner->getKey(), $this->organization->getKey(), $this->burgerHub->getKey()],
            [$model->requester_type->value, $model->source->value, $model->assigned_team->value, $model->requester_restaurant_user_id, $model->organization_id, $model->location_id]);

        // Support replies and waits for the restaurant; a COLLEAGUE of the same location answers — the case is the restaurant's.
        $agent = $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'NOTE-4471 customer has a history of claims', 'visibility' => 'INTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'Could you send the kitchen ticket number?', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_RESTAURANT', 'expected_version' => $this->version($case['id'])])->assertCreated();
        $manager = $this->asActor($this->member($this->organization, 'MANAGER', [$this->burgerHub]));
        $list = $this->restaurantSupport('GET', '/cases')->assertOk()->json();
        $this->assertSame([1, 'WAITING_FOR_YOU', true], [$list['unread'], $list['data'][0]['status'], $list['data'][0]['unread']]);
        $reply = $this->restaurantSupport('POST', '/cases/'.$case['id'].'/messages', ['message' => 'Ticket 88.', 'client_message_id' => 'rest-msg-000001'])->assertCreated()->json();
        $this->assertSame(['YOU', $manager->name, 'OPEN'], [$reply['message']['author'], $reply['message']['author_name'], $reply['case']['status']]);
        $thread = $this->restaurantSupport('GET', '/cases/'.$case['id'].'/messages')->assertOk()->json('data');
        $this->assertSame(['YOU', 'SUPPORT', 'YOU'], array_column($thread, 'author'));
        $this->assertSame([$owner->name, null, $manager->name], array_column($thread, 'author_name'));
        $everything = json_encode([$list, $reply, $thread, $this->restaurantSupport('GET', '/cases/'.$case['id'])->assertOk()->json()]);
        foreach (['4471', 'history of claims', $agent->name, 'INTERNAL'] as $leak) {
            $this->assertStringNotContainsString($leak, $everything, $leak);
        }
        foreach (['/notes', '/events', '/links'] as $path) {
            $this->restaurantSupport('GET', '/cases/'.$case['id'].$path)->assertNotFound();
        }
    }

    public function test_another_restaurants_case_does_not_exist_and_a_location_member_sees_only_their_location(): void
    {
        $this->asActor($this->member($this->organization, 'OWNER'));
        $burger = $this->restaurantCase($this->burgerHub);
        $pizza = $this->restaurantCase($this->pizzaPoint, ['subject' => 'Pizza Point question']);
        $whole = $this->restaurantSupport('POST', '/cases', ['category' => 'ACCOUNT', 'subject' => 'About our account', 'message' => 'For the whole organization.'])->assertCreated()->json('case');
        $this->assertNull($whole['location']);
        $this->assertCount(3, $this->restaurantSupport('GET', '/cases')->assertOk()->json('data'));
        $this->assertSame([$pizza['id']], array_column($this->restaurantSupport('GET', '/cases?location='.$this->pizzaPoint->public_id)->assertOk()->json('data'), 'id'));

        // Another organization: nothing of Riverside exists for it — by id, by number, or by naming a location.
        $this->asActor($this->member($this->secondKitchen->organization, 'OWNER'));
        $this->assertSame([], $this->restaurantSupport('GET', '/cases')->assertOk()->json('data'));
        foreach ([$burger['id'], $burger['case_number'], $whole['id']] as $reference) {
            $this->restaurantSupport('GET', '/cases/'.$reference)->assertNotFound()->assertJsonPath('error.code', 'case_not_found');
            $this->restaurantSupport('GET', '/cases/'.$reference.'/messages')->assertNotFound();
            $this->restaurantSupport('POST', '/cases/'.$reference.'/messages', ['message' => 'Let me in'])->assertNotFound();
            $this->restaurantSupport('POST', '/cases/'.$reference.'/close', ['intent' => 'WITHDRAW'])->assertNotFound();
        }
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->burgerHub->public_id, 'category' => 'ORDER', 'subject' => 'x', 'message' => 'y'])->assertNotFound();
        $this->restaurantSupport('POST', '/cases', ['organization' => (string) $this->organization->public_id, 'category' => 'ACCOUNT', 'subject' => 'x', 'message' => 'y'])->assertStatus(422);

        // A manager of ONE location: that location's cases only — not the sister location's, not the organization's.
        $this->asActor($this->member($this->organization, 'MANAGER', [$this->burgerHub]));
        $this->assertSame([$burger['id']], array_column($this->restaurantSupport('GET', '/cases')->assertOk()->json('data'), 'id'));
        $this->restaurantSupport('GET', '/cases/'.$pizza['id'])->assertNotFound();
        $this->restaurantSupport('GET', '/cases/'.$whole['id'])->assertNotFound();
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->pizzaPoint->public_id, 'category' => 'ORDER', 'subject' => 'x', 'message' => 'y'])->assertNotFound();
        $this->restaurantSupport('POST', '/cases', ['category' => 'ACCOUNT', 'subject' => 'x', 'message' => 'y'])->assertStatus(422);
        $this->assertSame(['Burger Hub'], array_column($this->restaurantSupport('GET', '/catalog')->assertOk()->json('locations'), 'name'));
        $this->assertSame(3, SupportCase::query()->count());

        // An order of the sister location cannot be attached to a case of this one; nor one of another restaurant.
        [, , , , $pizzaOrder] = $this->paidOrder($this->nextPhone(), '13:15');
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->pizzaPoint->public_id, 'category' => 'ORDER', 'subject' => 'x', 'message' => 'y', 'order' => $pizzaOrder['order_number']])->assertStatus(422);
        $this->asActor($this->member($this->secondKitchen->organization, 'OWNER'));
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->secondKitchen->public_id, 'category' => 'ORDER', 'subject' => 'x', 'message' => 'y', 'order' => $pizzaOrder['order_number']])->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
        $this->assertSame(3, SupportCase::query()->count());
    }

    public function test_money_cases_need_the_finance_permission_and_show_the_settlement_only_to_those_who_may_read_it(): void
    {
        $this->seed(RoleSeeder::class);
        $this->completedOrderWith();
        $this->nextWeek();
        $this->fakePayouts();
        $this->payoutProfile();
        $this->asFinance();
        $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id])->assertCreated();
        $settlement = $this->settlementOf();
        $this->financePost('/settlements/'.$settlement->public_id.'/approve')->assertOk();
        $this->financePost('/settlements/'.$settlement->public_id.'/payouts', ['confirm' => true])->assertCreated();
        $payout = RestaurantPayout::query()->firstOrFail();

        $this->asActor($this->member($this->organization, 'OWNER'));
        $case = $this->restaurantCase($this->burgerHub, ['category' => 'SETTLEMENT', 'subcategory' => 'SETTLEMENT_MISMATCH', 'subject' => 'Why is the payout lower?', 'message' => 'We expected more.', 'settlement' => $settlement->reference, 'payout' => $payout->supportReference()]);
        $this->assertSame(['SETTLEMENT', 'PAYOUT'], array_column($case['links'], 'type'));
        // The settlement's payout was started: the restaurant reads its own word for that, live from the settlement.
        $this->assertSame([$settlement->reference, 'PAYMENT_PENDING', (int) $settlement->net_payable_minor], [$case['links'][0]['reference'], $case['links'][0]['status'], $case['links'][0]['net_payable']['amount']]);
        $this->assertSame('PROCESSING', $case['links'][1]['status']);
        $this->assertSame('FINANCE', $this->caseModel($case['id'])->assigned_team->value);
        $order = $this->restaurantCase($this->burgerHub, ['subject' => 'An order question']);

        // Order staff hold the support permissions and no finance permission: the money case does not exist for them,
        // money categories are not offered, and they cannot open one or attach a settlement.
        $this->asActor($this->member($this->organization, 'ORDER_STAFF', [$this->burgerHub]));
        $this->assertSame([$order['id']], array_column($this->restaurantSupport('GET', '/cases')->assertOk()->json('data'), 'id'));
        $this->restaurantSupport('GET', '/cases/'.$case['id'])->assertNotFound();
        $this->restaurantSupport('POST', '/cases/'.$case['id'].'/messages', ['message' => 'x'])->assertNotFound();
        $this->assertSame([], array_values(array_intersect(['SETTLEMENT', 'PAYOUT'], array_column($this->restaurantSupport('GET', '/catalog')->assertOk()->json('categories'), 'code'))));
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->burgerHub->public_id, 'category' => 'PAYOUT', 'subject' => 'x', 'message' => 'y'])->assertForbidden();
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->burgerHub->public_id, 'category' => 'ORDER', 'subject' => 'x', 'message' => 'y', 'settlement' => $settlement->reference])->assertForbidden();

        // A viewer reads cases and writes nothing; a role without the support permission has no support API.
        $this->asActor($this->member($this->organization, 'VIEWER', [$this->burgerHub]));
        $this->restaurantSupport('GET', '/cases/'.$order['id'])->assertOk()->assertJsonPath('case.can.reply', false);
        $this->restaurantSupport('POST', '/cases/'.$order['id'].'/messages', ['message' => 'x'])->assertForbidden();
        $this->restaurantSupport('POST', '/cases', ['location' => (string) $this->burgerHub->public_id, 'category' => 'ORDER', 'subject' => 'x', 'message' => 'y'])->assertForbidden();
        $this->assertFalse($this->restaurantSupport('GET', '/catalog')->assertOk()->json('can.create'));
        $bare = app(RoleService::class)->define(PrincipalType::RestaurantUser, 'NO_SUPPORT', 'No support', [Permission::RestaurantOrdersView]);
        $this->asActor($this->member($this->organization, $bare->code, [$this->burgerHub]));
        $this->restaurantSupport('GET', '/cases')->assertForbidden();
        $this->restaurantSupport('GET', '/cases/'.$order['id'])->assertForbidden();

        // The seeded roles: owner and manager everything, order staff and menu manager view + create, viewer view.
        $roles = RoleSeeder::restaurantRoles();
        $holds = fn (string $role, Permission $p): bool => in_array($p, $roles[$role][1], true);
        foreach (['OWNER' => [true, true], 'MANAGER' => [true, true], 'ORDER_STAFF' => [true, true], 'MENU_MANAGER' => [true, true], 'VIEWER' => [true, false]] as $role => [$view, $create]) {
            $this->assertSame([$view, $create], [$holds($role, Permission::RestaurantSupportView), $holds($role, Permission::RestaurantSupportCreate)], $role);
        }
    }

    public function test_nothing_the_server_decides_is_accepted_from_a_restaurant(): void
    {
        $this->asActor($this->member($this->organization, 'OWNER'));
        $base = ['location' => (string) $this->burgerHub->public_id, 'category' => 'ORDER', 'subject' => 'Help', 'message' => 'Please'];
        foreach (['priority' => 'URGENT', 'status' => 'RESOLVED', 'requester_type' => 'ADMIN', 'restaurant_user_id' => 1, 'assigned_team' => 'FINANCE', 'visibility' => 'INTERNAL', 'classification' => 'RESTRICTED', 'market' => 'india',
            'payment' => 'PAY-1', 'refund' => 'RFD-1', 'customer' => 'x', 'dispute' => 'x', 'note' => 'x'] as $field => $value) {
            $this->restaurantSupport('POST', '/cases', $base + [$field => $value])->assertStatus(422)->assertJsonPath('error.details.fields.'.$field.'.0', 'This field is not accepted: it is decided by the server or unknown.');
        }
        foreach ([['category' => 'DISPUTE'], ['category' => 'PAYMENT'], ['category' => 'ORDER', 'subcategory' => 'PAYMENT_PENDING']] as $bad) {
            $this->restaurantSupport('POST', '/cases', $bad + $base)->assertStatus(422);
        }
        $this->assertSame(0, SupportCase::query()->count());
        $case = $this->restaurantCase();
        $this->restaurantSupport('POST', '/cases/'.$case['id'].'/messages', ['message' => 'Hi', 'visibility' => 'INTERNAL'])->assertStatus(422);
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->restaurantSupport($method, '/cases/'.$case['id'], ['status' => 'CLOSED'])->assertStatus(405);
        }
        // A restaurant's session is not an administrator's and not a customer's.
        $this->restaurantSupport('GET', '/cases')->assertOk();
        $this->getJson('/api/v1/admin/support/cases')->assertUnauthorized();
        $this->getJson('/api/v1/customer/support/cases')->assertUnauthorized();
    }
}
