<?php

namespace Tests\Feature\Support;

use App\Enums\Permission;
use App\Enums\SupportCaseStatus;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\SupportCase;
use App\Models\SupportCaseAssignment;
use App\Models\SupportCaseEscalation;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseInternalNote;
use App\Models\SupportCaseMessage;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — the service desk: the queue, assignment, priority, the state machine, escalation, resolution, internal
 * notes, and who may do which of them where.
 */
class SupportDeskTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_queue_orders_by_urgency_then_age_and_its_filters_counts_and_search_agree(): void
    {
        [, $rahul, , , $order] = $this->paidOrder('+919876543210', '13:00');
        [, $asha] = $this->signedIn('+919876543212');
        $late = $this->customerCase($rahul, ['subject' => 'Late order', 'order' => $order['order_number']]);
        $this->travel(5)->minutes();
        $charged = $this->customerCase($asha, ['category' => 'PAYMENT', 'subcategory' => 'PAYMENT_CHARGED_NO_ORDER', 'subject' => 'Charged, no order']);
        $this->travel(5)->minutes();
        $promo = $this->customerCase($asha, ['category' => 'PROMOTION', 'subject' => 'Promo code']);
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->travel(5)->minutes();
        $restaurant = $this->restaurantCase($this->burgerHub, ['subject' => 'Tablet is offline', 'category' => 'TECHNICAL']);

        $me = $this->agent();
        $rows = $this->desk('GET', '/cases')->assertOk()->json('data');
        // HIGH first (the payment without an order), then NORMAL by age, LOW last.
        $this->assertSame([$charged['id'], $late['id'], $restaurant['id'], $promo['id']], array_column($rows, 'id'));
        $this->assertSame(['HIGH', 'NORMAL', 'NORMAL', 'LOW'], array_column($rows, 'priority'));
        $this->assertSame(['PAYMENTS', 'CUSTOMER_SUPPORT', 'TECHNICAL_OPERATIONS', 'CUSTOMER_SUPPORT'], array_map(fn (array $r): string => $r['assigned']['team'], $rows));
        $this->assertSame(['CUSTOMER', 'CUSTOMER', 'RESTAURANT', 'CUSTOMER'], array_map(fn (array $r): string => $r['requester']['type'], $rows));
        // A row of the queue never carries a conversation, a note or a customer's contact.
        $this->assertStringNotContainsString('40 minutes', json_encode($rows));
        $this->assertSame([null, null], [$rows[0]['requester']['name'] ?? null, $rows[0]['requester']['phone'] ?? null]);

        $ids = fn (string $query): array => array_column($this->desk('GET', '/cases?'.$query)->assertOk()->json('data'), 'id');
        $this->assertSame([$charged['id']], $ids('filter[priority]=HIGH'));
        $this->assertSame([$charged['id']], $ids('filter[team]=PAYMENTS'));
        $this->assertSame([$restaurant['id']], $ids('filter[requester]=RESTAURANT'));
        $this->assertSame([$restaurant['id']], $ids('filter[restaurant]='.$this->burgerHub->public_id));
        $this->assertSame([$late['id']], $ids('filter[link_type]=ORDER'));
        $this->assertSame([$promo['id']], $ids('filter[category]=promotion'));
        $this->assertSame([$restaurant['id'], $promo['id'], $charged['id'], $late['id']], $ids('sort=-created_at'));
        $this->assertCount(2, $this->desk('GET', '/cases?page[size]=2')->assertOk()->json('data'));
        $this->assertSame(4, $this->desk('GET', '/cases?page[size]=2')->json('meta.total'));
        $this->desk('GET', '/cases?filter[nope]=1')->assertStatus(422);

        // Search: exact references only.
        $this->assertSame([$late['id']], $ids('q='.$late['case_number']));
        $this->assertSame([$late['id']], $ids('q='.strtolower($order['order_number'])));
        $this->assertSame([$restaurant['id']], $ids('q=burger'));
        $this->assertSame([], $ids('q=9876543210'));
        $this->assertSame([], $ids('q=Late'));

        // Work on them and read the queues: counts and filters use the same definitions.
        $this->desk('POST', '/cases/'.$late['id'].'/assign', ['admin' => (string) $me->public_id, 'expected_version' => 1])->assertOk()->assertJsonPath('case.status', 'ASSIGNED');
        $this->desk('POST', '/cases/'.$promo['id'].'/messages', ['message' => 'Which code?', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($promo['id'])])->assertCreated();
        $queues = $this->desk('GET', '/queues')->assertOk()->json();
        $this->assertSame([4, 4, 3, 1, 1, 0, 3], [$queues['total'], $queues['queues']['open'], $queues['queues']['unassigned'], $queues['queues']['mine'], $queues['queues']['waiting_customer'], $queues['queues']['escalated'], $queues['queues']['awaiting_reply']]);
        $this->assertSame([$late['id']], $ids('filter[queue]=mine'));
        $this->assertSame([$promo['id']], $ids('filter[queue]=waiting_customer'));
        $this->assertEqualsCanonicalizing([$charged['id'], $late['id'], $restaurant['id']], $ids('filter[queue]=awaiting_reply'));
        $metrics = $this->desk('GET', '/metrics')->assertOk()->json();
        $this->assertSame([4, 4, 3, 0, 1, 1, 1, 4], [$metrics['total'], $metrics['open'], $metrics['unassigned'], $metrics['urgent'], $metrics['high'], $metrics['waiting']['WAITING_FOR_CUSTOMER'], $metrics['by_category']['PAYMENT'], $metrics['sla_not_measured']]);
        $this->assertNotNull($metrics['first_response_minutes_avg_30d']);
        $this->assertNull($metrics['resolution_minutes_avg_30d']);
    }

    public function test_assignment_is_recorded_as_history_and_two_stale_assignments_cannot_both_win(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $first = $this->agent();
        $second = $this->adminWith(self::DESK);
        $viewer = $this->adminWith([Permission::AdminSupportView]);

        // Two administrators read version 1; both try to take the case.
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['admin' => (string) $first->public_id, 'expected_version' => 1])->assertOk()->assertJsonPath('case.assigned.admin.name', $first->name);
        $this->asActor($second);
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['admin' => (string) $second->public_id, 'expected_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'case_version_conflict')->assertJsonPath('error.details.current_version', 2);
        $this->assertSame($first->getKey(), $this->caseModel($case['id'])->assigned_admin_id);

        // Reassigned to another queue and person, then taken back: every change is a row of the history.
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['team' => 'PAYMENTS', 'admin' => (string) $second->public_id, 'note' => 'Payment question', 'expected_version' => 2])->assertOk();
        $after = $this->desk('POST', '/cases/'.$case['id'].'/assign', ['unassign' => true, 'expected_version' => 3])->assertOk()->json('case');
        $this->assertSame(['OPEN', 'PAYMENTS', null], [$after['status'], $after['assigned']['team'], $after['assigned']['admin']]);
        $this->assertSame([['CUSTOMER_SUPPORT', null, 'ROUTING'], ['CUSTOMER_SUPPORT', $first->name, 'ADMIN'], ['PAYMENTS', $second->name, 'ADMIN'], ['PAYMENTS', null, 'ADMIN']],
            array_map(fn (array $a): array => [$a['team'], $a['admin'], $a['source']], $after['assignments']));
        $this->assertSame(4, SupportCaseAssignment::query()->count());
        $this->assertSame(3, AuditEvent::query()->where('action', 'support.case_assigned')->count());
        $this->assertSame(['CASE_CREATED', 'CUSTOMER_MESSAGE', 'CASE_ASSIGNED', 'STATUS_CHANGED', 'CASE_REASSIGNED', 'CASE_REASSIGNED', 'STATUS_CHANGED'], array_column($this->desk('GET', '/cases/'.$case['id'].'/events')->assertOk()->json('data'), 'type'));

        // Nobody, a person who cannot work cases, a person who does not exist: refused. And assigning needs its own permission.
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['expected_version' => 4])->assertStatus(422);
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['admin' => (string) $viewer->public_id, 'expected_version' => 4])->assertStatus(422)->assertJsonPath('error.details.fields.admin.0', 'Choose an administrator who may work cases of this market.');
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['admin' => '11111111-1111-4111-8111-111111111111', 'expected_version' => 4])->assertStatus(422);
        $this->assertEqualsCanonicalizing([$first->name, $second->name], array_values(array_intersect(array_column($this->desk('GET', '/agents')->assertOk()->json('agents'), 'name'), [$first->name, $second->name, $viewer->name])));
        $this->agent([Permission::AdminSupportView, Permission::AdminSupportManage]);
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['admin' => (string) $first->public_id, 'expected_version' => 4])->assertForbidden();
        $this->desk('GET', '/agents')->assertForbidden();
    }

    public function test_a_status_moves_only_through_the_state_machine(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $this->agent();
        $v = fn (): int => $this->version($case['id']);
        $post = fn (string $action, array $body = []) => $this->desk('POST', '/cases/'.$case['id'].'/'.$action, $body + ['expected_version' => $v()]);

        // No request carries a status: there is no route to set one and no field for one.
        foreach (['status', 'set-status', 'transition', 'update'] as $path) {
            $this->desk('POST', '/cases/'.$case['id'].'/'.$path, ['status' => 'RESOLVED'])->assertNotFound();
        }
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->desk($method, '/cases/'.$case['id'], ['status' => 'RESOLVED'])->assertStatus(405);
        }
        foreach (['start', 'wait', 'resolve', 'close', 'reopen', 'cancel', 'assign', 'priority', 'escalate'] as $action) {
            $this->desk('POST', '/cases/'.$case['id'].'/'.$action, ['status' => 'CLOSED', 'expected_version' => 1])->assertStatus(422);
        }
        $this->assertSame(SupportCaseStatus::Open, $this->caseModel($case['id'])->status);

        // The working steps.
        $post('start')->assertOk()->assertJsonPath('case.status', 'IN_PROGRESS');
        foreach (['PROVIDER', 'FINANCE', 'INTERNAL', 'CUSTOMER'] as $for) {
            $post('wait', ['for' => $for, 'reason' => 'Waiting'])->assertOk()->assertJsonPath('case.status', 'WAITING_FOR_'.$for);
        }
        $post('wait', ['for' => 'SOMEBODY'])->assertStatus(422);
        $post('resume')->assertOk()->assertJsonPath('case.status', 'IN_PROGRESS');

        // What the machine refuses: closing or reopening a case that is not resolved.
        $post('close')->assertStatus(409)->assertJsonPath('error.code', 'case_invalid_transition')->assertJsonPath('error.details.current_status', 'IN_PROGRESS');
        $post('reopen', ['reason' => 'Not resolved yet'])->assertStatus(409)->assertJsonPath('error.code', 'case_invalid_transition');

        // Resolution needs a code and a note; then close; then nothing but a reopening.
        $post('resolve', ['resolution_code' => 'ANSWERED'])->assertStatus(422);
        $post('resolve', ['resolution_code' => 'NOT_A_CODE', 'note' => 'x y z'])->assertStatus(422);
        $post('resolve', ['resolution_code' => 'USER_WITHDREW', 'note' => 'They left'])->assertStatus(422);
        $resolved = $post('resolve', ['resolution_code' => 'ORDER_RESOLVED', 'note' => 'The restaurant handed the order over.'])->assertOk()->json('case');
        $this->assertSame(['RESOLVED', 'ORDER_RESOLVED', 'The restaurant handed the order over.'], [$resolved['status'], $resolved['resolution']['code'], $resolved['resolution']['note']]);
        $this->assertSame([false, false, true, true, false], [$resolved['can']['resolve'], $resolved['can']['start'], $resolved['can']['close'], $resolved['can']['reopen'], $resolved['can']['assign']]);
        $post('start')->assertStatus(409);
        $post('priority', ['priority' => 'HIGH', 'reason' => 'Too late'])->assertStatus(409)->assertJsonPath('error.code', 'case_not_open');
        $post('close')->assertOk()->assertJsonPath('case.status', 'CLOSED');
        $post('resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Again'])->assertStatus(409);
        $post('cancel', ['resolution_code' => 'OTHER', 'note' => 'Too late'])->assertStatus(409);
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'Anything else?', 'visibility' => 'EXTERNAL'])->assertStatus(409)->assertJsonPath('error.code', 'case_not_open');

        // An administrator reopens with a reason: the ending is cleared, the history stays.
        $post('reopen', [])->assertStatus(422);
        $reopened = $post('reopen', ['reason' => 'The customer called again.'])->assertOk()->json('case');
        $this->assertSame(['REOPENED', null, 1], [$reopened['status'], $reopened['resolution'], $reopened['reopen_count']]);
        $post('start')->assertOk();

        // Cancelling: a reason from the list; a duplicate names the case it duplicates.
        $other = $this->customerCase($headers, ['subject' => 'The same thing again']);
        $post('cancel', ['resolution_code' => 'ANSWERED', 'note' => 'x y z'])->assertStatus(422);
        $post('cancel', ['resolution_code' => 'DUPLICATE_CASE', 'note' => 'Same as the other one'])->assertStatus(422);
        $post('cancel', ['resolution_code' => 'DUPLICATE_CASE', 'note' => 'Same as itself', 'duplicate_of' => $case['case_number']])->assertStatus(422);
        $post('cancel', ['resolution_code' => 'DUPLICATE_CASE', 'note' => 'Same as the other one', 'duplicate_of' => 'CS-ZZZZZZZZ'])->assertNotFound();
        $cancelled = $post('cancel', ['resolution_code' => 'DUPLICATE_CASE', 'note' => 'Same as the other one', 'duplicate_of' => $other['case_number']])->assertOk()->json('case');
        $this->assertSame(['CANCELLED', 'DUPLICATE_CASE', $other['case_number']], [$cancelled['status'], $cancelled['resolution']['code'], $cancelled['resolution']['duplicate_of']]);
        $post('reopen', ['reason' => 'It was a mistake'])->assertStatus(409)->assertJsonPath('error.code', 'case_invalid_transition');

        // The timeline: every step, in order, numbered — and the database refuses to rewrite it.
        $events = $this->desk('GET', '/cases/'.$case['id'].'/events')->assertOk()->json('data');
        $this->assertSame(range(1, count($events)), array_column($events, 'sequence'));
        $this->assertSame(['IN_PROGRESS', 'WAITING_FOR_PROVIDER', 'WAITING_FOR_FINANCE', 'WAITING_FOR_INTERNAL', 'WAITING_FOR_CUSTOMER', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'REOPENED', 'IN_PROGRESS', 'CANCELLED'],
            array_values(array_filter(array_column($events, 'to_status'), fn ($s): bool => $s !== null && $s !== 'OPEN')));
        foreach (['support_case_events' => SupportCaseEvent::query()->firstOrFail(), 'support_case_messages' => SupportCaseMessage::query()->firstOrFail()] as $table => $row) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $row->getKey())->update($table === 'support_case_events' ? ['type' => 'FORGED'] : ['body' => 'rewritten']));
                $this->fail($table.' was rewritten');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_priority_and_escalation_are_different_things_and_both_are_audited(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $me = $this->agent();
        $finance = $this->adminWith([...self::DESK, Permission::AdminSupportRestrictedFinance]);

        $this->desk('POST', '/cases/'.$case['id'].'/priority', ['priority' => 'URGENT', 'expected_version' => 1])->assertStatus(422);
        $urgent = $this->desk('POST', '/cases/'.$case['id'].'/priority', ['priority' => 'URGENT', 'reason' => 'The customer is at the counter.', 'expected_version' => 1])->assertOk()->json('case');
        $this->assertSame(['URGENT', null], [$urgent['priority'], $urgent['escalation_level']]);

        // Escalated to finance: the queue changes, the priority does not; nothing happens in any other domain.
        $this->desk('POST', '/cases/'.$case['id'].'/escalate', ['reason' => 'FINANCIAL_RISK', 'level' => 'L4', 'note' => 'Double charge', 'expected_version' => 2])->assertStatus(422);
        $escalated = $this->desk('POST', '/cases/'.$case['id'].'/escalate', ['reason' => 'FINANCIAL_RISK', 'level' => 'L2', 'admin' => (string) $finance->public_id, 'note' => 'Possible double charge — please check the payment.', 'expected_version' => 2])->assertOk()->json('case');
        $this->assertSame(['URGENT', 'L2', 'FINANCE', $finance->name, 'ASSIGNED', 'STANDARD'], [$escalated['priority'], $escalated['escalation_level'], $escalated['assigned']['team'], $escalated['assigned']['admin']['name'], $escalated['status'], $escalated['classification']]);
        $this->assertSame([['FINANCIAL_RISK', 'L2', 'CUSTOMER_SUPPORT', 'FINANCE', $me->name, 'OPEN']], array_map(fn (array $e): array => [$e['reason'], $e['level'], $e['from_team'], $e['to_team'], $e['by'], $e['status']], $escalated['escalations']));
        $this->assertSame([1, 1], [$this->desk('GET', '/queues')->json('queues.escalated'), count($this->desk('GET', '/cases?filter[queue]=escalated')->json('data'))]);

        // A second escalation closes the first; resolving the case closes what is open.
        $this->desk('POST', '/cases/'.$case['id'].'/escalate', ['reason' => 'MANAGEMENT_REVIEW', 'level' => 'L3', 'note' => 'Needs a decision.', 'expected_version' => 3])->assertOk()->assertJsonPath('case.escalation_level', 'L3');
        $this->assertSame(['CLOSED', 'OPEN'], SupportCaseEscalation::query()->orderBy('id')->pluck('status')->all());
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Decided.', 'expected_version' => 4])->assertOk()->assertJsonPath('case.escalation_level', null);
        $this->assertSame(['CLOSED', 'CLOSED'], SupportCaseEscalation::query()->orderBy('id')->pluck('status')->all());

        $this->assertSame(['support.priority_changed', 'support.case_escalated', 'support.case_escalated', 'support.case_resolved'], AuditEvent::query()->where('action', 'like', 'support.%')->orderBy('id')->pluck('action')->all());
        $audit = AuditEvent::query()->where('action', 'support.case_escalated')->orderBy('id')->firstOrFail();
        $this->assertSame([(string) $me->public_id, 'support_cases', $case['id'], 'Possible double charge — please check the payment.'], [$audit->actor_public_id, $audit->target_type, $audit->target_public_id, $audit->reason]);
        // Escalating needs its own permission.
        $second = $this->customerCase($headers, ['subject' => 'Another']);
        $this->agent([Permission::AdminSupportView, Permission::AdminSupportManage]);
        $this->desk('POST', '/cases/'.$second['id'].'/escalate', ['reason' => 'OTHER', 'level' => 'L2', 'note' => 'Look at this', 'expected_version' => 1])->assertForbidden();
    }

    public function test_internal_notes_are_their_own_resource_with_their_own_permission_and_a_reply_cannot_be_sent_as_one(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $me = $this->agent();

        // The composer says what it sends: a mismatch is refused, in both directions.
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'Internal remark typed into the wrong box', 'visibility' => 'INTERNAL'])->assertStatus(422);
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'No mode given'])->assertStatus(422);
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'A note typed as a reply', 'visibility' => 'EXTERNAL'])->assertStatus(422);
        $this->assertSame([1, 0], [SupportCaseMessage::query()->count(), SupportCaseInternalNote::query()->count()]);

        $note = $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'Provider confirmed the capture.', 'visibility' => 'INTERNAL', 'client_note_id' => 'note-000000001'])->assertCreated()->json('note');
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'Provider confirmed the capture.', 'visibility' => 'INTERNAL', 'client_note_id' => 'note-000000001'])->assertCreated()->assertJsonPath('note.id', $note['id']);
        $this->assertSame(['INTERNAL', $me->name, false], [$note['visibility'], $note['author_name'], $note['system']]);
        $this->assertSame(1, SupportCaseInternalNote::query()->count());
        // A note is not a message: the conversation does not contain it, the requester's counters do not move, nobody is notified.
        $this->assertSame(['CUSTOMER'], array_column($this->desk('GET', '/cases/'.$case['id'].'/messages')->assertOk()->json('data'), 'author_type'));
        $model = $this->caseModel($case['id']);
        $this->assertSame([1, null, null], [$model->message_count, $model->last_support_message_id, $model->first_response_at]);
        $this->assertSame(1, $model->version);
        $this->assertSame(['Provider confirmed the capture.'], array_column($this->desk('GET', '/cases/'.$case['id'].'/notes')->assertOk()->json('data'), 'body'));

        // Reading and writing notes is its own permission: a viewer and a "manage only" agent get neither.
        foreach ([[Permission::AdminSupportView], [Permission::AdminSupportView, Permission::AdminSupportManage]] as $permissions) {
            $this->agent($permissions);
            $detail = $this->desk('GET', '/cases/'.$case['id'])->assertOk()->json('case');
            $this->assertFalse($detail['can']['notes']);
            $this->assertStringNotContainsString('confirmed the capture', json_encode([$detail, $this->desk('GET', '/cases/'.$case['id'].'/messages')->json(), $this->desk('GET', '/cases/'.$case['id'].'/events')->json(), $this->desk('GET', '/cases')->json()]));
            $this->desk('GET', '/cases/'.$case['id'].'/notes')->assertForbidden();
            $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'x y z', 'visibility' => 'INTERNAL'])->assertForbidden();
        }
        // A viewer reads and changes nothing.
        $this->agent([Permission::AdminSupportView]);
        foreach ([['messages', ['message' => 'Hi', 'visibility' => 'EXTERNAL']], ['priority', ['priority' => 'HIGH', 'reason' => 'x y z', 'expected_version' => 1]], ['start', ['expected_version' => 1]], ['resolve', ['resolution_code' => 'ANSWERED', 'note' => 'x y z', 'expected_version' => 1]],
            ['links', ['type' => 'ORDER', 'reference' => 'FOTG-20261007-AAAAAA']], ['annotations', ['summary' => 'x', 'expected_version' => 1]], ['domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => '11111111-1111-4111-8111-111111111111', 'reason' => 'x y z']]] as [$action, $body]) {
            $this->desk('POST', '/cases/'.$case['id'].'/'.$action, $body)->assertForbidden();
        }
        // No support permission at all: no desk.
        $this->agent([Permission::AdminOrdersView, Permission::AdminPaymentsView, Permission::AdminFinanceView]);
        foreach (['/cases', '/queues', '/metrics', '/catalog', '/cases/'.$case['id']] as $path) {
            $this->desk('GET', $path)->assertForbidden();
        }
    }

    public function test_an_administrator_of_one_market_reaches_that_markets_cases_only_and_restricted_cases_need_their_class(): void
    {
        $this->seed(RoleSeeder::class);
        $other = Market::factory()->create(['country_code' => 'AE', 'slug' => 'uae']);
        [, $headers] = $this->signedIn();
        $india = $this->customerCase($headers);
        $security = $this->customerCase($headers, ['category' => 'SECURITY', 'subject' => 'Somebody used my account']);
        $this->assertSame(['RESTRICTED', 'SECURITY', 'SECURITY', 'HIGH'], [$this->caseModel($security['id'])->classification, $this->caseModel($security['id'])->restricted_class, $this->caseModel($security['id'])->assigned_team->value, $this->caseModel($security['id'])->priority->value]);
        // The customer still reads their own security report.
        $this->customerSupport($headers, 'GET', '/cases/'.$security['id'])->assertOk();

        // An agent for another market: India's cases are not theirs — 403 by id, absent from lists, counts and search.
        $this->agent(self::DESK, $other);
        $this->assertSame([], $this->desk('GET', '/cases')->assertOk()->json('data'));
        $this->assertSame(0, $this->desk('GET', '/queues')->assertOk()->json('total'));
        $this->assertSame([], $this->desk('GET', '/cases?q='.$india['case_number'])->assertOk()->json('data'));
        $this->desk('GET', '/cases/'.$india['id'])->assertForbidden();
        $this->desk('POST', '/cases/'.$india['id'].'/messages', ['message' => 'Hi', 'visibility' => 'EXTERNAL'])->assertForbidden();
        $this->desk('POST', '/cases', ['category' => 'OTHER', 'subject' => 'Internal', 'note' => 'For India', 'market' => $this->market->slug])->assertForbidden();
        $this->desk('POST', '/cases', ['category' => 'OTHER', 'subject' => 'Internal', 'note' => 'No market'])->assertForbidden();

        // A support agent of India: the ordinary case, yes; the security case is not in any list or count and is refused by id.
        $this->seededAgent('SUPPORT_ADMIN');
        $this->assertSame([$india['id']], array_column($this->desk('GET', '/cases')->assertOk()->json('data'), 'id'));
        $this->assertSame([1, 0], [$this->desk('GET', '/queues')->json('total'), $this->desk('GET', '/queues')->json('queues.restricted')]);
        $this->assertSame([], $this->desk('GET', '/cases?q='.$security['case_number'])->json('data'));
        $this->desk('GET', '/cases/'.$security['id'])->assertForbidden()->assertJsonPath('error.code', 'case_restricted');
        foreach (['messages', 'notes', 'events', 'links'] as $resource) {
            $this->desk('GET', '/cases/'.$security['id'].'/'.$resource)->assertForbidden();
        }
        $this->desk('POST', '/cases/'.$security['id'].'/notes', ['note' => 'x y z', 'visibility' => 'INTERNAL'])->assertForbidden();
        // …and cannot be a duplicate target they name blindly.
        $this->desk('POST', '/cases/'.$india['id'].'/cancel', ['resolution_code' => 'DUPLICATE_CASE', 'note' => 'Same', 'duplicate_of' => $security['case_number'], 'expected_version' => 1])->assertForbidden();

        // Finance holds the finance class, not the security class. Only the holder of the security class opens it.
        $this->seededAgent('FINANCE_ADMIN');
        $this->desk('GET', '/cases/'.$security['id'])->assertForbidden()->assertJsonPath('error.code', 'case_restricted');
        $this->seededAgent('SUPER_ADMIN');
        $this->desk('GET', '/cases/'.$security['id'])->assertOk()->assertJsonPath('case.restricted_class', 'SECURITY');
        $this->assertSame(1, $this->desk('GET', '/queues')->json('queues.restricted'));

        // A security escalation narrows a case that was ordinary: the escalating agent loses sight of it.
        $this->seededAgent('SUPPORT_ADMIN');
        $answer = $this->desk('POST', '/cases/'.$india['id'].'/escalate', ['reason' => 'SECURITY', 'level' => 'L3', 'note' => 'The account looks taken over.', 'expected_version' => 1])->assertOk()->json('case');
        $this->assertSame([false, 'RESTRICTED', 'SECURITY'], [$answer['visible'], $answer['classification'], $answer['restricted_class']]);
        $this->desk('GET', '/cases/'.$india['id'])->assertForbidden();
        $this->assertSame([], $this->desk('GET', '/cases')->json('data'));

        // The seeded roles: who works the desk, and who opens which restricted class.
        $roles = RoleSeeder::adminRoles();
        $has = fn (string $role, Permission $p): bool => in_array($p, $roles[$role][1], true);
        foreach (['SUPPORT_ADMIN' => [true, true, true, false, false], 'OPERATIONS_ADMIN' => [true, true, true, false, false], 'FINANCE_ADMIN' => [true, true, true, true, false], 'MODERATION_ADMIN' => [true, false, false, false, false],
            'RESTAURANT_ONBOARDING' => [true, false, false, false, false], 'ANALYST' => [false, false, false, false, false], 'SUPER_ADMIN' => [true, true, true, true, true]] as $role => $expected) {
            $this->assertSame($expected, [$has($role, Permission::AdminSupportView), $has($role, Permission::AdminSupportManage), $has($role, Permission::AdminSupportNotes), $has($role, Permission::AdminSupportRestrictedFinance), $has($role, Permission::AdminSupportRestrictedSecurity)], $role);
        }
        // Working the desk gives a support agent no money permission.
        foreach ([Permission::AdminPaymentsReconcile, Permission::AdminRefundsCreate, Permission::AdminRefundsApprove, Permission::AdminRefundsIssue, Permission::AdminRefundsReconcile, Permission::AdminSettlementsView, Permission::AdminFinancePayoutsView, Permission::AdminFinancePayoutsExecute, Permission::AdminDisputesView] as $money) {
            $this->assertFalse($has('SUPPORT_ADMIN', $money), $money->value);
        }
    }

    public function test_an_administrator_opens_an_internal_case_that_nobody_outside_reads(): void
    {
        [$customer, $headers, , , $order] = $this->paidOrder('+919876543210', '13:00');
        $me = $this->agent([...self::DESK, Permission::AdminOrdersView]);
        $this->desk('POST', '/cases', ['category' => 'ORDER', 'subject' => 'Phoned in', 'note' => 'x', 'requester_type' => 'CUSTOMER'])->assertStatus(422);
        $this->desk('POST', '/cases', ['category' => 'ORDER', 'subject' => 'Phoned in', 'note' => 'x', 'customer_id' => $customer->getKey()])->assertStatus(422);
        $case = $this->desk('POST', '/cases', ['category' => 'ORDER', 'subcategory' => 'ORDER_DELAYED', 'subject' => 'Customer phoned about a late order', 'note' => 'Called the hotline at 13:10.', 'market' => $this->market->slug, 'links' => [['type' => 'ORDER', 'reference' => $order['order_number']]]])->assertCreated()->json('case');
        $this->assertSame(['ADMIN', $me->name, 'ADMIN', 0, false, $this->market->slug], [$case['requester']['type'], $case['requester']['name'], $case['source'], $case['message_count'], $case['can']['reply'], $case['market']]);
        $this->assertSame(['Called the hotline at 13:10.'], array_column($this->desk('GET', '/cases/'.$case['id'].'/notes')->assertOk()->json('data'), 'body'));
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'Hello', 'visibility' => 'EXTERNAL'])->assertStatus(409)->assertJsonPath('error.code', 'case_has_no_requester');
        // The customer whose order it is about sees nothing of it.
        $this->assertSame([], $this->customerSupport($headers, 'GET', '/cases')->assertOk()->json('data'));
        $this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertNotFound();
        $this->assertSame(1, SupportCase::query()->count());
    }
}
