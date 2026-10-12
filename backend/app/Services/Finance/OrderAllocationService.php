<?php

namespace App\Services\Finance;

use App\Enums\AllocationStatus;
use App\Enums\CommissionBasePolicy;
use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderFinancialAllocation;
use App\Models\RestaurantPayableItem;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An order's financial allocation (Module 36): made once when the order is created, recognized in the ledger when the
 * order is completed, void when it is rejected or cancelled.
 *
 * Everything is read from the ORDER'S OWN columns (its subtotal, discount, tax, fees and total as they were charged)
 * and from the commercial terms that applied when it was placed. Nothing is recalculated from today's menu, promotion,
 * tax rule or commission plan — an allocation is history.
 *
 * THE FORMULA (the lines marked POLICY come from configuration or the plan; the rest is arithmetic):
 *
 *   customer total      = merchandise − discount + tax + fees                       (the order's own check)
 *   discount            = restaurant-funded part + platform-funded part             POLICY: the promotion's funding source
 *   commission base     = merchandise [− restaurant discount [− platform discount]] POLICY: the plan's base policy
 *   commission          = commission base × rate, rounded half up                   POLICY: the plan's rate
 *   restaurant net      = merchandise − restaurant discount − commission
 *                         [+ tax when the tax is the restaurant's] [+ fees when the fees are the restaurant's]   POLICY: owners
 *   platform net        = commission + platform's fees − platform-funded discount
 *
 * Posted at recognition (one balanced transaction, key "order:{id}:recognized"):
 *
 *   Dr PAYMENT_CLEARING   customer total            Cr RESTAURANT_PAYABLE            restaurant net
 *   Dr PROMOTION_EXPENSE  platform-funded discount  Cr PLATFORM_COMMISSION_REVENUE   commission
 *                                                   Cr PLATFORM_FEE_REVENUE          platform's fees
 *                                                   Cr TAX_LIABILITY                 tax not passed to the restaurant
 */
final class OrderAllocationService
{
    public const BASIS_ORDER = 'ORDER_CREATED';

    public const BASIS_BACKFILL = 'BACKFILL';

    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly CommissionCalculationService $commission,
        private readonly FinanceMetrics $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The order's allocation — made now when it does not exist. Safe to call any number of times.
     */
    public function snapshot(Order $order, string $basis = self::BASIS_ORDER): OrderFinancialAllocation
    {
        if (($existing = $this->of($order)) !== null) {
            return $existing;
        }
        $attributes = $this->calculate($order, $basis);
        try {
            $allocation = DB::transaction(function () use ($attributes): OrderFinancialAllocation {
                $allocation = new OrderFinancialAllocation;
                $allocation->forceFill($attributes)->save();

                return $allocation;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->of($order) ?? throw $e;
        }
        $this->metrics->record('allocation');
        if ($allocation->status === AllocationStatus::ReviewRequired) {
            $this->metrics->record('allocation_review_required');
            Log::warning('finance.allocation_review_required', ['order' => $order->order_number, 'reason' => $allocation->review_reason]);
        }

        return $allocation;
    }

    public function of(Order $order): ?OrderFinancialAllocation
    {
        return OrderFinancialAllocation::query()->where('order_id', $order->getKey())->first();
    }

    /**
     * Brings the allocation in line with where the order is: recognized when the order is COMPLETED, void when it was
     * rejected or cancelled. An allocation that waits for a person stays as it is. Idempotent.
     */
    public function settle(Order $order, string $basis = self::BASIS_ORDER): OrderFinancialAllocation
    {
        $allocation = $this->snapshot($order, $basis);

        return match (true) {
            $order->status === OrderStatus::Completed && $allocation->status === AllocationStatus::Pending => $this->recognize($allocation, $order),
            in_array($order->status, [OrderStatus::Rejected, OrderStatus::Cancelled], true) && in_array($allocation->status, [AllocationStatus::Pending, AllocationStatus::ReviewRequired], true) => $this->void($allocation),
            default => $allocation,
        };
    }

    /**
     * A person decides the terms of an order that had none (no plan applied, or the promotion's funding is not known).
     * It is a decision about ONE order, with a reason, audited; the order's own amounts cannot be changed by it.
     *
     * @param  array{rate_bps: int, base_policy: string, restaurant_discount_minor?: int|null}  $terms
     */
    public function decide(OrderFinancialAllocation $allocation, AdminUser $admin, array $terms, string $reason): OrderFinancialAllocation
    {
        $decided = DB::transaction(function () use ($allocation, $admin, $terms, $reason): OrderFinancialAllocation {
            $locked = OrderFinancialAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== AllocationStatus::ReviewRequired) {
                throw ApiException::conflict('allocation_not_in_review', 'This order\'s allocation does not wait for a decision.');
            }
            $discount = (int) $locked->discount_minor;
            $split = (int) $locked->restaurant_discount_minor + (int) $locked->platform_discount_minor === $discount;
            $restaurantDiscount = (int) $locked->restaurant_discount_minor;
            if (! $split || array_key_exists('restaurant_discount_minor', $terms) && $terms['restaurant_discount_minor'] !== null) {
                if (! isset($terms['restaurant_discount_minor'])) {
                    throw new ApiException(422, 'discount_split_required', 'Say how much of the discount the restaurant pays for.', ['fields' => ['restaurant_discount_minor' => ['This order\'s discount has no known funding: the restaurant\'s part is required.']]]);
                }
                $restaurantDiscount = (int) $terms['restaurant_discount_minor'];
                if ($restaurantDiscount < 0 || $restaurantDiscount > $discount) {
                    throw new ApiException(422, 'discount_split_invalid', 'The restaurant\'s part of the discount lies between zero and the discount.', ['fields' => ['restaurant_discount_minor' => ['Must be between 0 and '.$discount.'.']]]);
                }
            }
            $platformDiscount = $discount - $restaurantDiscount;
            $policy = CommissionBasePolicy::from($terms['base_policy']);
            $commission = $this->commission->calculate((int) $terms['rate_bps'], $policy, (int) $locked->merchandise_gross_minor, $restaurantDiscount, $platformDiscount);
            $before = ['status' => $locked->status->value, 'review_reason' => $locked->review_reason];
            $locked->forceFill([
                'status' => AllocationStatus::Pending,
                'review_reason' => null,
                'restaurant_discount_minor' => $restaurantDiscount,
                'platform_discount_minor' => $platformDiscount,
                'commission_source' => OrderFinancialAllocation::SOURCE_DECISION,
                'commission_scope' => null,
                'commission_rate_bps' => $commission['rate_bps'],
                'commission_base_policy' => $policy,
                'commission_base_minor' => $commission['base_minor'],
                'commission_minor' => $commission['amount_minor'],
                ...self::nets((int) $locked->merchandise_gross_minor, $restaurantDiscount, $platformDiscount, $commission['amount_minor'], (int) $locked->tax_restaurant_minor, (int) $locked->fee_restaurant_minor, (int) $locked->fee_platform_minor),
                'decided_by_admin_id' => $admin->getKey(),
                'decision_reason' => mb_substr($reason, 0, 500),
                'version' => (int) $locked->version + 1,
            ])->save();
            $this->audit->record('finance.allocation_decided', $locked, $admin, [
                'status' => ['from' => $before['status'], 'to' => AllocationStatus::Pending->value],
                'review_reason' => ['from' => $before['review_reason'], 'to' => null],
                'commission_rate_bps' => ['from' => null, 'to' => $commission['rate_bps']],
                'commission_base_policy' => ['from' => null, 'to' => $policy->value],
                'commission_minor' => ['from' => null, 'to' => $commission['amount_minor']],
                'restaurant_discount_minor' => ['from' => null, 'to' => $restaurantDiscount],
            ], $reason, $locked->market_id);

            return $locked;
        });

        return $this->settle($decided->order()->firstOrFail());
    }

    /* ------------------------------------------------------------------ internals */

    private function recognize(OrderFinancialAllocation $allocation, Order $order): OrderFinancialAllocation
    {
        return DB::transaction(function () use ($allocation, $order): OrderFinancialAllocation {
            $locked = OrderFinancialAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== AllocationStatus::Pending) {
                return $locked;
            }
            $location = $locked->location()->firstOrFail();
            $currency = (string) $locked->currency;
            $at = CarbonImmutable::instance($order->completed_at ?? now());

            $draft = LedgerDraft::for('order:'.$locked->order_public_id.':recognized', LedgerTransactionType::OrderRecognized, $currency, $at)
                ->source('order', (int) $locked->order_id, (string) $locked->order_public_id, (string) $locked->order_number)
                ->dimensions($locked->market_id, (int) $locked->organization_id, (int) $locked->location_id)
                ->explained(null, null, ['commission_rate_bps' => $locked->commission_rate_bps, 'commission_base_policy' => $locked->commission_base_policy?->value, 'commission_plan' => $locked->commission_plan_public_id])
                ->debit($this->ledger->platform(LedgerAccountCode::PaymentClearing, $currency), (int) $locked->customer_total_minor, 'CUSTOMER_TOTAL')
                ->debit($this->ledger->platform(LedgerAccountCode::PromotionExpense, $currency), (int) $locked->platform_discount_minor, 'PLATFORM_FUNDED_DISCOUNT')
                ->credit($this->ledger->payable($location, $currency), (int) $locked->restaurant_net_minor, 'RESTAURANT_NET_EARNING')
                ->credit($this->ledger->platform(LedgerAccountCode::PlatformCommissionRevenue, $currency), (int) $locked->commission_minor, 'COMMISSION')
                ->credit($this->ledger->platform(LedgerAccountCode::PlatformFeeRevenue, $currency), (int) $locked->fee_platform_minor, 'PLATFORM_FEE')
                ->credit($this->ledger->platform(LedgerAccountCode::TaxLiability, $currency), (int) $locked->tax_held_minor, 'TAX_HELD');

            // An order with nothing to move (a free order with no commission) is recognized without a posting — the
            // ledger holds no empty transactions. It never happens to an order somebody paid for.
            $transaction = count($draft->lines()) >= 2 ? $this->ledger->post($draft) : null;
            if ($transaction === null) {
                $locked->forceFill(['status' => AllocationStatus::Void, 'voided_at' => $at, 'review_reason' => 'NOTHING_TO_RECOGNIZE', 'version' => (int) $locked->version + 1])->save();

                return $locked;
            }

            (new RestaurantPayableItem)->forceFill([
                'source_key' => 'order:'.$locked->order_public_id.':earning',
                'type' => RestaurantPayableItem::TYPE_ORDER_EARNING,
                'organization_id' => $locked->organization_id,
                'location_id' => $locked->location_id,
                'market_id' => $locked->market_id,
                'currency' => $currency,
                'amount_minor' => (int) $locked->restaurant_net_minor,
                'gross_minor' => (int) $locked->merchandise_gross_minor,
                'restaurant_discount_minor' => (int) $locked->restaurant_discount_minor,
                'commission_minor' => (int) $locked->commission_minor,
                'tax_passed_minor' => (int) $locked->tax_restaurant_minor,
                'fee_passed_minor' => (int) $locked->fee_restaurant_minor,
                'order_id' => $locked->order_id,
                'order_number' => $locked->order_number,
                'allocation_id' => $locked->getKey(),
                'ledger_transaction_id' => $transaction->getKey(),
                'description_code' => 'ORDER_EARNING',
                'status' => RestaurantPayableItem::STATUS_AVAILABLE,
                'available_at' => $at,
            ])->save();

            $locked->forceFill(['status' => AllocationStatus::Recognized, 'recognized_at' => $at, 'ledger_transaction_id' => $transaction->getKey(), 'version' => (int) $locked->version + 1])->save();
            $this->metrics->record('order_recognized');
            $this->metrics->record('commission_minor', max(0, (int) $locked->commission_minor));
            $this->metrics->record('gross_minor', max(0, (int) $locked->merchandise_gross_minor));

            return $locked;
        });
    }

    private function void(OrderFinancialAllocation $allocation): OrderFinancialAllocation
    {
        return DB::transaction(function () use ($allocation): OrderFinancialAllocation {
            $locked = OrderFinancialAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [AllocationStatus::Pending, AllocationStatus::ReviewRequired], true)) {
                return $locked;
            }
            $locked->forceFill(['status' => AllocationStatus::Void, 'voided_at' => now(), 'version' => (int) $locked->version + 1])->save();
            $this->metrics->record('order_voided');

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(Order $order, string $basis): array
    {
        $gross = (int) $order->subtotal_minor;
        $discount = (int) $order->discount_minor;
        $tax = (int) $order->tax_minor;
        $fee = (int) $order->fee_minor;
        $review = null;

        // Who pays for the discount: the promotion's own funding source, read once and kept.
        [$funding, $restaurantDiscount, $platformDiscount] = ['NONE', 0, 0];
        if ($discount > 0) {
            $funding = $order->promotion_id === null ? 'UNKNOWN' : (string) ($order->promotion()->value('funding_source') ?? 'UNKNOWN');
            $sharedBps = config('finance.promotions.shared_restaurant_share_bps');
            [$restaurantDiscount, $platformDiscount, $review] = match (true) {
                $funding === 'PLATFORM' => [0, $discount, null],
                $funding === 'RESTAURANT' => [$discount, 0, null],
                $funding === 'SHARED' && $sharedBps !== null => [$r = CommissionCalculationService::percentage($discount, max(0, min(10000, (int) $sharedBps))), $discount - $r, null],
                $funding === 'SHARED' => [0, 0, OrderFinancialAllocation::REVIEW_SHARED_SPLIT],
                default => [0, 0, OrderFinancialAllocation::REVIEW_FUNDING_UNKNOWN],
            };
        }

        $taxOwner = in_array(config('finance.tax.owner'), ['PLATFORM', 'RESTAURANT'], true) ? (string) config('finance.tax.owner') : 'UNASSIGNED';
        $feeOwner = config('finance.fees.owner') === 'RESTAURANT' ? 'RESTAURANT' : 'PLATFORM';
        $taxRestaurant = $taxOwner === 'RESTAURANT' ? $tax : 0;
        $feeRestaurant = $feeOwner === 'RESTAURANT' ? $fee : 0;

        $attributes = [
            'order_id' => $order->getKey(),
            'order_public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'market_id' => $order->market_id,
            'organization_id' => $order->organization_id,
            'location_id' => $order->location_id,
            'currency' => $order->currency,
            'customer_total_minor' => (int) $order->total_minor,
            'merchandise_gross_minor' => $gross,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'tax_included_minor' => (int) $order->tax_included_minor,
            'fee_minor' => $fee,
            'promotion_code' => $order->promotion_code,
            'promotion_funding' => $funding,
            'promotion_funding_basis' => $discount > 0 ? $basis : 'NONE',
            'restaurant_discount_minor' => $restaurantDiscount,
            'platform_discount_minor' => $platformDiscount,
            'tax_owner' => $taxOwner,
            'tax_restaurant_minor' => $taxRestaurant,
            'tax_held_minor' => $tax - $taxRestaurant,
            'fee_owner' => $feeOwner,
            'fee_restaurant_minor' => $feeRestaurant,
            'fee_platform_minor' => $fee - $feeRestaurant,
            'placed_at' => $order->placed_at,
        ];

        $plan = $order->market_id === null ? null : $this->commission->planFor((int) $order->market_id, (int) $order->organization_id, (int) $order->location_id, $order->placed_at);
        $review ??= $plan === null ? OrderFinancialAllocation::REVIEW_NO_PLAN : null;
        if ($review !== null) {
            return [...$attributes, 'status' => AllocationStatus::ReviewRequired, 'review_reason' => $review];
        }

        $commission = $this->commission->calculate((int) $plan->rate_bps, $plan->base_policy, $gross, $restaurantDiscount, $platformDiscount);

        return [
            ...$attributes,
            'status' => AllocationStatus::Pending,
            'commission_plan_id' => $plan->getKey(),
            'commission_plan_public_id' => $plan->public_id,
            'commission_plan_version' => $plan->version,
            'commission_scope' => $plan->scope_type,
            'commission_source' => OrderFinancialAllocation::SOURCE_PLAN,
            'commission_rate_bps' => $commission['rate_bps'],
            'commission_base_policy' => $plan->base_policy,
            'commission_base_minor' => $commission['base_minor'],
            'commission_minor' => $commission['amount_minor'],
            ...self::nets($gross, $restaurantDiscount, $platformDiscount, $commission['amount_minor'], $taxRestaurant, $feeRestaurant, $fee - $feeRestaurant),
        ];
    }

    /**
     * @return array{restaurant_net_minor: int, platform_net_minor: int}
     */
    private static function nets(int $gross, int $restaurantDiscount, int $platformDiscount, int $commission, int $taxRestaurant, int $feeRestaurant, int $feePlatform): array
    {
        return [
            'restaurant_net_minor' => $gross - $restaurantDiscount - $commission + $taxRestaurant + $feeRestaurant,
            'platform_net_minor' => $commission + $feePlatform - $platformDiscount,
        ];
    }
}
