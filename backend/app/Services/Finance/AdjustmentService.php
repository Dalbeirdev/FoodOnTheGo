<?php

namespace App\Services\Finance;

use App\Enums\LedgerAccountCode;
use App\Enums\LedgerTransactionType;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\FinancialAdjustment;
use App\Models\Order;
use App\Models\OrderFinancialAllocation;
use App\Models\PaymentDispute;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayableItem;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The decisions that move money between a restaurant and the platform after an order was recognized (Module 36):
 *
 *   REFUND_LIABILITY   a completed refund of a recognized order — who bears it?
 *   DISPUTE_LIABILITY  what a lost dispute took — who bears it?
 *   MANUAL             a correction of a restaurant's payable, by a person
 *
 * Each is a record with a reason, a balanced ledger posting and — when the restaurant's side changes — a NEW payable
 * line for a later settlement. Nothing here touches a past allocation, a past settlement or a past payout: when the
 * restaurant was already paid, its share of a later refund is a negative line that lowers what it is owed next time.
 * Nothing is ever taken from a restaurant's bank account.
 *
 * WHO BEARS A REFUND IS POLICY (config/finance.php, PENDING BUSINESS / LEGAL APPROVAL): per refund reason RESTAURANT,
 * PLATFORM or SHARED; without a policy for the reason the refund waits (UNASSIGNED) for a person with
 * admin.finance.adjustments.create, who states the restaurant's share explicitly. Commission is never given back by
 * this class — that would be a commercial rule nobody has defined.
 */
