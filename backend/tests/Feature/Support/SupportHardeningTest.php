<?php

namespace Tests\Feature\Support;

use App\Enums\DifferenceType;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Services\Finance\DifferenceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — what an independent review of the support domain found, each pinned by a test:
 * a record attached by the desk is shown to a requester only when it is their own; a restaurant's money rule follows
 * the links and not only the category; the sweeps reach every case and every serious difference; the clock after a
 * reopen and for cases nobody can answer; the version on a reply that moves the case and on the desk's summary; a
 * restricted case that leaves its author's sight; who a security escalation may name; what a no-op must not record.
 */
class SupportHardeningTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_record_the_desk_attaches_is_shown_to_the_requester_only_when_it_is_their_own(): void
    {
        [, $rahul, , , $own] = $this->paidOrder('+919876543210', '13:00');
        [, , , $foreignPayment, $foreign] = $this->paidOrder('+919876543212', '13:15');
        $case = $this->customerCase($rahul, ['subject' => 'About my order', 'order' => $own['order_number']]);

        // A mistyped number is enough: the desk may read both orders, so it can attach the other customer's order.
        $this->agent([...self::DESK, Permission::AdminOrdersView, Permission::AdminPaymentsView]);
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'ORDER', 'reference' => $foreign['order_number']])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/links', ['type' => 'PAYMENT', 'reference' => $foreignPayment['id']])->assertCreated();
        $this->assertSame(['ORDER', 'ORDER', 'PAYMENT'], array_column($this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links'), 'type'));

        // The customer reads their own order — and nothing of the other customer's order or payment, in any answer.
        foreach ([$this->customerSupport($rahul, 'GET', '/cases/'.$case['id'])->assertOk(), $this->customerSupport($rahul, 'GET', '/cases')->assertOk(),
            $this->customerSupport($rahul, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Any news?'])->assertCreated()] as $answer) {
            $json = json_encode($answer->json());
            $this->assertStringNotContainsString($foreign['order_number'], $json);
            $this->assertStringNotContainsString($foreignPayment['id'], $json);
        }
        $this->assertSame([$own['order_number']], array_column($this->customerSupport($rahul, 'GET', '/cases/'.$case['id'])->json('case.links'), 'reference'));

        // A restaurant's case: an order of its own that the desk attached is shown to it.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $theirs = $this->restaurantCase($this->burgerHub, ['subject' => 'About an order']);
        $this->agent([...self::DESK, Permission::AdminOrdersView]);
        $this->desk('POST', '/cases/'.$theirs['id'].'/links', ['type' => 'ORDER', 'reference' => $own['order_number']])->assertCreated();
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->assertSame([$own['order_number']], array_column($this->restaurantSupport('GET', '/cases/'.$theirs['id'])->assertOk()->json('case.links'), 'reference'));
    }

    public function test_a_case_that_points_at_a_settlement_is_a_money_case_whatever_its_category_says(): void
    {
        $this->seed(RoleSeeder::class);
        $this->commissionPlan(1000);
        $this->completedOrderWith();
        $this->nextWeek();
        $this->asFinance();
        $this->financePost('/settlements/generate', ['restaurant' => (string) $this->burgerHub->public_id])->assertCreated();
        $settlement = $this->settlementOf();

        // The owner (who reads the restaurant's money) opens an ORDER case and attaches the settlement to it.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $mixed = $this->restaurantCase($this->burgerHub, ['subject' => 'An order and its settlement', 'settlement' => $settlement->reference]);
        $plain = $this->restaurantCase($this->burgerHub, ['subject' => 'Only an order question']);
        $this->assertSame('ORDER', $this->caseModel($mixed['id'])->category);

        // Order staff do not read the restaurant's money: the case with the settlement does not exist for them — not in
        // the list, not by id, not for a reply — although its category is not a money category.
        $this->asActor($this->member($this->organization, 'ORDER_STAFF', [$this->burgerHub]));
        $this->assertSame([$plain['id']], array_column($this->restaurantSupport('GET', '/cases')->assertOk()->json('data'), 'id'));
        $this->restaurantSupport('GET', '/cases/'.$mixed['id'])->assertNotFound();
        $this->restaurantSupport('POST', '/cases/'.$mixed['id'].'/messages', ['message' => 'x'])->assertNotFound();

        // The same when the DESK attaches the settlement to a case that staff could read until then.
        $this->agent([...self::DESK, Permission::AdminSettlementsView]);
        $this->desk('POST', '/cases/'.$plain['id'].'/links', ['type' => 'SETTLEMENT', 'reference' => $settlement->reference])->assertCreated();
        $this->asActor($this->member($this->organization, 'ORDER_STAFF', [$this->burgerHub]));
        $this->assertSame([], $this->restaurantSupport('GET', '/cases')->assertOk()->json('data'));
        $this->asActor($this->member($this->organization, 'MANAGER', [$this->burgerHub]));
        $this->assertCount(2, $this->restaurantSupport('GET', '/cases')->assertOk()->json('data'));
    }

    public function test_the_sweeps_reach_every_case_and_every_serious_difference_not_only_the_first_batch(): void
    {
        config(['support.system_cases.sweep_batch' => 2]);
        $this->slaPolicy(['first_response_minutes' => 60, 'resolution_minutes' => 600]);
        [, $headers] = $this->signedIn();
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->customerCase($headers, ['subject' => 'Case '.$n])['id'];
        }
        // Only the LAST case is overdue: the four before it are on track and stay in the set the sweep reads.
        SupportCase::query()->where('public_id', $ids[4])->update(['first_response_due_at' => now()->subMinutes(5)]);
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['ON_TRACK', 'ON_TRACK', 'ON_TRACK', 'ON_TRACK', 'BREACHED'], array_map(fn (string $id): string => $this->caseModel($id)->sla_status->value, $ids));
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(1, SupportCase::query()->whereNotNull('sla_breached_at')->count());

        // Three differences below the threshold come first; the serious one after them still gets its case.
        $differences = app(DifferenceService::class);
        foreach (range(1, 3) as $n) {
            $differences->report(DifferenceType::UnknownReference, 'test:low:'.$n, ['market_id' => $this->market->getKey()]);
        }
        $high = $differences->report(DifferenceType::AmountMismatch, 'test:high:1', ['market_id' => $this->market->getKey(), 'currency' => 'INR', 'expected_minor' => 50000, 'actual_minor' => 49000]);
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['finance-difference:'.$high->public_id], SupportCase::query()->whereNotNull('system_key')->pluck('system_key')->all());
    }

    public function test_the_clock_of_a_reopened_case_starts_at_the_reopening_and_no_first_answer_is_owed_to_nobody(): void
    {
        $this->slaPolicy(['first_response_minutes' => 30, 'resolution_minutes' => 60, 'pause_while_waiting_for_requester' => true]);
        [, $headers] = $this->signedIn();
        $id = $this->customerCase($headers)['id'];
        $this->agent();
        $this->desk('POST', '/cases/'.$id.'/messages', ['message' => 'Which order was it?', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($id)])->assertCreated();
        // Five days of waiting for the customer, then the answer, the resolution — and the customer reopens.
        $this->travel(5)->days();
        $this->customerSupport($headers, 'POST', '/cases/'.$id.'/messages', ['message' => 'The burger order.'])->assertCreated();
        $this->assertSame(5 * 86400, (int) $this->caseModel($id)->sla_paused_seconds);
        $this->agent();
        $this->desk('POST', '/cases/'.$id.'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Explained.', 'expected_version' => $this->version($id)])->assertOk();
        $this->travel(1)->hours();
        $this->customerSupport($headers, 'POST', '/cases/'.$id.'/reopen', ['message' => 'It happened again.'])->assertOk();
        $case = $this->caseModel($id);
        $this->assertSame([0, 60, null, null], [(int) $case->sla_paused_seconds, (int) $case->reopened_at->diffInMinutes($case->resolution_due_at), $case->resolved_at, $case->closed_at]);
        // A re-calculation (the case is given to a queue) keeps the target at reopening + 60 minutes — not + five days.
        $this->desk('POST', '/cases/'.$id.'/assign', ['team' => 'PAYMENTS', 'expected_version' => $this->version($id)])->assertOk();
        $case = $this->caseModel($id);
        $this->assertSame(60, (int) $case->reopened_at->diffInMinutes($case->resolution_due_at));

        // An internal case has no requester: no first answer is due, and the clock never calls one missed.
        $internal = $this->desk('POST', '/cases', ['category' => 'TECHNICAL', 'subject' => 'Tablet offline at a counter', 'note' => 'Reported by phone.', 'market' => $this->market->slug])->assertCreated()->json('case.id');
        $this->assertSame([null, 60], [$this->caseModel($internal)->first_response_due_at, (int) $this->caseModel($internal)->created_at->diffInMinutes($this->caseModel($internal)->resolution_due_at)]);
        $this->travel(45)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertNull($this->caseModel($internal)->sla_breached_at);
    }

    public function test_a_reply_that_moves_the_case_and_the_desks_summary_carry_the_version_that_was_read(): void
    {
        [, $headers] = $this->signedIn();
        $id = $this->customerCase($headers)['id'];
        $this->agent();
        $path = '/cases/'.$id;
        $reply = ['message' => 'Which order was it?', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'client_message_id' => 'hardening-reply-0001'];

        // Without a version a reply may not also move the case; with an old one it is refused and nothing is written.
        $this->desk('POST', $path.'/messages', $reply)->assertStatus(422);
        $this->desk('POST', $path.'/wait', ['for' => 'FINANCE', 'expected_version' => 1])->assertOk();   // a colleague moved the case meanwhile
        $this->desk('POST', $path.'/messages', $reply + ['expected_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'case_version_conflict');
        $this->assertSame([1, 'WAITING_FOR_FINANCE'], [SupportCaseMessage::query()->count(), $this->caseModel($id)->status->value]);
        $sent = $this->desk('POST', $path.'/messages', $reply + ['expected_version' => $this->version($id)])->assertCreated()->json('message.id');
        // The same reply again (a lost answer): the same message, although its version is old by now.
        $this->assertSame($sent, $this->desk('POST', $path.'/messages', $reply + ['expected_version' => 2])->assertCreated()->json('message.id'));
        $this->assertSame([2, 'WAITING_FOR_CUSTOMER'], [SupportCaseMessage::query()->count(), $this->caseModel($id)->status->value]);
        // A plain reply (no change of state) needs no version.
        $this->desk('POST', $path.'/messages', ['message' => 'One more thing.', 'visibility' => 'EXTERNAL'])->assertCreated();

        // The summary: said with the version, refused on an old one, and it moves the version.
        $before = $this->version($id);
        $this->desk('POST', $path.'/annotations', ['summary' => 'Waiting for the order number.'])->assertStatus(422);
        $this->desk('POST', $path.'/annotations', ['summary' => 'Waiting for the order number.', 'expected_version' => $before])->assertOk();
        $this->assertSame($before + 1, $this->version($id));
        $this->desk('POST', $path.'/annotations', ['summary' => 'Overwritten by a stale screen.', 'expected_version' => $before])->assertStatus(409);
        $this->assertSame('Waiting for the order number.', $this->caseModel($id)->summary);
    }

    public function test_a_case_of_a_restricted_category_leaves_its_authors_sight_without_an_error_and_a_security_escalation_names_only_somebody_who_can_open_it(): void
    {
        $this->agent();   // works cases; holds no restricted class
        $made = $this->desk('POST', '/cases', ['category' => 'SECURITY', 'subject' => 'A report by phone', 'note' => 'Somebody used an account.', 'market' => $this->market->slug])->assertCreated()->json('case');
        $this->assertSame([false, 'RESTRICTED', 'SECURITY'], [$made['visible'], $made['classification'], $made['restricted_class']]);
        $this->assertSame(1, SupportCase::query()->count());
        $this->assertArrayNotHasKey('subject', $made);
        $this->desk('GET', '/cases/'.$made['id'])->assertForbidden()->assertJsonPath('error.code', 'case_restricted');

        // Escalating for security: the person named must be able to open the case AFTER it became restricted.
        [, $headers] = $this->signedIn();
        $id = $this->customerCase($headers)['id'];
        $colleague = $this->agent();
        $cleared = $this->agent([...self::DESK, Permission::AdminSupportRestrictedSecurity]);
        $this->agent();
        $escalation = ['reason' => 'SECURITY', 'level' => 'L3', 'note' => 'This looks like an account take-over.', 'expected_version' => $this->version($id)];
        $this->desk('POST', '/cases/'.$id.'/escalate', $escalation + ['admin' => (string) $colleague->public_id])->assertStatus(422)->assertJsonPath('error.details.fields.admin.0', fn ($m) => str_contains((string) $m, 'restricted'));
        $this->assertSame('STANDARD', $this->caseModel($id)->classification);
        $this->desk('POST', '/cases/'.$id.'/escalate', $escalation + ['admin' => (string) $cleared->public_id])->assertOk()->assertJsonPath('case.visible', false);
        $this->assertSame(['RESTRICTED', 'SECURITY', $cleared->getKey()], [$this->caseModel($id)->classification, $this->caseModel($id)->restricted_class, $this->caseModel($id)->assigned_admin_id]);
    }

    public function test_a_cancel_that_changes_nothing_records_nothing_and_the_people_of_a_market_are_named_only_to_its_own_desk(): void
    {
        [, $headers] = $this->signedIn();
        $id = $this->customerCase($headers)['id'];
        $this->customerSupport($headers, 'POST', '/cases/'.$id.'/close', ['intent' => 'WITHDRAW'])->assertOk();
        $this->agent();
        $this->desk('POST', '/cases/'.$id.'/cancel', ['resolution_code' => 'NO_ACTION_REQUIRED', 'note' => 'Nothing to do.', 'expected_version' => $this->version($id)])->assertOk()->assertJsonPath('case.resolution.code', 'USER_WITHDREW');
        $this->assertSame(0, AuditEvent::query()->where('action', 'support.case_cancelled')->count());
        // A duplicate is cancelled with the case it duplicates; it is not a way to resolve a case.
        $other = $this->customerCase($headers)['id'];
        $this->desk('POST', '/cases/'.$other.'/resolve', ['resolution_code' => 'DUPLICATE_CASE', 'note' => 'Same as before.', 'expected_version' => 1])->assertStatus(422);

        // The people list: for the caller's own market, not for another one.
        $elsewhere = Market::factory()->create(['country_code' => 'AE', 'slug' => 'uae']);
        $this->agent(self::DESK, $this->market);
        $this->desk('GET', '/agents?market='.$this->market->slug)->assertOk();
        $this->desk('GET', '/agents?market='.$elsewhere->slug)->assertForbidden();
        $this->desk('GET', '/agents?market=nowhere')->assertStatus(422);
    }
}
