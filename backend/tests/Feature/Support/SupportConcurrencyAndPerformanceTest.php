<?php

namespace Tests\Feature\Support;

use App\Enums\Permission;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportCaseNumbers;
use App\Services\Support\SupportCaseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — what holds when two people act at once, and what the support queries cost.
 *
 * CONCURRENCY is shown by what decides it: the version every state-changing action must name, the row lock the
 * services take, and the rows the database refuses whatever the application is asked. PERFORMANCE: queries are
 * counted and time is measured on this machine's test database; the numbers are measurements, never targets, and
 * are written to SUPPORT_PERF_REPORT when it is set.
 */
class SupportConcurrencyAndPerformanceTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    protected function tearDown(): void
    {
        if (($file = (string) env('SUPPORT_PERF_REPORT', '')) !== '' && $this->report !== []) {
            $existing = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
            file_put_contents($file, json_encode([...$existing, ...$this->report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int, 2: mixed} queries, milliseconds, the work's result
     */
    private function measure(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $result = $work();
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $ms, $result];
    }

    private function refused(callable $write, string $what): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The database accepted: '.$what);
        } catch (QueryException $e) {
            $this->assertContains((string) $e->getCode(), ['23505', '23514', '23001', '23503'], $what.': '.$e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_two_administrators_working_from_the_same_stale_case_cannot_both_change_it(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $one = $this->agent();
        $two = $this->adminWith(self::DESK);
        $path = '/cases/'.$case['id'];

        // Both read version 1. A resolves; B, still holding version 1, changes the priority / assigns / escalates / resolves.
        $this->desk('POST', $path.'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Answered by phone.', 'expected_version' => 1])->assertOk();
        $this->asActor($two);
        foreach ([['priority', ['priority' => 'URGENT', 'reason' => 'Looks urgent']], ['assign', ['admin' => (string) $two->public_id]], ['escalate', ['reason' => 'OTHER', 'level' => 'L2', 'note' => 'Have a look']],
            ['resolve', ['resolution_code' => 'OTHER', 'note' => 'Another ending']], ['start', []], ['wait', ['for' => 'PROVIDER']], ['cancel', ['resolution_code' => 'OTHER', 'note' => 'Not needed']], ['close', []], ['reopen', ['reason' => 'Reopen it']]] as [$action, $body]) {
            $this->desk('POST', $path.'/'.$action, $body + ['expected_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'case_version_conflict')->assertJsonPath('error.details.current_status', 'RESOLVED');
        }
        $model = $this->caseModel($case['id']);
        $this->assertSame(['RESOLVED', 'NORMAL', null, 'ANSWERED', 2], [$model->status->value, $model->priority->value, $model->assigned_admin_id, $model->resolution_code, $model->version]);
        // An action without the version it was decided on is not accepted at all.
        $this->desk('POST', $path.'/reopen', ['reason' => 'Without a version'])->assertStatus(422);

        // Resolve / reopen at the same moment: the customer reopens (version 3) while B closes from version 2.
        $this->customerSupport($headers, 'POST', $path.'/reopen', ['message' => 'It is not solved.'])->assertOk();
        $this->asActor($two);
        $this->desk('POST', $path.'/close', ['expected_version' => 2])->assertStatus(409)->assertJsonPath('error.details.current_status', 'REOPENED');
        // With the version that is true now, the state machine still decides: a reopened case is not closed.
        $this->desk('POST', $path.'/close', ['expected_version' => 3])->assertStatus(409)->assertJsonPath('error.code', 'case_invalid_transition');
        $this->assertSame('REOPENED', $this->caseModel($case['id'])->status->value);

        // Messages are independent appends: they never conflict with each other and never need a version.
        $this->asActor($one);
        $this->desk('POST', $path.'/messages', ['message' => 'First agent.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->asActor($two);
        $this->desk('POST', $path.'/messages', ['message' => 'Second agent.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->customerSupport($headers, 'POST', $path.'/messages', ['message' => 'And me.'])->assertCreated();
        $this->assertSame(5, $this->caseModel($case['id'])->message_count);
        $this->assertSame(3, $this->caseModel($case['id'])->version, 'a message is not a change of state');
        $sequences = SupportCaseEvent::query()->where('case_id', $model->getKey())->orderBy('id')->pluck('sequence')->all();
        $this->assertSame(range(1, count($sequences)), $sequences);
    }

    public function test_the_database_refuses_what_a_race_could_try_to_write_twice_or_wrongly(): void
    {
        [$customer, $headers] = $this->signedIn();
        $case = $this->caseModel($this->customerCase($headers)['id']);
        $message = SupportCaseMessage::query()->sole();
        $now = now();

        $this->refused(fn () => DB::table('support_cases')->insert(['public_id' => (string) Str::uuid(), 'case_number' => $case->case_number, 'requester_type' => 'CUSTOMER', 'customer_id' => $customer->getKey(), 'category' => 'ORDER', 'priority' => 'NORMAL', 'status' => 'OPEN', 'subject' => 'x', 'source' => 'CUSTOMER_WEB', 'created_at' => $now, 'updated_at' => $now]), 'a second case with the same number');
        $this->refused(fn () => DB::table('support_case_events')->insert(['case_id' => $case->getKey(), 'sequence' => 1, 'type' => 'FORGED', 'actor_type' => 'SYSTEM', 'audience' => 'INTERNAL', 'occurred_at' => $now]), 'a second timeline line with the same number');
        DB::table('support_case_messages')->where('id', $message->getKey())->getConnection()->statement('select 1');
        $this->customerSupport($headers, 'POST', '/cases/'.$case->public_id.'/messages', ['message' => 'Once', 'client_message_id' => 'race-0000000001'])->assertCreated();
        $this->refused(fn () => DB::table('support_case_messages')->insert(['public_id' => (string) Str::uuid(), 'case_id' => $case->getKey(), 'author_type' => 'CUSTOMER', 'author_customer_id' => $customer->getKey(), 'body' => 'Twice', 'client_message_id' => 'race-0000000001', 'created_at' => $now]), 'the same client message id twice');
        // A status, a priority, an author kind or a requester that the domain does not know.
        $this->refused(fn () => DB::table('support_cases')->where('id', $case->getKey())->update(['status' => 'DONE']), 'an unknown status');
        $this->refused(fn () => DB::table('support_cases')->where('id', $case->getKey())->update(['priority' => 'CRITICAL']), 'an unknown priority');
        $this->refused(fn () => DB::table('support_cases')->where('id', $case->getKey())->update(['status' => 'RESOLVED', 'resolved_at' => $now]), 'a resolved case without how it ended');
        $this->refused(fn () => DB::table('support_cases')->where('id', $case->getKey())->update(['resolution_code' => 'ANSWERED']), 'an ending on a case that is not over');
        $this->refused(fn () => DB::table('support_cases')->where('id', $case->getKey())->update(['customer_id' => null]), 'a customer case without its customer');
        $this->refused(fn () => DB::table('support_cases')->where('id', $case->getKey())->update(['classification' => 'RESTRICTED']), 'a restricted case without its class');
        $this->refused(fn () => DB::table('support_case_messages')->insert(['public_id' => (string) Str::uuid(), 'case_id' => $case->getKey(), 'author_type' => 'SUPPORT', 'author_customer_id' => $customer->getKey(), 'body' => 'x', 'created_at' => $now]), 'a support message written by a customer');
        $this->refused(fn () => DB::table('support_case_links')->insert(['public_id' => (string) Str::uuid(), 'case_id' => $case->getKey(), 'link_type' => 'ORDER', 'customer_id' => $customer->getKey(), 'entity_public_id' => (string) $customer->public_id, 'reference' => 'x', 'added_by_type' => 'ADMIN', 'created_at' => $now]), 'a link whose type and record do not match');
        // One live system case per watched condition — whoever inserts it.
        $system = fn (): bool => DB::table('support_cases')->insert(['public_id' => (string) Str::uuid(), 'case_number' => SupportCaseNumbers::generate(), 'requester_type' => 'SYSTEM', 'category' => 'PAYMENT', 'priority' => 'HIGH', 'status' => 'OPEN', 'subject' => 'x', 'source' => 'PAYMENT_RECOVERY', 'system_key' => 'payment-recovery:test', 'created_at' => $now, 'updated_at' => $now]);
        $system();
        $this->refused($system, 'a second live system case for the same condition');
        // What was written is never rewritten.
        foreach (['support_case_messages' => ['body' => 'edited'], 'support_case_events' => ['to_status' => 'CLOSED'], 'support_case_internal_notes' => ['body' => 'edited']] as $table => $change) {
            if (DB::table($table)->count() === 0) {
                $this->agent();
                $this->desk('POST', '/cases/'.$case->public_id.'/notes', ['note' => 'A note.', 'visibility' => 'INTERNAL'])->assertCreated();
            }
            $this->refused(fn () => DB::table($table)->limit(1)->update($change), 'an edit of '.$table);
        }
    }

    public function test_what_the_support_queries_cost_and_that_the_lists_do_not_grow_with_their_rows(): void
    {
        config(['support.limits.open_cases_per_requester' => 0]);
        [$rahul, $rahulHeaders] = $this->signedIn('+919876543210');
        [$asha] = $this->signedIn('+919876543212');
        $service = app(SupportCaseService::class);
        $open = fn (int $i, $customer): SupportCase => $service->openForCustomer($customer, ['category' => ['ORDER', 'PAYMENT', 'ACCOUNT', 'OTHER'][$i % 4], 'subject' => 'Performance case '.$i, 'message' => 'Message of case '.$i]);
        $first = $open(0, $rahul);
        $open(1, $asha);
        $open(2, $rahul);
        $admin = $this->agent([...self::DESK, Permission::AdminCustomersView]);

        [$fewQueue] = $this->measure(fn () => $this->desk('GET', '/cases?page[size]=100')->assertOk()->assertJsonCount(3, 'data'));
        [$fewQueues] = $this->measure(fn () => $this->desk('GET', '/queues')->assertOk());
        [$fewCustomer] = $this->measure(fn () => $this->customerSupport($rahulHeaders, 'GET', '/cases?per_page=100')->assertOk()->assertJsonCount(2, 'data'));

        for ($i = 3; $i < 120; $i++) {
            $case = $open($i, $i % 2 === 0 ? $rahul : $asha);
            if ($i % 10 === 0) {
                $service->assign($case, $admin, null, $admin, false, null, 1);
            }
        }
        // A long conversation on one case: 150 messages.
        for ($i = 0; $i < 149; $i++) {
            $service->requesterMessage($first, SupportActor::customer($rahul), 'Message number '.$i);
        }
        $this->assertSame([120, 150], [SupportCase::query()->count(), $this->caseModel((string) $first->public_id)->message_count]);

        $this->asActor($admin);
        [$manyQueue, $manyQueueMs] = $this->measure(fn () => $this->desk('GET', '/cases?page[size]=100')->assertOk()->assertJsonCount(100, 'data'));
        [$manyQueues, $manyQueuesMs] = $this->measure(fn () => $this->desk('GET', '/queues')->assertOk());
        [$detail, $detailMs] = $this->measure(fn () => $this->desk('GET', '/cases/'.$first->public_id)->assertOk());
        [$thread, $threadMs] = $this->measure(fn () => $this->desk('GET', '/cases/'.$first->public_id.'/messages?per_page=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 150));
        [$timeline, $timelineMs] = $this->measure(fn () => $this->desk('GET', '/cases/'.$first->public_id.'/events')->assertOk()->assertJsonCount(100, 'data'));
        [$search, $searchMs] = $this->measure(fn () => $this->desk('GET', '/cases?q='.$first->case_number)->assertOk()->assertJsonCount(1, 'data'));
        [$metrics, $metricsMs] = $this->measure(fn () => $this->desk('GET', '/metrics')->assertOk());
        [$manyCustomer, $manyCustomerMs] = $this->measure(fn () => $this->customerSupport($rahulHeaders, 'GET', '/cases?per_page=100')->assertOk()->assertJsonCount(60, 'data'));
        [$customerThread, $customerThreadMs] = $this->measure(fn () => $this->customerSupport($rahulHeaders, 'GET', '/cases/'.$first->public_id.'/messages?per_page=50')->assertOk()->assertJsonCount(50, 'data'));
        [$reply, $replyMs] = $this->measure(fn () => $this->customerSupport($rahulHeaders, 'POST', '/cases/'.$first->public_id.'/messages', ['message' => 'One more'])->assertCreated());

        // No N+1: a hundred rows cost what three rows cost (the page of the queue never loads a conversation).
        $this->assertLessThanOrEqual($fewQueue + 1, $manyQueue, 'the queue does not query per case');
        $this->assertLessThanOrEqual($fewQueues, $manyQueues, 'the queue counts are aggregate queries');
        $this->assertLessThanOrEqual($fewCustomer + 1, $manyCustomer, 'the customer list does not query per case');
        $this->assertLessThanOrEqual(25, $manyQueue);
        $this->assertLessThanOrEqual(25, $thread, 'a page of a long conversation is one page of rows');
        $this->report += [
            'admin_queue_100_of_120_cases' => ['queries' => $manyQueue, 'ms' => $manyQueueMs, 'queries_with_3_cases' => $fewQueue],
            'admin_queue_counts_16_queues' => ['queries' => $manyQueues, 'ms' => $manyQueuesMs],
            'admin_case_detail' => ['queries' => $detail, 'ms' => $detailMs],
            'admin_conversation_page_50_of_150' => ['queries' => $thread, 'ms' => $threadMs],
            'admin_timeline_page_100' => ['queries' => $timeline, 'ms' => $timelineMs],
            'admin_search_by_case_number' => ['queries' => $search, 'ms' => $searchMs],
            'admin_metrics' => ['queries' => $metrics, 'ms' => $metricsMs],
            'customer_case_list_60' => ['queries' => $manyCustomer, 'ms' => $manyCustomerMs, 'queries_with_2_cases' => $fewCustomer],
            'customer_conversation_page_50_of_150' => ['queries' => $customerThread, 'ms' => $customerThreadMs],
            'customer_reply' => ['queries' => $reply, 'ms' => $replyMs],
        ];

        // The restaurant's list: a few cases, then many.
        $owner = $this->member($this->organization, 'OWNER');
        $this->asActor($owner);
        $this->restaurantCase();
        [$fewRestaurant] = $this->measure(fn () => $this->restaurantSupport('GET', '/cases?per_page=100')->assertOk()->assertJsonCount(1, 'data'));
        for ($i = 0; $i < 40; $i++) {
            $service->openForRestaurant($owner, $this->organization, $this->burgerHub, ['category' => 'ORDER', 'subject' => 'Restaurant case '.$i, 'message' => 'x']);
        }
        [$manyRestaurant, $manyRestaurantMs] = $this->measure(fn () => $this->restaurantSupport('GET', '/cases?per_page=100')->assertOk()->assertJsonCount(41, 'data'));
        $this->assertLessThanOrEqual($fewRestaurant + 1, $manyRestaurant, 'the restaurant list does not query per case');
        $this->report['restaurant_case_list_41'] = ['queries' => $manyRestaurant, 'ms' => $manyRestaurantMs, 'queries_with_1_case' => $fewRestaurant];

        // EXPLAIN: the support queries have an index to use (the planner is told not to prefer a sequential scan of
        // these small test tables, so that what is shown is whether a usable index exists).
        DB::statement('set local enable_seqscan = off');
        $plans = [
            'queue_by_status_and_priority' => ['select * from support_cases where status = ? and priority = ? order by created_at limit 25', ['OPEN', 'HIGH']],
            'cases_of_a_team' => ['select * from support_cases where assigned_team = ? and status = ?', ['PAYMENTS', 'OPEN']],
            'cases_of_an_administrator' => ['select * from support_cases where assigned_admin_id = ? and status = ?', [$admin->getKey(), 'ASSIGNED']],
            'cases_of_a_market' => ['select * from support_cases where market_id = ? and status = ? order by created_at', [$this->market->getKey(), 'OPEN']],
            'cases_of_a_customer' => ['select * from support_cases where customer_id = ? and requester_type = ? order by created_at desc limit 20', [$rahul->getKey(), 'CUSTOMER']],
            'cases_of_a_location' => ['select * from support_cases where organization_id = ? and location_id = ? order by created_at desc', [$this->organization->getKey(), $this->burgerHub->getKey()]],
            'case_by_number' => ['select * from support_cases where case_number = ?', [$first->case_number]],
            'messages_of_a_case' => ['select * from support_case_messages where case_id = ? order by id limit 50', [$first->getKey()]],
            'timeline_of_a_case' => ['select * from support_case_events where case_id = ? order by sequence', [$first->getKey()]],
            'cases_by_linked_reference' => ['select case_id from support_case_links where reference = ?', ['FOTG-20261007-AAAAAA']],
            'cases_by_linked_record' => ['select case_id from support_case_links where link_type = ? and entity_public_id = ?', ['ORDER', (string) $first->public_id]],
            'sla_state_of_active_cases' => ['select count(*) from support_cases where sla_status = ? and status = ?', ['AT_RISK', 'OPEN']],
            'live_system_case_by_key' => ['select id from support_cases where system_key = ? and status not in (?, ?)', ['refund-review:x', 'CLOSED', 'CANCELLED']],
        ];
        foreach ($plans as $name => [$sql, $bindings]) {
            $plan = implode("\n", array_map(fn ($row): string => (string) current((array) $row), DB::select('explain (analyze, costs off, timing off, summary off) '.$sql, $bindings)));
            $this->assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap Index Scan/', $plan, $name.":\n".$plan);
            preg_match('/(Index Only Scan|Index Scan|Bitmap Index Scan)[^\n]* (?:using|on) ([a-z0-9_]+)/', $plan, $m);
            $this->report['explain'][$name] = trim(($m[1] ?? 'Index Scan').' '.($m[2] ?? ''));
        }
    }
}
