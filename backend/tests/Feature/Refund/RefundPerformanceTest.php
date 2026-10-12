<?php

namespace Tests\Feature\Refund;

use App\Models\Refund;
use App\Services\Refund\RefundEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\BuildsRefunds;
use Tests\TestCase;

/**
 * Query counts and timings of the refund domain (Module 35): the administrators' list costs the same number of
 * queries whatever its size (no query per row for the order, the payment, the customer or the restaurant), and the
 * indexes and constraints exist. What was measured is written to storage/logs/m35-performance.json — measured
 * numbers of this machine against the FAKE provider, not a benchmark and not Razorpay's latency.
 */
class RefundPerformanceTest extends TestCase
{
    use BuildsRefunds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        config(['rate_limits.refund_action' => 1000]);
    }

    public function test_refund_reads_use_a_bounded_number_of_queries_and_the_indexes_exist(): void
    {
        $refunds = [];
        foreach (['+919811100110', '+919811100111', '+919811100112', '+919811100113'] as $phone) {
            [, , , , $refund] = $this->rejectedPaidOrder($phone);
            $refunds[] = $refund;
        }
        $this->finance();

        $measure = function (callable $call): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            $result = $call();
            $ms = round((hrtime(true) - $started) / 1_000_000, 1);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$count, $ms, $result];
        };

        $this->refundGet('/queues')->assertOk();   // the caller's grants are read once and cached: not part of any measurement
        [$listOne] = $measure(fn () => $this->refundGet('?page[size]=1')->assertOk()->assertJsonCount(1, 'data'));
        [$listFour, $listMs] = $measure(fn () => $this->refundGet('?page[size]=4')->assertOk()->assertJsonCount(4, 'data'));
        [$queues] = $measure(fn () => $this->refundGet('/queues')->assertOk());
        [$detail, $detailMs] = $measure(fn () => $this->refundGet('/'.$refunds[0]->public_id)->assertOk());
        [$eligibility, $eligibilityMs] = $measure(fn () => app(RefundEligibilityService::class)->forOrder($this->orderModel((string) $refunds[0]->order_number)));
        [$approve, $approveMs] = $measure(fn () => $this->refundPost($refunds[0], 'approve')->assertOk());
        $this->fakeProvider()->refundsBecome('pending');
        [$execute, $executeMs] = $measure(fn () => $this->refundPost($refunds[0], 'execute')->assertOk()->assertJsonPath('refund.status', 'PROCESSING'));
        $settled = $this->providerSettlesRefund($refunds[0], 'processed');
        [$webhook, $webhookMs] = $measure(fn () => $this->providerSendsRefund('refund.processed', $settled)->assertOk());
        $this->finance();
        $this->refundPost($refunds[1], 'approve')->assertOk();
        $this->refundPost($refunds[1], 'execute')->assertOk();
        [$reconcile, $reconcileMs] = $measure(fn () => $this->refundPost($refunds[1], 'reconcile', ['reason' => 'Measure'])->assertOk());
        [, , $order] = $this->completedPaidOrder('+919811100114');
        $this->finance();
        [$create, $createMs] = $measure(fn () => $this->askRefund((string) $order->order_number, ['type' => 'PARTIAL', 'amount_minor' => 5000, 'reason_code' => 'ITEM_UNAVAILABLE'])->assertCreated());

        // No query per row: a page of four refunds costs what a page of one costs.
        $this->assertSame($listOne, $listFour, 'the refund list costs the same for one refund as for four');
        $this->assertLessThanOrEqual(10, $listFour);
        $this->assertLessThanOrEqual(6, $queues);
        $this->assertLessThanOrEqual(22, $detail);
        $this->assertLessThanOrEqual(4, $eligibility);

        $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'refunds'"))->pluck('indexdef', 'indexname');
        foreach (['(payment_attempt_id)', '(order_id)', '(customer_id, requested_at)', '(status, next_action_at)', '(status, requested_at)', '(market_id, requested_at)', '(location_id, requested_at)', '(completed_at)', '(reason_code)', '(payment_public_id)', '(order_number)'] as $columns) {
            $this->assertTrue($indexes->contains(fn (string $def): bool => str_contains($def, $columns)), 'index on '.$columns);
        }
        foreach (['(public_id)', '(provider_receipt)', '(request_key)', '(provider, provider_refund_reference)'] as $unique) {
            $this->assertTrue($indexes->contains(fn (string $def): bool => str_contains($def, 'UNIQUE') && str_contains($def, $unique)), 'unique index on '.$unique);
        }
        $constraints = collect(DB::select("select conname from pg_constraint where conrelid in ('refunds'::regclass, 'payment_refund_balances'::regclass, 'refund_attempts'::regclass, 'payment_disputes'::regclass)"))->pluck('conname')->all();
        foreach (['refunds_status_check', 'refunds_type_check', 'refunds_amount_check', 'refunds_currency_check', 'refunds_refunded_check', 'refunds_approved_check', 'refunds_requester_check', 'payment_refund_balances_limit_check', 'payment_refund_balances_currency_check', 'refund_attempts_operation_check', 'refund_attempts_outcome_check', 'payment_disputes_status_check'] as $name) {
            $this->assertContains($name, $constraints);
        }
        // A refund is a financial record: the payment, the order and the refund's own history cannot be deleted from under it.
        foreach ([['payment_attempts', $refunds[0]->payment_attempt_id], ['orders', $refunds[0]->order_id], ['refunds', $refunds[0]->getKey()]] as [$table, $id]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail('A row a refund depends on was deleted from '.$table);
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
            }
        }
        $this->assertSame(5, Refund::query()->count());
        $plan = collect(DB::select("explain select * from refunds where status = 'PROCESSING' and next_action_at <= now() order by next_action_at limit 100"))->pluck('QUERY PLAN')->implode(' ');

        File::put(storage_path('logs/m35-performance.json'), json_encode([
            'note' => 'Measured in the test environment on this machine against the FAKE provider (in-process, cache-backed). Not a benchmark; not Razorpay latency.',
            'admin_refund_list_queries' => ['page_of_1' => $listOne, 'page_of_4' => $listFour], 'admin_refund_list_ms' => $listMs,
            'admin_refund_queues_queries' => $queues,
            'admin_refund_detail_queries' => $detail, 'admin_refund_detail_ms' => $detailMs,
            'eligibility_queries' => $eligibility, 'eligibility_ms' => $eligibilityMs,
            'refund_request_creation_queries' => $create, 'refund_request_creation_ms' => $createMs,
            'approve_queries' => $approve, 'approve_ms' => $approveMs,
            'execute_with_fake_provider_queries' => $execute, 'execute_with_fake_provider_ms' => $executeMs,
            'refund_webhook_accept_and_process_queries' => $webhook, 'refund_webhook_accept_and_process_ms' => $webhookMs,
            'manual_reconcile_queries' => $reconcile, 'manual_reconcile_ms' => $reconcileMs,
            'due_refunds_sweep_plan' => mb_substr($plan, 0, 300),
        ], JSON_PRETTY_PRINT));
    }
}
