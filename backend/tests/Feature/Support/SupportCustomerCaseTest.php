<?php

namespace Tests\Feature\Support;

use App\Enums\SupportCaseStatus;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseInternalNote;
use App\Models\SupportCaseLink;
use App\Models\SupportCaseMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — a customer's own support cases: opening one, the conversation, closing and reopening, and everything a
 * customer must not be able to do or see.
 */
class SupportCustomerCaseTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_customer_opens_a_case_reads_it_and_talks_with_support_until_it_is_resolved(): void
    {
        [$customer, $headers] = $this->signedIn();
        $catalog = $this->customerSupport($headers, 'GET', '/catalog')->assertOk()->json();
        $this->assertContains('ORDER', array_column($catalog['categories'], 'code'));
        // Categories that are not a customer's are not offered: no settlement, payout or dispute.
        $this->assertSame([], array_values(array_intersect(['SETTLEMENT', 'PAYOUT', 'DISPUTE'], array_column($catalog['categories'], 'code'))));
        $this->assertSame(['ORDER', 'PAYMENT', 'REFUND'], $catalog['link_types']);

        $case = $this->customerCase($headers, ['category' => 'ORDER', 'subcategory' => 'ORDER_DELAYED', 'subject' => "  My order\tis late ", 'message' => "It has been 40 minutes.\r\nPlease check."]);
        $this->assertMatchesRegularExpression('/\ACS-[A-HJ-NP-Z2-9]{8}\z/', $case['case_number']);
        $this->assertSame(['ORDER', 'ORDER_DELAYED', 'My order is late', 'OPEN', false, 1], [$case['category'], $case['subcategory'], $case['subject'], $case['status'], $case['unread'], $case['message_count']]);
        $this->assertSame(['reply' => true, 'close' => false, 'withdraw' => true, 'reopen' => false], $case['can']);
        // What a requester never reads.
        foreach (['priority', 'assigned', 'sla', 'resolution', 'summary', 'classification', 'escalation_level', 'version', 'requester', 'system_key'] as $internal) {
            $this->assertArrayNotHasKey($internal, $case, $internal);
        }
        $model = $this->caseModel($case['id']);
        $this->assertSame([$customer->getKey(), 'CUSTOMER', 'CUSTOMER_WEB', 'NORMAL', 'CUSTOMER_SUPPORT', $this->market->getKey()], [$model->customer_id, $model->requester_type->value, $model->source->value, $model->priority->value, $model->assigned_team->value, $model->market_id]);

        // By its number as well as by its id — for its owner.
        $this->customerSupport($headers, 'GET', '/cases/'.$case['case_number'])->assertOk()->assertJsonPath('case.id', $case['id']);
        $this->assertSame([$case['id']], array_column($this->customerSupport($headers, 'GET', '/cases')->assertOk()->json('data'), 'id'));
        $messages = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'].'/messages')->assertOk()->json('data');
        $this->assertSame([['YOU', "It has been 40 minutes.\nPlease check."]], array_map(fn (array $m): array => [$m['author'], $m['body']], $messages));

        // Support answers and waits for the customer; the customer sees an unread reply from "SUPPORT" — no name.
        $agent = $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'Kitchen called: delayed by a staffing problem.', 'visibility' => 'INTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'We are checking with the restaurant.', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($case['id'])])->assertCreated();
        $list = $this->customerSupport($headers, 'GET', '/cases')->assertOk()->json();
        $this->assertSame([1, true, 'WAITING_FOR_YOU'], [$list['unread'], $list['data'][0]['unread'], $list['data'][0]['status']]);
        $seen = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case');
        $this->assertFalse($this->customerSupport($headers, 'GET', '/cases')->json('data.0.unread'));
        $thread = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'].'/messages')->assertOk()->json('data');
        $this->assertSame(['YOU', 'SUPPORT'], array_column($thread, 'author'));
        $this->assertStringNotContainsString($agent->name, json_encode([$seen, $thread]));
        $this->assertStringNotContainsString('staffing problem', json_encode([$seen, $thread, $list]));

        // The customer answers: the case is support's again.
        $reply = $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Thank you, still waiting.'])->assertCreated()->json();
        $this->assertSame(['YOU', 'OPEN'], [$reply['message']['author'], $reply['case']['status']]);

        // Resolved by support; the customer confirms and the case is closed.
        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'The order was handed over.', 'expected_version' => $this->version($case['id'])])->assertOk();
        $resolved = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case');
        $this->assertSame(['RESOLVED', false, true, false, true], [$resolved['status'], $resolved['can']['reply'], $resolved['can']['close'], $resolved['can']['withdraw'], $resolved['can']['reopen']]);
        $this->assertNotNull($resolved['reopen_until']);
        $this->assertStringNotContainsString('handed over', json_encode($resolved));
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'One more thing'])->assertStatus(409)->assertJsonPath('error.code', 'case_not_open');
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/close', ['intent' => 'CONFIRM'])->assertOk()->assertJsonPath('case.status', 'CLOSED');
        $this->assertSame(SupportCaseStatus::Closed, $this->caseModel($case['id'])->status);
    }

    public function test_a_customer_reopens_inside_the_window_and_withdraws_a_case_that_is_still_open(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Explained.', 'expected_version' => $this->version($case['id'])])->assertOk();

        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', [])->assertStatus(422);
        $reopened = $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', ['message' => 'It happened again.'])->assertOk()->json('case');
        $this->assertSame(['OPEN', 2], [$reopened['status'], $reopened['message_count']]);
        $model = $this->caseModel($case['id']);
        $this->assertSame([SupportCaseStatus::Reopened, 1, null], [$model->status, $model->reopen_count, $model->resolution_code]);

        // Resolved again and left alone past the window: no reopening — a new case.
        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Explained again.', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->travel((int) config('support.reopen.resolved_days') + 1)->days();
        $late = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case');
        $this->assertSame([false, null], [$late['can']['reopen'], $late['reopen_until']]);
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', ['message' => 'Again'])->assertStatus(409)->assertJsonPath('error.code', 'case_reopen_window_over');
        $this->travelBack();

        // A case that is still being worked on is withdrawn by its customer — cancelled, with the reason recorded.
        $second = $this->customerCase($headers, ['subject' => 'Never mind']);
        $this->customerSupport($headers, 'POST', '/cases/'.$second['id'].'/close', ['intent' => 'WITHDRAW'])->assertOk()->assertJsonPath('case.status', 'CANCELLED');
        $this->assertSame('USER_WITHDREW', $this->caseModel($second['id'])->resolution_code);
        $this->customerSupport($headers, 'POST', '/cases/'.$second['id'].'/reopen', ['message' => 'Sorry, I do need help'])->assertStatus(409);
        // "closed" for a requester = no longer being worked on: the resolved case and the withdrawn one.
        $this->assertSame(2, count($this->customerSupport($headers, 'GET', '/cases?state=closed')->assertOk()->json('data')));
        $this->assertSame([], $this->customerSupport($headers, 'GET', '/cases?state=open')->assertOk()->json('data'));
    }

    public function test_a_customer_links_only_their_own_order_payment_and_refund(): void
    {
        [$rahul, $rahulHeaders, , $rahulPayment, $rahulOrder] = $this->paidOrder('+919876543210', '13:00');
        [, $ashaHeaders, , $ashaPayment, $ashaOrder] = $this->paidOrder('+919876543212', '13:15');

        $case = $this->customerCase($rahulHeaders, ['category' => 'PAYMENT', 'subject' => 'About my payment', 'order' => $rahulOrder['order_number'], 'payment' => $rahulPayment['id']]);
        $this->assertSame(['ORDER', 'PAYMENT'], array_column($case['links'], 'type'));
        $this->assertSame([$rahulOrder['order_number'], 'WAITING_FOR_RESTAURANT'], [$case['links'][0]['order_number'], $case['links'][0]['status']]);
        $this->assertSame('VERIFIED', $case['links'][1]['status']);
        $this->assertSame(2, SupportCaseLink::query()->count());

        // Somebody else's order or payment does not exist for this customer — and no case is written.
        $before = SupportCase::query()->count();
        $this->customerSupport($rahulHeaders, 'POST', '/cases', ['category' => 'ORDER', 'subject' => 'x', 'message' => 'y', 'order' => $ashaOrder['order_number']])->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
        $this->customerSupport($rahulHeaders, 'POST', '/cases', ['category' => 'PAYMENT', 'subject' => 'x', 'message' => 'y', 'payment' => $ashaPayment['id']])->assertNotFound()->assertJsonPath('error.code', 'payment_not_found');
        $this->customerSupport($ashaHeaders, 'POST', '/cases', ['category' => 'REFUND', 'subject' => 'x', 'message' => 'y', 'refund' => 'RFD-0000000000'])->assertNotFound()->assertJsonPath('error.code', 'refund_not_found');
        $this->assertSame($before, SupportCase::query()->count());

        // A refund of the customer's own rejected order is linked by its reference and read in the customer's words.
        [, $third, $rejected, , $refund] = $this->rejectedPaidOrder('+919876543213');
        $refundCase = $this->customerCase($third, ['category' => 'REFUND', 'subcategory' => 'REFUND_PENDING', 'subject' => 'Where is my refund', 'refund' => $refund->supportReference(), 'order' => $rejected['order_number']]);
        $link = collect($refundCase['links'])->firstWhere('type', 'REFUND');
        $this->assertSame([$refund->supportReference(), 'REQUIRED'], [$link['reference'], $link['status']]);
        $this->customerSupport($rahulHeaders, 'POST', '/cases', ['category' => 'REFUND', 'subject' => 'x', 'message' => 'y', 'refund' => $refund->supportReference()])->assertNotFound();
        $this->assertNotNull($rahul);
    }

    public function test_another_customers_case_does_not_exist_and_a_case_number_opens_nothing(): void
    {
        [, $rahul] = $this->signedIn('+919876543210');
        [, $asha] = $this->signedIn('+919876543212');
        $case = $this->customerCase($rahul);

        foreach ([$case['id'], $case['case_number']] as $reference) {
            $this->customerSupport($asha, 'GET', '/cases/'.$reference)->assertNotFound()->assertJsonPath('error.code', 'case_not_found');
            $this->customerSupport($asha, 'GET', '/cases/'.$reference.'/messages')->assertNotFound();
            $this->customerSupport($asha, 'POST', '/cases/'.$reference.'/messages', ['message' => 'Let me in'])->assertNotFound();
            $this->customerSupport($asha, 'POST', '/cases/'.$reference.'/close', ['intent' => 'WITHDRAW'])->assertNotFound();
            $this->customerSupport($asha, 'POST', '/cases/'.$reference.'/reopen', ['message' => 'x'])->assertNotFound();
        }
        $this->assertSame([], $this->customerSupport($asha, 'GET', '/cases')->assertOk()->json('data'));
        $this->assertSame(1, SupportCaseMessage::query()->count());

        // No session, a restaurant's session, an administrator's session: not a customer.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/support/cases')->assertUnauthorized();
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->actingAsPrincipal($this->actor);
        $this->getJson('/api/v1/customer/support/cases/'.$case['id'])->assertUnauthorized();
        // And a customer has no way into the restaurants' or the administrators' cases.
        foreach (['/api/v1/admin/support/cases', '/api/v1/admin/support/cases/'.$case['id'], '/api/v1/admin/support/cases/'.$case['id'].'/notes', '/api/v1/restaurant/support/cases'] as $url) {
            $this->getJson($url, $this->using($rahul))->assertUnauthorized();
        }
    }

    public function test_a_customer_never_reads_an_internal_note_whatever_they_ask_for(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $this->agent();
        $secret = 'INTERNAL-ONLY-7731: provider says the card was reported stolen';
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => $secret, 'visibility' => 'INTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/annotations', ['summary' => 'SUMMARY-ONLY-7731 for the desk', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'We are looking into it.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'OTHER', 'note' => 'RESOLUTION-ONLY-7731', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->assertSame(1, SupportCaseInternalNote::query()->count());

        $everything = '';
        foreach (['/cases', '/cases?state=closed', '/cases/'.$case['id'], '/cases/'.$case['case_number'], '/cases/'.$case['id'].'/messages', '/cases/'.$case['id'].'/messages?per_page=100', '/catalog'] as $path) {
            $everything .= $this->customerSupport($headers, 'GET', $path)->assertOk()->getContent();
        }
        $everything .= $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', ['message' => 'Not solved'])->assertOk()->getContent();
        $everything .= $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Hello?'])->assertCreated()->getContent();
        foreach (['7731', 'INTERNAL', 'stolen', 'note', 'summary', 'resolution_note'] as $leak) {
            $this->assertStringNotContainsString($leak, $everything, $leak);
        }
        // There is no notes or events resource for a customer at all.
        foreach (['/notes', '/events', '/links', '/internal-notes'] as $path) {
            $this->customerSupport($headers, 'GET', '/cases/'.$case['id'].$path)->assertNotFound();
        }
        // The customer's notifications do not quote anything either.
        $inbox = $this->getJson('/api/v1/customer/notifications', $this->using($headers))->assertOk()->getContent();
        $this->assertStringNotContainsString('7731', $inbox);
    }

    public function test_nothing_the_server_decides_is_accepted_from_a_customer(): void
    {
        [, $headers] = $this->signedIn();
        $base = ['category' => 'ORDER', 'subject' => 'Help', 'message' => 'Please'];
        foreach (['priority' => 'URGENT', 'status' => 'RESOLVED', 'requester_type' => 'ADMIN', 'customer_id' => 1, 'assigned_admin' => 'x', 'assigned_team' => 'FINANCE', 'visibility' => 'INTERNAL', 'internal' => true, 'classification' => 'RESTRICTED',
            'case_number' => 'CS-AAAAAAAA', 'market' => 'india', 'links' => [['type' => 'ORDER', 'reference' => 'x']], 'settlement' => 'STL-1', 'payout' => 'PO-1', 'dispute' => 'x', 'note' => 'internal', 'sla' => 'x'] as $field => $value) {
            $this->customerSupport($headers, 'POST', '/cases', $base + [$field => $value])->assertStatus(422)->assertJsonPath('error.details.fields.'.$field.'.0', 'This field is not accepted: it is decided by the server or unknown.');
        }
        $this->assertSame(0, SupportCase::query()->count());
        // A category that is not a customer's, an unknown one, an issue of another category.
        foreach ([['category' => 'SETTLEMENT'], ['category' => 'DISPUTE'], ['category' => 'NOPE'], ['category' => 'ORDER', 'subcategory' => 'PAYOUT_PENDING'], ['category' => 'ORDER', 'subcategory' => 'CUSTOMER_DISPUTE']] as $bad) {
            $this->customerSupport($headers, 'POST', '/cases', $bad + $base)->assertStatus(422);
        }
        $this->customerSupport($headers, 'POST', '/cases', ['subject' => ' ', 'message' => "\t\n"] + $base)->assertStatus(422);
        $this->customerSupport($headers, 'POST', '/cases', ['message' => str_repeat('a', (int) config('support.limits.message_max') + 1)] + $base)->assertStatus(422);
        $this->assertSame(0, SupportCase::query()->count());

        $case = $this->customerCase($headers);
        foreach (['visibility' => 'INTERNAL', 'author_type' => 'SUPPORT', 'internal' => true, 'status' => 'RESOLVED', 'actor_type' => 'ADMIN'] as $field => $value) {
            $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Hi', $field => $value])->assertStatus(422);
        }
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/close', ['intent' => 'CONFIRM', 'resolution_code' => 'ANSWERED'])->assertStatus(422);
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/close')->assertStatus(422);   // what is meant must be said
        // No PUT / PATCH / DELETE of a case exists.
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->customerSupport($headers, $method, '/cases/'.$case['id'], ['status' => 'CLOSED'])->assertStatus(405);
        }
        $this->assertSame(['OPEN', 'NORMAL', 1], [$this->caseModel($case['id'])->status->value, $this->caseModel($case['id'])->priority->value, SupportCaseMessage::query()->count()]);
    }

    public function test_messages_are_stored_as_plain_text_and_a_retry_writes_nothing_twice(): void
    {
        [, $headers] = $this->signedIn();
        $attack = '<script>alert("x")</script><img src=x onerror=alert(1)> "quoted" & javascript:alert(1) 😀 नमस्ते';
        $case = $this->customerCase($headers, ['subject' => '<b>bold</b> subject', 'message' => $attack]);
        // Stored and returned exactly as text — in a JSON string; no client renders it as markup.
        $this->assertSame('<b>bold</b> subject', $case['subject']);
        $this->assertSame($attack, $this->customerSupport($headers, 'GET', '/cases/'.$case['id'].'/messages')->assertOk()->assertHeader('Content-Type', 'application/json')->json('data.0.body'));
        // Control characters are removed; the timeline never holds message text.
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => "a\x00b\x07c\x1bd"])->assertCreated()->assertJsonPath('message.body', 'abcd');
        $this->assertStringNotContainsString('script', (string) json_encode(SupportCaseEvent::query()->get()->toArray()));

        // The same client id again: the same message, one row.
        $first = $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Sent from a train', 'client_message_id' => 'msg-0001-abcdef'])->assertCreated()->json('message.id');
        $again = $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Sent from a train', 'client_message_id' => 'msg-0001-abcdef'])->assertCreated()->json('message.id');
        $this->assertSame($first, $again);
        $this->assertSame(3, SupportCaseMessage::query()->count());
        $this->assertSame(3, $this->caseModel($case['id'])->message_count);

        // Opening a case twice with the same Idempotency-Key: one case.
        $key = ['Idempotency-Key' => 'case-create-0001-abcdef'];
        $body = ['category' => 'OTHER', 'subject' => 'Once', 'message' => 'Only once'];
        $one = $this->json('POST', '/api/v1/customer/support/cases', $body, $this->using($headers) + $key)->assertCreated()->json('case.id');
        $two = $this->json('POST', '/api/v1/customer/support/cases', $body, $this->using($headers) + $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json('case.id');
        $this->assertSame($one, $two);
        $this->assertSame(2, SupportCase::query()->count());

        // The timeline is numbered per case and cannot be rewritten.
        $sequences = SupportCaseEvent::query()->where('case_id', $this->caseModel($case['id'])->getKey())->orderBy('id')->pluck('sequence')->all();
        $this->assertSame(range(1, count($sequences)), $sequences);
        $this->assertSame(count($sequences), $this->caseModel($case['id'])->event_sequence);
    }

    public function test_a_customer_cannot_keep_opening_cases_without_end(): void
    {
        config(['support.limits.open_cases_per_requester' => 2]);
        [, $headers] = $this->signedIn();
        $this->customerCase($headers);
        $second = $this->customerCase($headers);
        $this->customerSupport($headers, 'POST', '/cases', ['category' => 'OTHER', 'subject' => 'A third', 'message' => 'x'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_open_cases');
        // Withdrawing one makes room again: the cap is about cases that are being worked on.
        $this->customerSupport($headers, 'POST', '/cases/'.$second['id'].'/close', ['intent' => 'WITHDRAW'])->assertOk();
        $this->customerCase($headers);
        $this->assertSame(3, SupportCase::query()->count());
    }
}
