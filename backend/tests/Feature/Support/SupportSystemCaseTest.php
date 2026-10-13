<?php

namespace Tests\Feature\Support;

use App\Enums\DifferenceType;
use App\Enums\DisputeStatus;
use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Events\Payment\PaymentVerified;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\ReconciliationDifference;
use App\Models\Refund;
use App\Models\SupportCase;
use App\Models\SupportCaseLink;
use App\Services\Finance\DifferenceService;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Support\SystemCaseService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 37 — cases the system opens by itself: once per condition, linked to the record they are about, telling
 * people and changing nothing.
 */
class SupportSystemCaseTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /** A payment that is VERIFIED while nothing turned it into an order (the listener is switched off for a moment). */
    private function verifiedWithoutOrder(string $phone = '+919876543210'): array
    {
        $real = Event::getFacadeRoot();
        Event::fake([PaymentVerified::class]);
        [, $headers, $checkout, $payment] = $this->openPayment($phone);
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->assertJsonPath('payment.status', 'VERIFIED');
        Event::swap($real);

        return [$headers, $checkout, $payment];
    }

    public function test_a_verified_payment_that_cannot_become_an_order_opens_one_recovery_case(): void
    {
        [$headers, , $payment] = $this->verifiedWithoutOrder();
        // Something impossible happened: the verified amount is not the checkout's total. The order is never created.
        DB::table('payment_attempts')->where('public_id', $payment['id'])->update(['amount_minor' => 49900]);
        app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED'], [$attempt->status->value, $attempt->fulfillment_status->value]);

        // The payment's own event opened the case: linked to the payment, in the payments queue, high priority.
        $case = SupportCase::query()->sole();
        $this->assertSame(['SYSTEM', 'PAYMENT_RECOVERY', 'PAYMENT', 'PAYMENT_CHARGED_NO_ORDER', 'HIGH', 'PAYMENTS', 'OPEN', 'payment-recovery:'.$attempt->public_id, $attempt->customer_id, null, null],
            [$case->requester_type->value, $case->source->value, $case->category, $case->subcategory, $case->priority->value, $case->assigned_team->value, $case->status->value, $case->system_key, $case->customer_id, $case->requester_restaurant_user_id, $case->created_by_admin_id]);
        $this->assertSame([['PAYMENT', $attempt->supportReference()]], SupportCaseLink::query()->get()->map(fn (SupportCaseLink $l): array => [$l->link_type->value, $l->reference])->all());
        $this->assertSame(0, $case->message_count, 'a system case writes to nobody');

        // Reported again — by a retry of the creation, by the service, by the sweep: still one case.
        app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        app(SystemCaseService::class)->paymentRecovery($attempt->refresh());
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(1, SupportCase::query()->count());
        // Opening the case did nothing to the payment or the order creation.
        $this->assertSame(['VERIFIED', 'RECOVERY_REQUIRED', 0], [$attempt->refresh()->status->value, $attempt->fulfillment_status->value, Order::query()->count()]);

        // The customer does not see an internal case about their payment; the desk does, with what the system saw.
        $this->assertSame([], $this->customerSupport($headers, 'GET', '/cases')->assertOk()->json('data'));
        $this->customerSupport($headers, 'GET', '/cases/'.$case->public_id)->assertNotFound();
        $this->agent([...self::DESK, Permission::AdminPaymentsView, Permission::AdminOrdersRecover]);
        $detail = $this->desk('GET', '/cases/'.$case->case_number)->assertOk()->json('case');
        $this->assertSame(['SYSTEM', false, 'HIGH'], [$detail['requester']['type'], $detail['can']['reply'], $detail['priority']]);
        $note = $this->desk('GET', '/cases/'.$case->public_id.'/notes')->assertOk()->json('data.0');
        $this->assertSame(['SYSTEM', true], [$note['author_name'], $note['system']]);
        $this->assertStringContainsString('ORDER_PAYMENT_MISMATCH', $note['body']);
        $panel = $this->desk('GET', '/cases/'.$case->public_id.'/links')->assertOk()->json('links.0');
        $this->assertSame(['RECOVERY_REQUIRED', 'ORDER_PAYMENT_MISMATCH'], [$panel['record']['order_creation'], $panel['record']['order_creation_reason']]);
        // The shortcuts of a payment that needs recovery — each with the permission of ITS domain.
        $this->assertSame([['PAYMENT_RECONCILE', false, 'admin.payments.reconcile'], ['ORDER_RECOVER', true, 'admin.orders.recover']], array_map(fn (array $s): array => [$s['action'], $s['allowed'], $s['required_permission']], $panel['shortcuts']));
        $result = $this->desk('POST', '/cases/'.$case->public_id.'/domain-actions', ['action' => 'ORDER_RECOVER', 'link' => $panel['id'], 'reason' => 'Trying the creation again.'])->assertOk()->json('result');
        $this->assertSame(['ORDER_RECOVER', 'REVIEW_REQUIRED'], [$result['action'], $result['before']]);

        // A person cancels the case while the condition is still there: the clock does NOT open it again (it would come
        // back every minute) — a condition has one case, whatever became of it. Not even a repeated report reopens a
        // cancelled case: a person decided it is not one.
        $this->desk('POST', '/cases/'.$case->public_id.'/cancel', ['resolution_code' => 'OTHER', 'note' => 'Handled by phone, refund asked for.', 'expected_version' => $this->version((string) $case->public_id)])->assertOk();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        app(SystemCaseService::class)->paymentRecovery($attempt->refresh(), reported: true);
        $this->assertSame([1, 0, 'CANCELLED'], [SupportCase::query()->count(), SupportCase::query()->where('status', 'OPEN')->count(), $case->refresh()->status->value]);
    }

    public function test_a_refund_that_needs_a_person_opens_one_review_case_linked_to_the_refund(): void
    {
        // A refund is owed and approved; then the customer's bank opens a dispute: sending the refund now needs a person.
        [, $headers, $order, $payment, $refund] = $this->rejectedPaidOrder();
        $this->finance();
        $this->refundPost($refund, 'approve')->assertOk();
        $pay = (string) $this->attempt($payment['id'])->provider_payment_reference;
        $this->providerSendsDispute('payment.dispute.created', $this->fakeProvider()->openDispute($pay))->assertOk();
        $this->finance();
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'REVIEW_REQUIRED');

        $case = SupportCase::query()->where('system_key', 'refund-review:'.$refund->public_id)->sole();
        $this->assertSame(['SYSTEM', 'REFUND_RECOVERY', 'REFUND', 'HIGH', 'PAYMENTS', 'STANDARD'], [$case->requester_type->value, $case->source->value, $case->category, $case->priority->value, $case->assigned_team->value, $case->classification]);
        $this->assertEqualsCanonicalizing(['REFUND', 'ORDER', 'PAYMENT'], SupportCaseLink::query()->where('case_id', $case->getKey())->pluck('link_type')->map(fn ($t) => $t->value)->all());
        $this->assertSame($refund->supportReference(), SupportCaseLink::query()->where('case_id', $case->getKey())->where('link_type', 'REFUND')->value('reference'));
        // Found by the refund's reference and by the order's number.
        $this->agent([...self::DESK, Permission::AdminRefundsView]);
        foreach ([$refund->supportReference(), $order['order_number'], (string) $refund->public_id] as $q) {
            $this->assertContains((string) $case->public_id, array_column($this->desk('GET', '/cases?q='.$q)->assertOk()->json('data'), 'id'), $q);
        }
        $panel = collect($this->desk('GET', '/cases/'.$case->public_id.'/links')->assertOk()->json('links'))->firstWhere('type', 'REFUND');
        $this->assertSame(['REVIEW_REQUIRED', 'DISPUTE_OPEN'], [$panel['record']['status'], $panel['record']['review_reason']]);
        $this->assertStringContainsString('NOT refunded', $this->desk('GET', '/cases/'.$case->public_id.'/notes')->assertOk()->json('data.0.body'));

        // The dispute itself opened a finance-restricted case of its own; the sweep adds nothing.
        $dispute = SupportCase::query()->where('category', 'DISPUTE')->first();
        $this->artisan('support:sweep')->assertSuccessful();
        $dispute ??= SupportCase::query()->where('category', 'DISPUTE')->sole();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(['RESTRICTED', 'FINANCE', 'FINANCE', 'HIGH'], [$dispute->classification, $dispute->restricted_class, $dispute->assigned_team->value, $dispute->priority->value]);
        $this->assertSame(2, SupportCase::query()->count());
        // The desk agent does not see the dispute case at all; finance does, with the provider's deadline.
        $this->assertSame([(string) $case->public_id], array_column($this->desk('GET', '/cases')->assertOk()->json('data'), 'id'));
        $this->desk('GET', '/cases/'.$dispute->public_id)->assertForbidden()->assertJsonPath('error.code', 'case_restricted');
        $this->seed(RoleSeeder::class);
        $this->seededAgent('FINANCE_ADMIN');
        $disputePanel = collect($this->desk('GET', '/cases/'.$dispute->public_id.'/links')->assertOk()->json('links'))->firstWhere('type', 'DISPUTE');
        $this->assertSame(['OPEN', 'NOT_PROVIDED'], [$disputePanel['record']['status'], $disputePanel['record']['response']]);
        $this->assertNotNull($disputePanel['record']['respond_by_at']);
        // Neither the customer nor the refund was touched by any of it.
        $this->assertSame([], $this->customerSupport($headers, 'GET', '/cases')->assertOk()->json('data'));
        $this->assertSame(RefundStatus::ReviewRequired, Refund::query()->sole()->status);
    }

    public function test_a_serious_reconciliation_difference_opens_one_finance_restricted_case(): void
    {
        $differences = app(DifferenceService::class);
        $high = $differences->report(DifferenceType::AmountMismatch, 'test:amount:1', ['market_id' => $this->market->getKey(), 'subject_reference' => 'PAY-TEST000001', 'currency' => 'INR', 'expected_minor' => 50000, 'actual_minor' => 49000]);
        $low = $differences->report(DifferenceType::UnknownReference, 'test:unknown:1', ['market_id' => $this->market->getKey()]);
        $this->assertSame(['HIGH', 'LOW'], [(string) $high->severity, (string) $low->severity]);
        // The finance domain raises no event: the sweep finds the difference.
        $this->assertSame(0, SupportCase::query()->count());
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();

        $case = SupportCase::query()->sole();
        $this->assertSame(['SYSTEM', 'FINANCE_RECONCILIATION', 'SETTLEMENT', 'RECONCILIATION_DIFFERENCE', 'HIGH', 'FINANCE', 'RESTRICTED', 'FINANCE', 'finance-difference:'.$high->public_id],
            [$case->requester_type->value, $case->source->value, $case->category, $case->subcategory, $case->priority->value, $case->assigned_team->value, $case->classification, $case->restricted_class, $case->system_key]);
        $this->assertSame(['DIFFERENCE'], SupportCaseLink::query()->pluck('link_type')->map(fn ($t) => $t->value)->all());
        // Seen again by the reconciliation: the same difference, the same case.
        $differences->report(DifferenceType::AmountMismatch, 'test:amount:1', ['market_id' => $this->market->getKey(), 'currency' => 'INR', 'expected_minor' => 50000, 'actual_minor' => 49000]);
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame([1, 2], [SupportCase::query()->count(), ReconciliationDifference::query()->count()]);

        // Finance reads it with the difference's own figures; support does not see it; the case closes no difference.
        $this->seed(RoleSeeder::class);
        $this->seededAgent('SUPPORT_ADMIN');
        $this->assertSame([], $this->desk('GET', '/cases')->assertOk()->json('data'));
        $this->seededAgent('FINANCE_ADMIN');
        $panel = $this->desk('GET', '/cases/'.$case->public_id.'/links')->assertOk()->json('links.0');
        $this->assertSame(['AMOUNT_MISMATCH', 'HIGH', 'OPEN', 50000, 49000], [$panel['record']['type'], $panel['record']['severity'], $panel['record']['status'], $panel['record']['expected']['amount'], $panel['record']['actual']['amount']]);
        $this->desk('POST', '/cases/'.$case->public_id.'/resolve', ['resolution_code' => 'OTHER', 'note' => 'Explained in the reconciliation queue.', 'expected_version' => 1])->assertOk();
        $this->assertSame('OPEN', $high->refresh()->status->value);
        // Resolved is not closed: no second case while this one is only resolved.
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(1, SupportCase::query()->count());

        // The switch: with the severity threshold off nothing is opened.
        config(['support.system_cases.finance_difference_min_severity' => null]);
        $differences->report(DifferenceType::CurrencyMismatch, 'test:currency:1', ['market_id' => $this->market->getKey()]);
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(1, SupportCase::query()->count());
    }

    public function test_a_security_case_is_restricted_from_the_start_and_nothing_opens_one_automatically(): void
    {
        [$customer] = $this->signedIn();
        $system = app(SystemCaseService::class);
        $case = $system->security('pickup-lockout:'.$this->burgerHub->public_id, 'Repeated wrong pickup codes at one counter', 'Five wrong codes in two minutes at this location.', [], ['customer_id' => $customer->getKey(), 'market_id' => $this->market->getKey()]);
        $again = $system->security('pickup-lockout:'.$this->burgerHub->public_id, 'Repeated wrong pickup codes at one counter', 'And again.');
        $this->assertSame((string) $case->public_id, (string) $again->public_id);
        $this->assertSame(['SYSTEM', 'SECURITY', 'SECURITY', 'RESTRICTED', 'SECURITY', 'HIGH', 'SECURITY'], [$case->requester_type->value, $case->source->value, $case->category, $case->classification, $case->restricted_class, $case->priority->value, $case->assigned_team->value]);
        $this->agent();
        $this->desk('GET', '/cases/'.$case->public_id)->assertForbidden();
        $this->agent([...self::DESK, Permission::AdminSupportRestrictedSecurity]);
        $this->desk('GET', '/cases/'.$case->public_id)->assertOk()->assertJsonPath('case.classification', 'RESTRICTED');
        // The health view says it as it is: readiness only.
        $this->agent([Permission::AdminSystemView]);
        $this->actingAsPrincipal($this->actor);
        $health = $this->getJson('/api/v1/admin/system/support')->assertOk()->json();
        $this->assertSame(['READINESS_ONLY', 'NOT_DEFINED', false, false, false, false, 'DISABLED', 'NOT_CONFIGURED', 'NO'],
            [$health['policies']['system_cases']['value']['security'], $health['flags']['security_admin_role'], $health['flags']['contractual_sla'], $health['flags']['support_marks_payment_verified'], $health['flags']['support_marks_refund_completed'], $health['flags']['support_edits_settlement_or_payout'], $health['flags']['attachments'], $health['flags']['attachment_scanner'], $health['flags']['production']]);
        $this->assertSame([1, 1, 1], [$health['cases']['total'], $health['cases']['restricted'], $health['events']['case_created_system']]);
    }

    public function test_an_open_provider_dispute_opens_one_finance_restricted_case_and_a_decided_dispute_opens_none(): void
    {
        [, , , , $order] = $this->paidOrder('+919876543210', '13:00');
        $attempt = $this->orderModel($order['order_number'])->paymentAttempt;
        $opened = $this->fakeProvider()->openDispute((string) $attempt->provider_payment_reference, null, 'goods_not_received');
        $this->providerSendsDispute('payment.dispute.created', $opened)->assertOk();
        $dispute = PaymentDispute::query()->sole();
        // The dispute domain raises no event for support: the sweep finds the open dispute — once.
        $this->assertSame(0, SupportCase::query()->count());
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();

        $case = SupportCase::query()->sole();
        $this->assertSame(['SYSTEM', 'DISPUTE', 'HIGH', 'FINANCE', 'RESTRICTED', 'FINANCE', 'dispute:'.$dispute->public_id, 0],
            [$case->requester_type->value, $case->category, $case->priority->value, $case->assigned_team->value, $case->classification, $case->restricted_class, $case->system_key, (int) $case->message_count]);
        $this->assertEqualsCanonicalizing(['DISPUTE', 'PAYMENT', 'ORDER'], SupportCaseLink::query()->pluck('link_type')->map(fn ($t) => $t->value)->all());

        // Support does not see it; finance reads the provider's deadline and that nobody answers a dispute from here.
        $this->seed(RoleSeeder::class);
        $this->seededAgent('SUPPORT_ADMIN');
        $this->assertSame([], $this->desk('GET', '/cases')->assertOk()->json('data'));
        $this->desk('GET', '/cases/'.$case->public_id)->assertForbidden()->assertJsonPath('error.code', 'case_restricted');
        $this->seededAgent('FINANCE_ADMIN');
        $panel = collect($this->desk('GET', '/cases/'.$case->public_id.'/links')->assertOk()->json('links'))->firstWhere('type', 'DISPUTE');
        $this->assertSame([true, 'OPEN', $opened->reference, 'NOT_PROVIDED', []], [$panel['visible'], $panel['record']['status'], $panel['record']['provider_dispute_reference'], $panel['record']['response'], $panel['shortcuts']]);
        $this->assertNotNull($panel['record']['respond_by_at']);
        // There is no way to answer, accept or decide a dispute through a case.
        foreach (['DISPUTE_RESPOND', 'DISPUTE_ACCEPT', 'MARK_DISPUTE_WON'] as $invented) {
            $this->desk('POST', '/cases/'.$case->public_id.'/domain-actions', ['action' => $invented, 'link' => $panel['id'], 'reason' => 'Trying.'])->assertStatus(422);
        }
        // Closing the case decides nothing about the dispute.
        $this->desk('POST', '/cases/'.$case->public_id.'/resolve', ['resolution_code' => 'OTHER', 'note' => 'Evidence was sent in the provider dashboard.', 'expected_version' => 1])->assertOk();
        $this->desk('POST', '/cases/'.$case->public_id.'/close', ['expected_version' => 2])->assertOk();
        $this->assertSame('OPEN', $dispute->refresh()->status->value);

        // The provider decides it: a decided dispute opens no new case.
        $this->providerSendsDispute('payment.dispute.won', $this->fakeProvider()->settleDispute($opened->reference, DisputeStatus::Won))->assertOk();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame(1, SupportCase::query()->count());
    }

    public function test_a_condition_has_one_case_and_a_closed_one_is_reopened_only_when_the_condition_is_reported_again(): void
    {
        [, , $payment] = $this->verifiedWithoutOrder();
        DB::table('payment_attempts')->where('public_id', $payment['id'])->update(['amount_minor' => 49900]);
        app(OrderFulfillmentService::class)->fulfillPayment($payment['id']);
        $attempt = PaymentAttempt::query()->sole();
        $case = SupportCase::query()->sole();
        $this->agent();
        $this->desk('POST', '/cases/'.$case->public_id.'/resolve', ['resolution_code' => 'OTHER', 'note' => 'Handled with the customer by phone.', 'expected_version' => 1])->assertOk();
        $this->desk('POST', '/cases/'.$case->public_id.'/close', ['expected_version' => 2])->assertOk();

        // The condition still holds and its case is closed: the clock leaves it closed — minute after minute.
        $this->artisan('support:sweep')->assertSuccessful();
        $this->artisan('support:sweep')->assertSuccessful();
        $this->assertSame([1, 'CLOSED'], [SupportCase::query()->count(), $case->refresh()->status->value]);

        // The payment domain REPORTS the condition again (its own event): the same case is open again, with a note.
        $again = app(SystemCaseService::class)->paymentRecovery($attempt, reported: true);
        $this->assertSame([(string) $case->public_id, 'REOPENED', 1, 1], [(string) $again->public_id, $again->status->value, SupportCase::query()->count(), (int) $again->reopen_count]);
        $notes = array_column($this->desk('GET', '/cases/'.$case->public_id.'/notes')->assertOk()->json('data'), 'body');
        $this->assertCount(2, $notes);
        $this->assertStringStartsWith('Reported again:', $notes[1]);
        // …and reported once more while it is open: nothing changes.
        app(SystemCaseService::class)->paymentRecovery($attempt, reported: true);
        $this->assertSame([1, 2], [(int) $case->refresh()->reopen_count, count($this->desk('GET', '/cases/'.$case->public_id.'/notes')->json('data'))]);
    }
}