final class AdjustmentService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly FinanceMetrics $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * A completed refund of a recognized order: records who bears it — by the configured policy, or as a decision
     * that waits for a person. Once per refund.
     */
    public function forRefund(Refund $refund, OrderFinancialAllocation $allocation): FinancialAdjustment
    {
        if (($existing = FinancialAdjustment::query()->where('refund_id', $refund->getKey())->first()) !== null) {
            return $existing;
        }
        [$liability, $bps] = self::policyFor($refund->reason_code->value);
        $amount = $refund->amountMinor();
        try {
            $adjustment = DB::transaction(function () use ($refund, $allocation, $liability, $amount): FinancialAdjustment {
                $adjustment = new FinancialAdjustment;
                $adjustment->forceFill([
                    'kind' => FinancialAdjustment::KIND_REFUND,
                    'status' => FinancialAdjustment::STATUS_PENDING_DECISION,
                    'refund_id' => $refund->getKey(),
                    'order_id' => $allocation->order_id,
                    'order_number' => $allocation->order_number,
                    'organization_id' => $allocation->organization_id,
                    'location_id' => $allocation->location_id,
                    'market_id' => $allocation->market_id,
                    'currency' => $refund->currency,
                    'amount_minor' => $amount,
                    'liability' => $liability,
                    'reason_code' => $refund->reason_code->value,
                ])->save();

                return $adjustment;
            });
        } catch (UniqueConstraintViolationException $e) {
            return FinancialAdjustment::query()->where('refund_id', $refund->getKey())->first() ?? throw $e;
        }
        if ($liability === FinancialAdjustment::LIABILITY_UNASSIGNED) {
            return $adjustment;
        }
        $restaurant = match ($liability) {
            FinancialAdjustment::LIABILITY_RESTAURANT => $amount,
            FinancialAdjustment::LIABILITY_SHARED => CommissionCalculationService::percentage($amount, max(0, min(10000, (int) $bps))),
            default => 0,
        };

        return $this->post($adjustment, $restaurant, 'CONFIG', null, 'Refund liability policy for '.$refund->reason_code->value.'.');
    }

    /**
     * What a LOST dispute took from the platform's provider balance, for an order that had been recognized: always a
     * decision for a person — a restaurant is never charged for a dispute automatically.
     */
    public function forDispute(PaymentDispute $dispute, OrderFinancialAllocation $allocation, int $amountMinor): FinancialAdjustment
    {
        if (($existing = FinancialAdjustment::query()->where('dispute_id', $dispute->getKey())->first()) !== null) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($dispute, $allocation, $amountMinor): FinancialAdjustment {
                $adjustment = new FinancialAdjustment;
                $adjustment->forceFill([
                    'kind' => FinancialAdjustment::KIND_DISPUTE,
                    'status' => FinancialAdjustment::STATUS_PENDING_DECISION,
                    'dispute_id' => $dispute->getKey(),
                    'order_id' => $allocation->order_id,
                    'order_number' => $allocation->order_number,
                    'organization_id' => $allocation->organization_id,
                    'location_id' => $allocation->location_id,
                    'market_id' => $allocation->market_id,
                    'currency' => $dispute->currency,
                    'amount_minor' => $amountMinor,
                    'liability' => FinancialAdjustment::LIABILITY_UNASSIGNED,
                    'reason_code' => 'DISPUTE_LOST',
                ])->save();

                return $adjustment;
            });
        } catch (UniqueConstraintViolationException $e) {
            return FinancialAdjustment::query()->where('dispute_id', $dispute->getKey())->first() ?? throw $e;
        }
    }

    /**
     * A person decides who bears a refund or a dispute: the restaurant's share, in minor units, between zero and the
     * whole amount. The platform bears the rest.
     */
    public function decide(FinancialAdjustment $adjustment, AdminUser $admin, int $restaurantShareMinor, string $reason, ?int $expectedVersion = null): FinancialAdjustment
    {
        if ($adjustment->kind === FinancialAdjustment::KIND_MANUAL) {
            throw ApiException::conflict('adjustment_not_a_liability', 'This adjustment is not a liability decision.');
        }
        if ($adjustment->status === FinancialAdjustment::STATUS_POSTED) {
            return $adjustment;
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $adjustment->version) {
            throw ApiException::conflict('version_conflict', 'This record was changed by somebody else. Read it again.');
        }
        if ($restaurantShareMinor < 0 || $restaurantShareMinor > (int) $adjustment->amount_minor) {
            throw new ApiException(422, 'share_invalid', 'The restaurant\'s share lies between zero and the whole amount.', ['fields' => ['restaurant_share_minor' => ['Must be between 0 and '.$adjustment->amount_minor.'.']]]);
        }

        return $this->post($adjustment, $restaurantShareMinor, 'ADMIN', $admin, $reason);
    }

    /**
     * A manual correction of a restaurant's payable. Posted at once — or, with the four-eyes policy, kept for a
     * second administrator.
     *
     * @param  array{direction: string, amount_minor: int, currency: string, reason_code: string, reason: string, supporting_reference: string, order?: Order|null}  $input
     */
    public function manual(RestaurantLocation $location, AdminUser $admin, array $input, ?string $requestKey = null): FinancialAdjustment
    {
        if ($requestKey !== null && ($existing = FinancialAdjustment::query()->where('request_key', $requestKey)->first()) !== null) {
            return $existing;
        }
        if ($input['currency'] !== (string) $location->currency) {
            throw new ApiException(422, 'currency_mismatch', 'A restaurant is adjusted in its own currency.', ['fields' => ['currency' => ['This restaurant\'s currency is '.$location->currency.'.']]]);
        }
        $order = $input['order'] ?? null;
        if ($order !== null && (int) $order->location_id !== (int) $location->getKey()) {
            throw new ApiException(422, 'order_mismatch', 'This order is not an order of this restaurant.', ['fields' => ['order' => ['The order belongs to another restaurant.']]]);
        }
        $fourEyes = (bool) config('finance.adjustments.four_eyes');
        $adjustment = DB::transaction(function () use ($location, $admin, $input, $order, $requestKey, $fourEyes): FinancialAdjustment {
            $adjustment = new FinancialAdjustment;
            $adjustment->forceFill([
                'kind' => FinancialAdjustment::KIND_MANUAL,
                'status' => FinancialAdjustment::STATUS_PENDING_APPROVAL,
                'order_id' => $order?->getKey(),
                'order_number' => $order?->order_number,
                'organization_id' => $location->organization_id,
                'location_id' => $location->getKey(),
                'market_id' => $location->market_id,
                'currency' => $input['currency'],
                'amount_minor' => (int) $input['amount_minor'],
                'direction' => $input['direction'],
                'liability' => $input['direction'] === FinancialAdjustment::CREDIT_RESTAURANT ? FinancialAdjustment::LIABILITY_PLATFORM : FinancialAdjustment::LIABILITY_RESTAURANT,
                'reason_code' => $input['reason_code'],
                'reason' => mb_substr($input['reason'], 0, 500),
                'supporting_reference' => mb_substr($input['supporting_reference'], 0, 160),
                'request_key' => $requestKey,
                'created_by_admin_id' => $admin->getKey(),
            ])->save();
            $this->audit->record('finance.adjustment_created', $adjustment, $admin, [
                'direction' => ['from' => null, 'to' => $input['direction']],
                'amount_minor' => ['from' => null, 'to' => (int) $input['amount_minor']],
                'currency' => ['from' => null, 'to' => $input['currency']],
                'supporting_reference' => ['from' => null, 'to' => $input['supporting_reference']],
                'four_eyes' => ['from' => null, 'to' => $fourEyes],
            ], $input['reason'], $location->market_id);

            return $adjustment;
        });

        return $fourEyes ? $adjustment : $this->post($adjustment, (int) $adjustment->amount_minor, 'ADMIN', $admin, (string) $adjustment->reason);
    }

    /**
     * The second administrator of a manual adjustment that waits (four-eyes): never the one who made it.
     */
    public function approve(FinancialAdjustment $adjustment, AdminUser $admin, string $reason): FinancialAdjustment
    {
        if ($adjustment->kind !== FinancialAdjustment::KIND_MANUAL || $adjustment->status !== FinancialAdjustment::STATUS_PENDING_APPROVAL) {
            throw ApiException::conflict('adjustment_not_waiting', 'This adjustment does not wait for an approval.');
        }
        if ((int) $adjustment->created_by_admin_id === (int) $admin->getKey()) {
            throw new ApiException(403, 'four_eyes', 'Who made an adjustment cannot approve it.');
        }

        return $this->post($adjustment, (int) $adjustment->amount_minor, 'ADMIN', $admin, $reason);
    }

    public function cancel(FinancialAdjustment $adjustment, AdminUser $admin, string $reason): FinancialAdjustment
    {
        return DB::transaction(function () use ($adjustment, $admin, $reason): FinancialAdjustment {
            $locked = FinancialAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->kind !== FinancialAdjustment::KIND_MANUAL || $locked->status !== FinancialAdjustment::STATUS_PENDING_APPROVAL) {
                throw ApiException::conflict('adjustment_not_waiting', 'Only a manual adjustment that waits for its approval can be withdrawn.');
            }
            $locked->forceFill(['status' => FinancialAdjustment::STATUS_CANCELLED, 'decided_by_admin_id' => $admin->getKey(), 'decided_at' => now(), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('finance.adjustment_cancelled', $locked, $admin, ['status' => ['from' => FinancialAdjustment::STATUS_PENDING_APPROVAL, 'to' => FinancialAdjustment::STATUS_CANCELLED]], $reason, $locked->market_id);

            return $locked;
        });
    }

    /**
     * @return array{0: string, 1: int|null} liability and — for SHARED — the restaurant's share in basis points
     */
    public static function policyFor(string $reasonCode): array
    {
        $policy = ((array) config('finance.refund_liability.by_reason'))[$reasonCode] ?? null;
        $liability = is_array($policy) ? (string) ($policy[0] ?? '') : '';
        $bps = is_array($policy) ? ($policy[1] ?? null) : null;

        return match (true) {
            $liability === FinancialAdjustment::LIABILITY_RESTAURANT, $liability === FinancialAdjustment::LIABILITY_PLATFORM => [$liability, null],
            $liability === FinancialAdjustment::LIABILITY_SHARED && $bps !== null => [$liability, (int) $bps],
            default => [FinancialAdjustment::LIABILITY_UNASSIGNED, null],
        };
    }

    /* ------------------------------------------------------------------ internals */

    private function post(FinancialAdjustment $adjustment, int $restaurantShare, string $policySource, ?AdminUser $admin, string $reason): FinancialAdjustment
    {
        return DB::transaction(function () use ($adjustment, $restaurantShare, $policySource, $admin, $reason): FinancialAdjustment {
            $locked = FinancialAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === FinancialAdjustment::STATUS_POSTED) {
                return $locked;
            }
            if ($locked->status === FinancialAdjustment::STATUS_CANCELLED) {
                throw ApiException::conflict('adjustment_cancelled', 'This adjustment was withdrawn.');
            }
            $location = $locked->location()->firstOrFail();
            $currency = (string) $locked->currency;
            $amount = (int) $locked->amount_minor;
            $platformShare = $amount - $restaurantShare;
            $now = CarbonImmutable::now();
            $payable = $this->ledger->payable($location, $currency);
            $refundId = $locked->refund_id === null ? null : (string) $locked->refund()->value('public_id');
            $disputeId = $locked->dispute_id === null ? null : (string) $locked->dispute()->value('public_id');

            [$key, $type, $draft, $itemType, $itemAmount, $itemKey, $code] = match ($locked->kind) {
                FinancialAdjustment::KIND_REFUND => [
                    'refund:'.$refundId.':liability', LedgerTransactionType::RefundLiability,
                    fn (LedgerDraft $d) => $d->debit($payable, $restaurantShare, 'REFUND_BORNE_BY_RESTAURANT')
                        ->debit($this->ledger->platform(LedgerAccountCode::PlatformRefundExpense, $currency), $platformShare, 'REFUND_BORNE_BY_PLATFORM')
                        ->credit($this->ledger->platform(LedgerAccountCode::RefundClearing, $currency), $amount, 'REFUND_ASSIGNED'),
                    RestaurantPayableItem::TYPE_REFUND_ADJUSTMENT, -$restaurantShare, 'refund:'.$refundId.':restaurant-share', 'REFUND_ADJUSTMENT',
                ],
                FinancialAdjustment::KIND_DISPUTE => [
                    'dispute:'.$disputeId.':liability', LedgerTransactionType::DisputeLiability,
                    fn (LedgerDraft $d) => $d->debit($payable, $restaurantShare, 'DISPUTE_BORNE_BY_RESTAURANT')
                        ->debit($this->ledger->platform(LedgerAccountCode::PlatformDisputeExpense, $currency), $platformShare, 'DISPUTE_BORNE_BY_PLATFORM')
                        ->credit($this->ledger->platform(LedgerAccountCode::DisputeClearing, $currency), $amount, 'DISPUTE_ASSIGNED'),
                    RestaurantPayableItem::TYPE_DISPUTE_ADJUSTMENT, -$restaurantShare, 'dispute:'.$disputeId.':restaurant-share', 'DISPUTE_ADJUSTMENT',
                ],
                default => $locked->direction === FinancialAdjustment::CREDIT_RESTAURANT
                    ? ['adjustment:'.$locked->public_id, LedgerTransactionType::ManualAdjustment,
                        fn (LedgerDraft $d) => $d->debit($this->ledger->platform(LedgerAccountCode::PlatformAdjustments, $currency), $amount, 'MANUAL_CREDIT')->credit($payable, $amount, 'MANUAL_CREDIT'),
                        RestaurantPayableItem::TYPE_MANUAL_ADJUSTMENT, $amount, 'adjustment:'.$locked->public_id, 'MANUAL_CREDIT']
                    : ['adjustment:'.$locked->public_id, LedgerTransactionType::ManualAdjustment,
                        fn (LedgerDraft $d) => $d->debit($payable, $amount, 'MANUAL_DEBIT')->credit($this->ledger->platform(LedgerAccountCode::PlatformAdjustments, $currency), $amount, 'MANUAL_DEBIT'),
                        RestaurantPayableItem::TYPE_MANUAL_ADJUSTMENT, -$amount, 'adjustment:'.$locked->public_id, 'MANUAL_DEBIT'],
            };

            $transaction = $this->ledger->post($draft(LedgerDraft::for($key, $type, $currency, $now)
                ->source('financial_adjustment', (int) $locked->getKey(), (string) $locked->public_id, $locked->order_number)
                ->dimensions($locked->market_id, (int) $locked->organization_id, (int) $locked->location_id)
                ->explained($reason, $admin?->getKey(), ['kind' => $locked->kind, 'policy_source' => $policySource, 'supporting_reference' => $locked->supporting_reference])));

            if ($itemAmount !== 0) {
                (new RestaurantPayableItem)->forceFill([
                    'source_key' => $itemKey,
                    'type' => $itemType,
                    'organization_id' => $locked->organization_id,
                    'location_id' => $locked->location_id,
                    'market_id' => $locked->market_id,
                    'currency' => $currency,
                    'amount_minor' => $itemAmount,
                    'order_id' => $locked->order_id,
                    'order_number' => $locked->order_number,
                    'refund_id' => $locked->refund_id,
                    'adjustment_id' => $locked->getKey(),
                    'ledger_transaction_id' => $transaction->getKey(),
                    'description_code' => $code,
                    'status' => RestaurantPayableItem::STATUS_AVAILABLE,
                    'available_at' => $now,
                ])->save();
            }

            $before = $locked->status;
            $manual = $locked->kind === FinancialAdjustment::KIND_MANUAL;
            $locked->forceFill([
                'status' => FinancialAdjustment::STATUS_POSTED,
                'restaurant_share_minor' => $manual ? $amount : $restaurantShare,
                'platform_share_minor' => $manual ? 0 : $platformShare,
                'liability' => $manual ? $locked->liability : match (true) {
                    $restaurantShare === $amount => FinancialAdjustment::LIABILITY_RESTAURANT,
                    $restaurantShare === 0 => FinancialAdjustment::LIABILITY_PLATFORM,
                    default => FinancialAdjustment::LIABILITY_SHARED,
                },
                'policy_source' => $policySource,
                'reason' => $locked->reason ?? mb_substr($reason, 0, 500),
                'decided_by_admin_id' => $admin?->getKey(),
                'decided_at' => $now,
                'ledger_transaction_id' => $transaction->getKey(),
                'posted_at' => $now,
                'version' => (int) $locked->version + 1,
            ])->save();
            $this->audit->record('finance.adjustment_posted', $locked, $admin, [
                'status' => ['from' => $before, 'to' => FinancialAdjustment::STATUS_POSTED],
                'restaurant_share_minor' => ['from' => null, 'to' => $locked->restaurant_share_minor],
                'platform_share_minor' => ['from' => null, 'to' => $locked->platform_share_minor],
                'policy_source' => ['from' => null, 'to' => $policySource],
            ], $reason, $locked->market_id);
            $this->metrics->record('adjustment_posted');
            if ($locked->kind === FinancialAdjustment::KIND_REFUND) {
                $this->metrics->record('refund_adjustment');
            }

            return $locked;
        });
    }
}
