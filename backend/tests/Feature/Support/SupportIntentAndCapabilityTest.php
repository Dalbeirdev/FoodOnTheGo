<?php

namespace Tests\Feature\Support;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Services\Support\SupportLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — what the screens needed from the backend once they were built on it.
 *
 * "Close" can say what the requester meant (a confirmation must never turn into a withdrawal because the case changed
 * in between); a repeated reopen is told the truth; a customer reads a payment in a customer's words; the desk is told
 * what it may do (resume, open an internal case) instead of deriving it; a shortcut is offered only to somebody who
 * may also work the case.
 */
class SupportIntentAndCapabilityTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_close_with_an_intent_does_only_what_was_meant(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $path = '/cases/'.$case['id'].'/close';

        // The customer believes the case is resolved; it is still being worked on: nothing is withdrawn.
        $this->customerSupport($headers, 'POST', $path, ['intent' => 'CONFIRM'])->assertStatus(409)->assertJsonPath('error.code', 'case_changed')->assertJsonPath('error.details.status', 'OPEN');
        $this->assertSame('OPEN', $this->caseModel($case['id'])->status->value);
        $this->customerSupport($headers, 'POST', $path, ['intent' => 'CLOSE'])->assertStatus(422);
        $this->customerSupport($headers, 'POST', $path, ['intent' => 'CONFIRM', 'status' => 'CLOSED'])->assertStatus(422);

        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Answered in the conversation.', 'expected_version' => $this->version($case['id'])])->assertOk();
        // …and the other way round: a resolved case is not withdrawn by somebody who meant to withdraw an open one.
        $this->customerSupport($headers, 'POST', $path, ['intent' => 'WITHDRAW'])->assertStatus(409)->assertJsonPath('error.code', 'case_changed')->assertJsonPath('error.details.status', 'RESOLVED');
        $this->assertSame('RESOLVED', $this->caseModel($case['id'])->status->value);
        $this->customerSupport($headers, 'POST', $path, ['intent' => 'CONFIRM'])->assertOk()->assertJsonPath('case.status', 'CLOSED');

        $second = $this->customerCase($headers, ['subject' => 'Another question']);
        $this->customerSupport($headers, 'POST', '/cases/'.$second['id'].'/close', ['intent' => 'WITHDRAW'])->assertOk()->assertJsonPath('case.status', 'CANCELLED');
        $this->assertSame('USER_WITHDREW', (string) $this->caseModel($second['id'])->resolution_code);
    }

    public function test_a_restaurants_close_follows_the_same_rule(): void
    {
        $this->asActor($this->member($this->organization, 'OWNER'));
        $own = $this->restaurantCase();
        $this->restaurantSupport('POST', '/cases/'.$own['id'].'/close', ['intent' => 'CONFIRM'])->assertStatus(409)->assertJsonPath('error.code', 'case_changed');
        $this->restaurantSupport('POST', '/cases/'.$own['id'].'/close', ['intent' => 'WITHDRAW'])->assertOk()->assertJsonPath('case.status', 'CANCELLED');
    }

    public function test_a_repeated_reopen_is_told_that_the_case_is_open_and_the_detail_carries_the_reply_limit(): void
    {
        [, $headers] = $this->signedIn();
        $case = $this->customerCase($headers);
        $this->assertSame((int) config('support.limits.message_max'), $this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case.limits.message_max'));

        $this->agent();
        $this->desk('POST', '/cases/'.$case['id'].'/resolve', ['resolution_code' => 'ANSWERED', 'note' => 'Answered in the conversation.', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', ['message' => 'It happened again.'])->assertOk()->assertJsonPath('case.status', 'OPEN');
        // The answer was lost and support has already picked the case up: the retry is not told "can no longer be reopened".
        $this->desk('POST', '/cases/'.$case['id'].'/start', ['expected_version' => $this->version($case['id'])])->assertOk();
        $messages = $this->caseModel($case['id'])->message_count;
        $this->customerSupport($headers, 'POST', '/cases/'.$case['id'].'/reopen', ['message' => 'It happened again.'])->assertStatus(409)->assertJsonPath('error.code', 'case_already_open')->assertJsonPath('error.details.status', 'IN_PROGRESS');
        $this->assertSame($messages, $this->caseModel($case['id'])->message_count);
    }

    public function test_a_customer_reads_a_linked_payment_in_a_customers_words(): void
    {
        [, $headers, , $payment, $order] = $this->paidOrder('+919876543210', '13:00');
        $case = $this->customerCase($headers, ['category' => 'PAYMENT', 'subject' => 'Was I charged?', 'order' => $order['order_number'], 'payment' => $payment['id']]);
        $link = fn (): array => collect($this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk()->json('case.links'))->firstWhere('type', 'PAYMENT');
        $this->assertSame(['VERIFIED', true], [$link()['status'], $link()['order_created']]);

        // The payment domain's own words for "a person must look" never reach a requester through a case: every state
        // other than paid / failed / cancelled / expired reads PROCESSING.
        $words = [];
        foreach (PaymentStatus::cases() as $status) {
            $words[$status->value] = SupportLinkService::paymentWord($status->value);
        }
        $this->assertSame(['VERIFIED' => 'VERIFIED', 'FAILED' => 'FAILED', 'CANCELLED' => 'CANCELLED', 'EXPIRED' => 'EXPIRED'], array_filter($words, fn (string $w): bool => $w !== 'PROCESSING'));
        $this->assertSame(['CREATED', 'PROVIDER_READY', 'PROCESSING', 'PENDING', 'AUTHORIZED', 'UNKNOWN', 'REVIEW_REQUIRED'], array_keys(array_filter($words, fn (string $w): bool => $w === 'PROCESSING')));
    }

    public function test_the_desk_is_told_what_it_may_do_and_a_shortcut_needs_the_case_and_the_domain(): void
    {
        [, $headers, , $payment] = $this->paidOrder('+919876543210', '13:00');
        $case = $this->customerCase($headers, ['category' => 'PAYMENT', 'subject' => 'Was I charged?', 'payment' => $payment['id']]);
        $detail = fn (): array => $this->desk('GET', '/cases/'.$case['id'])->assertOk()->json('case');
        $shortcut = fn (): array => $this->desk('GET', '/cases/'.$case['id'].'/links')->assertOk()->json('links.0.shortcuts.0');

        // Works cases and holds the payment permissions: the shortcut is theirs.
        $this->agent([...self::DESK, Permission::AdminPaymentsView, Permission::AdminPaymentsReconcile]);
        $this->assertSame(['PAYMENT_RECONCILE', true], [$shortcut()['action'], $shortcut()['allowed']]);
        $this->assertTrue($this->desk('GET', '/catalog')->assertOk()->json('can.create'));
        $this->assertSame([true, false], [$detail()['can']['start'], $detail()['can']['resume']]);
        $this->desk('POST', '/cases/'.$case['id'].'/wait', ['for' => 'PROVIDER', 'expected_version' => $this->version($case['id'])])->assertOk();
        $this->assertTrue($detail()['can']['resume']);
        $this->desk('POST', '/cases/'.$case['id'].'/resume', ['expected_version' => $this->version($case['id'])])->assertOk()->assertJsonPath('case.status', 'IN_PROGRESS');
        $this->assertFalse($detail()['can']['resume']);

        // Holds the payment permissions and only READS cases: the button is not offered, because the endpoint refuses.
        $this->agent([Permission::AdminSupportView, Permission::AdminPaymentsView, Permission::AdminPaymentsReconcile]);
        $this->assertSame(['PAYMENT_RECONCILE', false, 'admin.payments.reconcile'], [$shortcut()['action'], $shortcut()['allowed'], $shortcut()['required_permission']]);
        $this->assertFalse($this->desk('GET', '/catalog')->assertOk()->json('can.create'));
        $link = $this->desk('GET', '/cases/'.$case['id'].'/links')->json('links.0.id');
        $this->desk('POST', '/cases/'.$case['id'].'/domain-actions', ['action' => 'PAYMENT_RECONCILE', 'link' => $link, 'reason' => 'Trying without working the case.'])->assertForbidden();
        // The people a case can be given to are also for somebody who may escalate (and not for a reader).
        $this->desk('GET', '/agents')->assertForbidden();
        $this->agent([Permission::AdminSupportView, Permission::AdminSupportEscalate]);
        $this->desk('GET', '/agents')->assertOk()->assertJsonStructure(['agents']);
    }
}
