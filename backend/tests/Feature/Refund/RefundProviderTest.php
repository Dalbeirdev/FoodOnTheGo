<?php

namespace Tests\Feature\Refund;

use App\Enums\ProviderFailure;
use App\Enums\RefundStatus;
use App\Exceptions\ProviderRefundRejected;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Services\Payment\Data\ProviderRefund;
use App\Services\Refund\RefundReconciliationService;
use App\Services\Refund\RefundSubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — sending a refund to the provider: what each answer becomes, and what happens when no answer comes.
 *
 * A timeout is not a failure and not a success. An unanswered submission is looked up by its receipt; only when the
 * provider holds nothing is the SAME request (same receipt, same idempotency key) sent again — so a retry can never
 * create a second refund. Run against the FAKE provider, which keeps its own record like a real one.
 */
class RefundProviderTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, mixed>, 2: Refund, 3: string} customer, order, an APPROVED refund, the provider payment reference
     */
    private function approvedRefund(string $phone = '+919876543210'): array
    {
        [, $customer, $order, $payment, $refund] = $this->rejectedPaidOrder($phone);
        $this->finance();
        $this->refundPost($refund, 'approve')->assertOk()->assertJsonPath('refund.status', 'APPROVED');

        return [$customer, $order, $refund, (string) $this->attempt($payment['id'])->provider_payment_reference];
    }

    /** @return list<ProviderRefund> */
    private function atProvider(string $paymentReference): array
    {
        return $this->fakeProvider()->refundsOfPayment($paymentReference, false);
    }

    public function test_the_providers_answer_decides_processed_pending_or_failed(): void
    {
        // Processed at once.
        $this->fakeProvider()->refundsBecome('processed');
        [, , $a] = $this->approvedRefund();
        $done = $this->refundPost($a, 'execute')->assertOk()->json('refund');
        $this->assertSame(['REFUNDED', 'processed', 'PROCESSED'], [$done['status'], $done['provider_detail']['status'], $done['attempts'][0]['outcome']]);
        $this->assertStringStartsWith('FAKEARN', $done['provider_detail']['trace_reference']);
        $this->assertNotNull($done['times']['completed_at']);

        // Pending: accepted, not confirmed — PROCESSING, with the next look scheduled. Never "refunded".
        $this->fakeProvider()->refundsBecome('pending');
        [, , $b] = $this->approvedRefund('+919876543212');
        $pending = $this->refundPost($b, 'execute')->assertOk()->json('refund');
        $this->assertSame(['PROCESSING', 'pending', null], [$pending['status'], $pending['provider_detail']['status'], $pending['times']['completed_at']]);
        $this->assertNotNull($pending['reconciliation']['next_at']);
        $this->assertSame(['approve' => false, 'reject' => false, 'execute' => false, 'retry' => false, 'reconcile' => true, 'mark_refunded' => 'NOT_PROVIDED'], $pending['actions']);
        // Sent: it cannot be cancelled here any more (the provider cannot cancel it either).
        $this->refundPost($b, 'reject', ['reason' => 'Changed my mind'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_processing');

        // Failed by the provider.
        $this->fakeProvider()->refundsBecome('failed');
        [, , $c] = $this->approvedRefund('+919876543213');
        $failed = $this->refundPost($c, 'execute')->assertOk()->json('refund');
        $this->assertSame(['FAILED', 'failed', 'REFUND_FAILED', true], [$failed['status'], $failed['provider_detail']['status'], $failed['failure']['code'], $failed['failure']['retryable']]);
    }

    public function test_a_refusal_is_a_failure_with_a_safe_reason_and_only_some_can_be_tried_again(): void
    {
        [, , $refund, $pay] = $this->approvedRefund();

        // The provider's method cannot be refunded: definitive — a new attempt cannot fix it.
        $this->fakeProvider()->rejectNextRefund(ProviderRefundRejected::METHOD_NOT_SUPPORTED);
        $failed = $this->refundPost($refund, 'execute')->assertOk()->json('refund');
        $this->assertSame(['FAILED', 'PROVIDER_REJECTED', 'METHOD_NOT_SUPPORTED', 'The provider cannot refund this payment method.', false], [$failed['status'], $failed['failure']['category'], $failed['failure']['code'], $failed['failure']['message'], $failed['failure']['retryable']]);
        $this->assertSame([false, true], [$failed['actions']['retry'], $failed['actions']['reject']]);
        $this->refundPost($refund, 'retry', ['reason' => 'Try again'])->assertStatus(409)->assertJsonPath('error.code', 'refund_failed');
        $this->assertSame([], $this->atProvider($pay));
        // Closed by a person, with a reason; the amount is free for another decision.
        $this->refundPost($refund, 'reject', ['reason' => 'Refunded by bank transfer is not possible; closing'])->assertOk()->assertJsonPath('refund.status', 'CANCELLED')->assertJsonPath('refund.balance.available', $this->inr(50000));

        // The provider account is short of balance: a later, NEW attempt may succeed.
        [, , $second, $pay2] = $this->approvedRefund('+919876543212');
        $this->fakeProvider()->rejectNextRefund(ProviderRefundRejected::INSUFFICIENT_BALANCE);
        $this->refundPost($second, 'execute')->assertOk()->assertJsonPath('refund.status', 'FAILED')->assertJsonPath('refund.failure.retryable', true);
        $this->fakeProvider()->refundsBecome('processed');
        $this->refundPost($second, 'retry', ['reason' => 'Account topped up'])->assertOk()->assertJsonPath('refund.status', 'REFUNDED')->assertJsonPath('refund.submission.generation', 2);
        $this->assertCount(1, $this->atProvider($pay2));

        // The provider says more is asked than is left on the payment: our books and theirs disagree — a person looks.
        [, , $third] = $this->approvedRefund('+919876543213');
        $this->fakeProvider()->rejectNextRefund(ProviderRefundRejected::AMOUNT_EXCEEDS_AVAILABLE);
        $review = $this->refundPost($third, 'execute')->assertOk()->json('refund');
        $this->assertSame(['REVIEW_REQUIRED', 'PROVIDER_BALANCE_MISMATCH', $this->inr(50000)], [$review['status'], $review['review_reason'], $review['balance']['in_flight']]);
    }

    public function test_a_timeout_after_the_provider_created_the_refund_is_found_by_its_receipt_and_never_sent_twice(): void
    {
        [$customer, $order, $refund, $pay] = $this->approvedRefund();

        // The provider creates the refund; the answer never arrives.
        $this->fakeProvider()->refundsBecome('processed');
        $this->fakeProvider()->failNext('create_refund', ProviderFailure::Timeout, 1, true);
        $unknown = $this->refundPost($refund, 'execute')->assertOk()->json('refund');
        $this->assertSame(['SUBMISSION_UNKNOWN', null, 'UNKNOWN', 'TIMEOUT'], [$unknown['status'], $unknown['provider_detail']['refund_reference'], $unknown['attempts'][0]['outcome'], $unknown['attempts'][0]['failure_code']]);
        $this->assertSame($this->inr(50000), $unknown['balance']['in_flight'], 'the amount stays held: it is not free for another refund');
        $this->assertSame($this->inr(0), $unknown['balance']['available']);
        // The customer is not told "failed" and not told "refunded".
        $this->assertSame(['PROCESSING', 'PROCESSING'], [$this->showOrder($customer, $order['order_number'])->json('order.refund_status'), $this->showOrder($customer, $order['order_number'])->json('order.refund.items.0.status')]);
        // Nobody can send it again blindly, and it cannot be cancelled or replaced while the outcome is not known.
        $this->finance();
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'SUBMISSION_UNKNOWN');
        $this->refundPost($refund, 'reject', ['reason' => 'Start over'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_processing');
        $this->askRefund($order['order_number'], ['type' => 'FULL', 'reason_code' => 'OTHER', 'note' => 'Second try'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_processing');
        $this->assertCount(1, $this->atProvider($pay));

        // Reconciliation asks the provider, finds the refund by OUR receipt and adopts it.
        $this->travel(2)->minutes();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $refund->refresh();
        $this->assertSame(RefundStatus::Refunded, $refund->status);
        $this->assertSame($this->atProvider($pay)[0]->reference, $refund->provider_refund_reference);
        $this->assertCount(1, $this->atProvider($pay), 'one refund at the provider — the timeout did not make a second one');
        $this->assertSame([['CREATE', 'UNKNOWN'], ['LIST', 'PROCESSED']], RefundAttempt::query()->orderBy('id')->get()->map(fn (RefundAttempt $a): array => [$a->operation, $a->outcome])->all());
        $this->assertSame(1, (int) $refund->generation);
    }

    public function test_a_timeout_before_the_provider_acted_sends_the_same_request_again_with_the_same_key(): void
    {
        [, , $refund, $pay] = $this->approvedRefund();
        $this->fakeProvider()->refundsBecome('processed');

        // The request never reached the provider.
        $this->fakeProvider()->failNext('create_refund', ProviderFailure::Timeout);
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'SUBMISSION_UNKNOWN');
        $this->assertSame([], $this->atProvider($pay));
        $receipt = (string) $refund->refresh()->provider_receipt;

        // Not before the submission is certainly over …
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame(RefundStatus::SubmissionUnknown, $refund->refresh()->status);
        $this->assertSame(1, RefundAttempt::query()->count());

        // … then: the provider is asked, holds nothing for our receipt, and the SAME request goes again.
        $this->travel(2)->minutes();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $refund->refresh();
        $this->assertSame([RefundStatus::Refunded, 1, $receipt, 1], [$refund->status, $refund->generation, $refund->provider_receipt, $refund->unknown_resubmits]);
        $this->assertSame([['CREATE', 'UNKNOWN', $receipt], ['LIST', 'NOT_FOUND', null], ['CREATE', 'PROCESSED', $receipt]], RefundAttempt::query()->orderBy('id')->get()->map(fn (RefundAttempt $a): array => [$a->operation, $a->outcome, $a->idempotency_key])->all());
        $this->assertCount(1, $this->atProvider($pay));
        $this->assertSame($receipt, $this->atProvider($pay)[0]->receipt);
    }

    public function test_an_unanswered_submission_that_cannot_be_settled_reaches_a_person_and_stays_reserved(): void
    {
        config(['refunds.submission.max_unknown_resubmits' => 1]);
        [, , $refund, $pay] = $this->approvedRefund();

        // Every submission times out without the provider acting, and the limit of resubmissions is one.
        $this->fakeProvider()->failNext('create_refund', ProviderFailure::Timeout, 5);
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'SUBMISSION_UNKNOWN');
        foreach ([1, 2, 3] as $round) {
            $this->travel(3)->minutes();
            $this->artisan('refunds:reconcile');
        }
        $refund->refresh();
        $this->assertSame([RefundStatus::ReviewRequired, 'SUBMISSION_UNRESOLVED', 1], [$refund->status, $refund->review_reason, $refund->unknown_resubmits]);
        $this->assertSame(50000, $this->balanceOf($refund)->reserved_minor, 'unknown stays reserved');
        $this->assertSame(2, RefundAttempt::query()->where('operation', 'CREATE')->count());
        $this->assertSame([], $this->atProvider($pay));

        // The provider is down: a person's retry sends nothing — a lookup that cannot be made settles nothing.
        $this->fakeProvider()->recover();
        $this->fakeProvider()->failNext('refunds', ProviderFailure::Unavailable, 5);
        $this->finance();
        $this->refundPost($refund, 'retry', ['reason' => 'Asked by the customer'])->assertOk()->assertJsonPath('refund.status', 'REVIEW_REQUIRED');
        $this->assertSame(2, RefundAttempt::query()->where('operation', 'CREATE')->count());
        $this->assertSame('UNAVAILABLE', RefundAttempt::query()->where('operation', 'LIST')->latest('id')->value('outcome'));

        // The provider is back: the lookup finds nothing, the same request goes again, and the provider completes it.
        $this->fakeProvider()->recover();
        $this->fakeProvider()->refundsBecome('processed');
        $this->refundPost($refund, 'retry', ['reason' => 'Provider is back'])->assertOk()->assertJsonPath('refund.status', 'REFUNDED')->assertJsonPath('refund.submission.generation', 1);
        $this->assertCount(1, $this->atProvider($pay));
    }

    public function test_a_request_the_provider_certainly_did_not_carry_out_is_sent_again_later_and_not_forever(): void
    {
        config(['refunds.submission.not_sent_backoff_seconds' => [60, 120]]);
        [, , $refund, $pay] = $this->approvedRefund();
        $this->fakeProvider()->refundsBecome('processed');

        // Rate limited: nothing was created — the refund stays approved and waits.
        $this->fakeProvider()->failNext('create_refund', ProviderFailure::RateLimited, 2);
        $waiting = $this->refundPost($refund, 'execute')->assertOk()->json('refund');
        $this->assertSame(['APPROVED', 'NOT_SENT', 'RATE_LIMITED'], [$waiting['status'], $waiting['attempts'][0]['outcome'], $waiting['attempts'][0]['failure_code']]);
        $this->assertNotNull($waiting['reconciliation']['next_at']);
        // Not yet due.
        $this->artisan('refunds:reconcile');
        $this->assertSame(1, RefundAttempt::query()->count());
        // Due: sent again (rate limited again), due again: sent — with the same key every time.
        $this->travel(61)->seconds();
        $this->artisan('refunds:reconcile');
        $this->assertSame(RefundStatus::Approved, $refund->refresh()->status);
        $this->travel(121)->seconds();
        $this->artisan('refunds:reconcile');
        $refund->refresh();
        $this->assertSame([RefundStatus::Refunded, 1], [$refund->status, $refund->generation]);
        $this->assertSame(1, RefundAttempt::query()->distinct()->count('idempotency_key'));
        $this->assertCount(1, $this->atProvider($pay));

        // A provider that stays unusable: after the configured tries a person looks. Never "failed" because of an outage.
        [, , $second] = $this->approvedRefund('+919876543212');
        $this->fakeProvider()->failNext('create_refund', ProviderFailure::RateLimited, 10);
        $this->refundPost($second, 'execute')->assertOk()->assertJsonPath('refund.status', 'APPROVED');
        foreach ([1, 2, 3] as $round) {
            $this->travel(5)->minutes();
            $this->artisan('refunds:reconcile');
        }
        $second->refresh();
        $this->assertSame([RefundStatus::ReviewRequired, 'PROVIDER_UNAVAILABLE'], [$second->status, $second->review_reason]);
        $this->assertSame(50000, $this->balanceOf($second)->reserved_minor);
    }

    public function test_two_workers_cannot_submit_the_same_refund_and_an_abandoned_submission_is_not_trusted(): void
    {
        [, , $refund, $pay] = $this->approvedRefund();
        $this->fakeProvider()->refundsBecome('processed');
        $submission = app(RefundSubmissionService::class);

        // Another worker holds the submission lock: this one sends nothing.
        $lock = Cache::lock(RefundSubmissionService::lockKey((int) $refund->getKey()), 30);
        $this->assertTrue($lock->get());
        $this->assertSame(RefundStatus::Approved, $submission->submit((int) $refund->getKey())->status);
        $this->assertSame([], $this->atProvider($pay));
        $lock->release();

        // A worker that took it and died (SUBMITTING, lock gone): what the provider did is not known — it is looked up.
        DB::table('refunds')->where('id', $refund->getKey())->update(['status' => 'SUBMITTING', 'generation' => 1, 'provider_receipt' => $refund->receiptFor(1), 'submitted_at' => now(), 'approved_amount_minor' => 50000]);
        $this->assertSame(RefundStatus::SubmissionUnknown, $submission->submit((int) $refund->getKey())->status);
        $this->assertSame([], $this->atProvider($pay), 'an abandoned submission is never repeated without asking the provider first');
        $this->travel(2)->minutes();
        $this->assertSame(RefundStatus::Refunded, app(RefundReconciliationService::class)->reconcile($refund->refresh(), 'JOB')->status);
        $this->assertCount(1, $this->atProvider($pay));
    }

    public function test_a_provider_refund_that_is_not_this_refund_is_never_attached_to_it(): void
    {
        [, , $refund, $pay] = $this->approvedRefund();
        [, , $other, $otherPay] = $this->approvedRefund('+919876543212');
        $this->fakeProvider()->refundsBecome('pending');
        $this->refundPost($refund, 'execute')->assertOk()->assertJsonPath('refund.status', 'PROCESSING');
        $this->refundPost($other, 'execute')->assertOk()->assertJsonPath('refund.status', 'PROCESSING');
        $mine = $this->atProvider($pay)[0];
        $theirs = $this->fakeProvider()->settleRefund($this->atProvider($otherPay)[0]->reference, 'processed');

        // A (signed) event names the OTHER payment's provider refund for this record while the provider cannot be asked:
        // it is not this refund — refused as a mismatch, nothing of it is attached, and a person is told.
        $this->fakeProvider()->failNext('refund', ProviderFailure::Unavailable, 5);
        $after = app(RefundReconciliationService::class)->reconcile($refund->refresh(), 'WEBHOOK', $theirs);
        $this->fakeProvider()->recover();
        $this->assertSame([RefundStatus::ReviewRequired, 'PROVIDER_REFUND_MISMATCH', $mine->reference], [$after->status, $after->review_reason, $after->provider_refund_reference]);
        $this->assertSame('MISMATCH', RefundAttempt::query()->where('refund_id', $refund->getKey())->latest('id')->value('outcome'));
        $this->assertNull(RefundAttempt::query()->where('refund_id', $refund->getKey())->latest('id')->value('provider_refund_reference'));
        $this->assertSame(0, $this->balanceOf($refund)->refunded_minor, 'the refund of the other payment did not complete this one');
        // The database itself refuses one provider refund on two records.
        $this->expectException(QueryException::class);
        DB::table('refunds')->where('id', $refund->getKey())->update(['provider_refund_reference' => $other->refresh()->provider_refund_reference]);
    }
}
