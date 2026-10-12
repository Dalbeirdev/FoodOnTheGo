<?php

namespace Tests\Feature\Refund;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\OrderTaxLine;
use App\Models\PaymentRefundBalance;
use App\Models\PromotionReservation;
use App\Models\Refund;
use App\Models\RefundAttempt;
use App\Services\Refund\RefundEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Module 35 — the amount of a refund is the server's: derived from the payment and from what was already refunded
 * or is on its way. A client's amount is a request that is checked. Over-refunding is impossible — the service
 * refuses it and the database refuses it again.
 */
class RefundAmountTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->fakeProvider()->refundsBecome('processed');
    }

    public function test_partial_refunds_add_up_to_the_payment_and_never_beyond_it(): void
    {
        // A completed ₹500 order: service recovery, approved by finance. The order stays COMPLETED throughout.
        [, $customer, $order] = $this->completedPaidOrder();
        $n = (string) $order->order_number;
        $this->finance();

        // ₹200 of ₹500 → ₹300 left.
        $first = $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 20000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated()->json('refund');
        $this->assertSame(['REQUESTED', 'PARTIAL', $this->inr(20000), null, 'ADMIN'], [$first['status'], $first['type'], $first['amount'], $first['approved_amount'], $first['requested_by']['type']]);
        $this->assertSame(['paid' => $this->inr(50000), 'refunded' => $this->inr(0), 'in_flight' => $this->inr(20000), 'charged_back' => $this->inr(0), 'available' => $this->inr(30000)], $first['balance']);
        // A request nobody approved does not show on the order or to the customer.
        $mine = $this->showOrder($customer, $n)->json('order');
        $this->assertSame(['COMPLETED', 'NONE', []], [$mine['status'], $mine['refund_status'], $mine['refund']['items']]);

        $this->finance();
        $done = $this->approveAndExecute(Refund::query()->where('public_id', $first['id'])->sole());
        $this->assertSame(['REFUNDED', $this->inr(20000), $this->inr(30000)], [$done['status'], $done['balance']['refunded'], $done['balance']['available']]);
        // Example 4 of the financial states: order COMPLETED, payment VERIFIED, refund status PARTIALLY_REFUNDED.
        $mine = $this->showOrder($customer, $n)->json('order');
        $this->assertSame(['COMPLETED', 'PAID', 'PARTIALLY_REFUNDED', $this->inr(20000), $this->inr(50000)], [$mine['status'], $mine['payment_state'], $mine['refund_status'], $mine['refund']['refunded'], $mine['total']]);
        $this->assertSame(['PARTIAL', $this->inr(20000), 'REFUNDED', 'An item was not available'], [$mine['refund']['items'][0]['type'], $mine['refund']['items'][0]['amount'], $mine['refund']['items'][0]['status'], $mine['refund']['items'][0]['reason']['label']]);

        // A second ₹100 → ₹200 left.
        $this->finance();
        $second = $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 10000, 'reason_code' => 'OTHER', 'note' => 'Cold fries'])->assertCreated()->json('refund');
        $this->assertSame($this->inr(20000), $second['balance']['available']);

        // ₹300 when ₹200 is left: refused — with what IS left, and nothing created.
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 30000, 'reason_code' => 'ITEM_UNAVAILABLE'])
            ->assertStatus(409)->assertJsonPath('error.code', 'refund_amount_exceeds_available')->assertJsonPath('error.details.available_minor', 20000)->assertJsonPath('error.details.requested_minor', 30000);
        $this->assertSame(2, Refund::query()->count());

        // FULL means what is left — not blindly the original total.
        $rest = $this->askRefund($n, ['type' => 'FULL', 'reason_code' => 'ORDER_CANNOT_BE_FULFILLED'])->assertCreated()->json('refund');
        $this->assertSame(['FULL', $this->inr(20000), $this->inr(0)], [$rest['type'], $rest['amount'], $rest['balance']['available']]);
        // Everything is held now: one more unit is too much.
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 1, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_processing');
        $this->askRefund($n, ['type' => 'FULL', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_processing');

        foreach ([$second['id'], $rest['id']] as $id) {
            $this->finance();
            $this->approveAndExecute(Refund::query()->where('public_id', $id)->sole());
        }
        $balance = PaymentRefundBalance::query()->sole();
        $this->assertSame([50000, 0, 50000, 0], [$balance->paid_minor, $balance->reserved_minor, $balance->refunded_minor, $balance->availableMinor()]);
        $mine = $this->showOrder($customer, $n)->json('order');
        $this->assertSame(['COMPLETED', 'REFUNDED', $this->inr(50000), 3], [$mine['status'], $mine['refund_status'], $mine['refund']['refunded'], count($mine['refund']['items'])]);
        $this->finance();
        $this->askRefund($n, ['type' => 'FULL', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(409)->assertJsonPath('error.code', 'refund_already_completed');
        // What the provider was asked for, in total, is exactly what was paid.
        $this->assertSame(50000, array_sum(array_map(fn ($r): int => $r->amount->amount, $this->fakeProvider()->refundsOfPayment((string) $order->paymentAttempt->provider_payment_reference))));
        $this->assertSame('VERIFIED', $order->paymentAttempt->refresh()->status->value);
    }

    public function test_an_amount_has_to_be_a_positive_whole_number_in_the_payments_currency(): void
    {
        [, , $order] = $this->completedPaidOrder();
        $n = (string) $order->order_number;
        $this->finance();

        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 0, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422)->assertJsonPath('error.code', 'refund_amount_invalid');
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => -500, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422)->assertJsonPath('error.code', 'refund_amount_invalid');
        $this->askRefund($n, ['type' => 'PARTIAL', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422)->assertJsonPath('error.code', 'refund_amount_invalid');
        // Not minor units: no decimal amount, no text.
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 199.5, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422);
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 'two hundred', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422);
        // A FULL refund takes no amount from the caller.
        $this->askRefund($n, ['type' => 'FULL', 'amount_minor' => 100, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422);
        // An INR payment cannot be refunded in another currency.
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 100, 'currency' => 'USD', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(422)->assertJsonPath('error.code', 'refund_currency_mismatch');
        // A controlled reason is required; OTHER needs a note.
        $this->askRefund($n, ['type' => 'FULL'])->assertStatus(422);
        $this->askRefund($n, ['type' => 'FULL', 'reason_code' => 'BECAUSE'])->assertStatus(422);
        $this->askRefund($n, ['type' => 'FULL', 'reason_code' => 'OTHER'])->assertStatus(422);
        $this->askRefund($n, ['type' => 'FULL', 'reason_code' => 'RESTAURANT_REJECTED'])->assertStatus(422);   // a system reason is not a person's to give
        $this->assertSame(0, Refund::query()->count());

        $ok = $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 100, 'currency' => 'inr', 'reason_code' => 'OTHER', 'note' => 'One rupee of goodwill'])->assertCreated()->json('refund');
        $this->assertSame([$this->inr(100), 'INR', 'One rupee of goodwill'], [$ok['amount'], $ok['currency'], $ok['note']]);
    }

    public function test_two_requests_against_the_last_of_the_balance_cannot_both_take_it(): void
    {
        [, , $order] = $this->completedPaidOrder();
        $n = (string) $order->order_number;
        $this->finance();

        // ₹400 and ₹300 against ₹500: whichever takes the payment's balance lock first wins; the other is refused.
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 40000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated();
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 30000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(409)->assertJsonPath('error.code', 'refund_amount_exceeds_available')->assertJsonPath('error.details.available_minor', 10000);
        $balance = PaymentRefundBalance::query()->sole();
        $this->assertSame([40000, 10000], [$balance->reserved_minor, $balance->availableMinor()]);

        // Whatever the code is asked — the database itself does not take more than was paid.
        foreach ([['reserved_minor' => 50001], ['refunded_minor' => 10001], ['reserved_minor' => 40000, 'refunded_minor' => 10001]] as $beyond) {
            try {
                DB::transaction(fn () => DB::table('payment_refund_balances')->update($beyond));
                $this->fail('The database took an over-refund: '.json_encode($beyond));
            } catch (QueryException $e) {
                $this->assertStringContainsString('payment_refund_balances_limit_check', $e->getMessage());
            }
        }
        // … and no refund row without a positive amount, a currency, a known state or — for REFUNDED — the provider's reference.
        $row = (array) DB::table('refunds')->first();
        unset($row['id']);
        foreach ([['requested_amount_minor' => 0], ['currency' => 'inr'], ['status' => 'DONE'], ['type' => 'HALF'], ['status' => 'REFUNDED'], ['status' => 'PROCESSING'], ['approved_amount_minor' => 40001]] as $bad) {
            try {
                DB::transaction(fn () => DB::table('refunds')->insert(['public_id' => (string) Str::uuid(), 'provider_receipt' => null, 'request_key' => null] + $bad + $row));
                $this->fail('The database took an invalid refund: '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertStringContainsString('refunds_', $e->getMessage());
            }
        }

        // A request that is turned down gives its amount back; then the other one fits.
        $first = Refund::query()->sole();
        $this->refundPost($first, 'reject', ['reason' => 'Not justified'])->assertOk()->assertJsonPath('refund.status', 'CANCELLED')->assertJsonPath('refund.balance.available', $this->inr(50000));
        $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 30000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated();
    }

    public function test_a_double_click_with_the_same_idempotency_key_makes_one_request(): void
    {
        [, , $order] = $this->completedPaidOrder();
        $n = (string) $order->order_number;
        $this->finance();
        $key = ['Idempotency-Key' => 'refund-dialog-0001-abcdef'];

        $a = $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 10000, 'reason_code' => 'ITEM_UNAVAILABLE'], $key)->assertCreated()->json('refund.id');
        $b = $this->askRefund($n, ['type' => 'PARTIAL', 'amount_minor' => 10000, 'reason_code' => 'ITEM_UNAVAILABLE'], $key)->assertCreated()->json('refund.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(10000, PaymentRefundBalance::query()->sole()->reserved_minor);
    }

    public function test_only_a_verified_payment_of_an_order_that_is_over_can_be_refunded(): void
    {
        // An order that is still being made: it is ended first — and ending a paid order creates its refund.
        [, , , , $active] = $this->paidOrder('+919876543210', $this->nextSlot());
        $this->finance();
        $this->askRefund($active['order_number'], ['type' => 'FULL', 'reason_code' => 'ORDER_CANNOT_BE_FULFILLED'])->assertStatus(409)->assertJsonPath('error.code', 'refund_order_active');
        $this->assertSame(0, Refund::query()->count());
        $cancelled = $this->adminPost('/orders/'.$active['order_number'].'/cancel', ['reason' => 'The restaurant had to close', 'reason_code' => 'RESTAURANT_CANCELLATION'])->assertOk()->json('order');
        $this->assertSame(['CANCELLED', 'REQUIRED', 'ADMIN', 'RESTAURANT_CANCELLATION'], [$cancelled['status'], $cancelled['refund_status'], $cancelled['cancellation']['by'], $cancelled['cancellation']['reason_code']]);
        $refund = Refund::query()->sole();
        $this->assertSame([RefundStatus::Required, 'RESTAURANT_CANCELLATION', 'ADMIN', 50000], [$refund->status, $refund->reason_code->value, $refund->requested_by_type, $refund->requested_amount_minor]);
        $this->assertEquals([['id' => (string) $refund->public_id, 'status' => 'REQUIRED', 'reason' => 'RESTAURANT_CANCELLATION', 'amount' => $this->inr(50000)]], array_map(fn (array $r): array => array_intersect_key($r, array_flip(['id', 'status', 'reason', 'amount'])), $cancelled['refunds']));
        $this->assertSame(['cancel' => false, 'refund' => true, 'mark_picked_up' => 'NOT_PROVIDED', 'mark_refunded' => 'NOT_PROVIDED'], $cancelled['actions']);

        // A payment that is not VERIFIED is not refundable — whatever the order row says.
        [, , $order] = $this->completedPaidOrder('+919876543212');
        $attempt = $order->paymentAttempt;
        DB::table('payment_attempts')->where('id', $attempt->getKey())->update(['status' => PaymentStatus::Unknown->value, 'fulfillment_status' => 'NOT_APPLICABLE']);
        $this->finance();
        $this->askRefund((string) $order->order_number, ['type' => 'FULL', 'reason_code' => 'ITEM_UNAVAILABLE'])->assertStatus(409)->assertJsonPath('error.code', 'refund_payment_not_verified');
        $this->assertFalse(app(RefundEligibilityService::class)->forOrder($order->refresh())->eligible);
        $this->assertSame(1, Refund::query()->count());
    }

    public function test_a_refund_leaves_the_orders_prices_tax_lines_and_promotion_as_they_were(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        [$customer, $headers] = $this->customerReadyForCheckout(slot: $this->nextSlot());
        $draft = $this->openCheckout($headers, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $payment = $this->startPayment($headers, $ready)->assertCreated()->json('payment');
        $verified = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->json('payment');
        $n = $verified['fulfillment']['order_number'];
        $before = $this->showOrder($headers, $n)->json('order');
        $taxBefore = OrderTaxLine::query()->get()->toArray();
        $promotionBefore = PromotionReservation::query()->get(['id', 'status', 'promotion_id'])->toArray();

        $this->orderStaff();
        $this->step($n, 'reject', ['reason' => 'CLOSING'])->assertOk();
        $refund = $this->refundOf($n);
        $this->assertSame((int) $before['total']['amount'], $refund->requested_amount_minor, 'what was PAID is owed — the discounted total, not the menu price');
        $this->finance();
        $this->approveAndExecute($refund);

        $after = $this->showOrder($headers, $n)->json('order');
        $this->assertSame('REFUNDED', $after['refund_status']);
        foreach (['total', 'pricing', 'taxes', 'fees', 'promotion', 'items', 'payment', 'payment_state'] as $kept) {
            $this->assertSame($before[$kept], $after[$kept], $kept.' is the historical record and is not rewritten by a refund');
        }
        $this->assertSame($taxBefore, OrderTaxLine::query()->get()->toArray());
        // The promotion's use is not given back automatically: that would be a policy nobody has approved.
        $this->assertSame($promotionBefore, PromotionReservation::query()->get(['id', 'status', 'promotion_id'])->toArray());
        $this->assertSame((int) $customer->getKey(), (int) $refund->refresh()->customer_id);
    }

    public function test_a_refund_the_provider_failed_gives_its_amount_back(): void
    {
        $this->fakeProvider()->refundsBecome('failed');
        [, $customer, $order, , $refund] = $this->rejectedPaidOrder();
        $this->finance();
        $failed = $this->approveAndExecute($refund);

        $this->assertSame(['FAILED', 'PROVIDER_FAILED', true, $this->inr(50000), $this->inr(0)], [$failed['status'], $failed['failure']['category'], $failed['failure']['retryable'], $failed['balance']['available'], $failed['balance']['in_flight']]);
        // The customer is told it is under review — never "failed payment", never "refunded".
        $mine = $this->showOrder($customer, $order['order_number'])->json('order');
        $this->assertSame(['REJECTED', 'PAID', 'UNDER_REVIEW', 'UNDER_REVIEW', 'UNDER_REVIEW', 'AWAIT_REFUND'], [$mine['status'], $mine['payment_state'], $mine['refund_status'], $mine['refund']['status'], $mine['refund']['items'][0]['status'], $mine['next_action']]);
        $this->assertStringNotContainsString('PROVIDER_FAILED', json_encode($mine));
        // The internal word stays internal: nothing in the customer's refund answer says "fail".
        $this->assertStringNotContainsStringIgnoringCase('fail', json_encode($mine['refund']));
        $this->assertSame('FAILED', $this->orderModel($order['order_number'])->refund_status->value);

        // Tried again by a person: a NEW provider refund (a new receipt and key), its amount held again.
        $this->fakeProvider()->refundsBecome('processed');
        $this->finance();
        $again = $this->refundPost($refund, 'retry', ['reason' => 'Provider incident resolved'])->assertOk()->json('refund');
        $this->assertSame(['REFUNDED', 2, 'rf-'.str_replace('-', '', (string) $refund->public_id).'-2'], [$again['status'], $again['submission']['generation'], $again['provider_detail']['receipt']]);
        $this->assertSame([['CREATE', 1, 'FAILED'], ['CREATE', 2, 'PROCESSED']], RefundAttempt::query()->orderBy('id')->get()->map(fn (RefundAttempt $a): array => [$a->operation, $a->generation, $a->outcome])->all());
        $this->assertSame('REFUNDED', $this->showOrder($customer, $order['order_number'])->json('order.refund_status'));
        $this->assertSame(1, Refund::query()->count());
    }
}
