<?php

namespace Tests\Feature\Support;

use App\Enums\SupportCaseStatus;
use App\Models\CustomerNotification;
use App\Models\SupportCase;
use App\Models\SupportCaseEscalation;
use App\Models\SupportCaseEvent;
use App\Models\SupportSlaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — internal targets (SLA) from real timestamps, what the clock does, and what a customer is told.
 *
 * The targets in these tests are TEST FIXTURES. No target is a commitment to a requester and none is shown to one.
 */
class SupportSlaAndNotificationTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_a_case_without_a_policy_is_not_measured_and_the_most_specific_policy_applies(): void
    {
        [, $headers] = $this->signedIn();
        $unmeasured = $this->customerCase($headers);
        $this->assertSame(['N/A', null, null], [$this->caseModel($unmeasured['id'])->sla_status->value, $this->caseModel($unmeasured['id'])->first_response_due_at, $this->caseModel($unmeasured['id'])->resolution_due_at]);

        $this->slaPolicy(['name' => 'General (TEST)', 'first_response_minutes' => 240, 'resolution_minutes' => 2880]);
        $this->slaPolicy(['name' => 'Payments (TEST)', 'category' => 'PAYMENT', 'first_response_minutes' => 120, 'resolution_minutes' => 1440]);
        $this->slaPolicy(['name' => 'High payments (TEST)', 'category' => 'PAYMENT', 'priority' => 'HIGH', 'first_response_minutes' => 30, 'resolution_minutes' => 480]);
        $this->slaPolicy(['name' => 'Retired (TEST)', 'category' => 'PAYMENT', 'priority' => 'HIGH', 'team' => 'PAYMENTS', 'first_response_minutes' => 1, 'resolution_minutes' => 2, 'status' => 'RETIRED']);
        $general = $this->caseModel($this->customerCase($headers, ['subject' => 'An order question'])['id']);
        $payment = $this->caseModel($this->customerCase($headers, ['category' => 'PAYMENT', 'subject' => 'A payment question'])['id']);
        $charged = $this->caseModel($this->customerCase($headers, ['category' => 'PAYMENT', 'subcategory' => 'PAYMENT_CHARGED_NO_ORDER', 'subject' => 'Charged, no order'])['id']);
        $minutes = fn (SupportCase $c): array => [$c->slaPolicy->name, (int) $c->created_at->diffInMinutes($c->first_response_due_at), (int) $c->created_at->diffInMinutes($c->resolution_due_at), $c->sla_status->value];
        $this->assertSame(['General (TEST)', 240, 2880, 'ON_TRACK'], $minutes($general));
        $this->assertSame(['Payments (TEST)', 120, 1440, 'ON_TRACK'], $minutes($payment));
        $this->assertSame(['High payments (TEST)', 30, 480, 'ON_TRACK'], $minutes($charged));

        // The desk reads the targets as internal; the customer reads none of it.
        $this->agent();
        $sla = $this->desk('GET', '/cases/'.$charged->public_id)->assertOk()->json('case.sla');
        $this->assertSame(['ON_TRACK', 'High payments (TEST)', 'TEST', false, false], [$sla['status'], $sla['policy']['name'], $sla['policy']['approval'], $sla['policy']['contractual'], $sla['contractual']]);
        $catalog = $this->desk('GET', '/catalog')->assertOk()->json('sla');
        $this->assertSame([false, 4], [$catalog['contractual'], count($catalog['policies'])]);
        $customer = $this->customerSupport($headers, 'GET', '/cases/'.$charged->public_id)->assertOk()->getContent().$this->customerSupport($headers, 'GET', '/catalog')->getContent();
        foreach (['sla', 'due_at', 'target', 'High payments', 'response_minutes'] as $word) {
            $this->assertStringNotContainsString($word, $customer, $word);
        }

        // A change of priority brings another policy: the targets follow it.
        $this->desk('POST', '/cases/'.$payment->public_id.'/priority', ['priority' => 'HIGH', 'reason' => 'Money was taken twice.', 'expected_version' => 1])->assertOk();
        $this->assertSame(['High payments (TEST)', 30, 480, 'ON_TRACK'], $minutes($payment->refresh()));
    }

    public function test_at_risk_and_breached_come_from_the_clock_and_a_breach_stays_a_breach(): void
    {
        $this->slaPolicy(['first_response_minutes' => 60, 'resolution_minutes' => 600]);
        [, $headers] = $this->signedIn();
        $id = $this->customerCase($headers)['id'];
        $this->agent();
        $state = fn (): array => [$this->caseModel($id)->sla_status->value, $this->desk('GET', '/queues')->json('queues.sla_at_risk'), $this->desk('GET', '/queues')->json('queues.sla_breached')];

        $this->travel(40)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['ON_TRACK', 0, 0], $state());
        // 80 % of the hour is gone and nobody answered: at risk — written to the timeline once.
        $this->travel(10)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['AT_RISK', 1, 0], $state());
        $this->assertSame(1, SupportCaseEvent::query()->where('type', SupportCaseEvent::SLA_AT_RISK)->count());
        // Past the hour: breached, once, with the target that was missed.
        $this->travel(15)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['BREACHED', 0, 1], $state());
        $breach = SupportCaseEvent::query()->where('type', SupportCaseEvent::SLA_BREACHED)->sole();
        $this->assertSame(['INTERNAL', 'FIRST_RESPONSE', 'SYSTEM'], [$breach->audience, $breach->detail['target'], $breach->actor_type]);
        $this->assertNotNull($this->caseModel($id)->sla_breached_at);

        // Answering late does not undo the breach, and resolving the case does not either: no made-up success.
        $this->desk('POST', '/cases/'.$id.'/messages', ['message' => 'Sorry for the wait.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->assertSame('BREACHED', $this->caseModel($id)->sla_status->value);
        $this->desk('POST', '/cases/'.$id.'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Done.', 'expected_version' => $this->version($id)])->assertOk();
        $this->assertSame('BREACHED', $this->caseModel($id)->sla_status->value);
        $this->assertSame(65, (int) round($this->desk('GET', '/metrics')->json('first_response_minutes_avg_30d')));
        // The requester's timeline has no trace of a target; the customer's case neither.
        $this->assertStringNotContainsString('BREACH', $this->customerSupport($headers, 'GET', '/cases/'.$id)->getContent());
        // Escalation by the clock is a switch, and it is off.
        $this->assertSame(0, SupportCaseEscalation::query()->count());

        config(['support.sla.auto_escalate' => true]);
        $second = $this->customerCase($headers, ['subject' => 'Another one'])['id'];
        $this->travel(61)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        $escalation = SupportCaseEscalation::query()->sole();
        $this->assertSame([$this->caseModel($second)->getKey(), 'SLA_RISK', 'L2', null, 'OPEN'], [$escalation->case_id, $escalation->reason->value, $escalation->level, $escalation->escalated_by_admin_id, $escalation->status]);
    }

    public function test_the_clock_stands_still_while_support_waits_for_the_requester(): void
    {
        $this->slaPolicy(['first_response_minutes' => 60, 'resolution_minutes' => 120, 'pause_while_waiting_for_requester' => true]);
        [, $headers] = $this->signedIn();
        $id = $this->customerCase($headers)['id'];
        $this->agent();
        $due = fn (): int => (int) $this->caseModel($id)->created_at->diffInMinutes($this->caseModel($id)->resolution_due_at);

        $this->travel(30)->minutes();
        $this->desk('POST', '/cases/'.$id.'/messages', ['message' => 'Which order was it?', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($id)])->assertCreated();
        $this->assertSame(['PAUSED', 120], [$this->caseModel($id)->sla_status->value, $due()]);
        $this->assertNotNull($this->caseModel($id)->first_response_at);
        // Four hours of silence from the customer: nothing is breached, the sweep leaves it alone.
        $this->travel(240)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['PAUSED', null], [$this->caseModel($id)->sla_status->value, $this->caseModel($id)->sla_breached_at]);
        // The customer answers: the clock runs again and the target moved by exactly the time it stood still.
        $this->customerSupport($headers, 'POST', '/cases/'.$id.'/messages', ['message' => 'The burger order.'])->assertCreated();
        $case = $this->caseModel($id);
        $this->assertSame([SupportCaseStatus::Open, 'ON_TRACK', 240 * 60, 360, null], [$case->status, $case->sla_status->value, $case->sla_paused_seconds, $due(), $case->sla_paused_at]);
        // Waiting for the PROVIDER is not waiting for the requester: that time counts.
        $this->agent();
        $this->desk('POST', '/cases/'.$id.'/wait', ['for' => 'PROVIDER', 'expected_version' => $this->version($id)])->assertOk();
        $this->travel(100)->minutes();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame('BREACHED', $this->caseModel($id)->sla_status->value);

        // A policy that does not pause: waiting for the customer counts as well.
        SupportSlaPolicy::query()->update(['pause_while_waiting_for_requester' => false]);
        $other = $this->customerCase($headers, ['subject' => 'No pause'])['id'];
        $this->agent();
        $this->desk('POST', '/cases/'.$other.'/messages', ['message' => 'Please tell us more.', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($other)])->assertCreated();
        $this->assertSame('ON_TRACK', $this->caseModel($other)->sla_status->value);
    }

    public function test_the_auto_close_rules_do_nothing_until_the_business_sets_the_days(): void
    {
        [$customer, $headers] = $this->signedIn();
        $waiting = $this->customerCase($headers, ['subject' => 'Waiting'])['id'];
        $resolved = $this->customerCase($headers, ['subject' => 'Resolved'])['id'];
        $this->agent();
        $this->desk('POST', '/cases/'.$waiting.'/messages', ['message' => 'Which order?', 'visibility' => 'EXTERNAL', 'then' => 'WAITING_FOR_CUSTOMER', 'expected_version' => $this->version($waiting)])->assertCreated();
        $this->desk('POST', '/cases/'.$resolved.'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Done.', 'expected_version' => 1])->assertOk();

        // Unset (the default): a year passes and nothing is closed by the clock.
        $this->travel(365)->days();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['WAITING_FOR_CUSTOMER', 'RESOLVED'], [$this->caseModel($waiting)->status->value, $this->caseModel($resolved)->status->value]);

        config(['support.auto_close.no_response_after_days' => 7, 'support.auto_close.resolved_after_days' => 14]);
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        $silent = $this->caseModel($waiting);
        $this->assertSame(['RESOLVED', 'NO_ACTION_REQUIRED', 'CLOSED'], [$silent->status->value, $silent->resolution_code, $this->caseModel($resolved)->status->value]);
        // The customer is told in the case itself, by the system — and can still reopen inside the window.
        $headers = $this->sessionOf($customer);   // a year later the old session is over
        $thread = $this->customerSupport($headers, 'GET', '/cases/'.$waiting.'/messages')->assertOk()->json('data');
        $this->assertSame(['YOU', 'SUPPORT', 'SYSTEM'], array_column($thread, 'author'));
        $this->assertTrue($this->customerSupport($headers, 'GET', '/cases/'.$waiting)->json('case.can.reopen'));
    }

    public function test_a_customer_is_told_once_about_their_case_a_reply_and_a_resolution_and_never_about_a_note(): void
    {
        [$customer, $headers] = $this->signedIn();
        $case = $this->customerCase($headers, ['subject' => 'SUBJECT-SECRET-551']);
        $types = fn (): array => CustomerNotification::query()->where('customer_id', $customer->getKey())->orderBy('id')->pluck('event_type')->all();
        $this->assertSame(['support.case_created'], $types());

        $this->agent();
        // An internal note tells nobody anything.
        $this->desk('POST', '/cases/'.$case['id'].'/notes', ['note' => 'NOTE-SECRET-551', 'visibility' => 'INTERNAL'])->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/priority', ['priority' => 'HIGH', 'reason' => 'x y z', 'expected_version' => 1])->assertOk();
        $this->desk('POST', '/cases/'.$case['id'].'/assign', ['team' => 'PAYMENTS', 'expected_version' => 2])->assertOk();
        $this->assertSame(['support.case_created'], $types());
        // Each reply once — a retry of the same reply is not a second notice.
        $body = ['message' => 'REPLY-SECRET-551 please check your bank statement.', 'visibility' => 'EXTERNAL', 'client_message_id' => 'reply-0000000001'];
        $this->desk('POST', '/cases/'.$case['id'].'/messages', $body)->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/messages', $body)->assertCreated();
        $this->desk('POST', '/cases/'.$case['id'].'/messages', ['message' => 'A second reply.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->assertSame(['support.case_created', 'support.reply', 'support.reply'], $types());
        // What the customer writes themselves is no news to them.
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/messages', ['message' => 'Thanks'])->assertCreated();
        $this->assertSame(3, count($types()));
        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'RESOLUTION-SECRET-551', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->assertSame(['support.case_created', 'support.reply', 'support.reply', 'support.case_resolved'], $types());

        // The inbox item: where it leads (the case, by its number) and what it says — never the text of the case.
        $inbox = $this->getJson('/api/v1/customer/notifications', $this->using($headers))->assertOk()->json('data');
        $this->assertCount(4, $inbox);
        $reply = collect($inbox)->firstWhere('type', 'support.reply');
        $this->assertSame(['SUPPORT_CASE', $case['case_number'], 'SUPPORT_CASE', $case['case_number'], 'ACCOUNT_SECURITY'], [$reply['action']['target'], $reply['action']['reference'], $reply['subject']['type'], $reply['subject']['reference'], $reply['category']]);
        $this->assertStringContainsString($case['case_number'], $reply['body']);
        foreach (['SECRET-551', 'bank statement'] as $leak) {
            $this->assertStringNotContainsString($leak, json_encode($inbox), $leak);
        }
        // Reopened and resolved again: the customer is told again (it is another resolution).
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', ['message' => 'Not solved'])->assertOk();
        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Again.', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->assertSame(2, count(array_keys($types(), 'support.case_resolved', true)));

        // A restaurant's case and a system case notify no customer: only customers have an inbox.
        $before = CustomerNotification::query()->count();
        $this->asActor($this->member($this->organization, 'OWNER'));
        $restaurant = $this->restaurantCase();
        $this->agent();
        $this->desk('POST', '/cases/'.$restaurant['id'].'/messages', ['message' => 'We are on it.', 'visibility' => 'EXTERNAL'])->assertCreated();
        $this->desk('POST', '/cases', ['category' => 'OTHER', 'subject' => 'Internal', 'note' => 'Internal work'])->assertCreated();
        $this->assertSame($before, CustomerNotification::query()->count());
        // The restaurant sees an unread mark instead.
        $this->asActor($this->member($this->organization, 'OWNER'));
        $this->assertSame(1, $this->restaurantSupport('GET', '/cases')->assertOk()->json('unread'));
    }
}
